# Search-quality evaluation tools

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

The tools operate on explicitly supplied private inputs; merchant reports and
results are not repository fixtures. They do not provide a legal basis to reuse
exported data or prove that the candidate is as relevant as a reference service.

## Workflow and code map

| Tool(s) in `tools/` | Purpose |
| --- | --- |
| `import_doofinder.py` | Normalize search-term, no-result and product CSV exports with explicit engine-to-locale mapping |
| `export_product_paths.php`, `map_product_references.py` | Associate private reference URLs/identifiers with catalog products |
| `build_popularity.py`, `build_query_hints.py`, `build_reviewed_hints.py` | Prepare optional language-specific reviewed ranking inputs |
| `build_query_click_feedback.py` | Generate keyed exact-query/product signals; development partition is the default |
| `capture_shopware.py`, `evaluate_shopware.py`, `evaluate_meilisearch.py` | Capture/evaluate candidate responses through their respective interfaces |
| `compare_snapshots.py`, `compare_synthetic.py` | Compare rankings and synthetic query cases |
| `evaluate_catalog_recall.py`, `evaluate_prefix_recall.py`, `evaluate_typo_tolerance.py`, `evaluate_umlaut_queries.py` | Focused recall, prefix and orthography checks |
| `evaluate_broad_latency.py` | Broad-query latency diagnostics |
| `review_search_results.py`, `review_zero_results.py`, `translation_impact.py`, `prioritize_facet_translations.py` | Review and language-coverage diagnostics |
| `evaluate_judged.py` | Weighted relevance comparison from independently judged result pools |
| `private_output.py`, `local_http.py` | Protected output creation and local-only HTTP helpers |

Use each script's `--help` and read its input schema. Engine IDs, locale mappings,
shop hosts, index mappings and paths are explicit rather than merchant defaults.
Some helpers invoke Shopware code; an explicit test/root path is still required.

Example import, with synthetic engine identity and private files:

```sh
python3 tools/import_doofinder.py \
  --terms /private/input/terms.csv \
  --no-results /private/input/no-results.csv \
  --products /private/input/products.csv \
  --engine synthetic-engine=de-DE \
  --output /private/output/benchmark.json
```

Generated hints/weights must be reviewed before importing through:

```sh
php bin/console shopware-meilisearch:search:import-signals \
  --sales-channel aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
  --query-hints /private/output/reviewed-hints.json
```

Optional `--popularity` and `--query-clicks` accept the corresponding JSON.
`--merge-query-hints` and `--merge-query-clicks` retain existing reviewed inputs.
Hints/popularity require an index rebuild; keyed exact-query feedback does not.

The click-feedback builder's legacy default key environment name is
`SHOPWARE_MEILISEARCH_KEY`. For current deployments explicitly pass
`--key-env SHOPWARE_MEILISEARCH_FEEDBACK_KEY`, populated securely from the same
feedback secret the plugin uses. The builder does not automatically read the
plugin's `_FILE` setting. Do not inadvertently use the writer key, expose the
secret in command arguments or replace it with a public hash.

## Objective measures and their limits

Agree on acceptance criteria before using the evaluation output as a release
gate. Report language-specific coverage and both narrow and broad searches;
a single blended score can hide language regressions.

`evaluate_judged.py` accepts a confidential JSON object with a `cases` list.
Every case includes `locale`, positive `weight`, `judgments` (product ID → grade
0–3), and unique `reference`/`candidate` result-ID lists. The first ten results of
both systems must all be judged. Example with synthetic IDs:

```json
{"cases":[{"locale":"en-GB","weight":1,"judgments":{"sample-a":3,"sample-b":0},"reference":["sample-b","sample-a"],"candidate":["sample-a","sample-b"]}]}
```

```sh
python3 tools/evaluate_judged.py /private/input/judged-cases.json
```

It reports weighted nDCG@10, Hit@5 using grade ≥2, overall empty-result rate and
empty-result rate where a relevant match is known. nDCG's ideal ranking is limited
to the judged pool, not every product in existence. Cases without known relevant
products are excluded from relevant denominators and counted explicitly. The
tool prints aggregates, not query text, and does not calculate a confidence
interval or automatically accept a release.

Reference-ranking overlap, historical clicked-product recall and synthetic typo
recall answer different questions. Clicks are biased by the previous ranking;
high overlap need not imply relevance. Never evaluate a claimed independent
quality improvement solely on the same query/click data used to create hints.
Use a held-out time period or separately judged queries where available, and
state clearly when neither is available. Avoid claiming universal parity from
historical fitting or a small synthetic sample.

Measure end-to-end storefront latency separately from engine processing time,
including error/429 rates and response-time percentiles under the agreed load.
Record catalog size, version, configured limits, cache state and whether indexing
was active in a private report. Do not publish that report by default.
