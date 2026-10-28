# Installation and upgrades

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Start here for a new installation. Read [compatibility and capacity](compatibility.md)
and prepare a separate staging/test shop first. The sequence is: prepare the
engine and keys → obtain a plugin package → install/configure → build → enable
→ verify background jobs → complete acceptance checks. Do not treat a successful
ZIP upload or a running engine as the end of installation.

## Requirements

- A working Shopware 6.7 storefront, its supported PHP runtime/extensions and
  database. The plugin uses `intl`, `mbstring` and Symfony's HTTP client in addition
  to the normal Shopware dependencies; use a cURL-capable client for Unix sockets.
- Meilisearch 1.x, version 1.9 or later; the implementation was tested with 1.56.0.
  This range is a runtime compatibility check, not a claim that every version was
  tested. Review the upstream version before deployment.
- A service manager that restarts the search process, persistent private index
  storage and enough memory for index construction as well as searches.
- A local filesystem shared by the installation's PHP and CLI workers for locks.
  This implementation is designed for one shop server.
- Python 3 if using the optional socket bridge or evaluation tools.

SSH/deployment access or equivalent provider assistance is needed for the engine,
private environment configuration and workers. An administration login alone
cannot provision these components. You need an account with plugin-management
rights and, for the rebuild controls, `system_config:read`/`system_config:update`.
Do not assume this PHP plugin can be installed in a Shopware SaaS tenant.

Before changing a shop, back up its database/configuration and keep its current
application release and previous search configuration. Do not purge user data,
queues or the current search provider to install this plugin.

Meilisearch is an **external runtime requirement**, not a bundled plugin binary
or a Composer-managed daemon. The plugin does not download or start the engine
during activation. See [the deployment decision](deployment-decision.md).

## Prepare your command environment

Replace these two example paths with the actual Shopware root (containing
`bin/console` and `vendor/`) and the host's supported PHP executable. Run application
commands as the shop's normal deployment/application user, not root:

```sh
SHOPWARE_ROOT=/srv/shopware/current
PHP_BIN=/usr/bin/php
sw() (
  cd "$SHOPWARE_ROOT"
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console "$@"
)
"$PHP_BIN" --version
"$PHP_BIN" -r 'foreach (["curl","intl","mbstring","pdo_mysql","json","zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: ".$extension.PHP_EOL); exit(1); } } echo "Required PHP extensions available".PHP_EOL;'
sw --version
```

Keep this shell/function available for subsequent `sw` examples. CLI PHP can be a
different version/configuration from web PHP; confirm both through the provider's
normal tools. The application identity must be able to read protected credentials
and write Shopware's normal cache/log paths and plugin lock directory under `var/`.
Do not solve permission errors by making the shop or credentials world-writable.

## 1. Prepare the search service

Follow [Install Meilisearch for the plugin](meilisearch-installation.md). It covers
verified downloads, root-managed Linux/systemd and managed hosting, service
accounts, resource limits, private storage, protected keys and connection checks.
Complete the matching service model and shared provisioning steps; do not mix
root-account paths and managed-account paths inadvertently.

Staging and production require separate engine instances/data/keys/endpoints in
the supported recipe. The current plugin does not distinguish copied channel IDs
inside one shared instance. The browser never receives the engine API key or
connects directly to its API. Do not publish a proxy for the engine.

## 2. Provision credentials

