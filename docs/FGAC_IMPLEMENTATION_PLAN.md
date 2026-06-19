# Fine-Grained Access Control — Full Implementation Plan
**Project:** IoT Meter Pilot
**Date:** 2026-06-04
**Stack:** Laravel 12, MySQL, Redis, Spatie Laravel Permission, Sanctum, Blade + Alpine.js

---

## 1. What We Are Building and Why

The current system has three hardcoded roles (`user`, `admin`, `super_admin`) stored as an enum
on the `users` table. Access decisions are made by checking which role a user has. This means you
cannot grant a user one specific capability without granting them the entire role's set of
capabilities. It also means self-registration is allowed, which conflicts with the business model.

The replacement is a **privilege-based system**:
- There are only two account types: **Super Admin** (full access, can never be restricted) and
  **regular Users** (start with zero access, granted specific privileges individually).
- Every feature of the application is treated as a named privilege.
- Super Admin creates all accounts. No self-registration.
- Super Admin grants and revokes individual privileges from a dedicated management screen.
- Users only see and interact with the features their privileges permit.

---

## 2. Technology Choice — spatie/laravel-permission

We use `spatie/laravel-permission` rather than building a custom pivot table layer because:

- It integrates directly with Laravel's `Gate` and `Policy` system. Existing `@can()` Blade
  directives and `$this->authorize()` controller calls continue to work without changes to their
  call sites — only the underlying check logic changes.
- It ships its own **cache layer** that stores the full permission set for a user in a single cache
  key. With Redis as the cache store, every `can()` call within a request is a sub-millisecond
  in-memory array lookup after the first one.
- It provides three ready-made route middleware (`role:`, `permission:`, `role_or_permission:`)
  that map directly to what we need.
- It supports both the `web` guard (session-based) and the `sanctum` guard (token-based API),
  which we need because the meter dashboard makes API calls via Sanctum.

---

## 3. Infrastructure Change — Redis Cache (Do This First)

Redis is already installed and configured in `.env` (`REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`).
The only change is switching the cache driver.

**Why this must happen before Spatie is installed:**
Spatie begins using the cache immediately. If the cache is set to `file` at install time, every
permission check during an HTTP request writes to and reads from disk. On the meter dashboard,
the auto-refresh makes 3 API calls every 30 seconds. Each call loads the user's permission set.
Over 24 hours that is ~8,640 cache reads. File cache: 2–4ms each. Redis: < 0.1ms each.

**Changes to `.env`:**
```
CACHE_STORE=redis
SESSION_DRIVER=database
```

Sessions move to `database` (they were already on `file`) for stability — file sessions can
become stale under concurrent requests, database sessions cannot.

**Verification:**
```bash
redis-cli ping                          # → PONG
php artisan cache:clear
php artisan tinker --execute="Cache::put('test',1,60); echo Cache::get('test');"  # → 1
```

---

## 4. Database Schema

### 4a. New Tables (created by Spatie's migration)

| Table | Purpose |
|---|---|
| `permissions` | One row per permission slug. Columns: id, name, guard_name |
| `roles` | One row per role. We create exactly one: `super_admin` |
| `model_has_permissions` | Pivot: user ↔ permission. Direct grants per user |
| `model_has_roles` | Pivot: user ↔ role. Super Admin users are assigned the `super_admin` role |
| `role_has_permissions` | Pivot: role ↔ permission. Super Admin role has all permissions |

### 4b. Transitional Migration

`make_role_nullable_on_users_table` — makes the existing `role` column nullable so new accounts
created after the switch don't require it.

### 4c. Cleanup Migration (Phase 7, deferred)

`drop_role_from_users_table` — runs only after all PHP code references to `$user->role` are
removed. This drops the column permanently.

### 4d. Impact on Existing Tables

None. The `meter_readings`, `latest_meter_states`, `devices`, `meter_ingestion_events`, and
`meter_alert_events` tables are completely untouched. MQTT ingestion is unaffected.

