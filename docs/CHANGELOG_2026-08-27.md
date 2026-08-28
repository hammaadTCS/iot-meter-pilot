# 2026-08-27 — Repairing the CI gate

**Commits:** `efc8ab3` (the fix), `d91d9a3` (documentation honesty pass)
**Branch:** `ci/repair-the-gate`
**Result:** 229 tests passing, `pint --test` 0, `composer audit` 0,
`composer validate --strict` 0 — from a clean tree.

---

## 1. The finding

CI landed on 2026-08-04 (`33a219c`) and **failed on every single run from that
day until today.** Twenty-three days. It was never green once.

That is worse than having no CI, because the repository presented a gate that
did not gate anything, and two documents stated in writing that it did. Work
continued behind it on the assumption it was watching.

The timing is what made this urgent rather than tidy. [PENDING_WORK.md](PENDING_WORK.md)
§0 queues, in order: backups, observability, daemon supervision, and then the
fleet-wide TLS and per-device-credential cutover — which that document itself
calls *"the riskiest operation in the whole plan."* Running a fleet-wide,
firmware-coordinated, hard-to-reverse operation behind a permanently red board
means you cannot tell a new break from three weeks of accumulated noise. The
gate had to work before the queue moved.

## 2. Three independent causes

### 2.1 `Tests (PHP 8.2)` — exit 2, could not even install

[composer.json](../composer.json) declared `"php": "^8.2"`, but
[composer.lock](../composer.lock) pinned `spatie/laravel-permission` 8.3.0,
which requires `php ^8.3`. The 8.2 leg failed at `composer install`, before a
single test ran.

