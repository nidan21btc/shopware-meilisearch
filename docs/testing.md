# Testing and development

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Run from this repository's root. `SHOPWARE_ROOT` points to the directory containing
Shopware's `vendor/` and `bin/console`, not to its public document root.

## Automated regression checks

GitHub Actions runs fast PHP and Python regressions and synthetic Chromium/Firefox
browser tests on pushes to `main`, version tags, pull requests and manual runs.
The synthetic administration layout check exercises desktop/mobile views, pending
state, rebuild/refresh controls and configuration editors; it does not replace a real
administration integration test.
CI installs isolated Shopware 6.7.14.0 dependencies from the committed lock file;
it does not start a Shopware shop or run the database-writing integration suite.
CI checks supplement the installation and acceptance checks below.

Published search fields are cached for 24 hours and warmed after a successful
index swap. Cross-process warming requires a shared `cache.app` backend. A
process-local adapter such as Symfony's development `ArrayAdapter` cannot share
the indexer's entries with web requests; verify the actual production adapter.

## Static analysis

After installing the isolated `.github/ci` dependencies, run `.github/ci/vendor/bin/phpstan analyse -c phpstan.neon --no-progress`. CI enforces PHPStan level 3 without a baseline. Higher levels may report additional issues that require separate review.

## Fast regression checks

```sh
SHOPWARE_ROOT=/path/to/shopware php tests/run.php
SHOPWARE_ROOT=/path/to/shopware php tests/search_guard.php
SHOPWARE_ROOT=/path/to/shopware php tests/admission_metrics.php
php tests/defaults.php
SHOPWARE_ROOT=/path/to/shopware php tests/admin_config_schema.php
node tests/admin_field_consistency.cjs
SHOPWARE_ROOT=/path/to/shopware python3 -m unittest discover -s tests -p 'test_*.py'
```

At the 0.2 documentation baseline, the PHP runner passed 221 checks and Python
unit discovery passed 56 tests. Admission checks separately cover cross-worker
slots, global/IP bursts, untrusted forwarding headers, configured ten-slot shared-IP
capacity, fail-closed state and credential-file permissions. Counts are evidence
for that baseline, not a claim that every integration script was executed.
Admission lock contention is retried for at most 250 ms; the tests also verify
that brief contention succeeds and a stalled lock still rejects requests.

The Python suite uses temporary local sockets/HTTP servers and invokes PHP for
transport checks. A sandbox prohibiting local socket creation will fail these
checks even without an application defect. Grant only the local capabilities
needed. No merchant dataset is required for the unit tests.

For syntax and asset consistency checks, lint PHP files and use Node's syntax
checker on JavaScript/CJS. Keep these two files byte-identical:

```text
src/Resources/app/administration/src/main.js
src/Resources/public/administration/js/shopware-meilisearch.js
```

The admission metrics regression covers shared reason counters, timing/peak
measurements, summary throttling, private files, nonblocking contention and
logging failure isolation. It uses synthetic data and temporary files only.

## Credentialed-page browser regression

Run `node tests/share_overlay_browser.cjs` with Playwright and its Chromium and
Firefox browsers installed to check shared links, filter/sort restoration,
desktop/mobile clearing and selection after a reload.

```sh
node tests/credentialed_page_url_browser.cjs
```

This starts a temporary loopback HTTP server with synthetic Basic authentication
and loads a credentialed page URL in Firefox and Chromium. It verifies initial
and typed searches, credential-free same-origin request URLs and continued HTTP
authentication. Both browsers passed this specific regression; no merchant data
or running Shopware installation is used. Install Playwright and its browsers
separately; `PLAYWRIGHT_MODULE` may point to an existing module and `TEST_BROWSERS`
may select a comma-separated subset of `firefox,chromium`.

## Disposable integration installation

Create a fresh Shopware 6.7 installation with its own database, URL, media/cache
paths and Meilisearch data directory/port. Install the plugin as described in
[installation](installation.md). Use a synthetic purchasable product, synthetic
translations/variants and synthetic accounts. Disable mail delivery and external
integrations. Do not copy a real shop database and call it sanitized.

