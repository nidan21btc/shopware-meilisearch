# Shopware Meilisearch

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
| [Installation](docs/installation.md) | Requirements, service setup, credentials, installation and upgrades |
| [Configuration](docs/configuration.md) | Environment variables, backend settings, fields and synthetic examples |
| [Architecture](docs/architecture.md) | Request flow, ranking, language handling, indexing and code map |
| [Deployment decision](docs/deployment-decision.md) | Why Meilisearch remains a separately managed runtime requirement |
| [Operations](docs/operations.md) | Daily/manual rebuilds, workers, monitoring, recovery and removal |
| [Security and privacy](docs/security.md) | Trust boundaries, limits, residual risks and publication rules |
| [Testing and development](docs/testing.md) | Unit, integration and browser tests, fixtures and cleanup |
| [Search evaluation](docs/evaluation.md) | Import/review tools, relevance measures and comparison limitations |
| [Changelog](CHANGELOG.md) | Changes in the current version |

## Getting started

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

## Status and license

This is an experimental, proprietary plugin. It has been exercised in isolated
and staging environments; that does not certify every Shopware installation,
production readiness or equal relevance to another search provider. Deployment
scripts are examples requiring adaptation. Automatic/manual background indexing
requires a working scheduled-task runner **and** queue consumer.

There are no bundled merchant defaults or real evaluation datasets. Configure
fields, units, suggestions and optional signals for each installation. Keep shop
exports, credentials, benchmark outputs and screenshots outside this repository.

Shopware icons are referenced through Shopware's icon system. Doofinder is used
as the name of an optional data-import source; no Doofinder icon or runtime is
bundled. Publication remains subject to license and third-party rights review.
