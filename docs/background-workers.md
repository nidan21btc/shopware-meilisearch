# Configure Shopware background jobs

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

A Meilisearch process only serves the index. Daily and manually requested rebuilds
also need Shopware's **scheduled-task dispatcher** and **messenger consumer**.
A functioning storefront search does not prove those jobs are running.

Use the installation's existing workers whenever possible. These are general
Shopware services, not private queues belonging exclusively to this plugin.
A consumer may process emails, integrations and other queued messages. Verify
staging mail/payment/integration settings before starting one on a copied shop.
Do not create duplicate services or multiple cron/daemon systems accidentally.

## 1. Identify the installation and transport

Use the same PHP binary, environment, application user and current-release path
as web PHP and your ordinary deployments. From the Shopware root:

```sh
APP_ENV=prod APP_DEBUG=0 /usr/bin/php bin/console debug:messenger
APP_ENV=prod APP_DEBUG=0 /usr/bin/php bin/console scheduled-task:list
```

Replace `/usr/bin/php` if the provider supplies a different runtime. Inspect the
actual messenger configuration to identify the receiving transport(s). The
examples below use `async low_priority`; do not assume these names exist in every
shop. A worker consuming the wrong transport does not execute the plugin's task.
Do not print environment/DSN values into public diagnostics.

After plugin activation, if its task is absent, register the installation's
currently defined tasks, then list them again:

```sh
APP_ENV=prod APP_DEBUG=0 /usr/bin/php bin/console scheduled-task:register
APP_ENV=prod APP_DEBUG=0 /usr/bin/php bin/console scheduled-task:list
```

`shopware.meilisearch.reindex` should exist with a 60-second polling interval.
That interval is a lightweight check: a successful full background build is due
again after 24 hours, or earlier when explicitly requested. Registering/listing
tasks does not execute pending messages and does not prove the consumer works.

## 2A. Root-managed Linux services

Skip this section if your host already operates equivalent workers. Otherwise,
review the two example units under `deploy/` and adapt them before enabling:

| Template | Role |
| --- | --- |
| `shopware-scheduled-tasks.service.example` | Dispatch due tasks |
| `shopware-messenger.service.example` | Consume the actual Shopware transports |

Change `User`, `Group`, `WorkingDirectory`, the PHP path, transport names and
memory/stop-time limits to match the installation. Example paths/users are
placeholders, not values detected by the plugin. Keep the units under root
control and application keys private to the application identity.

```sh
sudo install -o root -g root -m 0644 \
  "$PLUGIN_SOURCE/deploy/shopware-scheduled-tasks.service.example" \
  /etc/systemd/system/shopware-scheduled-tasks.service
sudo install -o root -g root -m 0644 \
  "$PLUGIN_SOURCE/deploy/shopware-messenger.service.example" \
  /etc/systemd/system/shopware-messenger.service
sudoedit /etc/systemd/system/shopware-scheduled-tasks.service
sudoedit /etc/systemd/system/shopware-messenger.service
sudo systemd-analyze verify \
  /etc/systemd/system/shopware-scheduled-tasks.service \
  /etc/systemd/system/shopware-messenger.service
sudo systemctl daemon-reload
sudo systemctl enable --now shopware-scheduled-tasks.service shopware-messenger.service
sudo systemctl status --no-pager shopware-scheduled-tasks.service shopware-messenger.service
```

Set `PLUGIN_SOURCE` to the full repository or extracted package location first.
The examples intentionally use `Restart=always`: the commands exit normally at
their configured time limits and still need to restart. `Restart=on-failure`
alone would not keep such short-lived successful workers running indefinitely.

The consumer examples use a 384 MiB PHP/worker limit and a 512 MiB service cap;
the dispatcher uses a 256 MiB service cap. These are tuning examples, not measured
requirements for every shop. Budget them **in addition to** the engine and other
application processes. Symfony's time/memory stopping checks are not a guaranteed
hard deadline for a message already being handled. Allow a rebuild to finish
gracefully during service shutdown; set `TimeoutStopSec` for the longest expected
message and inspect OOM records when processes are killed.

Do not set an owner-only umask globally on a general Shopware worker: other jobs
may generate public assets/media. Private plugin locks/keys have their own
permissions. The example units retain `UMask=0022` for normal application output.

To inspect failures:

```sh
sudo journalctl -u shopware-scheduled-tasks.service -n 30 --no-pager
sudo journalctl -u shopware-messenger.service -n 50 --no-pager
```

Keep log contents private. Service status "running" proves a process exists;
complete the end-to-end verification below too.

## 2B. Managed hosting with a daemon facility