**How it happened:** the lock was resolved during `c38a4b9` ("composer update
within existing constraints") on a machine running PHP 8.3. Composer resolves
against the platform it finds unless told otherwise, so it silently produced a
lock that excluded the project's own declared floor. Nothing caught it because
the only thing that would have — CI on 8.2 — was itself the thing being broken.

**Not re-lockable.** Every `spatie/laravel-permission` 8.x release requires
`php ^8.3`; there is no version in the 8.x line that satisfies 8.2. Keeping 8.2
meant dropping to the 7.x line, and the entire FGAC permission layer (`6e1ca32`)
is built on 8.x. The 8.2 claim was fiction from the moment it was written — the
project has always been developed and deployed on 8.3.

### 2.2 `Tests (PHP 8.3)` — exit 1, 17 failures

Every failure was the same exception:

```
Vite manifest not found at: .../public/build/manifest.json
(View: resources/views/layouts/app.blade.php)
```

The suite renders Blade layouts containing `@vite`, which reads
`public/build/manifest.json` at render time. `public/build` is gitignored, and
only the `quality` job ran `npm run build` — the `tests` job never built assets.

**Why nobody noticed locally:** a developer machine always has a `public/build`
left over from the last `npm run build`. The suite passed locally for exactly
the reason it failed in CI. This is the characteristic shape of a CI-only bug:
the difference is not the code, it is the assumption that the environment
carries state the repository does not.

Confirmed as a single root cause rather than a cluster: exactly 17
`ViteManifestNotFoundException`, no second failure hiding behind them.

### 2.3 Node 20 deprecation warnings

`actions/checkout@v4`, `actions/cache@v4` and `actions/setup-node@v4` all
declare the node20 runtime. GitHub's runners force them onto node24 and emit a
warning per action per run. Nothing broke — but the warnings were noise on a
board that already could not be read.

## 3. What changed, and why each choice

### `composer.json` — floor to 8.3, plus a platform pin

```
"php": "^8.3"

"config": {
    "platform": { "php": "8.3" },
    ...
}
```

Raising the floor fixes the symptom. **The platform pin fixes the cause.**
Without it, the next `composer update` resolves against whichever machine runs
it — which is precisely how this bug was created. Pinned, every developer
machine and CI resolve to an identical dependency set regardless of the PHP
binary present. This is the part of the change that prevents a recurrence
rather than clearing the current instance.

The re-lock (`composer update --lock`) was necessary and is easy to skip:
editing `require.php` invalidates the lock's content hash, and without a re-lock
every CI run carries a lock-out-of-date warning while `composer validate
--strict` fails outright. **Verified surgical — Composer reported "Nothing to
modify in lock file"; zero package versions moved.** Only the content hash and
the platform block changed.

### `ci.yml` — the tests job now builds assets

```yaml
- uses: actions/setup-node@v7
  with: { node-version: '24', cache: npm }
- name: Install npm packages
  run: npm ci
- name: Build assets
  run: npm run build
```

**The rejected alternative matters here.** `$this->withoutVite()` in
[tests/TestCase.php](../tests/TestCase.php) would have fixed all 17 failures with
one line and no extra CI time. It was rejected because it would have hollowed
out `csp allows the assets the dashboards actually load` in
[SecurityHeadersTest.php](../tests/Feature/SecurityHeadersTest.php) — a test that
exists specifically because a CSP change broke the dashboard for real. Stubbing
the manifest converts that assertion into theatre: it would keep passing while
testing nothing.

Building for real costs roughly a minute per run and buys two things: the
assertion stays honest, and asset build breakage now surfaces in the same job
that would be affected by it.

### `ci.yml` — 8.3 blocking, 8.4 non-blocking

```yaml
matrix:
  include:
    - php: '8.3'
      experimental: false
    - php: '8.4'
      experimental: true
continue-on-error: ${{ matrix.experimental }}
```

Dropping 8.2 would have left a single-entry matrix, which makes the matrix
decorative — the original point was catching version-specific breakage.

8.4 resolves cleanly as a replacement leg, but **only 8.3 is installed on the
development machine, so CI would be the first real test of it.** A blocking 8.4
leg therefore carried a specific risk: handing back a *fresh* red board on the
very change whose purpose was to make it green, at the exact moment the board
needed to be trustworthy for the backups work.

Non-blocking resolves that. The platform pin means the 8.4 leg installs the same
locked dependency set and runs it on a newer interpreter, which is the useful
question — *does our locked dependency set survive 8.4?* — answered ahead of an
upgrade instead of during one. A red 8.4 leg is information, not a blocked
merge. This mirrors the treatment the `npm audit` step already had, so it
introduces no new concept into the workflow.

### `ci.yml` — action majors

`checkout@v4→v7`, `cache@v4→v6`, `setup-node@v4→v7`.

Worth recording because the obvious answer was wrong: an earlier reading of this
suggested `@v5`, which would have silenced the warning while leaving the repo two
majors behind. The current majors were verified against the GitHub API, each
confirmed to declare `using: node24`, and every input this workflow passes
(`path`/`key`/`restore-keys`, `node-version`/`cache`) confirmed to still exist in
the new majors before the bump.

## 4. The documentation honesty pass (`d91d9a3`)

Three documents asserted things that were not true. This is treated as part of
the same defect, not as tidying: a stale assertion is worse than a missing one
because it is trusted.

| Where | Claimed | Actually |
|---|---|---|
| [PROJECT_HANDBOOK.md](PROJECT_HANDBOOK.md) §11 | "210 tests, 0 skipped … run in CI on every push" | 229 tests, and CI had never passed |
| [PROJECT_HANDBOOK.md](PROJECT_HANDBOOK.md) §10 | `tests` job blocking on PHP 8.2 and 8.3 | 8.2 could not install |
| [PENDING_WORK.md](PENDING_WORK.md) §1 | Phase 8's CI dependency "is now satisfied" | Guardrails would have been added to a suite that could not pass |

### The `npm audit` comment

The justification comment on the non-blocking `npm audit` step explained away
exactly one dependency chain (`ws ← engine.io-client ← laravel-echo`) while the
finding had grown to **11 advisories (2 critical, 7 high)** across seven more.

A stale justification reads as *"reviewed and accepted"* for chains nobody ever
reviewed. It was rewritten chain by chain — and tracing it properly changed the
conclusion:

- `shell-quote ← concurrently` (**critical**) — `concurrently` is invoked only by
  `composer dev`, a local process launcher. Not in the build, never in CI or
  production.
- `vite`, `esbuild` — both advisories are dev-server **and Windows-specific**.
  Production serves pre-built static assets.
- `postcss → nanoid` — build-time only (arbitrary `.map` file reads during a
  build).
- `ws`, `socket.io-parser` — Reverb is driven over the **Pusher** protocol via
  `pusher-js`, so the socket.io transport is never imported into the bundle.
- `form-data ← axios` — axios pulls it for its **Node** adapter, which a browser
  bundle does not use.
- **`axios` — this one genuinely ships.** It is set on `window.axios` in
  [resources/js/bootstrap.js](../resources/js/bootstrap.js) and reaches every
  browser.

So the step stays non-blocking because **one** chain needs a real fix, not
because eleven are dismissible. The condition for making it blocking is now
written into the workflow and tracked as item **2b** in [PENDING_WORK.md](PENDING_WORK.md)
§0: fix axios, then split `package.json` into `dependencies` and
`devDependencies`. There is no `dependencies` block at all today, which is why
`npm audit --omit=dev` reports a misleading zero — everything shipped is
currently declared as a dev dependency.

### `@tailwindcss/vite` removed

Declared in [package.json](../package.json) but **imported nowhere**:
[vite.config.js](../vite.config.js) loads only `laravel-vite-plugin`,
[app.css](../resources/css/app.css) uses v3 `@tailwind` directives, and the build
runs Tailwind **v3** through [postcss.config.js](../postcss.config.js).

It pulled a second Tailwind major plus oxide native binaries into every install
(624 lines of lockfile) and was an active trap: wiring up the plugin it appears
to offer produces a v3/v4 collision. Nothing failed today, which is exactly why
it was removed now rather than during an incident.

**Verified inert:** after removal the build emits byte-identical assets —
`app-C_hpeYF-.css`, `app-BjMeHjpC.js`. Identical hashes are the proof that the
package contributed nothing, as distinct from merely being unreferenced.

## 5. Verification method

The change was **applied to a throwaway clone and run end to end before being
proposed**, not reasoned about and hoped for. Both jobs were reproduced locally
rather than pushed-and-watched.

That method caught two defects in the proposal itself — the stale `@v5` advice,
and an omitted `composer update --lock` — either of which would have produced
another red run. On a board that had been red for 23 days, a fix that needed two
follow-up attempts would have been hard to distinguish from no fix at all.

Final verification, run from a genuinely clean tree
(`rm -rf vendor node_modules public/build`):

| Step | Result |
|---|---|
| `composer install --prefer-dist` | 0 |
| `composer validate --strict` | 0 |
| `npm ci` → `npm run build` | 0, `manifest.json` present |
| `php artisan test` | **229 passed**, 779 assertions |
| `./vendor/bin/pint --test` | 0 |
| `composer audit` | 0 |

Then repeated end to end after the Tailwind removal.

> The count became **230** once this document was written.
> `DocumentationLinksTest` is data-provider driven over `docs/*.md`, so adding a
> document adds a test case — this file's own links were checked before it was
> committed.

## 6. Incident during verification — `.env` overwritten

Recorded because the trap is still live for anyone else.

Verifying a clean-install path required a pristine environment file, and
`cp -f .env.example .env` was run **against the working tree** rather than a
clone. That took the local application down with:

```
SQLSTATE[HY000]: General error: 1 no such table: sessions
(Connection: sqlite, Database: database/database.sqlite)
```

**The underlying trap:** [.env.example](../.env.example) ships
`DB_CONNECTION=sqlite` pointing at `database/database.sqlite`, but the real local
setup is **MySQL** — as [RUNNING_LOCALLY.md](RUNNING_LOCALLY.md) documents.
`database/database.sqlite` is a 0-byte placeholder, so Laravel connects to it
successfully and then finds no tables. There is no connection error to diagnose,
just a missing table.

**This is not only a hazard for tooling.** `composer setup` runs
`cp .env` followed by `migrate`. Any new developer following the README gets a
silent SQLite database containing none of the project's data, with nothing
indicating anything is wrong. Fixing `.env.example` to match the documented
MySQL setup is a candidate change and is deliberately **not** bundled here.

**Recovery.** No data was at risk — a configuration file was overwritten, not a
database. MySQL was untouched: 29 migrations, 5 users, 4 devices, 115,380
readings, all present, with ingestion still running throughout. The database
block was restored and the app verified back to HTTP 200.

`php artisan key:generate` had also written a new `APP_KEY`. Checked before
concluding it was harmless: there are **no `encrypted` casts and no `Crypt::`
usage anywhere in `app/`**, so no stored data depended on the old key, and
password hashes are bcrypt, which is key-independent. The only consequence was
invalidated session cookies — one forced re-login.

**Lesson, applied going forward:** clean-install verification belongs in a
throwaway clone. If `.env` must be touched in place, back it up first — it is
gitignored, so there is no undo.

## 7. State after this change

- CI is a working gate for the first time since it was introduced.
- [PENDING_WORK.md](PENDING_WORK.md) §0 item 3 — **A10 backups** — is next, and it
  is the item that unblocks FGAC Phase 7.
- New item **2b** added: the axios advisory chain and the `package.json`
  dependency split, which together convert CI's last non-blocking step into a
  real gate.
- Still open and unchanged by this work: **A4** email verification, despite the
  mail transport landing in `2a82216`.
