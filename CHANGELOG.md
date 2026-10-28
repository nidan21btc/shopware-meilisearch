# Changelog

## 0.2.0

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
