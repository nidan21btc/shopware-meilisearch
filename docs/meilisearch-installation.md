# Install Meilisearch for the plugin

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

This guide installs the **separate Meilisearch Community Edition service** needed
by the plugin. It does not install another MySQL/MariaDB database. Meilisearch
stores its search indexes in its own persistent data directory.

Plugin 0.3 can store the connection, limits and restricted application keys in
Shopware's database. The master key still belongs to the service. Follow the
database-configuration and migration steps in [Installation](installation.md)
after provisioning; the environment examples below can be used for the initial
import but need not remain configured afterward. Database backups contain the
restricted application keys in plaintext.

Choose one service model, then complete the shared key/configuration and acceptance
steps. The commands describe a **new instance**. If a service, account, secret or
data directory already exists, inspect it before adapting these steps; do not
replace a live installation by copying the examples blindly.

## Choose the service model

| Hosting access | Service model |
| --- | --- |
| Linux VPS with root/sudo and systemd | [A: dedicated systemd service](#a-linux-vps-with-systemd) |
| Managed hosting with an executable foreground-daemon facility | [B: provider-managed service](#b-managed-hosting-without-root) |
| Hosting allowing only short web/PHP requests | Ask the provider for a supported local daemon facility; plugin activation alone cannot supply one |

The plugin intentionally accepts only local loopback origins or an explicitly
configured Unix socket. An arbitrary remote search host is not supported by the
current transport. A remote/cloud Meilisearch quick start is not a substitute for
this deployment model. No public reverse proxy for the search API is required.

Use **separate Meilisearch instances for staging and production**, including
separate data directories, master/application keys, service entries and ports or
sockets. The plugin names indexes by channel/language UUID, and a copied shop can
have the same UUIDs. There is no configurable environment namespace to separate
such copies inside one shared instance.

## Before starting

You need a working Shopware 6.7 installation, access to its database/administration,
and the checked-out plugin source. Identify the actual web-PHP and CLI worker
accounts; the examples use `shopware`, which you must replace where necessary.
Check available RAM, free local disk space, CPU architecture and executable-file
permissions. Persistent local SSD storage is preferable to a network mount.
Allow space for the published index **and** a building generation, task storage,
backups and program files. Index size depends on your catalog and searchable fields.

The examples use the previously exercised Meilisearch **v1.56.0 Community Edition**.
This is a reproducible example, not a claim that this is always the latest or
safest release. Review upstream release/security notes when deploying. A different
version requires matching artifacts, checksums and compatibility testing. Do not
select `meilisearch-enterprise-*` for this Community Edition recipe.

On Debian/Ubuntu, prerequisites can be installed with:

```sh
sudo apt-get update
sudo apt-get install --no-install-recommends ca-certificates curl python3
```

Other distributions require their own package-manager commands. Managed-hosting
users must use provider-supplied tools instead of these sudo commands. Basic Linux
utilities used below include `sha256sum`, `install` and, for model A, `systemctl`.
Python helpers use the standard library; no pip installation is needed.

Set the source location in your shell, replacing this example:

```sh
PLUGIN_SOURCE=/path/to/shopware-meilisearch
```

## Download and verify the Community Edition binary

Perform this step as your ordinary deployment user, not in a web request. The
following block handles Linux x86-64 and ARM64. Check `uname -m` first if unsure.
Its file names and digests are from the
[official v1.56.0 release](https://github.com/meilisearch/meilisearch/releases/tag/v1.56.0).

```sh
set -eu
MEILI_VERSION=v1.56.0
case "$(uname -m)" in
  x86_64)
    MEILI_ASSET=meilisearch-linux-amd64
    MEILI_SHA256=5a32e1d7a6a69e0ee5634a7456c421f59273d73f078b6f0d8f01384eafe79729
    ;;
  aarch64|arm64)
    MEILI_ASSET=meilisearch-linux-aarch64
    MEILI_SHA256=fcb2699f0bea994cdaf02064add2a4b1656a9aae3a3ee6ea7fd0d61575f19174
    ;;
  *)
    echo 'Choose a supported official binary for this architecture.' >&2
    exit 1
    ;;
esac
MEILI_DOWNLOAD_DIR="$(mktemp -d)"
curl --fail --show-error --location --proto '=https' --tlsv1.2 \
  "https://github.com/meilisearch/meilisearch/releases/download/$MEILI_VERSION/$MEILI_ASSET" \
  --output "$MEILI_DOWNLOAD_DIR/meilisearch"
printf '%s  %s\n' "$MEILI_SHA256" "$MEILI_DOWNLOAD_DIR/meilisearch" | sha256sum --check --strict
chmod 0755 "$MEILI_DOWNLOAD_DIR/meilisearch"
"$MEILI_DOWNLOAD_DIR/meilisearch" --version
```

Expected: checksum verification succeeds and `--version` reports `1.56.0`.
Stop on a mismatch; do not bypass verification. Compare the published digest on
the official release page as well. For later releases, update both version and
architecture-specific digest together, never just the version string.

A compatible CPU does not guarantee a compatible C runtime. Errors mentioning
`GLIBC_*`, a missing loader, or `Permission denied` indicate a runtime or host
restriction. Do not replace the host's libc or bypass a `noexec` policy. Obtain a
provider-compatible official build/runtime, or use the provider's supported
container/service facility. `deploy/meilisearch-musl.sh.example` illustrates a
wrapper for a matching Alpine binary, musl loader and libraries; it is not a
universal compatibility fix and its paths/files must be verified separately.

Keep `MEILI_DOWNLOAD_DIR` available for the next chosen service model. These
commands download/verify an executable; they have not started the search service.

## A: Linux VPS with systemd

### A1. Create the service account and directories

Run from the same shell containing `PLUGIN_SOURCE` and `MEILI_DOWNLOAD_DIR`.
These examples use a dedicated engine account that is different from web PHP.

```sh
if ! getent passwd meilisearch >/dev/null; then
  sudo useradd --system --user-group --home-dir /var/lib/shopware-meilisearch \
    --shell /usr/sbin/nologin meilisearch
fi
sudo install -d -o meilisearch -g meilisearch -m 0700 /var/lib/shopware-meilisearch
sudo install -d -o root -g root -m 0700 /etc/shopware-meilisearch
sudo install -d -o root -g root -m 0755 /opt/shopware-meilisearch
sudo install -o root -g root -m 0755 "$MEILI_DOWNLOAD_DIR/meilisearch" /usr/local/bin/meilisearch
sudo install -o root -g root -m 0644 \
  "$PLUGIN_SOURCE/deploy/create_scoped_keys.py" \
  "$PLUGIN_SOURCE/deploy/managed_socket_search.py" \
  /opt/shopware-meilisearch/
```

Only the engine account writes its index directory. Root controls the executable,
provisioning code and master configuration. Do not make any of them world-writable.
If the account already exists, verify its group and intended use before proceeding.

### A2. Generate the master secret without printing it

The raw file is used by the credential-provisioning helper. The second file is
systemd's environment file. Both are root-only. This script refuses to overwrite
existing files; do not rerun it as an improvised key rotation procedure.

```sh
sudo python3 - <<'PY'
import os
from pathlib import Path
import secrets
base = Path('/etc/shopware-meilisearch')
paths = [base / 'master', base / 'meilisearch.env']
if any(p.exists() or p.is_symlink() for p in paths):
    raise SystemExit('Master configuration already exists; inspect it before proceeding.')
secret = secrets.token_hex(32)
for path, content in [(paths[0], secret + '\n'),
                      (paths[1], 'MEILI_MASTER_KEY=' + secret + '\n')]:
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as stream:
        stream.write(content)
print('Created protected master configuration; no key printed.')
PY
```

systemd reads the root-only environment file before changing to the service user.
The PHP account must not receive this master secret. Treat process environments,
private logs and backups as confidential too.

### A3. Install and start the supervised service

Copy the repository's service template and add explicit resource settings:

```sh
sudo install -o root -g root -m 0644 \
  "$PLUGIN_SOURCE/deploy/shopware-meilisearch.service.example" \
  /etc/systemd/system/shopware-meilisearch.service
sudo install -d -o root -g root -m 0755 /etc/systemd/system/shopware-meilisearch.service.d
sudo tee /etc/systemd/system/shopware-meilisearch.service.d/resources.conf >/dev/null <<'INI'
[Service]
Environment=MEILI_MAX_INDEXING_MEMORY=256MiB
Environment=MEILI_MAX_INDEXING_THREADS=1
CPUQuota=100%
MemoryHigh=768M
MemoryMax=1G
INI
sudo systemd-analyze verify /etc/systemd/system/shopware-meilisearch.service
sudo systemctl daemon-reload
sudo systemctl enable --now shopware-meilisearch.service
sudo systemctl status --no-pager shopware-meilisearch.service
```

The example allows one CPU core and caps total service memory at 1 GiB. This is
an **initial tuning example**, not a guaranteed minimum or a promise of throughput.
The indexing-memory setting controls a part of engine work, not total RSS; the
service cap must leave room for the engine's other allocations. Adjust limits
against the whole host and representative searches/rebuilds. An over-tight cap
can cause OOM kills and restart loops; inspect those before repeatedly restarting.

The template binds `127.0.0.1:7700`, uses production mode, disables analytics,
restarts after failure and restricts filesystem/network access where supported.
If a sandboxing directive is unavailable, review the host's capabilities rather
than simply removing all protection. An ordinary root-managed service on the
same host/network namespace as PHP does not need the Unix-socket bridge.

### A4. Check reachability and authentication

```sh
curl --noproxy '*' --fail --show-error --silent --max-time 5 \
  http://127.0.0.1:7700/health
curl --noproxy '*' --silent --output /dev/null --max-time 5 \
  --write-out 'Unauthenticated indexes HTTP status: %{http_code}\n' \
  http://127.0.0.1:7700/indexes
sudo ss -ltnp 'sport = :7700'
```

Expected: health returns `{"status":"available"}`, the unauthenticated index API
returns HTTP **401**, and the listener is bound to loopback. Health alone does
not test credentials or any product search. Do not open port 7700 in an external
firewall or create an Apache/Nginx proxy exposing it publicly.

For startup failures:

```sh
sudo journalctl -u shopware-meilisearch.service -n 50 --no-pager
```

Keep logs private. Then continue with [shared credentials](#shared-steps-provision-the-application-credentials).

## B: Managed hosting without root

### B1. Confirm the provider's capabilities

The account needs a supported facility that runs a foreground executable,
restarts it after failure, exposes persistent local storage and allows sufficient
memory/CPU. A short PHP request or ordinary cron invocation is not equivalent.
Confirm whether the daemon and web PHP share a loopback network namespace and
can see the same filesystem paths. If they cannot share either local TCP or a
protected Unix socket, the current plugin cannot use that hosting setup directly.

If the provider isolates loopback, run the engine and bridge together using
`managed_socket_search.py`. Both child processes then share the daemon's
namespace, while PHP reaches the bridge through a filesystem socket.

### B2. Prepare a private service directory

Choose an absolute path outside the document root and a socket path no longer
than 100 characters. Replace `SHOP_USER`; the shell variables below are examples,
not automatically detected account names. Use the verified executable from the
download step, or a separately verified provider-compatible wrapper/runtime.

```sh
SEARCH_HOME=/home/SHOP_USER/shopware-meilisearch
mkdir -p "$SEARCH_HOME/bin" "$SEARCH_HOME/data" "$SEARCH_HOME/run" "$SEARCH_HOME/secrets"
chmod 0700 "$SEARCH_HOME" "$SEARCH_HOME/data" "$SEARCH_HOME/run" "$SEARCH_HOME/secrets"
install -m 0755 "$MEILI_DOWNLOAD_DIR/meilisearch" "$SEARCH_HOME/bin/meilisearch"
install -m 0644 \
  "$PLUGIN_SOURCE/deploy/managed_socket_search.py" \
  "$PLUGIN_SOURCE/deploy/socket_bridge.py" \
  "$PLUGIN_SOURCE/deploy/create_scoped_keys.py" \
  "$SEARCH_HOME/"
python3 - "$SEARCH_HOME/secrets/master" <<'PY'
import os
import secrets
import sys
with os.fdopen(os.open(sys.argv[1], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as stream:
    stream.write(secrets.token_hex(32) + '\n')
print('Created protected master secret; no key printed.')
PY
```

The launcher requires its master file to belong to the daemon user and forbids
group/other access. This example assumes the hosting account's PHP/CLI workers
have the same UID and can traverse the private directory. If identities differ,
ask the provider to arrange narrowly scoped socket traversal/access and separately
owned application-key files. Making secrets group-readable is not compatible with
the plugin's owner-only credential-file checks.

### B3. Register a foreground start script

Save the following as the chosen directory's `start.sh`, replacing the absolute
path and Python executable if necessary:

```sh
#!/bin/sh
set -eu
SEARCH_HOME=/home/SHOP_USER/shopware-meilisearch
exec /usr/bin/python3 "$SEARCH_HOME/managed_socket_search.py" \
  --meili "$SEARCH_HOME/bin/meilisearch" \
  --data "$SEARCH_HOME/data" \
  --socket "$SEARCH_HOME/run/search.sock" \
  --secret-file "$SEARCH_HOME/secrets/master" \
  --port 7700 --indexing-memory 256MiB --indexing-threads 1
```

Then make only the owner able to execute/edit it:

```sh
chmod 0700 "$SEARCH_HOME/start.sh"
```

In the provider's service manager choose an **absolute executable target** pointing
to `start.sh`, a foreground/non-forking mode (often named `Simple`), restart on
failure, and a memory/CPU budget appropriate for the host. A 1 GiB total memory
budget and one CPU core are starting examples to measure, not universal minima.
The daemon does not need outgoing internet access for local catalog search; obtain
the binary before disabling such access. During initial testing leave boot-time
autostart off; once verified, enable it if supported and intended for that service.
Do not place API keys in the manager's command arguments or notification fields.

Start the service using the provider's facility, then check the protected socket:

```sh
curl --noproxy '*' --unix-socket "$SEARCH_HOME/run/search.sock" \
  --fail --show-error --silent --max-time 5 http://127.0.0.1:7700/health
```

Expected: `{"status":"available"}`. The socket bridge relays credentials; it is
not an authentication replacement. Never expose it through a public web route.
A missing socket, traversal denial or namespace mismatch must be resolved before
configuring the plugin. The socket normally has mode `0660` inside a protected
directory; its existence alone does not show web PHP can connect.

## Shared steps: provision the application credentials

### 1. Obtain the channel/language IDs

Use a read-only database client for your own Shopware database. Run:

```sql
SELECT DISTINCT
    LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,
    LOWER(HEX(d.language_id)) AS language_id
FROM sales_channel_domain d
INNER JOIN sales_channel c ON c.id = d.sales_channel_id
WHERE c.active = 1;
```

Create one published index name per pair using
`shopware_<sales_channel_id>_<language_id>`. Include every pair the plugin will
index, also during a first build while the overlay is disabled. Do not reuse the
synthetic UUIDs below. Keep result exports outside the repository.

### 2. Create role-specific keys

For systemd model A, run the provisioning helper as root. Its output directory
must be new and its parent must already exist:

```sh
sudo python3 /opt/shopware-meilisearch/create_scoped_keys.py \
  --master-file /etc/shopware-meilisearch/master \
  --url http://127.0.0.1:7700 \
  --output-dir /etc/shopware-meilisearch/provisioned \
  --indexes shopware_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
```

Append further published index names after `--indexes` as separate arguments.
The helper also authorizes the matching `_building` names for the writer; do not
pass those names yourself. It produces `search`, `index`, `status` and
`key-ids.json` without printing key values.

For managed model B, run the same helper as the service account with its socket:

```sh
python3 "$SEARCH_HOME/create_scoped_keys.py" \
  --master-file "$SEARCH_HOME/secrets/master" \
  --socket "$SEARCH_HOME/run/search.sock" \
  --url http://127.0.0.1:7700 \
  --output-dir "$SEARCH_HOME/credentials" \
  --indexes shopware_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
```

The helper's `managed_socket_search.py` import must be beside it even for a direct
TCP setup. The search key cannot write indexes, the writer is limited to assigned
published/building indexes, the separate status key reads version/global task
metadata. The master is never a plugin key.
Generated API keys have no automatic expiration; plan rotation/revocation using
private key IDs. See [security](security.md).

### 3. Give PHP only its application-key files

For model A, replace the example Unix account with the actual Shopware web/CLI
identity. Keep the raw master and systemd configuration root-only:

```sh
SHOP_USER=shopware
SHOP_GROUP="$(id -gn "$SHOP_USER")"
sudo install -d -o "$SHOP_USER" -g "$SHOP_GROUP" -m 0700 /etc/shopware-meilisearch-app
for role in search index status; do
  sudo install -o "$SHOP_USER" -g "$SHOP_GROUP" -m 0600 \
    "/etc/shopware-meilisearch/provisioned/$role" \
    "/etc/shopware-meilisearch-app/$role"
done
sudo -u "$SHOP_USER" test -r /etc/shopware-meilisearch-app/search
```

A `test -r` success produces no output. Mode `0600` is not sufficient if files
belong to root or an inaccessible parent hides them. If PHP and CLI have different
UIDs, arrange a supported common application identity or separate private copies
and process-specific configuration. Do not solve it with `chmod 644` or by moving
the master into the application directory.

For same-UID managed model B, the generated credential files can be used directly.
This does not isolate staging/production applications sharing that Unix account;
the provider's account isolation remains a separate constraint.

### 4. Configure Shopware and build its first index

For model A, add these paths to the shop's private environment configuration:

```dotenv
SHOPWARE_MEILISEARCH_URL=http://127.0.0.1:7700
SHOPWARE_MEILISEARCH_SEARCH_KEY_FILE=/etc/shopware-meilisearch-app/search
SHOPWARE_MEILISEARCH_KEY_FILE=/etc/shopware-meilisearch-app/index
SHOPWARE_MEILISEARCH_STATUS_KEY_FILE=/etc/shopware-meilisearch-app/status
```

For model B, use the corresponding managed-account paths instead:

```dotenv
SHOPWARE_MEILISEARCH_URL=http://127.0.0.1:7700
SHOPWARE_MEILISEARCH_SOCKET=/home/SHOP_USER/shopware-meilisearch/run/search.sock
SHOPWARE_MEILISEARCH_SEARCH_KEY_FILE=/home/SHOP_USER/shopware-meilisearch/credentials/search
SHOPWARE_MEILISEARCH_KEY_FILE=/home/SHOP_USER/shopware-meilisearch/credentials/index
SHOPWARE_MEILISEARCH_STATUS_KEY_FILE=/home/SHOP_USER/shopware-meilisearch/credentials/status
```

Protect the shop's environment files and regenerate its compiled environment if
one is used. A shell environment change alone need not update web PHP. Restore
mode `0600` on compiled environments; keep public assets readable by the web server.

Now continue at [plugin installation, step 3](installation.md#3-install-the-plugin-and-configure-its-environment):
install/activate the plugin, configure fields and language settings, and run the
first CLI index command while the overlay is still disabled. Only then enable
the overlay. Do not repeat credential provisioning into an existing output
directory. No public engine key or additional PHP Meilisearch SDK is required.

## Acceptance checks

Before considering the setup usable:

1. Confirm the service manager reports the engine running, its private health
   endpoint responds, and the API is not listening publicly.
2. Run the initial index command and `shopware-meilisearch:search:audit-index-fields`
   as the Shopware CLI user. Confirm all intended channel/language pairs succeed.
3. Open the actual storefront and search through the overlay. This exercises **web
   PHP**, its environment and credentials, which an SSH health check does not.
   Check exact product numbers, a broad query, filters, displayed prices and a
   purchasable product's cart action in each enabled language.
4. Configure the [scheduled-task runner and messenger consumer](operations.md#background-workers-are-required).
   Request a rebuild in the backend and verify its pending status clears and the
   successful timestamp advances. HTTP 202 means queued, not completed.
5. Test a small representative parallel workload, including users sharing an IP,
   while watching CPU, available RAM and request errors. The default admission
   concurrency is two; tune it deliberately using [configuration](configuration.md).
6. Restart the search service deliberately and confirm it recovers without an
   open SSH terminal. Check boot-time behavior during an appropriate maintenance
   window; do not reboot a shared production VPS just to follow this guide.

The task scheduler/consumer is separate from Meilisearch. A running search daemon
cannot execute queued Shopware rebuild requests. An unchanged pending status is
a worker/setup problem to investigate, not confirmation of a successful rebuild.

## Maintenance and common failures

| Symptom | Next check |
| --- | --- |
| Binary cannot start | Architecture, C runtime/loader, executable mount policy and file permissions |
| Service repeatedly restarts | Private logs, engine settings and provider/cgroup OOM events |
| Health works but indexing fails | Published index names, writer/status roles, PHP file ownership and compiled environment |
| SSH works but storefront fails | Web-PHP identity, loaded environment, socket traversal and namespace |
| Rebuild remains pending | Both scheduled-task dispatch and correct messenger consumer/transport |
| Search reports HTTP 429 | Global/IP/concurrency budgets; shared-IP traffic can exhaust a client budget |
| Staging results affect live | Stop sharing one instance with identical channel/language index names |

Inspect disk use periodically, including engine task data, and monitor available
memory during builds. Do not copy a live LMDB directory as an improvised backup.
Use supported snapshots/dumps or a controlled stopped-service backup, depending
on the selected version and recovery plan. Follow upstream migration instructions
when updating the binary; replacing an executable is not a universally safe
on-disk database upgrade/downgrade procedure. Test upgrades against a separate
instance before changing a live one.

After verifying installation, remove the ordinary user's download temporary
directory if no longer needed. Retain the installed binary, data, private secrets
and private key-ID records. For service removal, first deactivate the plugin and
coordinate its jobs; then stop only the identified engine instance and revoke its
keys. Never remove a shared data directory or unrelated provider daemon.

## Official references

- [Install Meilisearch locally](https://www.meilisearch.com/docs/resources/self_hosting/getting_started/install_locally)
- [Community and Enterprise editions](https://github.com/meilisearch/meilisearch#editions--licensing)
- [Pinned v1.56.0 release and artifacts](https://github.com/meilisearch/meilisearch/releases/tag/v1.56.0)
- [Self-hosted quick start](https://www.meilisearch.com/docs/resources/self_hosting/getting_started/quick_start)
- [Health endpoint](https://www.meilisearch.com/docs/reference/api/health/get-health)
- [Meilisearch updates and migrations](https://www.meilisearch.com/docs/resources/migration/updating)

Upstream quick starts may demonstrate development defaults, public container port
mappings or example master keys. Keep the local binding, separate random secrets
and process supervision required by this plugin's deployment model.
