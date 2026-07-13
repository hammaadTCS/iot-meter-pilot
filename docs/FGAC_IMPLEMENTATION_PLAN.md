# Hybrid Access Control — Professional Implementation Plan (v2)

**Project:** IoT Meter Pilot
**Date:** 2026-07-10 · **Supersedes:** v1 (2026-06-04, pure per-user FGAC)
**Stack (verified):** Laravel 12, **MySQL** (`DB_DATABASE=iot_meter_pilot`), Redis (installed, phpredis), Sanctum, Blade + Alpine.js, spatie/laravel-permission (to be installed — not yet in composer.json)

---

## 0. Executive Summary

Replace the 3-role enum system with a **hybrid access-control architecture**:

| Layer | Mechanism | Who uses it |
|---|---|---|
| **Enforcement** (FGAC) | Permission slugs checked by `can()` everywhere | Code — routes, policies, controllers, Blade, API |
| **Administration** (bundles) | Spatie roles as named permission sets | Humans — one dropdown at account creation |
| **Exceptions** | Per-user direct grants on top of a bundle | Super Admin, for edge cases only |

**The invariant that makes it work:** application code checks *permissions only* — never
roles. The single `hasRole()` in the codebase is the `Gate::before` super-admin bypass.
Bundles exist purely so a human never toggles 25 checkboxes to onboard a user.

**Known Spatie limitation designed around:** effective permissions are a *union*
(direct ∪ via-bundles). There is **no per-user deny**. Therefore bundles are kept lean
(only what every member of the archetype needs); subtractive exceptions are handled by
the "detach bundle → keep as direct grants" action in the permission UI (Phase 4).

Every phase ends in a **deployable state with a green test suite** and is one revertable
commit. Core runtime systems — MQTT ingestion, rollups, alert detection/coalescing — are
untouched: nothing in `app/Services/Meters/`, the consumer command, or the scan commands
changes. Only two authorization *predicates* in the alert pipeline are swapped
(§6, Phase 5), exactly at the seams left for this purpose.

---

## Status Ledger

| Phase | Status | Commit | Date |
|---|---|---|---|
| R — Repository hygiene | ✅ DONE | `c812e7f` | 2026-07-10 |
| 0 — Redis cache + env dedupe | ✅ DONE | `559655c` | 2026-07-10 |
| 1 — Spatie install + Gate::before | ✅ DONE | `6e1ca32` | 2026-07-10 |
| 2 — Catalog, bundles, user migration | ✅ DONE | `6291f4a` | 2026-07-10 |
| 3 — Registration mode (D1) | ✅ DONE | `f6aa88e` | 2026-07-10 |
| 4 — Access management UI | ✅ DONE | `396258f` | 2026-07-10 |
| 5 — Enforcement cutover | ⏳ NEXT (awaiting browser review of Phase 4) | | |
| 6 — View cutover | pending | | |
| 7 — Legacy removal | pending | | |
| 8 — Guardrails + docs | pending | | |

**Current runtime state:** both systems coexist; the legacy role checks still enforce
everything, while bundles/permissions are fully populated and managed via the new
`/users/{user}/permissions` screen. App behavior is unchanged for end users. Suite: 161 green.

**Deviations from plan, decided during implementation (all deliberate):**

1. **Guards (§3.1):** permissions are seeded in the `web` guard **only**, not duplicated
   into `sanctum`. Spatie resolves the guard from the User model's default guard (`web`)
   for session, stateful-API and token requests alike — duplicate rows would add
   confusion, not safety. Phase 5's API tests must prove this holds.
