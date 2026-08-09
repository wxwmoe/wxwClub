# AGENTS.md

For anyone — human or agent — working in this repository. The design document has been removed: the code and the schema decide actual behavior, and the comment above a function records the trade-off behind it, not a description of the code. Read that comment before changing something, and update it in the same change — a comment that no longer matches the code is a defect in its own right.

## What this is

wxwClub: an ActivityPub-compatible group service. Users follow a group actor and tag it in a post; the group relays that post to every follower. PHP + MySQL, no composer, no vendor directory — everything it depends on lives under `src/`.

Two entry points share `src/bootstrap.php` (loads config, opens the DB connection, installs the exception handler). The schema check is *not* shared — each side handles a mismatch its own way:

- `index.php` → `src/controller.php`: the web side — WebFinger, inbox, outbox, profile pages. `bootstrap.php` blocks the entire entry with 503 + `Retry-After` when `meta.schema` is not `DB_VERSION`; that gate is web-only.
- `cli.php`: two subcommands. `worker` checks the version itself, and when the database is behind it merges and exits so the container restart brings up the queue processes; `migrate` runs the same merge by hand.

## Code map

| Path | Contents |
| --- | --- |
| `src/function.php` | All business logic, a single 170 KB file, partitioned by function-name prefix |
| `src/worker.php` | The three queue loops: `worker_delivery` / `worker_probe` / `worker_maintain` |
| `src/controller.php` | Web routing and HTTP entry |
| `src/migrate.php` + `src/migrate/N.php` | Merge framework and the per-version steps |
| `src/class/curl.php` | Trimmed-down php-curl-class |
| `src/template/`, `src/i18n/` | Page templates and localized strings |
| `tools/wxwclub.sql` | Fresh-install snapshot; must match the merge end state column for column |

Naming is the module boundary: `Club_<domain>_<action>()`, where the domain prefix *is* the partition (`Endpoint`, `Queue`, `Blacklist`, `Resolver`, `Log`, `Stat`, `Notice`, `Limit`, `DB`, `Migrate`). `ActivityPub_*` is the protocol side. When adding a function, find the matching prefix rather than starting a new scheme.

## Running and verifying

**There is no test framework and no composer here.** CI does exactly one thing: `php -l` over every `.php` file, across a 7.3 / 7.4 / 8.1 / 8.3 / 8.4 matrix (`.github/workflows/lint.yml`).

- **The syntax floor is PHP 7.3.** No arrow functions, `??=`, or typed properties (7.4); no `match`, named arguments, `?->`, or constructor promotion (8.0); no enums or `readonly` (8.1).
- `php -l` checks syntax, not whether a function exists. `str_contains`, `array_is_list` and friends will pass lint and blow up at runtime on old PHP. Guard with `function_exists` or write around them.
- `config.php` is not in the repo (`.gitignore`), so neither the worker nor the web side can run locally. What you *can* do locally is `php -l` and exercising pure functions in isolation. Correctness otherwise rests on reading the code and reasoning it through — don't imply you ran it.
- A new config key means updating `config.example.php` *and* an explicit reminder to sync the server's `config.php` by hand. Whether a missing key is silent depends on how it is read: `config.worker.*` falls back to a default and the change quietly does nothing, while `config.base`, `config.node.timezone` and `config.mysql.*` are read unguarded and a missing one breaks startup. Say which of the two it is.
- A schema change is verified by running it, not by reading it: fresh import of `tools/wxwclub.sql`, an upgrade from the previous version, a re-run after killing the merge partway through, and a second run that must be a no-op. Without a database to do that on, say plainly it was not run.

## Data model

12 tables (`tools/wxwclub.sql`):

- Local state: `clubs`, `users`, `activities`, `followers`, `announces`
- Delivery scheduling: `tasks`, `queues`, `endpoints`, `dns`, `blacklist`
- Everything else: `notices` (rate-limit DMs), `meta` (schema version + migration checkpoints)

Three sources of truth. Keep them straight before touching anything scheduling-related:

- **`queues` is the delivery truth** — whether there is anything to send, ask only this.
- **`blacklist` is the disabled truth** — whether an endpoint is disabled, ask only this.
- **`endpoints.next_at` is a repairable scheduling hint**, never grounds for deleting a queue or task.

Meanwhile `endpoints.fails` / `fail_since` / `retry_at` are the endpoint's own health history and **cannot be reconstructed from queues**. Reconciliation may recompute `next_at` from queues, together with the `idle_since` that has to accompany it; it must never overwrite those three. Overwriting them once means treating an instance that has been down for a month as freshly healthy.

## Scheduling model

The easiest part to get wrong. Read this through before touching `Club_Endpoint_*`, `Club_Blacklist_*`, or `Club_Reconcile_*`.