Integration scripts may update products, translations, settings, indexes or
scheduled-task state. Some have isolation checks; do not assume all do. Read
and configure each script before running it. Never run database-writing tests
against production or a shared staging database. Stop test workers before
changing/deleting test indexes or lock directories.

| Area | Representative scripts / prerequisites |
| --- | --- |
| Index publication/failure and concurrent writes | `index_failure_integration.php`, `concurrent_index_update_integration.php`, `concurrent_index_fields_integration.php` |
| Variants, language, eligibility and pricing | `scheduled_*_integration.php`, `language_scope_integration.php`, `customer_price_context_integration.php`, `stale_availability_integration.php` |
| Fields and units | `audit_index_fields_integration.php`, `reference_units_integration.php`; explicit fixture UUIDs/custom fields where required |
| Daily/manual state | `daily_reindex_integration.php`; disposable DB whose name matches `el_search_test_<hex>` |
| Plugin lifecycle | `lifecycle_integration.php`; disposable installation and `el_search_test_<hex>` DB; installs/uninstalls the plugin and changes plugin configuration |
| Backend authorization | `admin_reindex_integration.py`; loopback shop and private synthetic account file |
| Backend interaction | `admin_reindex_browser.cjs`; same account file, Playwright/Chromium |
| Backend settings | `admin_settings_browser.cjs`; same account file and disposable installation, saves synthetic settings |
| Storefront interaction | Cart, facets, pagination, retry, remembered query and responsive layout CJS scripts; see each script's environment/arguments |

For the daily/manual state test, set `DATABASE_URL` only in the process environment,
using the disposable database. Do not paste its credentials into commands saved
in shell history or commit them. The test writes its state table and checks the
initial build, 23/25-hour boundaries, manual coalescing, acknowledgement and
15-minute retry backoff.

The backend tests use `EL_TEST_SHOP_URL` (default loopback port 8098) and
`EL_TEST_ADMIN_CREDENTIALS`, a private JSON file containing an ordered list:

```json
[{"username":"synthetic-admin","password":"REPLACE_WITH_RANDOM_TEST_PASSWORD"}, {"username":"synthetic-reader","password":"REPLACE_WITH_ANOTHER_RANDOM_TEST_PASSWORD"}]
```

The first account needs administration privileges; the second must actually lack
`system_config:update`. Verify its stored role/admin flag rather than trusting
an account-creation CLI default. Set mode `0600` on the private account file.
`PLAYWRIGHT_MODULE` can point to an existing Playwright installation. Browser
tests require a separately installed browser and do not vendor/download it.
The backend browser check blocks requests to origins other than its loopback shop.

Fixture options used by other scripts include `EL_TEST_LITRE_UNIT_ID`,
`EL_TEST_CUSTOM_FIELD` and `EL_TEST_QUERY`. They must refer to explicitly created
fixtures, not the original development shop. The full set of prerequisites is
in each script. The shared loopback HTTP helper is `tools/local_http.py`.

## Acceptance scope and load

Use the [operator acceptance checklist](acceptance-checklist.md) for manual
installation checks without running the database-writing developer suite. Record
the [specific compatibility baseline](compatibility.md) for each installation.

Functional checks should cover every enabled language, exact identifiers,
misspellings, variants/units, current visibility/stock, customer prices, sorting,
filters, cart updates and mobile/desktop keyboard interaction. Compare relevance
separately using [evaluation](evaluation.md).

A small parallel real-catalog test can show that a particular configuration
handles five simultaneous requests. It does not establish sustained throughput,
worst-case performance, browser/network behavior or resistance to a large attack.
Measure CPU, available memory and response-time percentiles under an agreed
bounded workload; stop if normal shop operation is affected. Include shared-IP
users when testing admission settings.

## Cleanup and reproducibility

Keep source/tests in this repository; keep private inputs and generated outputs
outside it. After a disposable test, stop only its identified processes, drop only
its explicitly named disposable database, remove its index data and private
account/key files, and remove temporary browser profiles/caches. Preserve active
development/staging services and useful private reference reports. Deleting a
symlink must not recursively delete the shared installation it points to.

A cleaned workspace deliberately does not retain the full disposable shop or its
secrets. Recreate fixtures from these instructions for a new integration run;
reinstalling that environment is different from running the fast regression suite.
