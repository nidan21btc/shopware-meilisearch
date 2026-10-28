# Configuration reference

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Backend settings live under `ShopwareMeilisearch.config`. Use Shopware's normal
sales-channel inheritance deliberately: the selected channel determines which
settings the indexer and storefront see. Locale codes such as `de-DE` are different
from language UUIDs; JSON settings use locale codes, index names use UUIDs.

## Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `SHOPWARE_MEILISEARCH_URL` | `http://127.0.0.1:7700` | Loopback HTTP(S) origin; hostnames and redirects are rejected |
| `SHOPWARE_MEILISEARCH_SOCKET` | empty | Optional absolute Unix-socket path, at most 100 characters; HTTP only |
| `SHOPWARE_MEILISEARCH_KEY_FILE` | empty | Protected index-writer key file |
| `SHOPWARE_MEILISEARCH_SEARCH_KEY_FILE` | empty | Protected search-only key file |
| `SHOPWARE_MEILISEARCH_STATUS_KEY_FILE` | empty | Protected version/task-read key file |
| `SHOPWARE_MEILISEARCH_FEEDBACK_KEY_FILE` | empty | Independent keyed-feedback secret file |
| `SHOPWARE_MEILISEARCH_CONCURRENCY` | `2` | Installation-wide simultaneous computations; integer 1–10 |
| `SHOPWARE_MEILISEARCH_GLOBAL_RATE` | `2` | Global token refill rate in requests/second; integer 1–10 |
| `SHOPWARE_MEILISEARCH_CLIENT_RATE` | `2` | Per-IP short-term refill rate; integer 1–10 |

For credentials, the corresponding variable without `_FILE` accepts a direct
value. A configured file takes precedence. Prefer files to reduce accidental
exposure; these must be regular files, not symlinks, and grant no group/other
access. Do not reuse a master key in application variables.

The global bucket has capacity `max(8, 4 × GLOBAL_RATE)`. Each IP has a short
bucket of `3 × CLIENT_RATE` and a sustained bucket of `30 × CLIENT_RATE`, refilled
at `CLIENT_RATE / 2` per second. These are token buckets, not fixed per-minute
quotas. A new client can use an initial burst before the sustained rate applies.

All languages and sales channels share the same concurrency/global budget in an
installation. Devices sharing an internet connection also share the IP budget.
For example, values `10 / 10 / 10` allow ten simultaneous computations, a global
burst of 40 and an IP burst of 30, with a sustained IP refill of five/second.
This is a tuning example, not a default or a capacity guarantee. Measure the PHP
workers, database and host memory before using it. See [security](security.md).

## Administration fields

| Field | Format and effect | Rebuild required? |
| --- | --- | --- |
| `enabled` | Boolean; overlay is disabled by default | Build before first enabling |
| `indexFields` | Ordered JSON list of searchable fields | Yes |
| `indexFieldsByLanguage` | Locale → field-list overrides | Yes |
| `quantityUnits` | Unit UUID → symbol and conversion factor | Yes |
| `synonyms` | Locale → word → alternatives | Yes |
| `popularQueries` | Locale → list of suggested queries | No |
| `featuredProductIds` | Locale → up to ten product UUIDs | No |
| `popularityWeights` | Locale → product UUID → integer weight | Yes |
| `queryHints` | Locale → product UUID → reviewed query strings | Yes |
| `queryClickFeedback` | Versioned keyed query/product weights | No; secret must match |

The backend button **Suchindex jetzt neu aufbauen** requests a background build
for all enabled channels and their storefront languages. Save settings before
clicking. It does not run an expensive build inside the browser request.

## Searchable fields and language handling

The default ordered fields are:

```json
["productNumber", "ean", "manufacturerNumber", "name", "quantity", "keywords", "customSearchKeywords", "manufacturer", "properties", "categories", "description"]
```

Earlier fields receive priority within attribute ranking. `IndexFields::STANDARD`
is the complete allowlist; it also includes option labels, manufacturer
description, metadata, units, packaging and selected physical attributes.
Use `customFields.FIELD_NAME` for scalar custom-field content. Arbitrary entity
paths and Shopware-generated `searchKeywords` are rejected. Lists must contain
1–64 distinct supported fields. Blank configuration uses the defaults.

An example override is:

```json
{"de-DE": ["productNumber", "name", "customFields.example_de", "description"], "en-GB": ["productNumber", "name", "customFields.example_en", "description"]}
```

Language-local text is intentional; do not assume Shopware's normal fallback
chain will supply searchable text in another language. Parent translations and
variant labels are handled explicitly. An unnamed product requires its own
language-local searchable text or deliberately supplied hints to qualify for
natural-language discovery.

## Synthetic examples

Synonyms are separate by locale:

```json
{"de-DE": {"blume": ["blüte"]}, "en-GB": {"flower": ["bloom"]}}
```

Quantity conversion describes Shopware's stored unit. If a product's purchase
unit is stored in litres and should generate millilitre tokens:

```json
{"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa": {"symbol": "ml", "factor": 1000}}
```

Use the actual unit UUID and verify a known fixture; configuring a label alone
does not determine how the catalog stores quantities. Supported symbols are
`ml`, `l`, `g`, `kg`. Include `quantity` in the searchable field list.

Discovery suggestions:

```json
{"de-DE": ["blume"], "en-GB": ["flower"]}
```

An explicit empty locale list hides that locale's suggestions/featured defaults.
No merchant-specific suggestions or products are built in.

## Public request contract

The storefront route is `/shopware-meilisearch`, relative to the current language
storefront base URL. It returns an HTML fragment, not a public JSON search API.

| Parameter | Constraint |
| --- | --- |
| `q` | UTF-8, NFC-normalized, at most 150 characters |
| `page` | Clamped to 1–500; pages display 20 results |
| `sort` | `relevance`, `price-asc`, `price-desc`, `name-asc` |
| `manufacturer[]`, `category[]` | Up to 20 lowercase 32-character UUIDs each |
| `similar` | `1` enables supplemental matches; other values disable them |

`minPrice` and `maxPrice` are rejected; there is no public price-range filter.
The server selects a locale/query-dependent default for supplemental matches
when `similar` is absent. Malformed input returns HTTP 400. Overload admission
returns HTTP 429 and `Retry-After: 2`; service/limiter failures return HTTP 503.
