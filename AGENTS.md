# Plugin Guidelines

## Purpose & Scope

- Minimal "Was this page helpful?" widget. Plain HTML POST; no JS required. HTMX-progressive if HTMX is on the page.
- **Opinionated by design.** Three config options: `secret`, `storage.dir`, `cache`. Everything else is a `private const`. To disable the feature, do not call the snippet and/or set `'lemmon/helpful' => false` in site config; do not add a redundant `enabled` option.
- Before adding a new option, try: `secret` override, `storage.dir` override, snippet override in `site/snippets/helpful.php`, or a translation key. If none fits, fork -- don't add an option.
- Never write to page content files. The JSONL log under `storage/` is the sole source of truth.

## Project Structure

- `index.php` -- plugin registration, three options, snippet, POST route.
- `src/Helpful.php` -- single static class: tokens, rate limiting, dedupe, storage, counts, IP anonymization.
- `snippets/helpful.php` -- form markup. Labels via `t('helpful.*')` with English fallbacks; per-call overrides via snippet params.
- `README.md` -- end-user docs; configuration examples stay limited to the three real options.

## PHP Style

- **PSR-12**: four-space indent; brace on the next line for classes/methods.
- All state is static; `Helpful` is a utility class, never instantiated.
- Defaults as `private const UPPER_SNAKE_CASE`. Only snippet label fallbacks are `public const`.
- ASCII punctuation in code, comments, and docs (`--` instead of em dashes).
- Early returns; avoid nested boolean gymnastics.

## Security & Privacy

- Always hash IPs; never store raw IPs. IPv4 truncated to /24 and IPv6 to /64 **before** HMAC-SHA256 -- both are byte boundaries so the math is trivial byte-level work.
- HMAC-SHA256 signed tokens with a 30-min TTL; `hash_equals()` for signature comparison.
- Two rate-limit tiers (per-IP, per-IP-per-page) + dedupe cache keyed on `pageId + ipHash`.
- Every failure mode of `handle()` returns the same response shape as success so probing POSTs can't distinguish outcomes.
- Session cookies are a UX shortcut only; the IP-hash cache is authoritative.

## Storage & Caching

- Log path resolution: `lemmon.helpful.storage.dir` override -> Kirby's `storage` root + `/helpful` -> `{site-root}/storage/helpful` fallback.
- Log format: one JSON object per line at `votes.jsonl`. `F::append()` of a <4 KB line is atomic on POSIX; no `flock()`.
- `Helpful::counts($page->uuid()->toString())` streams the log line by line and memoises in Kirby's cache for 5 min. Successful votes invalidate the entry for that page; missed invalidations self-heal within the TTL. JSONL uses a `page` key (full `page://…` URI); legacy `pageId` path keys are ignored.
- Dedupe and rate-limit buckets use the same cache namespace (`lemmon.helpful`) and are intentionally ephemeral.
- **Cache config key is `cache`, not `lemmon.helpful.cache`.** Kirby's `AppCaches::cacheOptionsKey()` resolves `kirby()->cache('lemmon.helpful')` to the plugin option `cache` (or `cache.<sub>` if you use a subname like `'lemmon.helpful.votes'`). An explicit `prefix` is set in `index.php` to bypass Kirby's default `{indexUrl-slug}/lemmon/helpful` path -- without it, CLI and HTTP invocations land in different cache directories because `indexUrl()` differs. Do not pass cache config as a second arg to `kirby()->cache()`; the signature takes one arg and PHP silently drops extras.

## Request Flow

1. Snippet renders with a signed token.
2. POST `/helpful` -> validate token, dedupe, rate-limit, anonymize + hash IP, append to log, invalidate counts cache, set session marker.
3. HTMX clients get the "Thanks" fragment; everyone else gets a 302 back to the page (PRG).
