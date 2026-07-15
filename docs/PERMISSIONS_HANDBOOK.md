# Permissions Handbook — who can do what, and how to change it

The admin-facing guide to the access system. Written for whoever operates the
**Manage Access** screen (`/users/{user}/permissions`). Every statement here is
enforced in code — see `docs/FGAC_IMPLEMENTATION_PLAN.md` for the engineering
plan and `docs/FGAC_FEATURES_PERMISSIONS.csv` for the raw matrix.

Last updated: 2026-07-14 (includes the simplified consumer dashboard /
`meter.full_dashboard`).

---

## 1. The 30-second mental model

- **Permissions are the law.** Every page section and every API endpoint checks
  a permission slug server-side. Hiding is never the only protection — a user
  without the slug gets a 403 even if they hand-craft the request.
- **Bundles are just starter packs.** A bundle (consumer, prosumer,
  field_engineer, fleet_operator) is a named set of permissions you assign in
  one click. The app never asks "is this user a prosumer?" — it only ever asks
  "does this user hold slug X?".
- **Everything is additive.** A user's effective access = the union of all
  their bundles **plus** any direct grants. There is **no deny**: you cannot
  subtract one slug from a bundle-holder — instead use **Detach bundle** on the
  Manage Access screen, which converts the bundle into editable direct grants,
  then untick what you don't want.
- **Super Admin bypasses everything** (`Gate::before`). Never needs grants;
  new features never require updating a super-admin list.
- **Self-registration** (when `AUTH_ALLOW_REGISTRATION=true`) automatically
  assigns the **consumer** bundle.

---

## 2. The bundles — what each gives by default

### `consumer` *(default for self-service signups)*
| Gets | Experience |
|---|---|
| Built-ins + `meter.access`, `meter.live_data`, `meter.history`, `meter.rename` | The **simplified meter dashboard**: four tiles (Voltage, Power, Monthly Units, Daily Units) and a click-to-expand **Usage History** section showing hourly/daily aggregates (units + avg V/W). Can rename their own meter. Sees and configures alerts for their own devices. **Never sees raw minute-level data — not in the UI, not via the API.** |

### `prosumer`
| Gets | Experience |
|---|---|
| Everything consumer has, plus `meter.full_dashboard`, `meter.charts`, `meter.self_provision`, `devices.edit_own`, `devices.delete_own` | The **full operator dashboard**: 9 live KPI cards, five live electrical charts, monthly/daily report panels, raw minute-level readings table with pagination and CSV export. Can register their own meters, edit and delete their own devices. |

### `field_engineer`
| Gets | Experience |
|---|---|
| Built-ins + `devices.view_any`, `devices.create`, `devices.edit_any`, `devices.assign_owner`, `api.devices.write` | The installer profile: sees **every** device in the list, creates devices of any type, edits any device's config (topics, active state), assigns owners, and can provision devices through the API (for tooling). **No `meter.access`** — they manage hardware, they don't watch consumption dashboards. |

### `fleet_operator`
| Gets | Experience |
|---|---|
| Built-ins + `devices.view_any`, `dashboard.view_system_stats`, `alerts.view_any`, `alerts.fleet_scope`, `users.view_list`, `users.view_profile` | The monitoring/NOC profile: system-wide stats on the main dashboard, every device in lists, the **entire fleet's alerts** in the console, opt-in fleet-wide alert *delivery* (with a personal severity floor), and read-only access to the user directory. Cannot edit devices or users. |

### `super_admin`
Holds **no permission rows on purpose** — `Gate::before` answers "yes" to every
check. Also the only account that can delete users (route-locked, see §4).

---

## 3. Every switch, one by one — what turning it ON does

### 3.1 Built-ins (every bundle carries these — a user always has them)