Reuse provider-supplied Shopware workers where available. If you must register
workers yourself, create **two foreground targets**, separate from the Meilisearch
engine target. Use the provider's correct PHP executable, application UID,
restart policy and resource limits. A clean exit at `--time-limit` must restart,
not only a crash.

Example dispatcher target script:

```sh
#!/bin/sh
set -eu
cd /path/to/shopware/current
exec /path/to/php bin/console scheduled-task:run --time-limit=60 --env=prod --no-debug
```

Example consumer target script:

```sh
#!/bin/sh
set -eu
cd /path/to/shopware/current
exec /path/to/php -d memory_limit=384M bin/console messenger:consume \
  async low_priority --time-limit=300 --memory-limit=384M --env=prod --no-debug
```

Replace every path/transport before making each script executable by its owner.
Keep scripts outside the public directory. The provider must allow enough runtime
and memory for an actual message/build, not just for launching the worker. Do not
register a bare `async low_priority ...` as a command: those are arguments to
`messenger:consume`.

## 2C. Cron when supported by the provider

If long-lived workers are unavailable but sufficiently long cron jobs are
supported, the dispatcher can run once each minute without waiting. The consumer
can start periodically with an OS lock preventing overlap. The following are
**crontab entries**, not terminal commands; replace paths, identity and transports:

```cron
* * * * * cd /path/to/shopware/current && /usr/bin/flock -n var/shopware-task-dispatcher.cron.lock /path/to/php bin/console scheduled-task:run --no-wait --env=prod --no-debug >> var/log/task-dispatcher-cron.log 2>&1
* * * * * cd /path/to/shopware/current && /usr/bin/flock -n var/shopware-messenger.cron.lock /path/to/php -d memory_limit=384M bin/console messenger:consume async low_priority --time-limit=55 --memory-limit=384M --env=prod --no-debug >> var/log/messenger-cron.log 2>&1
```

Use only if `flock` exists, `var/` is writable by the application account and the
provider permits the full message duration. A 55-second consumer time limit does
not imply an ongoing rebuild stops at 55 seconds; the lock remains held until
the process exits. A provider that forcibly kills every job after a short timeout
cannot safely use this as a substitute for a suitable worker facility. Arrange
rotation for the private cron logs. Do not run these entries alongside duplicate
provider/systemd consumers unintentionally.

## 3. Verify a complete backend-requested rebuild

1. Ensure the overlay is enabled for at least one intended sales channel and the
   initial indexes are already built. Save the plugin configuration.
2. In plugin settings, note **Letzter erfolgreicher Neuaufbau**, then click
   **Suchindex jetzt neu aufbauen**. The API's HTTP 202 means accepted only.
3. Confirm `shopware.meilisearch.reindex` is registered and the dispatcher and
   correct consumer are active. Wait for the polling interval and build time.
4. Click **Status aktualisieren**. The pending message should clear and the
   successful timestamp should advance. The displayed timestamp is UTC.
5. Verify a deliberately changed searchable fixture appears in the storefront.

The backend controls need `system_config:read` and `system_config:update`; a
restricted user may not be authorized. The German backend help text assumes
workers are operational; it does not create them. A direct CLI index run does
not acknowledge the queued manual request or the background state timestamp.

For private diagnostics, this read-only SQL distinguishes plugin state from
Shopware's general scheduled-task status:

```sql
SELECT indexed_at, attempted_at,
       requested_revision > completed_request_revision AS manual_pending
FROM shopware_meilisearch_state WHERE id = 1;

SELECT name, status, run_interval, last_execution_time, next_execution_time
FROM scheduled_task WHERE name = 'shopware.meilisearch.reindex';
```

Do not edit these tables to fake completion. `queued` with no progress suggests
a missing/wrong consumer; an attempt followed by failure requires inspecting its
private log and engine state. Failed attempts have at least a 15-minute backoff.
A stopped/disabled scheduler or consumer can leave a request pending indefinitely.
Do not repeatedly force-reschedule a message while an existing rebuild may still
be running. For catalog/configuration troubleshooting see [operations](operations.md).

## Deployments and restarts

Restart the installation's supervised workers after changing plugin source or
its compiled environment so long-running processes load the new code/keys. Use
normal graceful deployment procedures; do not kill an active build simply to
clear a pending flag. Do not flush unrelated queues or replay failed messages
without reviewing their effects. Keep workers tied to the correct current-release
path and separate staging/live databases and credentials.

Further reading: [Shopware message queue](https://developer.shopware.com/docs/guides/hosting/infrastructure/message-queue.html)
and [scheduled tasks](https://developer.shopware.com/docs/guides/hosting/infrastructure/scheduled-task.html).