---

## 5. The Super Admin Bypass

This one line, added to `AppServiceProvider::boot()`, is the single most important architectural
decision:

```php
Gate::before(fn ($user, $ability) => $user->hasRole('super_admin') ? true : null);
```

When any `can()`, `@can()`, `authorize()`, or policy method is called for a Super Admin user,
the Gate evaluates this callback first and returns `true` immediately — bypassing every policy
and permission check in the application. This means:

- Super Admin never needs explicit permissions listed.
- No policy method, no Blade directive, no route middleware can accidentally block Super Admin.
- Adding new features in the future never requires updating a Super Admin permission list.
- The Super Admin experience is completely future-proof.

`hasRole('super_admin')` itself reads from the Spatie role cache — it is a sub-millisecond
in-memory check after the first request.

---

## 6. Complete Permission List

### Built-in (automatically granted when any account is created)

| Slug | Guard | Purpose |
|---|---|---|
| `dashboard.view` | web | Access the `/dashboard` page |
| `devices.view_own` | web | See own assigned devices in list |
| `api.devices.read` | sanctum | Call GET device endpoints via API |
| `api.readings.read` | sanctum | Call readings/chart/snapshot API endpoints |

Each permission exists in both guards because the meter dashboard JS calls the API using
Sanctum's stateful (cookie-based) authentication and the guard must match.

### Grantable by Super Admin

**Dashboard**
| `dashboard.view_system_stats` | web | System-wide stats on dashboard |

**Devices**
| `devices.view_any` | web | See all devices regardless of owner |
| `devices.create` | web | Register any device type, full form |
| `devices.edit_own` | web | Full edit of own devices |
| `devices.edit_any` | web | Full edit of any device |
| `devices.delete_own` | web | Delete own devices |
| `devices.delete_any` | web | Delete any device |
| `devices.assign_owner` | web | Change device's user_id |

**Meter System**
| `meter.access` | web | Master gate — enter meter dashboards at all |
| `meter.self_provision` | web | Register own meter devices (type locked, self-owned) |
| `meter.rename` | web | Edit only the name field of own meters |
| `meter.live_data` | web | Section 1 — 8 KPI cards |
| `meter.charts` | web | Section 2 — 5 Chart.js charts |
| `meter.history` | web | Section 3 — Paginated readings table |

**Users**
| `users.view_list` | web | `/users` paginated list |
| `users.view_profile` | web | `/users/{user}` detail page |
| `users.create` | web | Create user accounts |
| `users.edit` | web | Edit user profiles |
| `users.delete` | web | Delete user accounts |
| `users.manage_permissions` | web | Per-user permission toggle screen |

**API**
| `api.devices.write` | sanctum | POST/DELETE `/api/devices` |

---

## 7. Meter System Permissions in Detail

The meter system has the most granular permission structure because it is the core product.

### 7a. meter.access — The Master Gate

Without `meter.access`, opening `/devices/{device}/dashboard` for any meter returns the existing
"disabled" placeholder view with a `reason = 'no_access'` message. No data is loaded. No API
calls are made. The user sees a clear message that they do not have access to the meter system.

With `meter.access`, the user enters the meter dashboard shell (header with device name, health
pill, availability pill, status banners). What they see inside that shell depends on the three
section permissions below.

### 7b. meter.live_data — Section 1 (KPI Cards)

The 8 live KPI cards: Voltage, Current, Power, Computed Energy, PZEM Energy, Frequency, Power
Factor, Last Reading timestamp. These always show the most recent snapshot regardless of the
selected time range.

Without this permission: the KPI card grid is not rendered. The auto-refresh status poll
(`GET /api/devices/{id}/status`) is not started. The header (device name, health, availability)
is still visible — health state is informational, not a data privilege.

### 7c. meter.charts — Section 2 (Graphs)