| Slug | ON means |
|---|---|
| `dashboard.view` | Can open the main dashboard page. |
| `devices.view_own` | Sees **their own** devices in lists and can open their dashboards. Without `devices.view_any`, lists are query-scoped to owned devices only. |
| `alerts.view_own` | The alerts console (`/alerts`) shows alerts for **their own** devices. |
| `alerts.settings_own` | Can configure alert triggers (budgets, thresholds) on **their own** devices via `/devices/{id}/alerts`. |
| `api.devices.read` | API: list devices, device detail, live status endpoint. |
| `api.readings.read` | API: the readings family (chart/table/consumption/daily/aggregate) — each endpoint then applies its own `meter.*` check on top. |

### 3.2 Meter system

| Slug | ON means | OFF means |
|---|---|---|
| `meter.access` | **Master switch.** The meter dashboard renders (which variant depends on `meter.full_dashboard` below). All other `meter.*` slugs only matter with this ON. | Every meter dashboard shows a "no access" placeholder; all meter data APIs refuse. |
| `meter.live_data` | The live KPI tiles render and the 30s status poll runs (all 9 cards on the full view; the 4 tiles on the simple view). | No numeric readings — the user still sees the device header, health/availability pills and banners (health is informational, not a data privilege). |
| `meter.history` | Historical data. On the **full** view: the paginated raw readings table. On the **simple** view: the click-to-expand Usage History (hour/day buckets). API: `/readings` (full users) and `/readings/aggregate`. | No history section of any kind; both history APIs return 403. |
| `meter.charts` | The five live electrical charts (V+I dual-axis, power, energy, frequency, PF) + the `/readings/chart` API. **Also implies the full dashboard** — the charts only exist there. Per-user opt-in by design: in no default bundle except prosumer. | No live charts; chart API 403. |
| `meter.full_dashboard` | The **full operator dashboard**: raw minute-level readings, all KPI cards, monthly/daily report panels, full range bar. Also unlocks the raw `/readings` API (together with `meter.history`). | The user gets the **simplified consumer dashboard** — and the raw readings API refuses them even if called directly. |
| `meter.rename` | Can change **only the display name** of their own meter ("Kitchen Meter"). Server-side, every other submitted field is stripped. | Device edit form inaccessible (unless they hold `devices.edit_own`/`edit_any`). |
| `meter.self_provision` | Can register **their own** meter: create form locked to type=meter, ownership forced to self regardless of what the request claims. | Cannot add devices (unless they hold the broader `devices.create`). |

**Which dashboard does a user get?** One rule, one place
(`User::hasFullMeterDashboard()`):

```
meter.full_dashboard OR meter.charts  →  full operator dashboard
otherwise (with meter.access)         →  simplified consumer dashboard
no meter.access                       →  placeholder
```

### 3.3 Devices (management, mostly staff)

| Slug | ON means |
|---|---|
| `devices.view_any` | Sees **every** device in lists (with owner shown) and can open any device's pages. Main dashboard switches from "My Devices" to "Recent Devices" fleet view. |
| `devices.create` | Full device-create form: any type, and (with `assign_owner`) any owner. |
| `devices.edit_own` / `devices.edit_any` | Full edit (topics, type, active toggle) of own devices / of any device. `edit_any` also allows configuring alert settings on any device. |
| `devices.delete_own` / `devices.delete_any` | Delete own devices / any device. Deletion cascades that device's readings and rollups. |
| `devices.assign_owner` | The "Assign to user" dropdown appears on create/edit and is honoured server-side; without it, submitted `user_id` values are ignored. |
| `api.devices.write` | API: create/delete devices (`POST /api/devices`, `DELETE /api/devices/{id}`) — for provisioning tools. |

### 3.4 Alerts

| Slug | ON means |
|---|---|
| `alerts.view_any` | The alerts console shows **every device's** alerts, not just their own. (Seeing ≠ being notified — that's `fleet_scope`.) |
| `alerts.fleet_scope` | Eligible for **fleet-wide alert delivery**: their notification-preferences page shows the "all devices" scope toggle and a minimum-severity floor; the delivery pipeline then pages them for any device's alerts meeting that floor. Device owners are always notified about their own devices regardless of this slug. |

