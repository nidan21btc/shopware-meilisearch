# Installation and upgrades

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

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

Run application commands from the Shopware root with the same environment and
filesystem access as its web workers. Use a disposable shop for initial testing.

Meilisearch is an **external runtime requirement**, not a bundled plugin binary
or a Composer-managed daemon. The plugin does not download or start the engine
during activation. See [the deployment decision](deployment-decision.md).

## 1. Prepare the search service

For a complete provider-independent walkthrough, follow
[Install Meilisearch for the plugin](meilisearch-installation.md). It covers a
root-managed Linux/systemd VPS and a managed-hosting foreground service, verified
downloads, service accounts, resource limits, protected keys and connection checks.
If you complete its credential-provisioning steps, continue with step 3 below;
do not create a second key set by repeating step 2 into the same directory.

Keep Meilisearch behind loopback, or use a protected Unix socket when web PHP and
the provider-managed daemon have different network namespaces. Never publish a
Meilisearch API or the master key through a storefront URL or `.htaccess` proxy.

The repository provides examples, not an installer:

| File in `deploy/` | Purpose |
| --- | --- |
| `shopware-meilisearch.service.example` | Example systemd service with a separate service account |
| `servercon-start.sh.example` | Foreground startup for a provider-managed service |
| `managed_socket_search.py` | Supervises the engine and bridge together |
| `socket_bridge.py` | Bridges a protected Unix socket to that namespace's loopback |
| `meilisearch-musl.sh.example` | Wrapper for a compatible musl binary and loader |
| `create_scoped_keys.py` | Provisions separate protected credentials |

Use an official binary suitable for the host, and verify it independently.
Create the data and socket directories before starting the launcher. Replace all
example paths and choose memory/thread limits for the host. The engine's indexing
memory setting is not a limit on the daemon's total memory; a service-manager
limit must leave additional room for the engine, data mappings and bridge.

Create a random master secret of at least 32 characters in an owner-readable file
outside the document root, with mode `0600`. Parent directories should be private.
The service launcher reads the secret from the file; do not put it in command
arguments, the repository or browser code. The launcher must remain in the
foreground; use the service manager's equivalent of a `Simple` service.

When accounts differ, grant only the required socket access and ensure parent
directories can be traversed by the authorized PHP workers. Verify connectivity
from **web PHP**, not only SSH: network namespaces and permissions may differ.

## 2. Provision credentials

Each sales-channel/language pair has a published index named:

```text
shopware_<32-character-channel-uuid>_<32-character-language-uuid>
```

Enumerate the storefront domains to obtain these UUIDs. Provision all enabled
pairs, including languages that will be enabled later. Otherwise the scoped keys
will refuse those indexes. The helper and `managed_socket_search.py` must remain
in the same directory because the helper imports its secret reader.

```sh
python3 /opt/local-search/create_scoped_keys.py \
  --master-file /var/lib/local-search/secrets/master \
  --url http://127.0.0.1:7700 \
  --output-dir /var/lib/local-search/credentials \
  --indexes shopware_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
```

The example UUIDs are synthetic. Add `--socket /path/to/search.sock` for the
bridge. The output directory must not exist. It receives private `search`, `index`,
`status`, `feedback` and `key-ids.json` files. The writer is scoped to published
and `_building` indexes. The status key can read global task metadata; see
[security](security.md). The helper leaves key expiration unset; rotation is an
operator responsibility. On failure it attempts to revoke keys it created and
remove its output; check the engine if that cleanup was interrupted.

## 3. Install the plugin and configure its environment

Copy this repository's plugin files to `custom/plugins/ShopwareMeilisearch`,
excluding `.git`, local outputs and secrets. Set the following in the shop's
private environment configuration, using paths readable by its PHP/CLI account:

```dotenv
SHOPWARE_MEILISEARCH_URL=http://127.0.0.1:7700
SHOPWARE_MEILISEARCH_SEARCH_KEY_FILE=/var/lib/local-search/credentials/search
SHOPWARE_MEILISEARCH_KEY_FILE=/var/lib/local-search/credentials/index
SHOPWARE_MEILISEARCH_STATUS_KEY_FILE=/var/lib/local-search/credentials/status
SHOPWARE_MEILISEARCH_FEEDBACK_KEY_FILE=/var/lib/local-search/credentials/feedback
# Only when using the optional bridge:
# SHOPWARE_MEILISEARCH_SOCKET=/path/to/search.sock
```

Do not configure the master key as an application key. Make environment files
owner-readable only, including compiled `.env.local.php` after `dotenv:dump`.
Keep the release root non-world-writable. Generate public assets with a normal
public-file umask such as `022`; an owner-only umask for assets makes icons and
other bundles inaccessible to the web server.

```sh
php bin/console plugin:refresh
php bin/console plugin:install --activate ShopwareMeilisearch
php bin/console assets:install
php bin/console cache:clear
```

In the administration's plugin configuration, select the required sales channel
and configure language-specific fields, units and synonyms. Leave **Suchoverlay
aktivieren** disabled until the first build succeeds.

```sh
php bin/console shopware-meilisearch:search:index \
  --sales-channel aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
```

Without `--enabled-only`, the command can build indexes while the overlay is
disabled. Optional `--language` limits the run further. After verifying the build,
enable the overlay and disable the previous storefront search overlay to avoid
competing event handlers. Test every configured storefront language, cart actions,
filters and web-PHP connectivity. Enable the scheduled workers as described in
[operations](operations.md).

## Upgrades

Back up configuration and keep the previous application release. Install the new
source and run the normal Shopware plugin update so migrations are applied:

```sh
php bin/console plugin:refresh
php bin/console plugin:update ShopwareMeilisearch
php bin/console assets:install
php bin/console cache:clear
```

When using compiled environments, regenerate them after changing environment
variables, then restore private-file permissions. Avoid running builds from both
old and new releases at once.

Version 0.2 introduces separate application keys and an independent feedback
secret. Provision and configure them before rebuilding; there is no implicit
fallback from the read key to the master or writer. Regenerate existing keyed
click feedback with the new feedback secret or remove that optional configuration.
Rebuild indexes to publish the candidate limit and search cutoff settings.
