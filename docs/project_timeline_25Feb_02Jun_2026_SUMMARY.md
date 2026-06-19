# IoT Meter Platform — Project Timeline Summary
**Period:** 25 February 2026 – 02 June 2026
**Prepared:** 02 June 2026
**Responsible:** Hammaad Ahmad Khalid
**Project:** IoT Webapp Development

---

## Overview

This document is the authoritative timeline for the IoT Meter Platform project from initial environment setup through the current stabilization pass. The project began as a single-device MQTT pilot and has grown into a multi-tenant, role-governed IoT monitoring platform with real-time dashboards, full authentication, and a three-tier access control system.

The companion file `project_timeline_25Feb_02Jun_2026.csv` contains the complete machine-readable task register (Tasks 1–25) in spreadsheet format.

---

## Phase Summary Table

| # | Task | Date Range | Status | Progress |
|---|------|-----------|--------|----------|
| 1 | PHP, IoT & Foundations | 25 Feb – 27 Feb 2026 | Completed | 100% |
| 2 | Database Design & Migrations | 02 Mar – 03 Mar 2026 | Completed | 100% |
| 3 | MQTT Integration Setup | 04 Mar – 05 Mar 2026 | Completed | 100% |
| 4 | MQTT Consumer Development | 06 Mar 2026 | Completed | 100% |
| 5 | Data Storage Implementation | 09 Mar 2026 | Completed | 100% |
| 6 | Debugging & Issue Fixing | 10 Mar – 11 Mar 2026 | Completed | 100% |
| 7 | Initial Dashboard Integration | 12 Mar – 13 Mar 2026 | Completed | 100% |
| 8 | System Testing (Single Device) | 17 Mar – 19 Mar 2026 | Completed | 95% |
| 9 | System Review & Next-Phase Planning | 20 Mar 2026 | Completed | 100% |
| 10 | Eid Holiday Break | 21 Mar – 23 Mar 2026 | Holiday | — |
| 11 | Multi-Device Support & Testing | 24 Mar – 26 Mar 2026 | Completed | 100% |
| 12 | Data Validation & Normalization | 27 Mar – 30 Mar 2026 | Completed | 90% |
| 13 | Latest State & API Development | 31 Mar – 01 Apr 2026 | Completed | 100% |
| 14 | Dashboard Reliability & Error Handling | 02 Apr – 03 Apr 2026 | Completed | 100% |
| 15 | Documentation, Setup & Handoff | 06 Apr – 08 Apr 2026 | Completed | 100% |
| 16 | PPT Analysis, Feasibility & Roadmap | 09 Apr – 10 Apr 2026 | Completed | 100% |
| 17 | Multi-Meter Planning & UX Definition | 13 Apr – 14 Apr 2026 | Completed | 100% |
| 18 | Multi-Meter Implementation | 15 Apr – 28 Apr 2026 | Completed | 100% |
| 19 | Final UAT / Closing Window | 22 Apr – 28 Apr 2026 | Completed | 100% |
| **20** | **Core Authentication System** | **29 Apr – 05 May 2026** | **Completed** | **100%** |
| **21** | **Role-Based Access Control (RBAC)** | **06 May – 12 May 2026** | **Completed** | **100%** |
| **22** | **User Management Admin Panel** | **13 May – 15 May 2026** | **Completed** | **100%** |
| **23** | **Device Authorization & Policy** | **15 May – 19 May 2026** | **Completed** | **100%** |
| **24** | **Role-Aware Dashboard & Navigation** | **19 May – 01 Jun 2026** | **Completed** | **100%** |
| **25** | **Server-Side Pagination** | **01 Jun – 02 Jun 2026** | **Completed** | **100%** |
| **26** | **FGAC – Data Layer & Permission Resolution** | **03 Jun – 09 Jun 2026** | **Planned** | **0%** |
| **27** | **FGAC – Route & Middleware Rewiring** | **10 Jun – 16 Jun 2026** | **Planned** | **0%** |
| **28** | **FGAC – Privilege Management UI** | **17 Jun – 23 Jun 2026** | **Planned** | **0%** |