The 5 Chart.js charts: Voltage+Current (dual Y-axis), Active Power, Energy Comparison
(Computed vs PZEM), Grid Frequency, Power Factor (colour-coded bars). Chart data is sampled
to at most 500 points over the selected time range.

Without this permission: the charts section is not rendered. The Chart.js CDN script tag is
not even emitted — users without this permission never download the 200KB library.
The `GET /api/devices/{id}/readings/chart` endpoint returns 403 if called directly.

### 7d. meter.history — Section 3 (Readings Table)

The paginated readings table showing raw readings 100 rows per page, newest first, with
timestamp, voltage, current, power, energies, frequency, and PF columns. Affected by the
time range selector. Navigable with Prev/Next pagination.

Without this permission: the table section and pagination bar are not rendered. Table fetch
calls are not made. The `GET /api/devices/{id}/readings` endpoint returns 403.

### 7e. Range Selector (Shared, Not a Separate Permission)

The range selector (1H / 6H / 24H / Today / 7D / 30D / All + custom date picker) controls
both the charts and the table. It is rendered only when at least one of `meter.charts` or
`meter.history` is granted. It has no value when neither section is visible.

A user with only `meter.live_data` (KPI cards) sees no range selector — it would have nothing
to control.

### 7f. meter.rename — Name-Only Editing

A user with `meter.rename` but NOT `devices.edit_own` or `devices.edit_any` can open the edit
page for their meter and change only the display name field. All other fields — MQTT topic,
availability topic, device type, active/inactive toggle, assigned user — are shown as read-only
text, not form inputs. The server enforces this: even if a crafted request sends other fields,
`DeviceManagementController::update()` discards everything except `name`.

A user with `devices.edit_own` or `devices.edit_any` has full edit access and `meter.rename`
adds nothing for them.

### 7g. meter.self_provision — Adding Own Meters

A user with `meter.self_provision` but NOT `devices.create` can register new meter-type devices
for themselves. They get a simplified create form where the device type is locked to "Meter"
and there is no "Assign To User" dropdown. The server forces `user_id = auth()->id()` and
rejects the form if `type !== 'meter'`.

A user without either permission sees no "Add Device" button at all.

---

## 8. Implementation Phases (Ordered for Safety)

Each phase leaves the system in a working, deployable state. Never skip to the next phase
until the current one is verified.

### Phase 0 — Switch Cache to Redis
- Change `.env`: `CACHE_STORE=redis`, `SESSION_DRIVER=database`
- Run `php artisan cache:clear`
- Verify with `redis-cli ping`
- **No code changes. Fully reversible.**

### Phase 1 — Install Spatie, Add HasRoles, Register Gate Bypass
- `composer require spatie/laravel-permission`
- Publish and run Spatie migrations
- Run transitional `make_role_nullable` migration
- Add `use HasRoles` to `User` model (keep all existing methods)
- Add `Gate::before` bypass to `AppServiceProvider`
- Register Spatie middleware aliases in `bootstrap/app.php`
- **Run test suite — must pass green. Zero behaviour change for users.**

### Phase 2 — Seed Permissions and Migrate Existing Users
- Create `PermissionSeeder`, `SuperAdminSeeder`, `MigrateRolesToPermissionsSeeder`
- Run seeders
- Verify with Tinker that each user class has the expected permissions

### Phase 3 — Lock Down Registration
- Return 404 from register routes
- Add `abort(403)` inside `RegisteredUserController::store()`
- Remove register links from login view and welcome view

### Phase 4 — Permission Management UI
- Create `PermissionController` with `show()` and `update()`
- Create `resources/views/users/permissions.blade.php`
- Add routes under `role:super_admin`
- Update `UserManagementController::store()` to auto-assign built-in permissions
- Remove role dropdown from create/edit user views
- Add "Manage Permissions" link to users list and user detail

