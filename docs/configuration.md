# Configuration reference

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Open the configuration from **Settings → Shop → Meilisearch search** or from the
extension list. Both links open the same settings, subject to Shopware plugin
maintenance permission. Backend settings live under `ShopwareMeilisearch.config`. Use Shopware's normal
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

| Backend label | Configuration key | Format and effect | Rebuild required? |
| --- | --- | --- | --- |
| Suchoverlay aktivieren | `enabled` | Boolean; disabled by default | Build before first enabling |
| Durchsuchbare Indexfelder | `indexFields` | Ordered list of searchable fields | Yes |
| Abweichende Indexfelder je Sprache | `indexFieldsByLanguage` | Locale → field-list overrides | Yes |
| Mengeneinheiten für die Suche | `quantityUnits` | Unit UUID → symbol and conversion factor | Yes |
| Synonyme je Sprache | `synonyms` | Locale → word → alternatives | Yes |
| Suchvorschläge je Sprache | `popularQueries` | Locale → list of suggested queries | No |
| Startprodukte je Sprache | `featuredProductIds` | Locale → up to ten product UUIDs | No |

Upgrading from a version with `queryHints`: that setting is no longer used or
imported. Review and transfer any needed terms to the appropriate Shopware
product search keywords before rebuilding. The old stored configuration is not
automatically deleted; a successful rebuild removes those hints from the index.
The former `queryClickFeedback` setting is also ignored; existing values are not
automatically deleted. Click-based reranking stops immediately after upgrading. The former
`popularityWeights` setting is ignored and no longer imported. A rebuild removes
the old popularity ranking from the index; stored configuration is retained.

The backend button **Suchindex jetzt neu aufbauen** requests a background build
for all enabled channels and their storefront languages. Save settings before
clicking. It does not run an expensive build inside the browser request.

### Selecting fields, units, synonyms, suggestions and featured products

Select a sales channel first. A channel can inherit a global setting or override
it; for example, the overlay can be off globally and on for one channel. Editing
an inherited field requires switching it to a channel-specific value through
Shopware's inheritance control. Save settings before requesting a rebuild.

- **Durchsuchbare Indexfelder:** Select standard fields or existing custom fields and click **Hinzufügen**,
  then use the arrows to order them. Higher entries have higher attribute-ranking
  priority. Empty saved configuration means the standard selection; it is not
  written merely by opening the screen. The reset button restores that default.
  Custom fields must be assigned to products; only scalar, language-local content
  is indexed. The selection is limited to 64 distinct supported fields.
- **Abweichende Indexfelder je Sprache:** Pick a locale and create an override
  only when necessary. Unlisted locales use the general fields. A new override
  starts with the standard fields and can be reordered or removed. Existing other
  locales remain unchanged. Languages are selected from those configured in Shopware.
- **Mengeneinheiten für die Suche:** Select an existing Shopware unit, a search
  symbol and a factor, then add it. The factor is the number of search symbols
  in one stored Shopware unit: litre → `l` and millilitre → `ml` use factor `1`.
  An unambiguous unit short code is suggested; confirm it before adding. Remove
  and re-add a mapping to change it. Keep `quantity` among the searchable fields.
  Empty configuration disables structured quantity derivation, not ordinary search.
- **Synonyme je Sprache:** Choose a configured language, enter a search term and
  comma-separated alternatives, and add them. Save and rebuild the index.
- **Suchvorschläge je Sprache:** Select a language and enter one suggestion per
  line. A maximum of ten non-empty suggestions per language can be saved; each
  may have at most 150 characters. An empty list hides suggestions for that
  language. Suggestions take effect without rebuilding the index.

- **Startprodukte je Sprache:** Choose a language and add up to ten products
  using Shopware’s product selector. Remove products individually; other
  languages remain unchanged. Only products currently visible in the sales
  channel can appear. Save changes; a rebuild is not required.

The controls preserve the existing JSON storage format. Opening a control does
not rewrite values. If an existing value cannot be interpreted safely, the UI
shows it for correction instead of silently replacing it. Before the extension
configuration form saves, it validates all loaded sales-channel scopes, including
language codes against configured Shopware languages. This is an administration
form check; other configuration-writing paths must validate their own inputs. Index fields, locale
overrides and unit mappings require a successful rebuild after saving. More
synonyms are edited through the language-specific administration control.

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
