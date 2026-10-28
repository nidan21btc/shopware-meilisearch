# Operations and recovery

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

For a new setup use the [complete plugin installation](installation.md),
[background job setup](background-workers.md) and [acceptance checklist](acceptance-checklist.md).
This page is the day-to-day troubleshooting/recovery reference.

## Background workers are required

Daily indexing and the backend rebuild button need two separate Shopware
components: the scheduled-task runner dispatches work and a messenger consumer
executes it. A running Meilisearch daemon provides neither of these components.

Use the shop's existing, verified worker setup. Typical commands, run under a
service manager or provider job scheduler, are:

```sh
php bin/console scheduled-task:run --time-limit=60
php bin/console messenger:consume async low_priority --time-limit=300 --memory-limit=256M
```

Transport names and limits depend on the installation. Inspect
`php bin/console debug:messenger` and the shop's messenger configuration before
adapting the example. See the [worker setup guide](background-workers.md) for supervised service and
provider/cron examples. The consumer can execute other queued Shopware messages;
ensure staging mail, payment and external integrations are configured for testing.
The example limits are not measured minimums for every catalog. Restart supervised
workers after deploying source/configuration changes.

Check task registration with `php bin/console scheduled-task:list`. The plugin's
task name is `shopware.meilisearch.reindex`, with a 60-second polling interval.
That interval is not the index regeneration interval. A full automatic rebuild
becomes due 24 hours after the last successful background build. There is no
fixed midnight timezone schedule. A stopped worker delays both daily and manual
updates indefinitely.

If a task remains queued, first inspect the consumer rather than increasing the
search engine's memory. An error such as `Command "async" is not defined` means
the executable command is incomplete: transport names are arguments to
`messenger:consume`, not standalone console commands.

## Manual rebuild and verification

Save configuration changes, then open the plugin configuration in the
administration. **Suchindex jetzt neu aufbauen** records one pending rebuild for
all enabled channels/languages. **Status aktualisieren** refreshes the pending
state and last successful timestamp, displayed in UTC. The page does not poll
automatically. A request's HTTP 202 confirms acceptance, not completion.

The status route needs `system_config:read`; requesting a rebuild needs
`system_config:update`. Pending requests disable the normal button. A failed
attempt remains pending and is eligible for retry after at least 15 minutes.

For controlled operator maintenance, a synchronous command is also available:

```sh
php bin/console shopware-meilisearch:search:index --enabled-only
php bin/console shopware-meilisearch:search:audit-index-fields
```

Optional `--sales-channel` and `--language` narrow the index command. Audit
`--require-current` is stricter than a daily-staleness policy: outstanding product
revisions can make it fail even while waiting for the next scheduled daily build.
Direct CLI publication does not clear a pending backend request; inspect the
background state separately. Do not repeatedly run builds to clear an error.

Verify all configured language endpoints, a broad query, an exact product number,
manufacturer/category filters, customer-specific prices and a cart action. A
healthy engine alone does not verify the complete storefront path.

## Monitoring and request limits

Observe host CPU, available memory, swap/OOM events, engine RSS, PHP-worker
occupancy, database pressure and search response times together. Measure during
index construction as well as during searches. Test a small representative
parallel workload before raising the admission limits. Five users behind one
IP exercise a different path from five distinct client IPs.

The guard stores short-lived hashed client state in
`var/shopware-meilisearch/admission` and nonblocking slot locks beside it. Do not
remove this directory while workers are using it: replacing lock files can
invalidate mutual exclusion across workers. State expires normally. Invalid or
inaccessible state fails closed with HTTP 503; recover under maintenance after
stopping affected workers and checking permissions.

HTTP 429 indicates a rate/concurrency budget was exhausted, including an
occasional nonblocking limiter-lock collision. HTTP 503 may be a service failure
or unusable limiter state. The overlay currently presents a generic unavailable
message for these cases; that message alone does not prove the engine is down.
The guard's short `Retry-After` header is advisory; the browser's current retry
button does not create an unlimited automatic retry loop.

## Admission and capacity log

The plugin writes a dedicated rotating Shopware application log at
`var/log/shopware-meilisearch-admission-YYYY-MM-DD.log` (or the configured
`kernel.logs_dir`). It retains 14 daily files and creates files with mode `0600`.
This logger deliberately bypasses the standard production handler, which may
filter out warning/info events. Ensure the PHP user can write the log directory;
verify rotation and available disk space under your hosting permissions.