### Phase 5 — Wire All Authorization Logic
- Update `DevicePolicy` (all methods)
- Update `DashboardController`
- Update `DeviceManagementController` (including `meter.rename` + `meter.self_provision`)
- Update `DeviceDashboardController` (`meter.access` gate + 3 section flags)
- Update `DeviceReadingController` (`meter.charts` + `meter.history` guards on API)
- Update `UserManagementController` (permission gates, remove `updateRole`)
- Update `Api/DeviceController`
- Update `routes/web.php` (per-route permission middleware)

### Phase 6 — Update All Blade Views
- `dashboard.blade.php` — guard stats strip
- `devices-manage.blade.php` — guard create/edit/owner column
- `devices-edit.blade.php` — name-only mode
- `devices-create.blade.php` — self-provision mode
- `devices/dashboards/meter.blade.php` — three-section conditional rendering + conditional JS
- `users/index.blade.php` — guard create/delete/permissions links
- `users/show.blade.php` — permissions link, remove role-change form
- `auth/login.blade.php` — remove register link
- `welcome.blade.php` — remove register link

### Phase 7 — Cleanup
- Remove `isAdminOrAbove()`, `isAdmin()` from `User` model
- Delete `AdminMiddleware.php`
- Remove `updateRole()` route and method
- Run `drop_role_from_users_table` migration
- Update test factories

---

## 9. API Layer (Sanctum Guard)

The meter dashboard is a browser-based SPA fragment that calls the Laravel API from JavaScript.
These calls use Sanctum's stateful (cookie + CSRF) authentication — they are not token-based.
The user's session is active, so the `web` guard user is available.

However, when external API clients use Sanctum tokens (e.g., a mobile app or a provisioning
script), the guard resolves as `sanctum`. Permissions are stored per-guard, so each permission
is created in both `web` and `sanctum` guards during seeding.

For the meter dashboard JS calls, `DeviceReadingController` uses `Auth::user()` which resolves
through the active session — no extra permission lookup. The cache stores the permission set
per-user regardless of guard, so the overhead remains the same.

---

## 10. Performance Summary

| Concern | Before | After | Notes |
|---|---|---|---|
| Permission check cost | N/A (role string compare) | < 0.1ms (Redis in-memory) | With Redis |
| Meter dashboard 30s refresh | 2 DB queries/call | 2 DB queries + 0.1ms cache read/call | Redis |
| Super Admin any page | Role check | Gate::before short-circuit | Faster |
| Device list 8 rows @can | N/A | 0 extra DB queries | Cache is warm per request |
| MQTT ingestion | Unaffected | Unaffected | Runs outside HTTP |
| Chart.js CDN load | Always | Only when meter.charts granted | Saves 200KB for restricted users |

---

## 11. Security Considerations

**Permission cache invalidation window:** When Super Admin revokes a permission, `syncPermissions()`
clears the user's cache entry immediately. However, if the user has an active session with a
loaded permission set already in PHP memory for that specific request, the revocation takes
effect on their next HTTP request. This is a seconds-long window, not a security hole.

**Server-side enforcement is always the authority.** Every permission check exists both in
the Blade view (to hide UI elements) AND in the controller (to reject the request). Hiding a
button does not prevent a crafted HTTP request. Both layers are required.

**The `meter.rename` strip-down is enforced at the server.** Even if a user manually crafts
a PATCH request with MQTT topic changes, the controller discards everything except `name` when
in name-only mode.

**Super Admin accounts cannot have permissions managed.** `PermissionController::show()` calls
`abort_if($user->hasRole('super_admin'), 403)` — it is not possible to view or modify a Super
Admin's permissions through the UI, even by another Super Admin.

---

## 12. Files Reference

### New Files to Create

| Path | Description |
|---|---|
| `database/seeders/PermissionSeeder.php` | All permission slugs (both guards) + super_admin role |
| `database/seeders/SuperAdminSeeder.php` | Ensure super admin account exists with role assigned |
| `database/seeders/MigrateRolesToPermissionsSeeder.php` | One-time migration from role enum |
| `app/Http/Controllers/PermissionController.php` | show() + update() |
| `resources/views/users/permissions.blade.php` | Per-user permission toggle screen |