**The unit of claim is an endpoint (a normalized inbox URL), not a queue row.** All several-thousand queue rows for one target are held by a single worker, so one inbox only ever faces one in-flight request from us. What is exclusive is the URL, not the remote host: two different inboxes on the same instance are claimed independently and can be delivered to at the same time. Scheduling per queue row would instead have dozens of processes each grab a row for the *same* target and hit it together.

**Three process types** (forked in `cli.php`; slot names are `type.index`):

- `delivery` (`config.worker.delivery`) and `probe` (`config.worker.probe`) are mutually excluded by leases, so adding processes just adds concurrency.
- `maintain` is fixed at one **per master** and deliberately not configurable — log rotation, reconciliation and blacklist cleanup are site-wide work, and running N copies just does the same thing N times. `maintain.0` is therefore only unique if the deployment runs exactly one worker master; scale with `worker.delivery` / `worker.probe`, never by starting a second master. **The maintenance queue never resolves a hostname, never makes an HTTP request, and never delivers or probes.** It does own the `dns` table's expiry sweep, which is a row delete and touches no resolver. Mixing the two means a sustained backlog starves maintenance forever.

**Lease protocol** (`lease_token` + `lease_until`, 120 s):

1. Read candidates without locking, so no row lock is carried into DNS and HTTP.
2. Claim by primary-key CAS; the affected row count decides the winner.
3. Swap the token again right before going out (`Club_Endpoint_Authorize`) — the system resolver has no hard deadline, and a resolve that outlives the lease means the endpoint already changed hands.
4. Results land only through `Club_Endpoint_Complete`, which locks the row by token, so a stale owner cannot write a single field.

**Always take locks by primary key.** An UPDATE that scans a secondary index locks the index record first and then the row, while every completion path locks the row first and rewrites index columns last; the two directions form a cycle, which is error 1213. `Club_Lease_Pick` exists for this reason.

**An early `next_at` is harmless; a late one delays delivery.** Claim, queue selection and pre-HTTP authorization collectively re-test the real conditions, so a hint that runs early cannot bypass backoff or the blacklist — but one that runs late means nobody sends. That is why every completion recomputes it from queues. Know which check lives where before you move one: the claim only tests `retry_at`, and reaches a blacklisted target solely through `next_at`, which is a hint; `Club_Endpoint_Queue` and `Club_Endpoint_Authorize` are where the blacklist is actually enforced.

**A connection cannot cross a `fork()`.** The master disconnects before forking and every child opens its own — `last_insert_id()` and the session isolation level are per-connection state, and a shared socket hands two children the same one. Persistent connections are for FPM only; under CLI the inherited pool would defeat the reconnect.

## Invariants

- Every `blacklist` target must have an `endpoints` row with `next_at IS NULL` for its entire lifetime — probing and eventual restoration both depend on that row existing.
- `next_at IS NULL` is permitted in exactly two cases: blacklisted, or no queues left.
- `next_at IS NULL` and `idle_since > 0` move together — every path writing one writes the other. `idle_since` is the moment the row went empty and must not be refreshed while it stays empty, or the grace period never elapses and nothing is ever reclaimed.
- A control row is never deleted the moment it goes empty: one delivery round leaves a large group's several thousand rows empty at once, and the next round rebuilds them identically. It has to sit out the grace period, and the reclaim re-tests queues, blacklist, lease and backoff under the primary-key lock — everything read outside the transaction may already be stale.
- `tasks` are deleted only when `NOT EXISTS queues`.
- **An HTTP request that already went out must never be replayed by a database retry.** `Club_DB_Retry` wraps the database segment only. Actor fetches, DNS and HTTP all happen outside any transaction.
- Bulk deletes are always batched into independent bounded transactions. Never delete a target's several thousand queue rows in one transaction.

## Logging

Levels are `silent` / `error` / `warning` / `info` / `debug` (`config.node.log-level`). `Club_Log_Event` writes the event stream; `Club_Log_Console` is for the master and CLI only.

- **Silent returns are not acceptable.** Every early exit leaves a log line: `info` for a state change, `debug` for *why no delivery or write happened*.
- But idling must not flood. Paths that dozens of processes hit every round (no work claimed, no candidates) only bump a `Club_Stat` counter. A fully idle system must not emit one all-zero summary per process per minute.
- Workers clear `Club_Log_Ref('')` at the end of each task.
- `logs/` directories are created 0777 and files 0666, each followed by an explicit `chmod`: web and worker usually run as different users, `umask` trims the mode `mkdir` was given, and `unlink` checks the directory's permissions rather than the file's.

## Database schema changes

