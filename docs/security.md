# Security, privacy and distribution

This document describes implemented controls and known limits. It is not a
penetration-test certificate or a guarantee against denial of service.

## Trust boundaries and credentials

The browser calls a Shopware route and receives rendered HTML. It receives no
Meilisearch endpoint credential. Engine requests accept only loopback origins,
reject redirects and disable inherited HTTP proxies. Optional socket paths must
be absolute existing sockets, not symlinks. The bridge must share the engine's
network namespace and expose only a protected local socket.

Application credentials are separate:

| Role | Capabilities | Exposure limit |
| --- | --- | --- |
| Search | `search`, `settings.get` | Explicit published indexes only |
| Index writer | Create/get/delete/swap indexes, update settings, add/get documents | Explicit published and `_building` names |
| Status | `version`, `tasks.get` | Global engine task metadata; no document/search writes |
| Feedback | Independent random HMAC secret | Query fingerprints; not an engine API credential |
| Master | Engine administration/provisioning | Daemon and provisioning only; never configure in the plugin |

Task status/version permissions are global in the engine API, so the status key
is intentionally separate. Treat metadata it can read as confidential. The index
writer is powerful within its assigned indexes; compromised server-side PHP can
still misuse credentials it can read. File-based secrets reduce accidental
exposure, not compromise of the same account.

Keep credential files and compiled environments owner-readable, parent directories
private and release roots non-world-writable. Do not make public assets private.
Same-UID staging and production are not security isolation: another application
under that account may read the same secrets or alter application files. Separate
accounts, service restrictions and browser origins are preferred where available.

## Public input and resource controls

Search input has fixed length, sort and filter allowlists. The public API rejects
price-range filters. Database access uses Shopware DAL and parameterized DBAL
queries; it does not execute visitor-supplied code or SQL. Rendering uses Twig
and normal Shopware cart behavior. The authenticated rebuild API has separate
read/update ACLs and does not perform a build inline.

Admission occurs before expensive context/search work where possible, with a
controller fallback. Process-shared global/IP token buckets bound admission and
OS locks bound simultaneous computations. State is private, bounded in size and
client count, and expires client entries after two minutes. IP strings are stored
as SHA-256 hashes rather than plaintext; these identifiers are not claimed to be
anonymous. A failure to obtain/parse safe limiter state does not bypass limits.
Trusted-proxy configuration determines which client IP Symfony uses; untrusted
forwarding headers must not be treated as identity.

Candidate processing is capped at 5,000 IDs; engine cutoff is 250 ms. These limits
do not bound the total cost of every database query. A single admitted broad
query may be more expensive than a narrow identifier query. The file-based limiter
assumes one server and a suitable local filesystem; multiple web servers need
shared admission control at the proxy or another central service.

PHP-level admission cannot stop connection floods, bandwidth exhaustion or the
cost of reaching PHP itself. Maintain provider/web-server protections and
appropriate worker/memory limits. Shared IPs require headroom for legitimate
users; increasing their allowance necessarily permits a larger attacker burst.

## Remaining exposure and privacy

- Actual product results re-check current sales-channel eligibility and prices.
  Facet-label resolution is not a comprehensive confidentiality boundary: depending
  on stored catalog associations, category/manufacturer names may be exposed even
  where a related product is not displayed. Do not rely on those labels being
  secret. This has not been represented as a hidden-product-content leak fix.
- The current query is retained in the current tab's `sessionStorage`, separately
  by locale/endpoint, and can contain sensitive visitor text. Other scripts on
  the same origin can access it. Clearing the input removes its stored value;
  no cross-device query history is implemented.
- Optional hints contain plaintext historical terms in local configuration/indexes.
  Keyed click feedback avoids plaintext query keys, but the secret/configuration
  remains sensitive. Hashing/HMAC does not make all derived data non-personal.
- Search requests are GET requests; access logs, proxies and browser history can
  contain query strings. Configure retention/redaction outside the plugin where
  required. The plugin does not implement analytics consent or automatic live
  click collection.
- Local speech recognition is restricted to browsers exposing `processLocally`;
  there is no prefixed/cloud fallback or automatic language-pack download. The
  feature depends on browser support and is hidden otherwise.
- Meilisearch, PHP, Shopware, the web server and the host remain dependencies to
  patch. AI-assisted attackers have the same network and application entry points;
  the plugin adds no LLM execution, prompt or agent-tool endpoint.

## Repository and publication rules

Keep merchant domains, account names, product exports, real IDs, credentials,
private reports, screenshots and installation paths out of source and Git history.
Use synthetic fixtures and `example.invalid` identities. Review untracked files
before staging; `.gitignore` is only a convenience and does not protect data
already tracked. `.env*`, CSV/JSONL and Python caches are ignored; ordinary JSON
can be legitimate source and therefore still requires review.

Icons are referenced from Shopware's icon system, not copied from a competing
search overlay. Optional import tooling names its source format; imported data
is never part of the plugin distribution. Third-party trademarks, dependencies,
licenses and interface rights still require review before public release.
`composer.json` currently declares `proprietary`; a public Git repository alone
would not grant an open-source license. There is no chosen open-source license
or blanket legal clearance in this project.
