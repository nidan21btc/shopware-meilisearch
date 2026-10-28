# Architecture and implementation map

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

## Request flow

1. Twig extensions in `storefront/base.html.twig` and `storefront/layout/meta.html.twig`
   add the enabled overlay and local CSS/JavaScript assets to the storefront.
   Product templates reference Shopware's icon system.
2. `search.js` opens the dialog, remembers the latest query in tab-local
   `sessionStorage` per locale/endpoint, and sends same-origin HTML requests after
   a 300 ms typing debounce. It aborts superseded browser requests and discards
   stale responses. Aborting a browser fetch does not cancel work already in PHP.
3. `SearchRequestGuard` runs early on the request, before expensive sales-channel
   resolution, and checks bounded global/IP token state and OS-lock slots.
   The controller also checks admission as a fallback. A slot remains held until
   the request finishes or its worker exits.
4. `SearchController` checks channel enablement and validates input. `SearchService`
   obtains candidate IDs from a language/channel-specific Meilisearch index and
   resolves current eligibility, names, filters and prices through Shopware's
   sales-channel product repository and customer context.
5. Twig renders product cards, manufacturer/category filters, result status and
   pagination. Cart actions use Shopware's storefront behavior. Errors expose no
   engine credential or internal exception text to the customer.

A search candidate is not authorization to display a product. The Shopware
availability/visibility checks remain necessary even when the index is current.

## Ranking and language

`DocumentBuilder` emits only configured searchable text plus required IDs and
filter/ranking attributes. It strips HTML script/style content and normalizes
visible text. `LanguageText`, `ParentTranslations` and variant-label helpers
control language-local content rather than broadly importing fallback text.

Meilisearch ranking prioritizes words, typos, proximity, attribute priority,
exactness and requested sorting. Identifiers and quantities
have special exact-match behavior. Additional helpers handle Unicode/orthography,
hyphen compounds, quantity representations, named variants and optional partial
matches. `IdentifierFallback` supplements exact identifier matches through
Shopware when necessary. `SearchService` applies these rules and current
Shopware eligibility rather than returning the engine's response directly.

Historical clicks used for evaluation are not independent ground truth; see
[evaluation](evaluation.md).

At most 5,000 candidate IDs are processed per search, including supplemental
hits. Meilisearch is configured with a 250 ms search cutoff and 5,000 maximum
hits. This bounds a stage of the work, not the whole PHP request. Broad queries,
current-price sorting and facet calculations can still involve significant DAL
work. Truncated candidate sets must not be presented as exhaustive catalog recall.

## Index construction

`IndexCommand` enumerates active sales-channel domains and constructs their
language contexts. `Indexer` obtains eligible products in ID-ordered batches of
200. For each channel/language pair it locks the index, creates a `_building`
index, applies settings and waits for document tasks to complete. It atomically
swaps the finished index with the published name, then deletes the old generation.

The search/read key can access only published names. A failed build before the
swap leaves the previous published generation available. An interrupted build
may leave `_building` behind; the next locked rebuild replaces it. A build spans
multiple database queries, not a database-wide immutable snapshot.

## Daily and manual updates

`IndexInvalidationSubscriber` records relevant catalog/configuration changes in
`shopware_meilisearch_state`. Monotonic revisions avoid acknowledging changes
that arrived during a rebuild. Product writes no longer cause an immediate
rebuild: a successful full background build is due again after 24 hours.

`ReindexTask` checks every 60 seconds through Shopware's scheduled-task system.
`ReindexTaskHandler` uses an installation-wide lock, checks daily/manual state,
records the attempt, then invokes the index command for enabled channels. The
request response does not perform this work. Failures have at least a 15-minute
backoff. An initial build is due when no successful timestamp exists.

The administration component calls authenticated GET/POST routes at
`/api/_action/shopware-meilisearch/reindex`. POST increments the manual request
revision only when no manual request is pending. A successful handler acknowledges
the request revision it observed; repeated clicks coalesce. A pending request
requires the scheduled-task runner and messenger consumer to make progress.
A direct CLI index run publishes indexes but does not itself acknowledge the
background handler's revision state.

## Source map

| Path | Responsibility |
| --- | --- |
| `src/Controller/` | Storefront HTML endpoint and authenticated rebuild actions |
| `src/Security/` | Admission control and protected secret loading |
| `src/Search/SearchService.php` | Search orchestration, eligibility, facets, sorting |
| `src/Search/DocumentBuilder.php`, `Indexer.php`, `Engine.php` | Documents, publication and local HTTP transport |
| `src/Search/IndexFields.php`, `LanguageText.php`, `QuantityUnits.php` | Configuration and text/quantity handling |
| `src/Subscriber/` | Catalog/configuration change tracking |
| `src/ScheduledTask/`, `src/Migration/` | Background execution and persistent state schema |
| `src/Command/` | Explicit indexing, field audits and reviewed signal imports |
| `src/Resources/views/`, `public/storefront/` | Overlay templates, styles and JavaScript |
| `src/Resources/app/administration/`, `public/administration/` | Backend component and deployable copy/entrypoint manifest |
| `deploy/` | Generic service, bridge and credential-provisioning examples |
| `tests/`, `tools/` | Regression checks and private-data evaluation tooling |

The administration source is dependency-free and its deployable JavaScript copy
is committed intentionally; keep both identical when changing it. Its Vite
entrypoint manifest is required for Shopware to load the backend component.