If the Meilisearch guide already created the credentials, skip provisioning here.
Otherwise complete its [shared credential steps](meilisearch-installation.md#shared-steps-provision-the-application-credentials).
They show how to obtain actual channel/language UUIDs with read-only SQL, generate
scoped keys, and transfer only the required private files to the PHP identity.

You need `search`, `index` and `status` application files. The master
key stays with the daemon/provisioning administrator. Every intended published
index follows `shopware_CHANNELUUID_LANGUAGEUUID`; include all pairs you will
build. Missing language/index permissions will not fix themselves when you enable
a language in Shopware. Re-provision/review scoped keys deliberately when adding
pairs. Never rerun the helper into an existing output directory or print keys.

## 3. Install the plugin and configure its environment

### Obtain the package

Use [the packaging guide](packaging.md) to create a correctly structured ZIP from
a reviewed commit, or use a trusted explicitly published plugin package. Keep it
outside the public document root. Set its transferred path and verify the checksum
against the build record:

```sh
PLUGIN_PACKAGE=/path/outside/public/ShopwareMeilisearch.zip
sha256sum "$PLUGIN_PACKAGE"
```

The top-level directory must be `ShopwareMeilisearch`, containing `composer.json`,
`LICENSE` and `src/`. Do not upload an arbitrary GitHub source archive as if it
were a prepared plugin ZIP. No Shopware Store account/Packagist publication or
plugin-specific npm/PHP SDK installation is assumed.

### Configure the private environment before using the plugin

For the root-managed engine recipe, add to the shop's private environment file
(e.g. `.env.local`) using the actual paths created for the PHP user:

```dotenv
SHOPWARE_MEILISEARCH_URL=http://127.0.0.1:7700
SHOPWARE_MEILISEARCH_SEARCH_KEY_FILE=/etc/shopware-meilisearch-app/search
SHOPWARE_MEILISEARCH_KEY_FILE=/etc/shopware-meilisearch-app/index
SHOPWARE_MEILISEARCH_STATUS_KEY_FILE=/etc/shopware-meilisearch-app/status
```

For the managed-hosting model, use its private credential paths and set
`SHOPWARE_MEILISEARCH_SOCKET` to the protected socket. Do not copy the master key
into these variables. Use only one intended credential source per role. A `_FILE`
setting takes precedence over a direct value; a stale file path can still break
an otherwise valid direct key.

For compiled-environment installations, regenerate the existing dump and restore
its private permissions. This example is for the normal `.env.local.php` model;
use the deployment pipeline's equivalent where environment injection differs:

```sh
cd "$SHOPWARE_ROOT"
if [ -f .env.local.php ]; then
  sw dotenv:dump prod
  chmod 0600 .env.local.php
fi
for private_env in .env .env.local .env.prod .env.prod.local; do
  if [ -f "$private_env" ]; then chmod 0600 "$private_env"; fi
done
```

Files must belong to an identity that the authorized web/CLI processes can read.
Only the relevant private files should be `0600`; do not apply that recursively
to public assets. A shell-only change does not necessarily update web PHP or an
already running consumer. Reload them through the host's normal deployment
procedure if their environment/opcode configuration requires it.

### Import, install and activate

For a fresh installation, first confirm the technical plugin is not already
installed; use the upgrade procedure instead of importing over a running version:

```sh
sw plugin:list --filter=ShopwareMeilisearch
sw plugin:zip-import "$PLUGIN_PACKAGE"
sw plugin:refresh
sw plugin:install --activate ShopwareMeilisearch
umask 022
sw assets:install
sw cache:clear
sw scheduled-task:register
sw plugin:list --filter=ShopwareMeilisearch
```

Expected: the plugin is installed/active, the state-table migrations succeed,
and normal console commands still start. Activation of the PHP plugin does not
enable its customer overlay by default. Keep **Suchoverlay aktivieren** off until
its first index is ready.

Alternatively upload the prepared ZIP through **Extensions → My extensions →
Upload extension**, then install and activate it. Do not repeat CLI installation
over that same installed version. The backend upload does not replace environment,
engine, asset/cache and worker setup. See Shopware's
[extension management guide](https://docs.shopware.com/en/shopware-6-en/extensions/myextensions).

The plugin ships its small storefront and administration JavaScript assets,
including the hidden administration `.vite` manifest. Keep them in the package.
`assets:install` must run with public-readable output (normally directories `0755`,
files `0644`). If your theme/deployment pipeline requires recompilation, use its
usual process; a direct synchronous command available in the recorded baseline is:

```sh
sw theme:compile --sync
```

This may compile multiple themes and be expensive; do it in the appropriate test
or deployment window. No standalone `npm install` or administration rebuild is
needed for the plugin's committed assets. Other installed extensions may have
their own build requirements.

## 4. Configure the intended sales channel

Open the plugin's **Configuration** from the extension's menu. The technical name
is `ShopwareMeilisearch`; the label is "Shopware lokale Suche" / "Shopware local
search". The configuration card is **Lokale Suche**. Select the intended sales
channel and understand Shopware's inherited/global setting values before editing.

Start with this minimal configuration:

| Setting | Initial value / action |
| --- | --- |
| **Suchoverlay aktivieren** | Off until the first CLI build succeeds |
| **Durchsuchbare Indexfelder ...** | Leave blank for the built-in standard field list |
| Language-specific fields | Leave blank unless you deliberately need overrides/custom fields |
| Quantity units | Leave blank initially, or configure the actual unit UUID/factor if quantity search is required |
| Synonyms | Optional; configure per locale after a basic working search |
| Suggestions and featured products | Optional; no merchant products/suggestions are built in |

Save changes before indexing. A blank optional field is not a request to supply
sample merchant data. Consult [configuration](configuration.md) for exact JSON
formats and allowed fields. The backend currently has German labels even when
the administrator's interface language differs.

If quantity conversion is needed, this read-only SQL helps identify translated
units; inspect the product's actual `purchaseUnit` and `unitId` as well:

```sql
SELECT LOWER(HEX(u.id)) AS unit_id,
       LOWER(HEX(t.language_id)) AS language_id,
       t.name, t.short_code
FROM unit u
INNER JOIN unit_translation t ON t.unit_id = u.id;
```

Locale codes in configuration (`de-DE`) and language UUIDs in index names are
different concepts. Configure actual storefront domains for every desired channel
and language. Searchable text is language-local by design; do not expect another
language's product description to make a missing translation searchable.

## 5. Build and inspect the initial indexes

Choose the actual channel UUID obtained in the Meilisearch guide, not this
synthetic value. Build while its overlay is still disabled:

```sh
sw shopware-meilisearch:search:index --sales-channel aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
sw shopware-meilisearch:search:audit-index-fields --sales-channel aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
```

Optional `--language` limits the build to one language UUID. Repeat for each
intended channel; index all configured storefront-language pairs before enabling
it. The command reports counts and channel/language IDs. It must finish with a
successful exit status; an engine process simply being present is not enough.
Keep audits containing catalog identifiers/counts private.

Do not use `--enabled-only` for the very first build of a disabled overlay: it can
skip that channel. An empty eligible catalog can legitimately publish zero
products; if unexpected, inspect active status, channel search visibility,
translations and availability rather than assuming it is a network failure.

The state table's successful background timestamp may still be empty after a
manual CLI publication. The direct index command publishes indexes but does not
acknowledge a background rebuild request; test that separately below.

## 6. Enable and test the overlay

Once the first build and field configuration are correct, enable **Suchoverlay
aktivieren** for the intended channel and save. Disable competing storefront
search overlays through their normal configuration. Do not uninstall them or
purge their data merely to test this plugin. Clear storefront/page caches using
your normal Shopware/CDN procedure and reload the actual storefront.

Open its normal header search. One overlay should appear. Check a known eligible
product number/name before judging broad-query quality. With no configured
suggestions/featured products, an empty initial discovery view is not itself an
error. Product text changes/new products may wait for the next build; displayed
prices and current eligibility are still resolved through Shopware.

The overlay integrates with the classic header's `.search-toggle-btn` and
`.header-search-input` elements. A custom theme/headless frontend may not provide
these or the extended Twig blocks. The plugin does not expose an arbitrary header
selector setting. Verify mobile triggers, CSS/CSP and browser network errors on
your actual theme. See [compatibility](compatibility.md) for scope and limitations.

The direct cart action follows normal Shopware rules, including listing-buy
configuration and variant/price constraints. A detail button is expected where a
product cannot be bought directly. Manufacturer labels, reference prices and
translations depend on the corresponding actual catalog data.

## 7. Make background updates operational

Complete [Configure Shopware background jobs](background-workers.md). It includes
reusing existing workers, root-managed unit examples, managed-hosting targets,
cron constraints and verification. The engine daemon is not a Shopware worker.

The plugin's task is `shopware.meilisearch.reindex` (60-second polling). Automatic
full background builds are due 24 hours after the previous successful one, or
when manually requested. This is not a fixed local-midnight schedule. Failed
attempts have at least a 15-minute retry backoff.

In plugin settings, save changes and click **Suchindex jetzt neu aufbauen**. Then
use **Status aktualisieren** until the pending request clears and the successful
UTC timestamp advances. HTTP 202/pending means queued only. If it remains pending,
inspect dispatcher/transport/consumer logs; do not fake completion by editing the
state table. Check restricted users have the required configuration ACLs.

## 8. Accept the installation and record a recovery plan

Work through the [acceptance checklist](acceptance-checklist.md) on your own test
shop: languages, exact/typo/broad queries, filters/sorting, customer prices,
availability, cart, responsive/keyboard behavior, remembered query, background
updates and a bounded shared-IP/parallel workload.

Observe the engine, PHP workers/consumers, database and available host memory.
Defaults are conservative (two concurrent searches) and larger legitimate user
bursts may require measured [admission tuning](configuration.md). A generic
unavailable message can be HTTP 400/401/403/429/500/503 or a browser/network error;
check the actual response before increasing limits or changing authentication.

Record the source commit/package checksum, versions, service/current-release
paths, configured channel/languages, admission settings and private key-ID/backup
locations. Preserve a compatible previous release and a way to disable the new
overlay. Completing these checks does not remove the experimental/no-support
status or guarantee relevance equal to another search service.

## Upgrades and recovery

Use the normal deployment process and a reviewed package/source revision. Back up
the database and private settings, keep the previous compatible release, and
coordinate active workers/builds before replacing code. Do not use `--reinstall`
or uninstall/data deletion as a routine update method. Import the new package or
replace the plugin directory through the deployment pipeline, then run:

```sh
sw plugin:refresh
sw plugin:update ShopwareMeilisearch
umask 022
sw assets:install
sw cache:clear
sw scheduled-task:register
sw plugin:list --filter=ShopwareMeilisearch
```

Regenerate a compiled environment when variables change, restore private-file
permissions and restart the appropriate supervised workers so they load new code
and secrets. Refresh the administration page and relevant storefront/CDN caches.
Recompile themes only as required by the deployment/theme changes. Rebuild and
recheck all affected channel/language indexes after changes to fields/settings
or index behavior. Re-run the acceptance checks appropriate to the change.

Version 0.2 introduced separate application-role keys. Configure/provision these
before rebuilding an older installation. There is no read-key fallback to
master/writer. New index/cutoff settings
require a successful index rebuild; updating PHP source alone does not publish them.

For immediate storefront rollback, disable the overlay and restore the intended
previous search configuration. If configuration cannot load, use Shopware's normal
plugin deactivation command and clear caches through the deployment process:

```sh
sw plugin:deactivate ShopwareMeilisearch
sw cache:clear
```

Do not remove a live state table or downgrade engine data as an improvised fix.
Database migrations are additive; rolling back application files is not a complete
database rollback. See [operations](operations.md) for backups/removal and
[security](security.md) for trust boundaries, data exposure and known limitations.

## Official references

- [Shopware plugin base guide](https://developer.shopware.com/docs/guides/plugins/plugins/plugin-base-guide.html)
- [Shopware extension management](https://docs.shopware.com/en/shopware-6-en/extensions/myextensions)
- [Message queue](https://developer.shopware.com/docs/guides/hosting/infrastructure/message-queue.html)
- [Scheduled tasks](https://developer.shopware.com/docs/guides/hosting/infrastructure/scheduled-task.html)