Look for `Search admission summary`. Shared counters produce **at most one record
per 60 seconds per installation**, triggered by the next admission/completion
event. There is no timer or extra daemon: an idle last window is emitted when
traffic resumes, with its actual `windowSeconds`. Counters are reset for the next
window and stored privately in `var/shopware-meilisearch/metrics/metrics.json`.
Do not delete/replace that file while requests are running. No IPs, client hashes,
queries, cookies, URLs, channel identifiers or credentials are included.

The structured context contains:

- `counts.accepted`: admitted requests, not unique customers.
- `counts.completed` / `counts.failed`: finished admitted requests, with HTTP 5xx
  classified as failed; completion can fall in a later window than admission.
- `counts.concurrency`, `global_rate`, `client_rate`, `client_capacity`: rejections
  due to occupied computation slots, global tokens, client tokens or the bounded
  client-state table. `client_capacity` does not mean the product catalog is full.
- `counts.limiter_busy`: nonblocking contention on admission state;
  `counts.limiter_error`: an unusable limiter, returned as HTTP 503.
- `peakActive`: highest sampled number of occupied slot locks on admission.
- `durationMeanMs` / `durationMaxMs`: server-side time from admission to request
  completion, including PHP/context/rendering; not browser/network latency or
  engine-only time. The mean is null when no admitted request finished.
- `limits`: configured concurrency, global requests/second and client
  requests/second. Burst/minute formulas remain as documented in
  [configuration](configuration.md).

Records with rejections or failed requests have level WARNING; other windows
have level INFO. Missing counter keys mean zero. Compare repeated windows with
CPU, available memory, swap and latency before changing limits. A few client-limit
hits can indicate a single burst, not general capacity exhaustion. Low host load
with frequent concurrency/global rejections may justify a bounded adjustment;
high load and rising durations require investigating capacity first.

Statistics are **best effort**, not billing or an exact attack counter. Their
separate lock is nonblocking, so overlapping updates can be skipped instead of
queuing PHP workers; aborted/killed workers may lack a completion. Lock/storage
or logging failures never bypass admission and do not fail an otherwise valid
search. They can suppress statistics; absence of entries does not prove absence
of traffic. The fixed-size state is capped at 8 KiB and per-window counts saturate
at one billion. Log output is bounded to 1,440 summaries/day; ordinary application
and web-server logs have their own independent policies.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| One client fails while another works | IP budget/shared NAT, HTTP status, request timing, stale assets and that client's network; do not assume different browser means different IP |
| Assets load but no search request appears | Browser console and current JavaScript asset version; credentialed page URLs must not pass userinfo into fetch URLs. Prefer the browser authentication dialog and redact credentials from diagnostics |
| HTTP 429 with low host load | Rate limits and client bursts, not only concurrency; verify the compiled environment uses the intended values |
| HTTP 503 for every query | Engine/socket health, credential roles and file permissions, limiter state, application logs |
| SSH connectivity works but web search fails | Web-PHP identity, socket traversal, namespace and compiled environment |
| Missing icons/flags after asset installation | Public bundle directories usually need `0755` and files `0644`; do not generate public assets under `umask 077` |
| Rebuild pending indefinitely | Both scheduler and consumer, correct transport/command, task status and worker logs |
| Indexing process killed | Provider/cgroup memory limit and OOM records; indexing-memory alone is not total RSS |
| New language/index denied | Re-provision scoped keys including its published and building names |
| Query feedback rejected after key changes | Regenerate with the independent feedback secret, not a search or writer key |

Never paste raw environment files, authorization headers, query text or private
exports into public tickets. The storefront controller's application error log
records exception class and channel/language identifiers, not exception messages.
Web-server logs may independently retain query strings.

## Backup, rollback and removal

Back up source releases and private configuration separately from the public
repository. Product indexes are reconstructible from the catalog/configuration;
reviewed signal imports may not be. Protect both the database-stored settings and
original private signal files. Follow Meilisearch's supported backup procedure
rather than copying a live data directory indiscriminately.

For a storefront rollback, disable the overlay, restore the previous compatible
release and clear/rebuild its assets and caches. Database migrations are additive;
do not drop the state table on a running installation to simulate rollback.
Coordinate worker versions and credentials. A completed atomic swap removes the
previous generation, so a prior source version may require an explicit rebuild.

Deactivate/uninstall through Shopware before deleting plugin source. Uninstalling
without keeping data removes `shopware_meilisearch_state`; the plugin does not
remove an independently managed engine, its data files, API keys, service-manager
entries or provider jobs. Inventory these separately, confirm they belong only
to the removed installation, then revoke/delete them deliberately.
