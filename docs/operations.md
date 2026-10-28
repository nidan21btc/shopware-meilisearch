# Operations and recovery

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
adapting the example. The consumer can execute other queued Shopware messages;
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

## Troubleshooting

| Symptom | Check |
| --- | --- |
| One client fails while another works | IP budget/shared NAT, HTTP status, request timing, stale assets and that client's network; do not assume different browser means different IP |
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
