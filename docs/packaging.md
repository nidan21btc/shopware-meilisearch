# Obtain and package the plugin

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Use a reviewed source revision or an explicitly published plugin package. A
version in `composer.json` does not by itself identify a particular Git commit.
Record both the source commit and package checksum in your private deployment
record. This guide does not assume a Shopware Store listing, Packagist package
or published GitHub release asset exists. Access to a private repository requires
the repository owner's authorization until it is made public.

## Prepare a source checkout

If you already have the reviewed checkout, skip cloning. Otherwise replace the
repository URL, local path and revision below with the intended values. Use your
normal Git authentication; do not place access tokens in the URL or documentation.

```sh
PLUGIN_REPOSITORY_URL=https://github.com/OWNER/shopware-meilisearch.git
PLUGIN_SOURCE=/path/to/shopware-meilisearch
PLUGIN_REVISION=REPLACE_WITH_REVIEWED_TAG_OR_FULL_COMMIT
git clone -- "$PLUGIN_REPOSITORY_URL" "$PLUGIN_SOURCE"
git -C "$PLUGIN_SOURCE" checkout --detach "$PLUGIN_REVISION"
git -C "$PLUGIN_SOURCE" status --short
git -C "$PLUGIN_SOURCE" rev-parse HEAD
```

Expected: a clean checkout of the intended revision. Do not package unreviewed
working-directory edits or a moving `main` branch and call it a fixed release.
Review the changelog, license and third-party requirements. The Meilisearch
executable and private configuration are never part of the plugin package.

## Create an installable ZIP

Run from a checked-out revision containing `LICENSE` and the committed
administration assets. This uses Git's tracked snapshot and an explicit path
allowlist rather than recursively zipping an active shop directory:

```sh
set -eu
PLUGIN_REF="$(git -C "$PLUGIN_SOURCE" rev-parse HEAD)"
PLUGIN_BUILD_DIR="$(mktemp -d)"
git -C "$PLUGIN_SOURCE" archive --format=zip \
  --prefix=ShopwareMeilisearch/ \
  --output="$PLUGIN_BUILD_DIR/ShopwareMeilisearch.zip" \
  "$PLUGIN_REF" composer.json LICENSE README.md CHANGELOG.md src docs deploy
sha256sum "$PLUGIN_BUILD_DIR/ShopwareMeilisearch.zip"
printf 'Source revision: %s\nPackage: %s\n' "$PLUGIN_REF" "$PLUGIN_BUILD_DIR/ShopwareMeilisearch.zip"
```

The result contains one top-level directory named **ShopwareMeilisearch**. In
particular, these paths must exist inside the ZIP:

```text
ShopwareMeilisearch/composer.json
ShopwareMeilisearch/LICENSE
ShopwareMeilisearch/src/ShopwareMeilisearch.php
ShopwareMeilisearch/src/Resources/public/storefront/js/search.js
ShopwareMeilisearch/src/Resources/public/administration/.vite/entrypoints.json
ShopwareMeilisearch/src/Resources/public/administration/js/shopware-meilisearch.js
```

Preserve the hidden `.vite` directory: the backend rebuild component needs its
entrypoint manifest. No npm compilation is necessary for these committed plugin
assets. `tests/` and `tools/` remain in the full source repository for development
and evaluation; they are not required to run the packaged plugin. Do not add the
shop's `vendor/`, `.git/`, environment files, engine data, account files or reports.

Git's generic **Source code (zip)** archive may have a different top-level folder
name and is not the same as a prepared plugin package. Either use the explicit
ZIP above or deliberately arrange the correct plugin directory yourself. Do not
rename unrelated files just to bypass an import error.

## Install or transfer the package

Copy the ZIP through your normal secure deployment channel. Verify its SHA-256
again on the target host. A matching checksum verifies the transferred bytes;
you must still trust/review the original source and build process.

Install through Shopware's ZIP import or the administration upload as described
in [plugin installation](installation.md#3-install-the-plugin-and-configure-its-environment).
Do not put the ZIP inside the public document root. A backend upload installs
plugin files only; it does not provision Meilisearch, secrets or background jobs.
Retain the checksum/revision record and previous compatible package for rollback,
then remove temporary build files when they are no longer needed.
