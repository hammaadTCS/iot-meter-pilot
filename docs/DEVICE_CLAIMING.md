# Device Claiming — Design Decision (A5)

**Status:** Approved design, not yet implemented.
**Written:** 2026-08-04 · **Implements:** hardening item A5 · **Implemented by:** A6 (broker credentials + ACLs), Phase C provisioning.
**Depends on:** A4 (email verification) — currently **skipped**, see `PENDING_WORK.md` §4a and §7 below.

This document is the normative rule. Code that contradicts it is a bug in the code.

---

## 1. Why this exists

Today a customer attaches a meter to their account by **typing an MQTT topic into a web form**
([`DeviceManagementController::store`](../app/Http/Controllers/DeviceManagementController.php#L45-L83)).
That is the entire binding. Three defects follow from it, all verified in the current code:

### 1.1 Topic squatting (the reason this document exists)

`mqtt_topic` carries a global unique index, so a user cannot take a topic that is **already
registered**. Nothing stops them registering a topic belonging to a meter that has **not been
added yet**. When that meter is installed and powers on, its readings arrive on a topic owned by
the squatter's device row, and
[`MeterPayloadProcessor::process`](../app/Services/Meters/MeterPayloadProcessor.php#L29)
files them against the squatter's account. The victim sees an empty dashboard and never learns why.

Guessing is cheap because the topic convention is predictable (`meters/{code}/data`).

### 1.2 The web form does not validate topic uniqueness at all

Compare the two creation paths:

| | `mqtt_topic` | `availability_topic` |
|---|---|---|
| API — [`Api/DeviceController::store:59-60`](../app/Http/Controllers/Api/DeviceController.php#L59-L60) | `unique:devices,mqtt_topic` | `unique:...` + `different:mqtt_topic` |
| Web — [`DeviceManagementController::store:65-66`](../app/Http/Controllers/DeviceManagementController.php#L65-L66) | **no uniqueness rule** | **no uniqueness rule** |

On the web path a duplicate `mqtt_topic` reaches the database and trips the unique index as an
unhandled `QueryException` — the user gets a 500, not a validation error.

### 1.3 `availability_topic` is not unique — this one is a live cross-tenant defect

[The migration](../database/migrations/2026_04_21_150000_add_availability_columns_to_devices_table.php#L17)
adds a **plain index**, not a unique one:

```php
$table->index('availability_topic', 'devices_availability_topic_index');
```

Combined with §1.2, a self-provisioning user can set `availability_topic` to **another customer's
status topic**, and nothing rejects it. [`MeterAvailabilityProcessor`](../app/Services/Meters/MeterAvailabilityProcessor.php#L16)
then resolves the owner with an exact string match and `->first()`:

```php
$device = Device::whereRaw('TRIM(availability_topic) = ?', [$topic])->first();
```

With two rows sharing a topic, which one wins is whatever the database returns first. The victim's
meter can stop receiving availability updates while the attacker's device shows the victim's
online/offline state — which reveals when that household is home.

**This is exploitable today and does not wait for the full claiming implementation.** See §8 for the
one-line interim fix.

---

## 2. The rule

> **A device is bound to an account by presenting a single-use claim code that ships physically with
> the unit. The customer never sees, types, or chooses an MQTT topic — the server derives it from an
> immutable server-assigned identifier.**

Normative consequences:

1. **Claim codes are generated at provisioning**, before the unit leaves the factory/workshop, and
   printed on the device.
2. **A claim code is single-use.** Claiming marks it consumed. Re-claiming requires an explicit
   release by the current owner (or an operator with `devices.assign_owner`).
3. **The server owns the topic namespace.** `mqtt_topic` and `availability_topic` become derived,
   read-only, server-computed values. They are removed from every request payload.
4. **A device has exactly one owner at a time**, and an unclaimed device has `user_id = null`.
5. **Claiming requires an authenticated, email-verified account** (see §7 — this dependency is
   currently unmet).

---

## 3. Schema

```
devices
  + device_uid          char(26)  NOT NULL  UNIQUE   -- ULID, server-generated, immutable
  + claim_code_hash     char(64)  NULL      UNIQUE   -- sha256 of the printed code
  + claimed_at          timestamp NULL
  + claimed_by_user_id  bigint    NULL      FK users -- audit; user_id remains the live owner
  + released_at         timestamp NULL
  ~ user_id             stays nullable — null now means "manufactured, unclaimed"
  ~ availability_topic  plain index -> UNIQUE index  (closes §1.3)
```

**Why `device_uid` and not the existing `code`.** `code` is a user-editable display label
(`meter.rename` grants exactly that). Deriving a topic from it would mean a rename silently
re-points the MQTT subscription and orphans the physical meter. `device_uid` is assigned once at
provisioning and never changes, so renaming is free and the topic is stable for the life of the unit.

**Why store only a hash of the claim code.** The plaintext code exists on the printed label and
nowhere else. A database leak must not yield a list of claimable devices. Lookup is by
`hash(input)`, so the unique index still gives an O(1) claim path.

Derived topics, computed by the server, never user-supplied:

```
mqtt_topic          meters/{device_uid}/data
availability_topic  meters/{device_uid}/status
```

`Device::deriveAvailabilityTopic()` ([Device.php:149](../app/Models/Device.php#L149)) already produces
`.../status` from `.../data` and is reused unchanged.

---

## 4. Claim code format

- **Alphabet:** Crockford Base32 minus `I`, `L`, `O`, `U` — no character pairs a customer can misread
  or that can form an offensive string.
- **Length:** 12 characters ⇒ 60 bits of entropy, printed as `XXXX-XXXX-XXXX`.
- **Not derived** from `device_uid`, `code`, serial number, or a counter. Generate with
  `random_bytes()`, never `rand()`/`uniqid()`.
- **Case-insensitive on input**, normalised to upper case and stripped of separators before hashing.

60 bits is not brute-forceable at the rate limits in §6, and it stays short enough to read off a
label and type on a phone.

---

## 5. Flows

### 5.1 Provisioning (operator, before shipping)

```
1. Generate device_uid (ULID) and a claim code.
2. Derive mqtt_topic + availability_topic from device_uid.
3. INSERT devices row: user_id = NULL, claim_code_hash = sha256(code), is_active = true.
4. Generate per-device broker credentials and topic ACLs          [A6]
5. Print the claim code on the unit. Display the plaintext ONCE.  Never persist it.
```

Step 5 is the only moment the plaintext exists in the system. If it is lost, the fix is
re-provisioning, not recovery.

### 5.2 Claiming (customer)

```
POST /devices/claim   { claim_code }
```

All of the following inside **one database transaction**:

```
1. Reject unless the actor is authenticated and email-verified.        [A4 — see §7]
2. Rate-limit: per account AND per source IP (§6).
3. hash = sha256(normalise(claim_code))
4. SELECT ... WHERE claim_code_hash = hash FOR UPDATE
   - no row            -> generic failure (§6.2)
   - claimed_at != null -> generic failure (§6.2)
5. UPDATE user_id = actor, claimed_at = now(), claimed_by_user_id = actor,
          claim_code_hash = NULL          -- single-use: burn it
6. Provision/enable the broker ACL for this device_uid.               [A6]
7. Write an audit record (actor, device_uid, action=claim, ip, ua).
8. COMMIT
```

**Step 6 must be inside the transaction** (threat T-I7). If the ACL is provisioned afterwards and
that step fails, the customer owns a device the broker will refuse — and the MQTT consumer, once A16
lands and it re-subscribes dynamically, retries a subscription the broker rejects. Roll the claim
back instead.

### 5.3 Release

```
DELETE /devices/{device}/claim
```

- Permitted to the current owner, or a holder of `devices.assign_owner`.
- Sets `user_id = NULL`, `released_at = now()`, revokes the broker ACL, writes an audit record, and
  **issues a new claim code** (the printed one was burned in 5.2 step 5). The new code is displayed
  once to whoever performed the release — that is the handover artifact for resale or RMA.
- Readings are **retained** and stay attached to the device, not the person. Whether a new owner may
  see the previous owner's history is a **product decision, not a technical one, and is deliberately
  left open here.** Default until decided: the reporting queries filter on `claimed_at`, so a new
  owner sees only their own tenure.

---

## 6. Threat model

### 6.1 The claim code is a bearer token (T-I4)

Anyone who reads the label can claim the device: an installer, a courier, a previous tenant, a
flatmate, someone with a photo of the box. This is inherent to printed-code claiming and is accepted;
these controls bound it:

| Control | Effect |
|---|---|
| Single-use | A code works once. A second attempt fails even with the correct code. |
| Owner-authorised release only | A claimed device cannot be silently re-claimed. |
| Verified account required | Ties every claim to a provable email address (§7). |
| Audit record on claim + release | Disputes are resolvable after the fact. |
| Notify the previous owner on release | The legitimate owner learns immediately. |

### 6.2 Enumeration

- **Rate limit:** 5 attempts/hour per account **and** 20/hour per IP. Both, not either — per-account
  alone is defeated by registering accounts; per-IP alone punishes shared carrier-grade NAT, which is
  common on mobile networks in our market (the same reasoning as A4's throttle keying).
- **Uniform failure response:** unknown code, already-claimed code, and released-device code all
  return one identical error. Distinguishing them turns the endpoint into an oracle for which codes
  exist.
- **Timing:** compare with `hash_equals`, and hash the input even when no row is found, so a miss
  costs the same as a hit.

### 6.3 What this closes

| Defect | Closed by |
|---|---|
| §1.1 topic squatting | Topics are server-derived; `mqtt_topic` leaves the request payload entirely. |
| §1.2 missing web validation | The field no longer exists on the form. |
| §1.3 availability topic collision | Unique index + server-derived value. |
| Cross-tenant publish | Per-device broker ACLs keyed to `device_uid` (A6). |

---

## 7. Unmet dependency: verified accounts

§2 rule 5 and §6.1 both assume a claim can be tied to a **proven** email address. **A4 was skipped on
2026-08-04**, and `User` does not implement `MustVerifyEmail`, so `verified` middleware passes
everyone (`PENDING_WORK.md` §4a).

Until A4 lands, a claim is bound to an account whose email address may belong to someone else. That
weakens §6.1's "verified account" control and makes ownership disputes unresolvable — the audit trail
in §6.1 points at an address nobody proved they control.

**Decision: do not weaken the rule to match the current state.** Implement the verified-account check
as specified. If claiming ships before A4, the check is inert (it passes everyone) and becomes
effective the moment A4 lands, with no code change. Do **not** substitute a weaker binding.

---

## 8. Interim fix — do this before the full implementation

§1.3 is exploitable today. It does not need `device_uid`, claim codes, or the broker work:

1. Add `unique:devices,availability_topic` and `different:mqtt_topic` to
   [`DeviceManagementController::store`](../app/Http/Controllers/DeviceManagementController.php#L65-L66)
   and to `update()`, matching what the API path already does.
2. Add `unique:devices,mqtt_topic` on the same rules, so a duplicate is a 422 rather than a 500.
3. Migration: promote `devices_availability_topic_index` to a **unique** index. Check for existing
   duplicates first — if the live table already has collisions, resolve them before the migration,
   because it will fail loudly (which is correct).

Roughly an hour, and it removes the cross-tenant exposure without pre-empting any decision here.

---

## 9. Existing devices

The live fleet has hand-typed topics that firmware already publishes to. They cannot be re-pointed by
a database migration alone.

1. Backfill `device_uid` for every existing row. **Do not change `mqtt_topic`.**
2. Add `topic_is_legacy` (boolean). Legacy rows keep their typed topic; the resolver returns the
   stored value for those and the derived value otherwise.
3. Re-point legacy devices to derived topics during the **A6/A7 OTA window**, since firmware is being
   reflashed for broker credentials and TLS anyway. One coordinated touch, not two.
4. Issue claim codes for existing devices only if they are ever transferred; a device already bound to
   the correct owner does not need to be re-claimed.

`topic_is_legacy` is expected to reach zero and the column should then be dropped.

---

## 10. Also resolve: the dead unique index

`devices` carries **both** a global `unique(code)` (from
[the create migration](../database/migrations/2026_03_10_055708_create_devices_table.php#L18-L40))
and a per-user `unique(user_id, code)` (from
[the user_id migration](../database/migrations/2026_05_07_114243_add_user_id_to_devices_table.php#L15-L22)).
The global one was never dropped, so the per-user index is unreachable — two customers can never use
the same display label, despite both controllers validating per-user with `Rule::unique(...)->where('user_id', ...)`.

Under this design `code` is a pure display label. **Drop the global `unique(code)`** and keep the
per-user one. Do this in the same migration that adds `device_uid`, since that is what takes over
`code`'s identity role.

---

## 11. Test plan

- Claiming an unclaimed device binds it and burns the code.
- Replaying the same code fails with the §6.2 uniform error.
- Claiming an already-claimed device fails, and the existing owner is unchanged.
- An unknown code and an already-claimed code return **byte-identical** responses.
- Rate limits trip per-account and per-IP independently; a second user on the same IP is not locked
  out by the first (the carrier-grade NAT case).
- Release restores claimability and issues a new code; the old code stays dead.
- `mqtt_topic` / `availability_topic` supplied in a claim or update request are ignored.
- A failed ACL provisioning (A6) rolls the claim back — no half-claimed device.
- Legacy devices keep their stored topic and continue to ingest (§9).
