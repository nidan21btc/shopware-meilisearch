# Compatibility, scope and capacity

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

## Recorded validation baseline

This separates declared constraints from recorded checks. It is not a general
support promise or certification matrix.

| Component | Constraint / model | Recorded baseline |
| --- | --- | --- |
| Shopware Core and Storefront | Composer `~6.7.0` | Dependencies and isolated checks on 6.7.10.2 |
| PHP | A runtime/extensions supported by the selected Shopware version | PHP 8.4.27 for the local regression environment |
| Meilisearch | Runtime check accepts 1.x from 1.9.0 | Index/search/service checks with 1.56.0 Community Edition |
| Python | Python 3 standard library for optional deployment/evaluation helpers | Python 3.13.5 for the local tool test environment |
| Browser | Classic storefront with modules, fetch, AbortController and native HTML dialog | Selected Chromium regressions and a Firefox/Chromium credentialed-page regression; no exhaustive browser/version matrix |
| Deployment | One Shopware server, local lock storage, loopback TCP or protected Unix socket | Isolated and managed-hosting staging exercises |

A declared range is not evidence that every version in it works. Verify your
specific Shopware patch, PHP runtime, Meilisearch binary, theme and hosting
combination before considering a live rollout. Shopware 6.6/6.8 and Meilisearch
2.x are not declared compatible by the current package. The examples pin an
exercised engine version but do not replace upstream security-release review.

PHP requires the normal Shopware extensions, especially `intl`, `mbstring`,
`curl`, JSON and PDO MySQL. The plugin uses Shopware's existing PHP dependencies;
there is no separate PHP Meilisearch SDK or npm build required for the packaged
plugin. Git is needed only for source checkout/packaging. Node/Playwright are
development/browser-test dependencies, not production requirements.

## Storefront and language scope

This is a PHP plugin for a self-hosted classic Shopware Storefront. It is not a
Shopware SaaS app and does not add a headless frontend or replace the Store API's
search routes. Its overlay binds to `.search-toggle-btn` and
`.header-search-input`. Themes replacing these elements or suppressing the
extended Twig blocks need their own integration. Check your actual header,
mobile navigation, CSP and cart behavior; compatibility with every theme is not
claimed. The overlay uses local scripts/assets and Shopware's icon/cart system.

Storefront snippets are included for `de-DE`, `en-GB`, `es-ES`, `fr-FR` and `it-IT`.
Backend configuration/rebuild controls currently use German labels. Other locales
need appropriate snippets and explicit evaluation of their language behavior;
they are not implicitly covered by the five-language checks. Language-specific
search text intentionally does not broadly inherit another language's content.

The latest query is remembered per tab/locale/endpoint using `sessionStorage`,
not synchronized across browsers or devices. Browser storage restrictions are
handled without disabling search. Local speech recognition is optional and is
hidden on unsupported browsers; ordinary typed search does not require it.

## Catalog and capacity limits

Each query processes at most **5,000 candidate IDs**, including supplemental
matches. This is not a limit on how many products can be indexed, but a broad
query exceeding the cap cannot promise exhaustive results, sorting or facet
coverage. Evaluate larger catalogs and broad-match scenarios before use; raising
the constant is not an administrator capacity-tuning substitute.

The Meilisearch cutoff is 250 ms, not an end-to-end PHP timeout. Shopware must
still resolve current visibility, availability, customer prices and facets.
Default admission is two concurrent computations, with additional global/IP
budgets. Shared-IP users consume one client budget. See [configuration](configuration.md)
for formulas, configurable ranges and measured tuning rather than simply disabling
limits when a client sees an error.

There is no universal RAM/disk minimum or throughput guarantee derived from the
current tests. Budget the engine, PHP/CLI consumers, database, web workers and
filesystem cache together; measure during rebuilds as well as normal traffic.
The service's indexing-memory setting is not its complete memory footprint.

Use a separate engine instance for each independently copied staging/live shop.
Multiple web servers or a network filesystem require a separately designed shared
admission/locking arrangement. Remote search URLs are intentionally rejected by
the current transport. These deployment changes are outside the single-server
recipe.
