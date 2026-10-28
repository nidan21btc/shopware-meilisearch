# Deployment decision: a separately managed search engine

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Status: current implementation, version 0.2.

Meilisearch is an external runtime requirement. The Shopware plugin does not
include an engine binary, download it at activation, start it from web PHP or
manage the daemon's lifecycle. It connects to an explicitly configured local
service, validates compatibility during indexing and owns its document/index
configuration. Composer declares the Shopware PHP requirements, not an operating
system process or binary package.

For concrete commands for both supported service models, see the
[Meilisearch installation guide](meilisearch-installation.md).

## Responsibility split

| Component | Responsibilities |
| --- | --- |
| Plugin | Catalog/language projection, ranking settings, scoped API calls, index publication, overlay and rebuild requests |
| Meilisearch service | Persistent search index, query execution and asynchronous engine tasks |
| Service manager/provider | Startup, restart after failure, resource limits, logs and service account/network restrictions |
| Operator | Compatible engine binary/version, secrets, patching, storage, worker setup, monitoring and backups |

This supports conventional servers and managed hosts where web PHP cannot create
long-running processes, download executables or share the daemon's loopback
namespace. The optional Unix-socket bridge addresses the last case without
publishing the engine through a public web route. Service examples in `deploy/`
provide an integration path; they are not a universal installer.

Separating the service also permits engine security updates and resource-limit
changes without shipping a new plugin version. It avoids executing an unknown
binary as a side effect of plugin activation. It does not remove the operator's
responsibility to test API compatibility and allocate enough memory.

## Installation artifacts

The plugin distribution contains PHP, Twig, CSS/JavaScript, migrations and
administration assets. A deployment can additionally copy the generic Python
launcher/bridge and choose a service template. The Meilisearch executable, optional
loader/libraries, data directory, credentials and actual service-manager entry
are installation artifacts outside the plugin repository.

On a managed VPS, the operator provisions a compatible executable and protected
paths, adapts the startup script and registers it as a supervised foreground
service in the provider's interface. Enabling the Shopware plugin alone will not
create that service. A staging search daemon can remain installed while a local
disposable integration shop and its own engine are removed.

## Possible future packaging

A Docker Compose example or host-specific provisioning package could install and
configure both components together while retaining separate services. Such a
package would need pinned/verified downloads or images, persistent volumes,
credential provisioning, health checks, resource limits, upgrade/rollback steps
and an explicit supported-platform policy.

That packaging is not included or promised in 0.2. Direct binary bundling and
automatic web-triggered downloads are not the current installation model.
