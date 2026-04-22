# Helpful Feedback for Kirby

A minimal, privacy-friendly "Was this page helpful?" widget for Kirby CMS. Plain HTML POST, no JavaScript required. HTMX progressively enhances if present.

**Intentionally opinionated.** Three config options (`secret`, `storage.dir`, `cache`). Rate limits, dedupe windows, IP anonymization, token TTL, session behavior, log filename, and HTMX attributes are baked in -- if you need to tune them, fork the plugin.

## Installation

```bash
composer require lemmon/kirby-helpful
# or
git submodule add git@github.com:lemmon/kirby-plugin-helpful.git site/plugins/helpful
```

## Usage

```php
<?php snippet('helpful') ?>
```

Override labels per call when needed:

```php
<?php snippet('helpful', [
    'question'     => 'Was this guide useful?',
    'confirmation' => 'Thanks!',
]) ?>
```

Labels read from `t('helpful.*')` with English fallbacks. Add translation keys for i18n instead of per-call overrides:

```
helpful.question:     Bylo to užitečné?
helpful.yes:          Ano
helpful.no:           Ne
helpful.confirmation: Díky za zpětnou vazbu.
```

BEM-like classes for styling: `helpful`, `helpful__question`, `helpful__actions`, `helpful__button`, `helpful__confirmation`. No CSS ships with the plugin.

## Storage

Every valid vote is appended to `{storage-root}/helpful/votes.jsonl` as one JSON object per line. The log is the source of truth; counts are derived on demand:

```php
use Lemmon\Helpful\Helpful;

$totals = Helpful::counts($page->uuid()->toString());
// => ['yes' => 42, 'no' => 3]
```

Votes are keyed by the full page UUID string (`page://…` from `$page->uuid()->toString()`), not the filesystem path, so data survives renames. UUIDs are enabled in Kirby by default; if you have turned them off globally, this plugin is not a fit.

Each JSONL line is a small JSON object; new writes use a `page` key. Older `pageId` (path) lines from a previous version are not counted for `Helpful::counts()`.

Counts are cached for 5 minutes; the entry for a page is invalidated after each successful vote.

The log path resolves in this order:

1. `lemmon.helpful.storage.dir` override -- wins if set.
2. `{storage-root}/helpful/` if the site registers a `storage` root.
3. `{site-root}/storage/helpful/` as the zero-config fallback.

### Register a shared `storage` root (recommended)

The `storage/` directory is intended as a universal, Git-ignored area for runtime-only plugin data. Register it once in `public/index.php`:

```php
$kirby = new Kirby([
    'roots' => [
        'index'   => __DIR__,
        'base'    => $base = dirname(__DIR__),
        'site'    => $base . '/site',
        'content' => $base . '/content',
        'storage' => $base . '/storage',
    ],
]);
```

Then add `/storage` to `.gitignore`.

## HTMX (optional)

The form always renders with `hx-post`, `hx-target="this"`, and `hx-swap="outerHTML"` attributes. They are ignored when HTMX is not loaded; when it is, you get partial swaps for free.

## Configuration

```php
return [
    'lemmon.helpful.secret'      => null,          // HMAC secret; falls back to Kirby's content token
    'lemmon.helpful.storage.dir' => null,          // absolute path override
    'lemmon.helpful.cache'       => [
        'active' => true,
        'type'   => 'file',
        'prefix' => 'lemmon/helpful',              // see note below
    ],
];
```

The `cache` option is the plugin-cache config key Kirby resolves for `kirby()->cache('lemmon.helpful')` (see `AppCaches::cacheOptionsKey`). The explicit `prefix` bypasses Kirby's default `{indexUrl-slug}/lemmon/helpful` path, so caches stay in one place across CLI and HTTP invocations. Redis/memcached users only need to change `type` + driver options.

To turn the widget off: remove `snippet('helpful')` from your templates, or disable the whole plugin in `site/config` with `'lemmon/helpful' => false` (Kirby does not load the plugin, so the route and snippet are both gone). There is no per-plugin `enabled` option -- that would only duplicate what Kirby already gives you.

### Baked-in defaults

| Behavior           | Value                                           |
| ------------------ | ----------------------------------------------- |
| Token TTL          | 30 min                                          |
| Dedupe window      | 24 h (per IP + page)                            |
| Rate limit (IP)    | 30 votes / 10 min                               |
| Rate limit (page)  | 3 votes per IP + page / 24 h                    |
| Counts cache TTL   | 5 min                                           |
| IPv4 anonymization | /24 (last octet zeroed)                         |
| IPv6 anonymization | /64 (last 8 bytes zeroed)                       |
| Session dedupe     | always on, `kirby_session` cookie, long session |
| Log filename       | `votes.jsonl`                                   |
| User agent         | not stored                                      |

## Security

Set a strong secret in production:

```php
'lemmon.helpful.secret' => bin2hex(random_bytes(32)),
// or: openssl rand -hex 32
```

If the secret leaks, attackers can forge tokens and bypass rate limiting. Load it from env / secret manager; never commit it.

## License

MIT. See `LICENSE`.