> Tasks 20–28 (bold) represent work done or planned after the previous timeline closed on 24 April 2026.

---

## Detailed Work Log: Apr 24 – Jun 2, 2026

### Tasks 18 & 19 — Closure (Apr 22–28)

**Delivered:** Full multi-meter implementation and UAT sign-off.

The multi-meter implementation sprint, which had reached 90% in the prior timeline, was closed out with a final end-to-end verification pass on 28 April 2026. All UAT scenarios were confirmed operational:
- Old and new meter visibility in the device selector UI
- Add/delete device flow with cascade behavior
- MQTT consumer restart with updated topic subscriptions
- Selected-device-only dashboard rendering against an all-device ingestion background

**Git evidence:** Commit `46955da` — *"28-04-2026 Fully Functional IoT Meter"*

---

### Task 20 — Core Authentication System (Apr 29 – May 5)

**Delivered:** Complete Laravel Breeze authentication covering all credential flows.

Authentication was the first major addition after the IoT pilot was stabilized. Laravel Breeze was scaffolded and all built-in auth controllers were implemented and wired:

| Controller | Responsibility |
|---|---|
| `AuthenticatedSessionController` | Login / logout with session management |
| `RegisteredUserController` | New user registration |
| `PasswordResetLinkController` | Password reset email dispatch |
| `NewPasswordController` | Reset confirmation and password update |
| `PasswordController` | In-profile password change |
| `EmailVerificationPromptController` | Verification reminder screen |
| `VerifyEmailController` | Email token verification |
| `ConfirmablePasswordController` | Sensitive-action re-authentication |
| `ProfileController` | Profile view, update, and account deletion |

All associated Blade views were built for the guest layout: login, register, forgot-password, reset-password, verify-email, and confirm-password. `ProfileUpdateRequest` form validation was added alongside the profile management screen.

**Key decision:** Stayed with Breeze (session-based) rather than Jetstream or a token-only API approach, keeping the architecture simple and consistent with the server-rendered Blade stack already in use.

---

### Task 21 — Role-Based Access Control (May 6 – May 12)

**Delivered:** Three-tier role hierarchy enforced at database, middleware, and model layers.

A custom role system was built using a native PHP enum rather than a third-party package (e.g., Spatie), keeping the dependency surface minimal. Three roles were defined:

| Role | Access Level |
|---|---|
| `user` | Default; sees and manages only own devices |
| `admin` | Elevated; sees all devices and users, cannot modify other admins |
| `super_admin` | Full platform access; can promote/demote roles and delete users |

**Migrations added:**
- `role` enum column on `users` table (default: `user`)
- `cnic`, `phone_number`, `address` nullable fields on `users` table for operator profile completeness

**User model methods:**

```php
isSuperAdmin()       // role === super_admin
isAdminOrAbove()     // role === admin || super_admin
isAdmin()            // backward-compatible alias for isAdminOrAbove()
canAccessDevice()    // owns device OR isAdminOrAbove()
```

**Middleware:**
- `AdminMiddleware` — blocks non-admin requests with a 403
- `SuperAdminMiddleware` — blocks non-super_admin requests with a 403

Both middleware classes were registered in `bootstrap/app.php` and applied to the appropriate route groups in `routes/web.php`.

---

### Task 22 — User Management Admin Panel (May 13 – May 15)

**Delivered:** Full CRUD admin panel for platform user management, gated by role.

`UserManagementController` was created with six actions (index, create, store, show, edit, update) plus role management and deletion sub-routes. The following views were built:

| View | Purpose |
|---|---|
| `users/index.blade.php` | Paginated user list with search and role filter |
| `users/create.blade.php` | New user creation form |
| `users/edit.blade.php` | Edit name, email, role, and profile fields |
| `users/show.blade.php` | Read-only user detail with device summary |

Route access is tiered:
- `/users/*` (read/edit) — accessible to `admin` and above
- `/users/{user}/role` (role assignment) — `super_admin` only
- `/users/{user}` DELETE — `super_admin` only

