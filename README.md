# Shopware Meilisearch

> [!WARNING]
> **EXPERIMENTELL · KEINE GEWÄHRLEISTUNG · KEIN SUPPORT**
>
> Dieses Plugin ist experimentell und nicht als produktionsreif freigegeben.
> Die Nutzung erfolgt auf eigenes Risiko. Es wird im vorliegenden Zustand
> ("as is") und ohne Gewährleistung bereitgestellt, soweit gesetzlich zulässig.
> Es gibt keinen Support und keinen Anspruch auf Wartung, Updates oder Fehlerbehebungen.
>
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This experimental plugin is not approved for production use. Use it at your own
> risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Local multilingual product search for Shopware 6.7, with a responsive full-screen
storefront overlay and a separately hosted Meilisearch service. Version **0.2.0**.

The browser talks to Shopware. Shopware resolves current product visibility,
availability and prices and queries a local search index. No external search
provider, remote JavaScript package or cloud language model is required.

Features include typo tolerance, configurable language-specific fields and
synonyms, manufacturer/category filters, variants and quantity matching, optional
reviewed historical signals, and retaining the latest query within the browser
tab. Product cards use Shopware's cart and icon system. Voice input is offered
only when the browser exposes an explicit local speech-recognition capability;
there is no cloud fallback.

## Documentation

| Guide | Contents |
| --- | --- |
| [Start here: plugin installation](docs/installation.md) | Complete operator walkthrough from prerequisites/package to enabled search and verified background rebuilds |
| [Compatibility and capacity](docs/compatibility.md) | Declared constraints, recorded versions, storefront scope and limits |
| [Plugin package](docs/packaging.md) | Reviewed source checkout, installable ZIP layout and transfer checks |
| [Meilisearch installation](docs/meilisearch-installation.md) | Linux VPS/managed-hosting engine service, protected keys and connection tests |
| [Background jobs](docs/background-workers.md) | Existing workers, systemd/provider/cron examples and completion checks |
| [Acceptance checklist](docs/acceptance-checklist.md) | Expected functional, isolation, background and capacity results |
| [Configuration](docs/configuration.md) | Environment variables, backend settings, fields and synthetic examples |
| [Architecture](docs/architecture.md) | Request flow, ranking, language handling, indexing and code map |
| [Deployment decision](docs/deployment-decision.md) | Why Meilisearch remains a separately managed runtime requirement |
| [Operations](docs/operations.md) | Daily/manual rebuilds, workers, monitoring, recovery and removal |
| [Security and privacy](docs/security.md) | Trust boundaries, limits, residual risks and publication rules |
| [Testing and development](docs/testing.md) | Unit, integration and browser tests, fixtures and cleanup |
| [Search evaluation](docs/evaluation.md) | Import/review tools, relevance measures and comparison limitations |
| [Changelog](CHANGELOG.md) | Changes in the current version |

## Getting started

Follow [Start here: plugin installation](docs/installation.md) in order. Installing
this package requires a self-hosted classic Shopware Storefront, local engine
service and deployment/worker access; a backend ZIP upload alone is insufficient.

Prepare an isolated Shopware installation and a supervised local Meilisearch
service. Provision separate search, index and status credentials, configure their
protected file paths, install the plugin and build the indexes. Enable the overlay
only after a successful build. Follow the [installation guide](docs/installation.md)
for the command order; a service URL alone is insufficient.

Run the dependency-backed checks from the repository root:

```sh
SHOPWARE_ROOT=/path/to/shopware php tests/run.php
SHOPWARE_ROOT=/path/to/shopware php tests/search_guard.php
SHOPWARE_ROOT=/path/to/shopware python3 -m unittest discover -s tests -p 'test_*.py'
```

Integration tests require disposable databases, indexes and synthetic accounts.
Some modify data; read [the test guide](docs/testing.md) before running them.

## Security reports

Please use the private reporting process described in [SECURITY.md](SECURITY.md)
for vulnerabilities. Do not post sensitive details in public issues.

## Status and license

This plugin is available under the [MIT License](LICENSE), including its
documentation, tests and deployment/evaluation tools. Copyright and license
notices must be retained when redistributing copies or substantial portions.

It remains experimental. It has been exercised in isolated and staging
environments; that does not certify every Shopware installation,
production readiness or equal relevance to another search provider. Deployment
scripts are examples requiring adaptation. Automatic/manual background indexing
requires a working scheduled-task runner **and** queue consumer.

There are no bundled merchant defaults or real evaluation datasets. Configure
fields, units, suggestions and optional signals for each installation. Keep shop
exports, credentials, benchmark outputs and screenshots outside this repository.

Shopware icons are referenced through Shopware's icon system. Third-party
components retain their own licenses; this MIT grant does not relicense them
or grant trademark rights. Review third-party rights before public distribution.