2. **Redis (Phase 0):** no system Redis existed (v1's claim was wrong). Runs as Docker
   container `iot-redis` (redis:7-alpine, localhost-only) with the pure-PHP `predis`
   client (`REDIS_CLIENT=predis`) — no PHP extension needed. `.env` also had
   `CACHE_STORE`/`SESSION_DRIVER` defined 3× each (last-one-wins); deduplicated.
3. **Legacy role column:** new accounts keep the column's DB default `'user'` rather
   than NULL — legacy code paths always see a valid enum until Phase 7 drops the column.
   Bundles are the access authority for these accounts.
4. **Phase 4 pulled a slice of Phase 6 forward:** users index/show/edit already display
   bundle chips instead of the role badge, and the list filter is by bundle — the role
   badge would have shown wrong data for bundle-only accounts.
5. **Test seeding:** the permission catalog is seeded per-test in `TestCase::setUp()`,
   NOT via `afterRefreshingDatabase()` — the RefreshDatabase trait ships an empty stub
   of that hook which takes precedence over a parent-class override and silently
   disables it. Suite duration ~6.5s → ~11s (catalog seeded per test).
6. **Known trade-off until Phase 5:** an account created now with `field_engineer` /
   `fleet_operator` bundles cannot exercise those powers yet — the legacy `admin`
   middleware still guards those routes and reads the role column. Resolved by the
   Phase 5 cutover.

---

## 1. Decisions Locked Before Work Starts

| # | Decision | Resolution |
|---|---|---|
| **D1** | Self-registration (B2C scope says yes; v1 plan said no) | **RESOLVED 2026-07-10: self-serve B2C.** Config flag `auth.allow_registration` (env `AUTH_ALLOW_REGISTRATION`, **default `true`**); registration auto-assigns the `consumer` bundle. Flipping to invite-only is one env change (routes vanish, links hide via `Route::has('register')`, controller keeps an `abort_unless` as defense in depth). |
| **D2** | Migration is **deliberately not behavior-preserving** for regular users | Today a `user` can create devices and fully edit/delete their own ([DeviceManagementController.php:45-59](../app/Http/Controllers/DeviceManagementController.php#L45-L59)). Post-migration, `consumer` has rename-only + no create. This tightening is the point of the project (v1 §1). Users needing the old power get the `prosumer` bundle — a one-click re-grant. Communicate before cutover. |
| **D3** | Old `admin` role maps to | `field_engineer` + `fleet_operator` bundles (composable — Spatie allows multiple roles per user). |
| **D4** | Doc/file hygiene | Legacy root scratch files removed in Phase R (§4). Docs stay at 10 files: this plan replaces v1 in place. |

---

## 2. Verified Current-State Inventory (every touchpoint, from code)

### 2.1 Role checks to replace

| Location | What it does today |
|---|---|
| [User.php:66-93](../app/Models/User.php#L66-L93) | `canAccessDevice()`, `isSuperAdmin()`, `isAdminOrAbove()`, `isAdmin()`; `'role'` in `$fillable` |
| [DevicePolicy.php](../app/Policies/DevicePolicy.php) | all methods = admin-or-owner; `viewAny()`/`create()` return `true` |
| [AdminMiddleware](../app/Http/Middleware/AdminMiddleware.php) / [SuperAdminMiddleware](../app/Http/Middleware/SuperAdminMiddleware.php) | aliased `admin`/`superadmin` in [bootstrap/app.php:17-20](../bootstrap/app.php#L17-L20), used in [routes/web.php:53-66](../routes/web.php#L53-L66) |
| [DashboardController.php:20,37](../app/Http/Controllers/DashboardController.php#L20) | system stats + device scope |
| [DeviceManagementController.php](../app/Http/Controllers/DeviceManagementController.php) (lines 21-123) | list scope, create form, owner assignment, edit/delete gates |
| [DeviceDashboardController.php:18](../app/Http/Controllers/DeviceDashboardController.php#L18) | admin-or-owner gate |
| [UserManagementController.php](../app/Http/Controllers/UserManagementController.php) (43-131) | role dropdown validation, `updateRole()`, super-admin gates |
| [Api/DeviceController.php:22](../app/Http/Controllers/Api/DeviceController.php#L22) | `isAdmin()` → unscoped query |
| [NotificationPreferenceController.php:17,42](../app/Http/Controllers/NotificationPreferenceController.php#L17) | exposes `isAdmin` to view; gates `fleet_scope='all'` |
| [AlertEvent::visibleTo()](../app/Models/AlertEvent.php#L54) | admin sees all alerts — **FGAC seam #1** |
| [EnqueueAlertForDelivery::recipientIds()](../app/Listeners/EnqueueAlertForDelivery.php) | owner + fleet-scope users — **FGAC seam #2** |
| [Device::forUser()](../app/Models/Device.php#L61-L64) | ownership scope (stays; callers decide when to bypass via `devices.view_any`) |
| Blades | `devices-create/edit/manage`, `dashboard`, `users/{index,create,edit,show}`, `settings/notifications`, `components/sidebar`, `components/role-badge` |
| Tests referencing `role` | `AuthenticationTest`, `AlertsConsoleTest`, `MeterAlertSettingsTest`, `NotificationPreferencesTest` (+ any factory usage) |

### 2.2 Dead / legacy code discovered (removed by this plan)

| Item | Evidence | Action |
|---|---|---|
| `app/Http/Controllers/MeterDashboardController.php` | referenced by **zero** routes/tests/code (grep-verified) | delete (Phase R) |
| Root files `consumer`, `dashboard.`, `MySQL` | zero-byte accidental files | delete (Phase R) |
| Root file `User::whereIn('id', [1,2,3,4,5,6])->…` | tinker output pasted into a filename | delete (Phase R) |
| Root file `iot_meter_pilot` (SQLite) | stray artifact — **live DB is MySQL** with the same schema name | back up to `storage/backups/`, then delete (Phase R) |
| Root scratch docs: `AUTH_IMPLEMENTATION_GUIDE.md`, `QUICK_START_AUTH.md`, `WEEK_1_AUTH_COMPLETE.md`, `WEEK_3_4_DETAILED.md`, `IMPLEMENTATION_PLAN.md`, `IMPLEMENTATION_CHECKLIST.md` | handbook §5 already declares them "historical scratch, not load-bearing" | delete (Phase R); `README.md`, `QUICK_START.md` stay |
| `/devices/manage` redirect route | [web.php:50](../routes/web.php#L50) self-labelled "remove in Phase 6" | delete (Phase 7) |
| `components/role-badge.blade.php` | meaningless without the role column | delete (Phase 7) |
| `AdminMiddleware`, `SuperAdminMiddleware`, `User` role helpers, `updateRole()`, `users` role column + dropdowns | replaced by this system | delete (Phase 7) |

---

## 3. Target Design

### 3.1 Permission catalog (single source of truth = `PermissionSeeder`)

All slugs live in the **`web` guard only** (see Status Ledger, deviation 1): Spatie
resolves the guard from the User model's default guard, so session, stateful-API and
Sanctum-token requests all check the same set.

**Built-in (member of every bundle — a user always holds ≥ these):**

| Slug | Grants |
|---|---|
| `dashboard.view` | the `/dashboard` page |
| `devices.view_own` | own devices in lists |
| `alerts.view_own` | alerts console scoped to own devices |
| `alerts.settings_own` | per-meter alert-trigger screen for own meters |
| `api.devices.read` | GET device/status/snapshot API |
| `api.readings.read` | readings/chart/consumption API |

Identity operations (login, logout, own profile CRUD, own notification prefs) are
**not permission-gated** — tied to the authenticated session, per v1's CSV.

**Grantable:**

| Group | Slugs |
|---|---|
| Dashboard | `dashboard.view_system_stats` |
| Devices | `devices.view_any`, `devices.create`, `devices.edit_own`, `devices.edit_any`, `devices.delete_own`, `devices.delete_any`, `devices.assign_owner` |
| Meter | `meter.access`, `meter.self_provision`, `meter.rename`, `meter.live_data`, `meter.charts`, `meter.history` |
| Alerts *(new — closes the v1 gap)* | `alerts.view_any` (fleet alert console), `alerts.fleet_scope` (may set `fleet_scope='all'` → fleet alert *delivery*) |
| Users | `users.view_list`, `users.view_profile`, `users.create`, `users.edit`, `users.delete`, `users.manage_permissions` |
| API | `api.devices.write` |

Semantics of `meter.access` + the three section permissions, `meter.rename`
name-only server-side stripping, and `meter.self_provision` type-locked/self-owned
creation are **unchanged from v1 §7** — that design was correct and is adopted verbatim.

### 3.2 Bundles (Spatie roles used *only* as grant templates)

| Bundle | Built-ins + |
|---|---|
| `consumer` *(default)* | `meter.access`, `meter.live_data`, `meter.history`, `meter.rename` — **`meter.charts` deliberately excluded** (2026-07-13): it gates only the five live electrical charts and is a per-user opt-in the super admin grants directly. The Monthly Consumption panel and Daily Breakdown report are **basic** — they render for everyone with `meter.access`, matching their API guards. |
| `prosumer` | consumer + `meter.self_provision`, `devices.edit_own`, `devices.delete_own` |
| `field_engineer` | `devices.view_any`, `devices.create`, `devices.edit_any`, `devices.assign_owner`, `api.devices.write` |
| `fleet_operator` | `devices.view_any`, `dashboard.view_system_stats`, `alerts.view_any`, `alerts.fleet_scope`, `users.view_list`, `users.view_profile` |
| `super_admin` | *no permission rows* — `Gate::before` bypass |

Rules: bundles are composable (a user may hold several); bundles stay lean; extras are
direct grants; bundle contents are pinned by a snapshot test (Phase 8) so they cannot
drift silently.

### 3.3 Resolution order at runtime

```
can('meter.charts') → Gate::before: hasRole('super_admin')? → allow
                    → Spatie cached set = direct ∪ bundle permissions   (Redis, ~0.1ms)
                    → slug ∈ set → allow / deny
```

`DevicePolicy` combines permission + ownership (e.g. `update` =
`devices.edit_any` ∨ (`devices.edit_own` ∧ owner) ∨ name-only path via `meter.rename`).

---

## 4. Phase R — Repository Hygiene (independent; do first)

Pure deletion, zero behavior risk, its own commit.

1. `mkdir -p storage/backups && mv iot_meter_pilot storage/backups/stray-root-sqlite-$(date +%F).sqlite` (verify app boots + `php artisan migrate:status` against MySQL afterwards).
2. `git rm` the dead controller and stray/scratch files listed in §2.2 (Phase R rows).
3. Full suite green; `php artisan route:list` unchanged.

**Rollback:** `git revert` (backup file preserved outside git).

---

## 5. Phases 0–4 — Foundation (additive, zero user-visible change until Phase 5)

### Phase 0 — Infrastructure
- `.env`: `CACHE_STORE=redis`, `SESSION_DRIVER=database` (sessions table already exists as a framework table).
- Verify: `redis-cli ping` → PONG; tinker cache put/get; log in/out still works.
- **Rationale unchanged from v1 §3** (Spatie caches permission sets; the dashboard's 30s poll must hit Redis, not disk). Rollback: revert two env lines.

### Phase 1 — Install & scaffold (no behavior change)
- `composer require spatie/laravel-permission`; publish + run its migrations (5 tables, MySQL).
- Transitional migration `make_role_nullable_on_users_table`. **MySQL note:** the column is an `enum`; modify with a raw `ALTER TABLE users MODIFY role ENUM('user','admin','super_admin') NULL` guarded by `if (DB::getDriverName() === 'mysql')`, with the SQLite branch a no-op recreate via `Schema::table()->change()` so the in-memory test DB (phpunit.xml) stays compatible.
- `User`: add `use HasRoles`. Keep all existing helpers for now (dual-run window).
- `AppServiceProvider::boot()`: `Gate::before(fn ($u, $a) => $u->hasRole('super_admin') ? true : null);`
- `bootstrap/app.php`: register Spatie aliases `role`, `permission`, `role_or_permission` alongside the (temporary) `admin`/`superadmin`.
- **Gate:** `php artisan test` fully green; manual smoke of login/dashboard/device flows.

### Phase 2 — Seed catalog, bundles, migrate users
- `PermissionSeeder` — idempotent (`firstOrCreate`), creates every slug (§3.1) in both guards, the 5 bundles, and `role_has_permissions` (§3.2).
- `SuperAdminSeeder` — ensures the super-admin account holds the `super_admin` Spatie role.
- `MigrateRolesToPermissionsSeeder` — one-time, idempotent: `user`→`consumer`; `admin`→`field_engineer`+`fleet_operator`; `super_admin`→`super_admin`. Logs a per-user summary line for audit.
- Wire into `DatabaseSeeder`; run on prod after deploy.
- **Gate:** tinker — a `role='user'` account returns exactly the consumer permission set from `getAllPermissions()`; suite green (old checks still in force — nothing enforces the new permissions yet, which is what makes this phase safe).

### Phase 3 — Registration mode (D1)
- `config/auth.php`: `'allow_registration' => env('AUTH_ALLOW_REGISTRATION', false)`.
- [routes/auth.php:15-18](../routes/auth.php#L15-L18): wrap register routes in the config check (absent flag ⇒ 404, matching v1's intent).
- `RegisteredUserController::store()`: `abort_unless(config('auth.allow_registration'), 403)` + on success `$user->assignRole('consumer')`.
- Remove register links from `auth/login.blade.php`, `welcome.blade.php` (render conditionally on the flag).
- **Tests:** new `RegistrationModeTest` (flag off → 404; flag on → account created holding `consumer`). Update `AuthenticationTest` accordingly.

### Phase 4 — Permission management UI
- `PermissionController::show()/update()` + `resources/views/users/permissions.blade.php`, routes under `role:super_admin`.
- Screen layout: **bundle multiselect** on top (checkbox per bundle); below, the full slug list grouped by §3.1 category — slugs inherited from bundles render **locked + "via {bundle}"**, others as toggles (direct grants).
- **"Detach bundle, keep as direct grants"** action — the escape hatch for subtractive exceptions (Spatie has no deny).
- `update()` = `syncRoles()` + `syncPermissions()` in one transaction, then `app(PermissionRegistrar::class)->forgetCachedPermissions()`.
- Guard: `abort_if($user->hasRole('super_admin'), 403)` — super-admin accounts are never editable here, even by another super admin (v1 §11 retained).
- `UserManagementController::store()` gains a bundle dropdown (default `consumer`) and stops writing `role`.
- **Tests:** new `PermissionManagementTest` (grant/revoke reflected in `can()`, super-admin lockout, detach action).

---

## 6. Phase 5 — Enforcement Cutover (the swap; one commit, biggest review)

Every predicate below replaces a §2.1 role check. Old middleware/helpers stay alive
(unused) until Phase 7 — instant `git revert` restores prior behavior.

| File | New predicate |
|---|---|
| `DevicePolicy` | `viewAny` → true (lists are query-scoped); `view` → `devices.view_any` ∨ owner; `create` → `devices.create` ∨ `meter.self_provision`; `update` → `edit_any` ∨ (`edit_own` ∧ owner) ∨ (`meter.rename` ∧ owner, meters); `delete` → `delete_any` ∨ (`delete_own` ∧ owner); drop `restore`/`forceDelete` (no soft deletes on devices — dead methods) |
| `DashboardController` | stats strip ← `dashboard.view_system_stats`; device scope ← `devices.view_any` |
| `DeviceManagementController` | list scope ← `devices.view_any`; create/store ← policy + self-provision path (force `type='meter'`, `user_id=auth()->id()`, no owner dropdown); owner dropdown ← `devices.assign_owner`; edit/update ← policy + **server-side name-only strip** (`$validated = ['name' => …]` when in rename mode); destroy ← policy |
| `DeviceDashboardController` | owner-or-`devices.view_any` + **`meter.access` master gate** (else existing placeholder view, `reason='no_access'`) + passes `$canViewLiveData/Charts/History` section flags |
| `DeviceReadingController` | `abort_unless(can('meter.charts'))` on `chart()`; `abort_unless(can('meter.history'))` on `index()`; consumption/daily endpoints under `meter.access` |
| `Api/DeviceController` | scope ← `devices.view_any`; writes already `$this->authorize()` → flow through the rewritten policy |
| `UserManagementController` | gates ← `users.view_list/view_profile/create/edit`; **delete `updateRole()`**; store assigns bundle |
| `NotificationPreferenceController` | `'isAdmin' =>` … ← `can('alerts.fleet_scope')`; line 42 fleet-scope guard ← same slug |
| `AlertEvent::visibleTo()` *(seam #1)* | `isAdminOrAbove()` → `$user->can('alerts.view_any')` |
| `EnqueueAlertForDelivery::recipientIds()` *(seam #2)* | fleet recipients = `User::permission('alerts.fleet_scope')` ∩ `notification_preferences.fleet_scope='all'` ∩ severity floor. Runs in the queue worker — one indexed join, never on the request path. **Restart the queue worker on deploy** (long-running process, stale code otherwise — same rule as the ops runbook's config changes). |
| `routes/web.php` | `/users` group `admin` → `permission:users.view_list`; destroy `superadmin` → `role:super_admin`; permissions routes `role:super_admin` |
| `routes/auth.php` | done in Phase 3 |

**Factories & tests (same commit):** `UserFactory` gains `->consumer()`,
`->prosumer()`, `->fieldEngineer()`, `->fleetOperator()`, `->superAdmin()` states
(assigning bundles via `afterCreating`); a `TestCase` helper seeds the permission
catalog once per suite. The four role-referencing test files switch to factory states.
New `MeterSectionPermissionsTest` covers: no `meter.access` → placeholder;
live_data-only → KPI yes / charts 403 / table 403 / no range bar; +charts → charts and
range bar appear; +history → table appears; rename-mode PATCH with extra fields →
only `name` persisted; self-provision POST with `type≠meter` or foreign `user_id` → rejected.

**Gate:** full suite green; manual matrix from §9 passes.

---

## 7. Phase 6 — View Cutover

`@can` replaces every role conditional; sections not permitted are **not rendered**
(server-side view logic, not CSS hiding):

- `dashboard.blade.php` — stats strip behind `@can('dashboard.view_system_stats')`.
- `devices-manage.blade.php` — Add-Device button behind `@canany(['devices.create','meter.self_provision'])`; owner column behind `devices.view_any`; per-row edit/delete via `@can('update', $device)` / `@can('delete', $device)` (policy-driven, so rename-only users still see Edit).
- `devices-create.blade.php` — self-provision mode: type locked to Meter, no owner dropdown.
- `devices-edit.blade.php` — name-only mode: all fields except name rendered read-only.
- `devices/dashboards/meter.blade.php` — three `@if($canView…)` section wraps; range bar `@if($canViewCharts || $canViewHistory)`; **Chart.js CDN tag and each JS initializer emitted only when its section exists** (guard on element presence, per v1 §7c).
- `users/index|show|create|edit.blade.php` — permission-gated links; role dropdown/role-change form → bundle selector + "Manage Permissions" link.
- `components/sidebar.blade.php` — nav items behind their permission (`users.view_list`, etc.).
- `components/header.blade.php` — no change (bell is identity-scoped).

**Gate:** suite green + the §9 matrix walked in a browser per bundle.

---

## 8. Phase 7 — Legacy Removal (the "old code out" commit)

Only after Phases 5–6 have soaked in production for an agreed window (suggest ≥ 1 week):

1. Delete `AdminMiddleware.php`, `SuperAdminMiddleware.php`; remove their aliases from `bootstrap/app.php`.
2. `User.php`: remove `isSuperAdmin()`, `isAdminOrAbove()`, `isAdmin()`, `canAccessDevice()` (unused after policy rewrite — verify with grep first), and `'role'` from `$fillable`.
3. Delete `components/role-badge.blade.php` and remaining role UI fragments.
4. Remove the `/devices/manage` redirect ([web.php:50](../routes/web.php#L50)).
5. Migration `drop_role_from_users_table` (MySQL `ALTER TABLE users DROP COLUMN role`; sqlite branch via `Schema`). Run **only after** `grep -rn "->role\|'role'" app/ resources/views/ routes/ tests/` returns nothing but Spatie's own API.
6. Suite green; `route:list` shows no `admin`/`superadmin` middleware.

**Rollback:** restore column from the pre-migration backup + `git revert` (roles are reconstructible from bundle assignments if ever needed).

---

## 9. Phase 8 — Guardrails, Verification, Documentation

**Permanent guardrails (committed):**
- `tests/Feature/PermissionBundlesTest.php` — snapshot-asserts each bundle's exact slug set and that built-ins ⊂ every bundle. A bundle change must change this test — deliberate friction.
- `tests/Feature/RouteAuthorizationAuditTest.php` — walks `Route::getRoutes()`: every non-public web/api route must carry `auth` and either a `permission:`/`role:` middleware or be on the documented policy-checked allowlist. New unprotected routes fail CI.
- CI step: `grep -rn "hasRole" app/ | grep -v AppServiceProvider` must be empty (the "code checks permissions only" invariant, enforced).

**Full acceptance matrix (run per phase gate and at the end):**

| Actor | Expected |
|---|---|
| Fresh `consumer` | dashboard + own devices + full own-meter dashboard + own alerts + rename-only edit; no create, no user pages, no system stats; direct API calls to forbidden endpoints → 403 |
| `consumer` minus `meter.charts` (detached bundle) | KPI + table, no charts, no Chart.js download |
| `prosumer` | + self-provision (meter-only, self-owned enforced server-side), edit/delete own |
| `field_engineer` | all devices CRUD + assign owner; no user management, no fleet alerts |
| `fleet_operator` | all devices read, system stats, fleet alert console, receives fleet-wide digests per prefs; no device writes |
| `super_admin` | everything, permission screens for others, own permissions not editable |
| Crafted requests | rename-mode PATCH with `mqtt_topic` → ignored; self-provision POST with `user_id` of another user → forced to self; section APIs without slug → 403 |
| Pipeline regression | MQTT ingest → reading stored → alert opens → consumer owner gets digest; fleet operator gets digest iff `alerts.fleet_scope` + pref `all` |

**Documentation updates (same commit as Phase 8):** handbook §8.1 rewritten for the
hybrid; `PENDING_WORK.md` FGAC section closed out; `use-case.md` UC26 "Change User
Role" → "Manage Permissions/Bundles", UC1 "Register" annotated with the D1 flag;
`FGAC_FEATURES_PERMISSIONS.csv` updated with the `alerts.*` slugs and bundle column.

---

## 10. Risk Register

| Risk | Mitigation |
|---|---|
| MySQL enum alter differs from SQLite tests | driver-branched migrations (§5 Phase 1); rehearse `migrate` against a MySQL staging copy before prod |
| Spatie guard mismatch (web vs sanctum) breaks dashboard JS | seed every API-relevant slug in both guards; `statefulApi()` session path covered by feature tests hitting `/api/*` |
| Stale permission cache after grant/revoke | `forgetCachedPermissions()` in `PermissionController::update()`; residual window is one in-flight request (v1 §11 assessment stands) |
| Long-running processes (queue worker, MQTT consumer) run old code at cutover | deploy step: restart both (already standard practice per ops runbook) |
| Seeder drift vs. this document | `PermissionBundlesTest` snapshot is the enforced source of truth |
| Phase-5 regression | old middleware/column kept until Phase 7 → single-commit `git revert` restores prior enforcement |
| Fat bundles recreate RBAC rigidity | lean-bundle rule + snapshot-test friction + direct grants for extras |

---

## 11. Definition of Done

- [ ] All §2.1 role checks replaced by permission predicates; §2.2 inventory deleted
- [ ] `grep hasRole` guardrail + route audit test + bundle snapshot test in CI, green
- [ ] Full acceptance matrix (§9) passes in a browser and via crafted HTTP requests
- [ ] `users.role` column dropped; Spatie tables are the only authority
- [ ] Queue worker + MQTT consumer restarted on final deploy; alert pipeline regression verified end-to-end
- [ ] Handbook, PENDING_WORK, use-case, CSV updated; this plan marked **implemented**