Regular authenticated users have no access to any `/users/*` routes; attempts return 403.

---

### Task 23 — Device Authorization & Policy (May 15 – May 19)

**Delivered:** Multi-tenant device isolation via Laravel Policy, applied uniformly across web and API routes.

`DevicePolicy` was created with four gate methods: `view`, `update`, `delete`, and `create`. The policy was registered in `AuthServiceProvider` and enforced in all relevant controllers.

**Authorization logic:**
- `create` — any authenticated user
- `view / update / delete` — owner (`user_id` match) OR `isAdminOrAbove()`

`DeviceManagementController` was updated to call `authorize()` before every device action. The device index query was scoped: non-admin users receive only their own devices (`where('user_id', auth()->id())`), while admins receive all devices.

`Api/DeviceController` was also updated to enforce Sanctum token authentication and apply the same policy, ensuring API and web surface area behave identically from an authorization standpoint.

**Git evidence:** Commit `e4882a9` — *"19 MAY 2026 11:41:45 GMT"*

---

### Task 24 — Role-Aware Dashboard & Navigation (May 19 – Jun 1)

**Delivered:** Fully role-differentiated dashboard experience and navigation system.

`DashboardController` was updated to pass role-conditional data to the view:
- **Regular users** see: own device count, own active device count, own alert count
- **Admins and super_admins** additionally see: platform-wide totals (all devices, all users, all alerts)

`layouts/navigation.blade.php` was updated with `@can` / `@auth` guards to show the User Management link only to admin roles.

**New reusable Blade components built:**

| Component | Purpose |
|---|---|
| `role-badge.blade.php` | Coloured pill showing user role (user/admin/super_admin) |
| `status-badge.blade.php` | Online / offline / stale / never_seen status indicator |
| `device-card.blade.php` | Standardised device summary card for lists |
| `stats-card.blade.php` | KPI metric card for dashboard panels |
| `sidebar.blade.php` | Role-aware left navigation sidebar |
| `sidebar-item.blade.php` | Individual sidebar nav entry |
| `sidebar-section.blade.php` | Section group header for sidebar |

`DeviceReadingController` API endpoints for historical readings and chart data were corrected to align with the current query structure and return formats.

**Git evidence:** Commits `e6a9423` and `6977f1a` — *"01-06-2026"* / *"1st June"*

---

### Task 25 — Server-Side Pagination for Meter Readings (Jun 1 – Jun 2)

**Delivered:** Full server-side pagination for the meter readings table, handling arbitrarily large datasets without performance degradation.

**Problem:** With devices transmitting every few seconds, the readings table grows quickly — loading thousands of rows in a single request caused slow page renders and heavy memory usage on both server and browser.

**Solution implemented across two files:**

`DeviceReadingController.php` — The `index()` API endpoint now operates in two distinct modes detected by query parameters:

| Mode | Trigger | Response shape | Use case |
|---|---|---|---|
| Paginated | `?page=N` | `{ data: [...], meta: { current_page, last_page, per_page, total } }` | User-driven table navigation |
| Cursor/refresh | `?after_received_at=T&after_id=N` | Plain JSON array, oldest-first | Silent 30-second background poll |

`TABLE_PER_PAGE = 100` constant is set server-side; page bounds are clamped to `[1, lastPage]` to handle stale frontend state. Both time-window range filters (relative ranges and custom from/to) are applied before pagination.

`meter.blade.php` — Frontend pagination wired end-to-end:
- `TABLE_PER_PAGE = 100` JS constant mirrors backend
- `tablePage` and `tableTotalPages` state variables track current position
- `goToPage(page)` fetches a fresh page with all active range/filter params
- Previous/Next buttons with disabled states at boundaries
- "Page X of Y" label and "Showing rows X to Y of Z total" row-range indicator
- On realtime push: new reading prepended to page 1 and total page count recalculated

The cursor-based refresh mode is completely unchanged — live updates continue to flow without any page-navigation interference.

