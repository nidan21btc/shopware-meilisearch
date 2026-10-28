# Installation acceptance checklist

> [!WARNING]
> **EXPERIMENTAL · NO WARRANTY · NO SUPPORT**
> This plugin is experimental and not approved for production use. Use it at your
> own risk. It is provided "as is", without warranty to the extent permitted by law.
> No support, maintenance, updates or bug fixes are promised.

Complete these checks in your own isolated/staging installation before deciding
on a live rollout. Use synthetic or deliberately selected test products/accounts;
do not run database-writing regression scripts against a shared/live catalog.
Keep the result record private. Passing the list is not a production certificate.

## Installation and isolation

- [ ] Record source commit, package checksum, plugin/Shopware/PHP/engine versions,
  application identity, service names and endpoint/socket paths.
- [ ] Confirm the package installed as `ShopwareMeilisearch`, migrations applied,
  assets are readable, and no environment/credential file is publicly accessible.
- [ ] Confirm Meilisearch is supervised, has persistent private storage, requires
  authentication for its index API and does not listen on an external interface.
- [ ] Confirm staging and live use separate instances/data/keys; copied channel
  UUIDs are not pointing both shops at the same published indexes.
- [ ] Confirm web PHP, CLI and workers see the intended protected role-key files
  and current environment. An SSH health check is not a web-PHP test.
- [ ] Build every intended channel/language pair successfully before enabling its
  overlay. Review the index field audit without publishing its output.

## Storefront function

| Check | Expected result |
| --- | --- |
| Open desktop and mobile header search | One responsive overlay opens; no competing old search layer |
| Open with no previous query | Configured suggestions/featured products appear; an unconfigured empty start is not an error |
| Search a known exact product number/name | Expected currently eligible product appears |
| Search a deliberate typo and a broad term | Useful results without repeated failures; truncation is not mistaken for full catalog coverage |
| Switch every enabled language | Appropriate names/text and locale-specific results; no unintended language fallback |
| Filter manufacturer/category and reset | Results and selected controls update consistently |
| Sort by name and current price | Order matches displayed names/prices within the processed candidate set |
| Product has configured quantity/reference price | Correct variant size and reference-price unit/amount |
| Product is hidden or unavailable | It is not offered as an eligible result/cart action |
| Guest and representative customer context | Prices, tax/currency formatting and purchasing rules match Shopware |
| Buy a simple purchasable item | Correct item and minimum quantity reach the normal Shopware cart |
| Product requires variant/detail selection | Appropriate detail action rather than an invalid direct buy |
| Click a product, then reopen search | Latest query is available in that tab/language; clearing it removes the remembered query |
| Keyboard, Escape, pagination, retry and filters | Focus and controls remain usable after HTML updates |
| Actual target browsers and theme | Assets load and no blocking CSP/JavaScript/network errors occur |

The direct-buy button also depends on Shopware's `core.listing.allowBuyInListing`,
stock/closeout, variants and price structure; not every card should show a cart
icon. The optional microphone is hidden when local recognition is unsupported.
A missing microphone alone is not a failed typed-search installation.

## Background updates

- [ ] Verify both [background components](background-workers.md), not just the
  engine daemon. Check the actual transport names and the plugin's registered task.
- [ ] Change a known searchable fixture, request a backend rebuild and save the
  observed pending → completed transition with its new UTC timestamp.
- [ ] Verify the changed text becomes searchable after the completed rebuild.
  New products/text changes may wait for the daily build otherwise; current
  prices and eligibility are still resolved by Shopware on search requests.
- [ ] Confirm repeated button clicks coalesce, and understand that a failed
  attempt may wait at least 15 minutes before retrying.
- [ ] Confirm supervised jobs recover after their normal time limits, service
  restart and the deployment's normal worker reload procedure.

## Capacity, error handling and recovery

- [ ] Run a bounded parallel workload representative of your users, including
  several users behind one IP. Record response times, HTTP failures, CPU and
  available memory during normal searches and a rebuild.
- [ ] Check the configured concurrency/global/IP budgets against that workload.
  Defaults are conservative; a generic "unavailable" message needs an HTTP status
  before deciding whether the cause is rate limiting, authentication or failure.
- [ ] Verify the webserver/provider has its own connection/bandwidth protections;
  the PHP admission guard cannot stop a volumetric flood.
- [ ] In an isolated test window, verify an engine outage yields a controlled
  error/retry path and service recovery restores search. Do not interrupt a live
  shared service just to perform this check.
- [ ] Confirm there is enough disk room for published/building indexes and an
  appropriate backup/rebuild procedure. Do not improvise live data-directory copies.
- [ ] Record a rollback plan and verify that disabling the overlay restores the
  installation's intended previous search behavior without an uninstall/data purge.

If a check fails, keep the deployment in testing and use [operations](operations.md)
for diagnosis. Record exact time/timezone, route, HTTP status, browser and relevant
version/configuration information. Application logs and webserver logs may use
different timezones or be delayed; use their explicit timestamps/offsets. Redact
cookies, authorization headers, query text and private product/customer data
before sharing any diagnostic extract.