The version constant is `DB_VERSION` at the top of `src/function.php`; the steps live in `src/migrate/N.php`, one file per version, kept forever — a database at any historical version must be able to merge all the way up. There is no CHANGELOG.md; do not create one.

- **A migration that has already taken effect on a server must never be edited.** Once `meta.schema` equals that version, the file is never executed again (`if ($from === DB_VERSION) return $from;` in `src/migrate.php`), so anything added to it is silently a no-op. Open a new version instead.
- The criterion is always *what the database looks like right now*, never *where the last run stopped*. Structural steps ask `information_schema`; a data backfill has nothing there to ask, so it carries an idempotent predicate, a work table, or a checkpoint instead. A crashed merge must be resumable on restart and must not corrupt the parts already merged.
- High-cardinality data moves page through a keyset cursor. Never `fetchAll()` a full `DISTINCT` result into PHP.
- Each version's validator asserts the complete end state that version is responsible for, column by column: types, collations, indexes, control rows, and that the work tables are gone. It runs once, inside the merge — `php cli.php migrate` returns before any validator when the version already matches, so it is not a re-runnable health check.
- Checkpoints live in `meta` as `migration.N.*` and are cleared by version N itself; their lifetime never outlasts that version. Version 2 is the historical exception: it had already taken effect on the server, so its state is dropped by `src/migrate/3.php` instead.
- **A schema change must also update `tools/wxwclub.sql`**: both the `CREATE TABLE` and the trailing `meta.schema` row. Miss the latter and every fresh install triggers an empty merge on first boot. That file is checked by eye and by nothing else — it installs *at* the current version, so it never runs a merge and never runs a validator. Compare it against the version's validator column by column: types, collations, defaults, nullability, indexes, foreign keys. Column order is the one thing that may differ; every `INSERT` in the codebase names its columns.
- **A database newer than the code is refused outright.** Old code never downgrades a schema and never runs a step backwards.

**The upgrade is `git pull` plus a worker restart, and nothing else.** No snapshot, no stopping processes, no blocking the web by hand — the web answers 503 for as long as the schema does not match, the worker merges on start and exits, and `--restart always` brings the queues back up. That convenience is paid for in full *before* the commit, because the merge then runs unattended, on a live database, with nobody watching and nothing to roll back to:

- **The merge's runtime is the site's downtime.** The web is 503 from the moment the code lands until the merge finishes. A step that rewrites a large table has to be worth the outage it costs.
- **A migration that throws becomes a crash loop.** `cli.php` exits 1, the container restarts, the merge is retried, and the site stays at 503 until a human intervenes — the one thing this flow assumes nobody has to do. Anything that can fail must fail *before* it has changed the database.
- **Neither the schema gate nor the migration named lock is a write lock.** Both stop new work from starting; neither stops what is already running.
- Never re-import `tools/wxwclub.sql` over a live database, and never repair a failed upgrade by editing `meta.schema` by hand.

## Protocol behavior

When you are unsure how some piece of ActivityPub is supposed to behave, **read Mastodon's source and follow it** — do not invent it. The compatibility targets are Mastodon, Misskey and Pleroma.

**Endpoint identity.** Every remote inbox goes through `Club_Endpoint_Normalize` / `Club_Endpoint_Require` before it may enter `users`, `queues`, `endpoints` or `blacklist`. The normalized string is both the database key and the URL actually handed to cURL — the two must never diverge. Only spellings the protocol makes equivalent are merged: scheme and host case, default port, IPv6 compression, empty path. **`path` and `query` are identity, byte for byte** — a reverse proxy is free to route `/Inbox` and `/inbox` to two different applications, and guessing wrong crosses two sites' deliveries.

**Outbound requests.** Every request re-checks that the target resolves to a public address — a target queued long ago may since have been re-pointed at a private range — and pins the verified IPs with `CURLOPT_RESOLVE` so cURL does not resolve a second time. `setFollowLocation` is off globally: GET follows redirects by hand, redoing the public-address check and re-signing on every hop; POST never follows one at all.

## Code style

- Comments explain **why** this choice was made. No change history, no "it used to be X, now it's Y", no addressing a particular reader — the reader is a stranger. Write no more than needed, but a stretch of logic with a real trade-off should carry a comment stating it.
- Comments are written in Chinese; log messages and string literals in English.
- SQL is all lowercase, identifiers are backquoted, and prepared statements use named parameters.
- Match the density and idiom of the code around you. Don't introduce a second style.
- This file is not hard-wrapped: one paragraph or list item is one line, and wrapping is the editor's job.

## Commits

- Subjects are English, lowercase, imperative, and as short as they can be: `claim leases by primary key`, `log outages once and probe sooner`.
- "Commit" means committing every outstanding change in one go — do not split it up unprompted.