### Files to Modify

| Path | Change |
|---|---|
| `.env` | `CACHE_STORE=redis`, `SESSION_DRIVER=database` |
| `app/Models/User.php` | Add `HasRoles` trait |
| `app/Providers/AppServiceProvider.php` | `Gate::before` super_admin bypass |
| `bootstrap/app.php` | Spatie middleware aliases |
| `routes/web.php` | Per-route `permission:` and `role:` middleware |
| `routes/auth.php` | Disable register routes |
| `app/Policies/DevicePolicy.php` | All methods rewritten to use `can()` |
| `app/Http/Controllers/DashboardController.php` | `can()` for stats + device filter |
| `app/Http/Controllers/DeviceManagementController.php` | Full gating + nameOnly + selfProvisionOnly |
| `app/Http/Controllers/DeviceDashboardController.php` | meter.access gate + 3 section flags |
| `app/Http/Controllers/DeviceReadingController.php` | meter.charts + meter.history guards |
| `app/Http/Controllers/UserManagementController.php` | Permission gates, built-in auto-assign, remove updateRole |
| `app/Http/Controllers/Api/DeviceController.php` | can('devices.view_any') for index |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | abort(403) in store() |
| `resources/views/dashboard.blade.php` | Guard stats strip |
| `resources/views/devices-manage.blade.php` | @can on create/edit/owner column |
| `resources/views/devices-edit.blade.php` | nameOnly mode |
| `resources/views/devices-create.blade.php` | selfProvisionOnly mode |
| `resources/views/devices/dashboards/meter.blade.php` | Three-section splits + conditional JS |
| `resources/views/users/index.blade.php` | @can on create/delete/permissions |
| `resources/views/users/show.blade.php` | Permissions link, remove role form |
| `resources/views/users/create.blade.php` | Remove role dropdown |
| `resources/views/users/edit.blade.php` | Remove role dropdown |
| `resources/views/auth/login.blade.php` | Remove register link |
| `resources/views/welcome.blade.php` | Remove register link |
| `database/seeders/DatabaseSeeder.php` | Call new seeders |

### Files to Delete (Phase 7)

| Path | Reason |
|---|---|
| `app/Http/Middleware/AdminMiddleware.php` | Replaced by Spatie `permission:` middleware |

---

## 13. Verification Checklist

Run this after each phase before proceeding.

**After Phase 0:**
- `redis-cli ping` → `PONG`
- `php artisan tinker` cache put/get works

**After Phase 1:**
- `php artisan test` → all green
- Existing login/dashboard/device flows work normally

**After Phase 2:**
- Tinker: existing user (role=user) has exactly 4 built-in permission slugs
- Tinker: existing super_admin user has the `super_admin` Spatie role

**After Phase 3:**
- `GET /register` → HTTP 404
- Login page has no register link

**After Phase 4:**
- Super Admin can open `/users/{user}/permissions`
- Checking a permission and saving grants it
- `$user->hasPermissionTo('meter.charts')` in Tinker reflects the change

**After Phase 5 + 6:**
- Fresh user (built-in only): can see dashboard + own devices, everything else 403
- Grant `meter.access` + `meter.live_data` only: dashboard shows KPI cards, no charts, no table, no range bar
- Grant `meter.charts`: charts section appears, range bar appears
- Grant `meter.history`: table + pagination + range bar appear
- Revoke `meter.access`: meter dashboard shows placeholder
- Super Admin: all sections always visible, no restrictions

**After Phase 7:**
- `grep -r 'isAdminOrAbove\|isAdmin' app/` → no results
- `php artisan test` → all green
- `php artisan migrate:status` → `drop_role_from_users_table` shows as Ran