### 3.5 User management (delegation)

| Slug | ON means |
|---|---|
| `users.view_list` | Opens the `/users` area (also reveals the sidebar link). Prerequisite for everything below. |
| `users.view_profile` | Can open individual user profiles. |
| `users.create` | Can create accounts (and assign bundles on the create form). |
| `users.edit` | Can edit accounts (name, email, bundles). |
| `users.manage_permissions` | Can operate the **Manage Access** screen for other users — this is delegating the very authority described in this handbook. Grant deliberately. |
| `users.delete` | **Inert by design.** The delete route is hard-locked to super_admin; granting this slug to anyone else has no effect. It exists in the catalog for completeness only. |

### 3.6 Dashboard extras

| Slug | ON means |
|---|---|
| `dashboard.view_system_stats` | The main dashboard adds system-wide statistics (fleet counts etc.) instead of only personal ones. |

---

## 4. Enforcement guarantees (why toggles are safe)

1. **UI and API are symmetric.** Every section that can be hidden has a matching
   server-side check on its data endpoint — verified by the test suite
   (`MeterSectionPermissionsTest`, `MeterSimpleDashboardTest`,
   `PermissionManagementTest`).
2. **Ownership still binds.** Permissions never cross ownership unless the slug
   says "any": a prosumer with every meter slug still gets 403 on someone
   else's meter.
3. **Changes apply on the next request — no logout needed.** Per-user grants
   are read live; the Redis-backed Spatie cache only holds the catalog/bundle
   definitions and is flushed automatically when those change (e.g. re-running
   `PermissionSeeder`).
4. **Two things never delegate:** deleting users (route-locked to super_admin)
   and the super-admin bypass itself (a bundle with no rows — there is nothing
   to copy).

---

## 5. Recipes (common admin tasks)

| I want to… | Do this on Manage Access |
|---|---|
| Onboard a normal customer | Nothing — self-registration assigns **consumer** (simple dashboard). |
| Give a customer the full dashboard, no charts | Direct-grant `meter.full_dashboard`. |
| Give a customer the live charts | Direct-grant `meter.charts` (implies the full dashboard on its own). |
| Let a customer add their own meter | Direct-grant `meter.self_provision`. |
| Hide numbers from an account but keep it alive | Detach its bundle, keep only `meter.access` off — dashboards show the placeholder; or keep `meter.access` and drop `meter.live_data`/`meter.history` for a header-only view. |
| Hire an installer | Assign **field_engineer**. |
| Hire a NOC/monitoring person | Assign **fleet_operator**; they opt into fleet alert delivery themselves in notification preferences. |
| Make a support lead who manages customer access | `users.view_list` + `users.view_profile` + `users.manage_permissions`. |
| Remove one ability from a bundle-holder | **Detach bundle** (converts to direct grants) → untick the slug. Remember: no per-user deny. |
| Change what a bundle means for everyone | Edit `PermissionSeeder::BUNDLES` + re-run `php artisan db:seed --class=PermissionSeeder` — propagates to every holder. |

---

## 6. Quick who-sees-what (meter dashboard)

| | consumer | consumer + charts opt-in | prosumer | field_engineer | fleet_operator | super_admin |
|---|---|---|---|---|---|---|
| Dashboard variant | Simple | **Full** | Full | placeholder (no meter.access) | placeholder | Full |
| Voltage/Power/Monthly/Daily tiles | ✅ | ✅ (all 9 cards) | ✅ (all 9 cards) | — | — | ✅ |
| Live charts | — | ✅ | ✅ | — | — | ✅ |
| History resolution | **hour/day buckets** | raw (minute) | raw (minute) | — | — | raw |
| Raw readings API | 403 | ✅ | ✅ | 403 | 403 | ✅ |
| Aggregate API | ✅ | ✅ | ✅ | 403 | 403 | ✅ |