**Git evidence:** Commits `e6a9423` and `6977f1a` — *"01-06-2026"* / *"1st June"*

---

## Upcoming Work: Fine-Grained Access Control (FGAC) — Jun 3–23, 2026

### Background

The current authorization system uses a three-tier role enum (`user / admin / super_admin`) hardcoded into the database and enforced via two middleware classes. The CEO has directed that this be replaced with a **Fine-Grained Access Control** system: no predefined role buckets; instead, `super_admin` can grant any combination of discrete, named privileges to any individual user. Each user's access is fully customizable and independently audited.

The `super_admin` role is retained as an unconditional bypass — it always has full platform access and cannot be restricted via the privilege system.

---

### Defined Privilege Keys

| Key | Label | Group | What it unlocks |
|---|---|---|---|
| `view_all_devices` | View All Devices | Devices | See every device on the platform, not just own |
| `manage_any_device` | Manage Any Device | Devices | Edit and delete any device regardless of ownership |
| `view_all_readings` | View All Readings | Devices | Access meter readings for any device |
| `view_system_stats` | View System Statistics | Dashboard | Platform-wide KPI panel on the main dashboard |
| `manage_users` | Manage Users | Users | View user list, edit profiles and custom fields |
| `delete_users` | Delete Users | Users | Permanently delete user accounts |
| `assign_privileges` | Assign Privileges | Access | Grant or revoke privileges for other users |
| `view_all_alerts` | View All Alerts | Alerts | Alert events for all devices, not just own |
| `manage_ingestion_events` | Manage Ingestion Events | System | View and prune MQTT ingestion audit logs |

---

### Database Design

**`permissions` table** — catalogue of all known privilege keys:
```
id               bigint PK
key              varchar(100) unique    — machine key, e.g. "view_all_devices"
label            varchar(150)           — UI display name
description      text nullable          — tooltip / help text
group            varchar(50)            — "Devices" | "Users" | "Dashboard" | ...
created_at / updated_at
```

**`user_permissions` pivot** — live grant records:
```
id               bigint PK
user_id          FK → users.id  ON DELETE CASCADE
permission_key   varchar(100)   FK → permissions.key
granted_by       FK → users.id nullable   — audit: who granted this
granted_at       timestamp                — audit: when
UNIQUE (user_id, permission_key)
```

The `role` column on `users` is kept — it continues to identify `super_admin` as the platform bypass. No data migration is needed for regular users.

---

### Task 26 — FGAC: Data Layer & Permission Resolution (Jun 3–9)

**Goal:** Build the database foundation and the core `hasPermission()` / `grantPermission()` / `revokePermission()` API on the `User` model. No existing behavior changes until Task 27.

Key deliverables:
- Two new migrations: `permissions` table and `user_permissions` pivot
- `Permission` Eloquent model with `BelongsToMany` on `User`
- `PermissionSeeder` seeding all 9 defined keys
- `User::hasPermission(string $key): bool` — super_admin short-circuits `true`
- `User::grantPermission(string $key, User $grantedBy)` and `revokePermission(string $key)`
- Parametric `PermissionMiddleware` — accepts `permission:key_name` syntax
- `DevicePolicy` updated to use `hasPermission()` calls
- `DeviceReadingController` inline checks updated to `hasPermission('view_all_readings')`
- Unit tests for all three User model methods

---

### Task 27 — FGAC: Route & Middleware Rewiring (Jun 10–16)

**Goal:** Replace all role-based gates throughout the application with permission key checks. Navigation and sidebar adapt dynamically to each user's active privilege set.

Key deliverables:
- `middleware('admin')` → `middleware('permission:manage_users')` on user routes
- `middleware('superadmin')` → `middleware('permission:assign_privileges')` on role routes
- `permission:delete_users` gate on user delete routes
- `DashboardController` stats panel gated by `view_system_stats`
- `DeviceManagementController` all-device query gated by `view_all_devices`
- `Api/DeviceController` mutating actions gated by `manage_any_device`
- Alert and ingestion event views gated by respective keys
- Navigation and sidebar items rendered conditionally by `hasPermission()`
- Integration tests covering granted vs denied paths for key permissions

