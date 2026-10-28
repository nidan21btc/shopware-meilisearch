# Local product search for Shopware

A Shopware 6.7 plugin providing a locally hosted multilingual search overlay backed by Meilisearch.

This repository version removes installation-specific defaults. Configure search fields, quantity units and discovery suggestions for your own shop. It has not yet been approved for production or public distribution.

The plugin is currently proprietary. Shopware icons are used through Shopware’s icon system.

## Configuration

The plugin requires a separately managed Meilisearch service and an explicitly
configured local endpoint and key. Optional UNIX-socket bridging is illustrated
in `deploy/`. Configure indexed fields per locale, including custom fields,
and define unit conversions and featured products for each installation.

The evaluation tools accept local input files. `import_doofinder.py` requires
explicit `--engine ID=LOCALE` mappings; URL mapping and synthetic comparison
require explicit `--host` arguments. Example data is synthetic. Do not commit
customer reports, keys, environment files, or generated evaluation outputs.

## Tests

The Python unit tests run with `python3 -m unittest discover -s tests -p
'test_*.py'`. Tests needing the Shopware dependencies accept
`SHOPWARE_ROOT=/path/to/shopware`; for example:

```sh
SHOPWARE_ROOT=/path/to/shopware php tests/run.php
SHOPWARE_ROOT=/path/to/shopware python3 -m unittest discover -s tests -p 'test_*.py'
```

Integration tests require an isolated test shop and may change its database or
indexes temporarily. Read each test's prerequisites before running it.
Quantity and reference-unit integration tests require an explicitly configured
fixture unit via `EL_TEST_LITRE_UNIT_ID`; field-audit tests can optionally check
an installation-specific custom field via `EL_TEST_CUSTOM_FIELD`. The real
cart-icon test requires `EL_TEST_QUERY` for a purchasable fixture product.
The sanitized general-purpose version still requires integration testing in a
separate shop before production use.
