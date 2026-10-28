# Local product search for Shopware

A Shopware 6.7 plugin providing a locally hosted multilingual search overlay backed by Meilisearch.

This repository version removes installation-specific defaults. Configure search fields, quantity units and discovery suggestions for your own shop. It has not yet been approved for production or public distribution.

The plugin is currently proprietary. Shopware icons are used through Shopware’s icon system.

## Configuration

The plugin requires a separately managed Meilisearch service and an explicitly
configured local endpoint and key. Optional UNIX-socket bridging is illustrated
in `deploy/`. Configure indexed fields per locale, including custom fields,
and define unit conversions and featured products for each installation.

The evaluation tools accept local input files. `import_doofinder.py` requires
explicit `--engine ID=LOCALE` mappings; URL mapping and synthetic comparison
require explicit `--host` arguments. Example data is synthetic. Do not commit
customer reports, keys, environment files, or generated evaluation outputs.

## Tests

The Python unit tests run with `python3 -m unittest discover -s tests -p
'test_*.py'`. Tests needing the Shopware dependencies accept
`SHOPWARE_ROOT=/path/to/shopware`; for example:

```sh
SHOPWARE_ROOT=/path/to/shopware php tests/run.php
SHOPWARE_ROOT=/path/to/shopware python3 -m unittest discover -s tests -p 'test_*.py'
```

Integration tests require an isolated test shop and may change its database or
indexes temporarily. Read each test's prerequisites before running it.
Quantity and reference-unit integration tests require an explicitly configured
fixture unit via `EL_TEST_LITRE_UNIT_ID`; field-audit tests can optionally check
an installation-specific custom field via `EL_TEST_CUSTOM_FIELD`. The real
cart-icon test requires `EL_TEST_QUERY` for a purchasable fixture product.
The sanitized general-purpose version still requires integration testing in a
separate shop before production use.

## Security and operational defaults

By default, the public search endpoint admits at most two concurrent computations across all
languages and sales channels in one Shopware installation. A process-shared
local token bucket allows a global burst of eight, refilling at two requests per
second. Each client has a burst of six at two requests per second and a separate
60-token bucket refilling at one per second. Exhaustion returns HTTP 429 with
`Retry-After`; limiter failures return HTTP 503. Set
`SHOPWARE_MEILISEARCH_CONCURRENCY`, `SHOPWARE_MEILISEARCH_GLOBAL_RATE`, and
`SHOPWARE_MEILISEARCH_CLIENT_RATE` to tune these limits (integers 1–10; defaults
2). The rate settings are requests per second; client identity is an IP address,
so allow for multiple users behind one shared connection. Client burst capacity
is three times its rate and its sustained bucket refills at half that rate.
Global burst capacity is four times its rate, with a minimum of eight.
Benchmark the configured concurrency against available PHP workers and memory.

Client identifiers are hashed,
expired after two minutes, and bounded in number. Configure trusted proxies
correctly: client identity uses Symfony's trusted-proxy-aware `getClientIp()`.
Do not run this file-backed limiter on an unverified network filesystem. Multiple
web servers require shared admission control at the reverse proxy or another
central service. Provider-level connection and bandwidth protection remains
necessary; PHP admission control does not prevent volumetric denial of service.

Candidate processing is capped at 5,000 per search, including supplemental hits.
The index search cutoff is 250 ms; the result view indicates truncated candidate
sets. Reassess capacity before exceeding this catalog size. Price range query
parameters are rejected. Price sorting remains available.

Use `deploy/create_scoped_keys.py` to provision credentials against a separately
managed local engine. Supply the published `shopware_CHANNEL_LANGUAGE` indexes
explicitly and keep all files outside the document root. It creates:

- `search`: search and settings-read access only to the published indexes.
- `index`: document/index maintenance for those indexes and their building copies.
- `status`: version and task-read permissions only. Meilisearch treats these task
  operations as global, including the status of atomic index swaps.
- `feedback`: an independent random secret for keyed historical feedback.

Set `SHOPWARE_MEILISEARCH_SEARCH_KEY_FILE`, `SHOPWARE_MEILISEARCH_KEY_FILE`
(index writer), `SHOPWARE_MEILISEARCH_STATUS_KEY_FILE`, and
`SHOPWARE_MEILISEARCH_FEEDBACK_KEY_FILE`. Direct values without the `_FILE`
suffix are also accepted. Files must have owner-only permissions. The master
key is used only by the daemon and provisioning tool, never by the plugin.
Existing keyed feedback must be regenerated when switching to the independent
feedback secret. Keep `SHOPWARE_MEILISEARCH_SOCKET` for the optional local bridge.
Regularly rotate credentials and check upstream security releases.

Automatic rebuilding runs once every 24 hours after the last successful rebuild.
Product changes are tracked but do not trigger an immediate automatic rebuild.
Availability and prices are still checked against Shopware on each search.
The minute-by-minute scheduled-task check is lightweight. Failed builds back off
for at least 15 minutes. The plugin settings contain **Rebuild search index now**
(currently labelled in German). It queues an asynchronous rebuild of all enabled
channels; the scheduled-task runner and queue consumer must be operational.
The action requires `system_config:update`, status requires `system_config:read`.
Repeated clicks coalesce and a shared lock prevents concurrent background rebuilds.

The web/PHP account must be able to read its environment files, but they should
not be accessible to other accounts (`0600`). Shop release roots should not be
world-writable (`0755` is typical). Apply this after generating compiled environment
files on every deployment. Separate operating-system accounts and browser origins
for staging and production are recommended where the hosting environment permits.

Security regressions can be checked with `tests/search_guard.php`,
`tests/daily_reindex_integration.php` (disposable database only), and
`tests/admin_reindex_integration.py` (loopback test shop and synthetic accounts).