---

### Task 28 — FGAC: Privilege Management UI (Jun 17–23)

**Goal:** Give super_admin a purpose-built screen to view and modify every user's privilege set, with full audit metadata and guards against escalation abuse.

Key deliverables:
- `UserManagementController::privileges()` (GET) and `updatePrivileges()` (POST)
- Routes: `GET|POST /users/{user}/privileges` — gated by `assign_privileges` or super_admin
- `users/privileges.blade.php` — permission keys grouped by category with toggle controls
- Each toggle shows "Granted by [name] on [date]" when active
- Privilege count badge added to `users/index.blade.php`
- Privilege chips added to `users/show.blade.php`
- Guards: cannot grant `assign_privileges` to self; super_admin privilege page is read-only
- All grant/revoke actions written to audit log with `granted_by` and timestamp
- End-to-end tests: grant → access confirmed; revoke → access denied immediately

---

## Phase Summary Table (Updated)

| # | Task | Date Range | Status | Progress |
|---|------|-----------|--------|----------|
| 1–17 | Foundation through Multi-Meter Planning | 25 Feb – 14 Apr 2026 | Completed | 100% |
| 18 | Multi-Meter Implementation | 15 Apr – 28 Apr 2026 | Completed | 100% |
| 19 | Final UAT / Closing Window | 22 Apr – 28 Apr 2026 | Completed | 100% |
| 20 | Core Authentication System | 29 Apr – 05 May 2026 | Completed | 100% |
| 21 | Role-Based Access Control (RBAC) | 06 May – 12 May 2026 | Completed | 100% |
| 22 | User Management Admin Panel | 13 May – 15 May 2026 | Completed | 100% |
| 23 | Device Authorization & Policy | 15 May – 19 May 2026 | Completed | 100% |
| 24 | Role-Aware Dashboard & Navigation | 19 May – 01 Jun 2026 | Completed | 100% |
| **25** | **Server-Side Pagination** | **01 Jun – 02 Jun 2026** | **Completed** | **100%** |
| **26** | **FGAC – Data Layer & Permission Resolution** | **03 Jun – 09 Jun 2026** | **Planned** | **0%** |
| **27** | **FGAC – Route & Middleware Rewiring** | **10 Jun – 16 Jun 2026** | **Planned** | **0%** |
| **28** | **FGAC – Privilege Management UI** | **17 Jun – 23 Jun 2026** | **Planned** | **0%** |

---

## Cumulative Deliverables (As of Jun 2, 2026)

| Area | Delivered |
|---|---|
| **MQTT Pipeline** | Daemon consumer, payload validation, ingestion recorder, availability processor |
| **Data Layer** | 6 migrations, 5 Eloquent models, latest-state cache, audit trail |
| **Authentication** | Full Breeze auth — login, register, password reset, email verification, profile management |
| **Authorization** | Three-tier RBAC, DevicePolicy, AdminMiddleware, SuperAdminMiddleware |
| **User Management** | Admin panel — CRUD, role management, search/filter, role-gated delete |
| **Device Management** | Multi-device CRUD, device dashboard selector, owner-scoped queries |
| **Real-time Dashboard** | WebSocket via Reverb, live KPIs, charts, readings table, role-aware stats panels |
| **Health Monitoring** | ScanMeterHealth command, stale/down thresholds, auto-create and auto-resolve alerts |
| **Pagination** | Server-side 100-row pages, cursor-based live refresh, frontend Previous/Next controls |
| **API** | Sanctum-protected REST endpoints — devices, readings, charts, status snapshots |
| **Infrastructure** | Supervisor + Systemd configs for MQTT consumer daemon, custom health/MQTT configs |
| **UI Components** | 20+ reusable Blade components — badges, cards, modals, toasts, sidebar, forms |

---

*Document prepared by Hammaad Ahmad Khalid — IoT Webapp Development Project*
*Last updated: 02 June 2026*
