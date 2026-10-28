# Changelog

## 0.3.1

- Hide the current sales channel's navigation root in category filters, and
  ignore it in existing filter links while preserving selectable subcategories.

## 0.3.0

- Show automatically aggregated popular searches per language and sales channel,
  retaining manually configured suggestions as a fallback. Document retention
  and privacy limitations.

- Store the local search connection, request limits and restricted application
  keys in a private plugin database table, with a privileged admin control.
- Import legacy environment configuration and fail closed on copied installations.

- Add sales-channel-aware administration controls for ordered search fields,
  per-language overrides, existing Shopware units and multilingual suggestions;
  preserve the underlying JSON configuration and invalid existing values.

- Improve the administration index status layout with Shopware components and responsive actions.

## 0.2.0

- Add a private vulnerability reporting policy and automated, synthetic PHP,
  Python, Chromium and Firefox regression checks for the repository.

- Add bounded, process-shared admission statistics and a dedicated private rotating
  log: rejection reasons, admitted/completed requests, sampled peak concurrency
  and server-side mean/max duration, with at most one summary per minute.

- Resolve storefront search URLs against the credential-free origin, preserving
  same-origin HTTP authentication when a page URL contains userinfo. Cover initial
  and typed searches with a synthetic Firefox/Chromium regression.

- Complete the operator onboarding documentation with source/ZIP packaging,
  recorded compatibility, environment and asset setup, supervised worker examples,
  configuration, initial builds, acceptance checks and upgrade/rollback steps.

- Add a provider-independent Meilisearch installation guide covering verified
  downloads, systemd/managed hosting, role-key ownership and end-to-end checks.

- Remove the provider-specific historical CSV importer and its dedicated tests;
  retain general-purpose relevance, latency and reviewed-signal tools.

- License the plugin, documentation, tests and supporting tools under MIT; retain
  the prominent experimental, no-warranty and no-support notices.

- Prominently state experimental status, absence of warranty and lack of support
  in the README and every documentation guide.

- Add process-shared configurable concurrency and global/client admission limits,
  private bounded limiter state, fail-closed behavior and overload responses.
- Separate application search, index-writer, status and keyed-feedback credentials;
  support protected secret files and scoped-key provisioning.
- Bound search candidate processing to 5,000 and configure a 250 ms engine cutoff.
- Reconcile automatically after 24 hours; keep product-change revisions without
  rebuilding on each write. Back off failed attempts for at least 15 minutes.
- Add authenticated backend status and queued manual rebuild controls with ACLs,
  duplicate-request coalescing and a shared background-build lock.
- Reject public price-range parameters and handle empty failure responses.
- Add admission, credential, daily/manual state and backend regression tests.
- Document setup, architecture, operations, limitations and private-data evaluation.

Background changes require working Shopware scheduled-task dispatch and messenger
consumption. Candidate/cutoff settings require a successful index rebuild.

## 0.1.0

- Initial generic plugin: local multilingual search, responsive overlay,
  configurable fields/synonyms/units, variant and quantity handling, current
  Shopware product/pricing resolution and optional reviewed historical signals.
- Include generic deployment examples, evaluation tools and regression checks.
- Refine card density/typography, manufacturer labels, hover styling, cart icon,
  remembered tab query and search-input focus presentation.
