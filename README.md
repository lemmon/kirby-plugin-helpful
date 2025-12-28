# Helpful Feedback for Kirby

A minimal, privacy-friendly feedback block for Kirby CMS that asks a single question: "Was this page helpful?" Visitors answer with Yes or No via a plain HTML form POST. JavaScript is optional; HTMX can enhance the interaction but is never required.

## Installation

### Composer

```bash
composer require lemmon/kirby-helpful
```

### Git Submodule

```bash
git submodule add https://github.com/lemmon/kirby-plugin-helpful.git site/plugins/helpful
```

### Manual

[Download the plugin](https://api.github.com/repos/lemmon/kirby-plugin-helpful/zipball) and extract it to `/site/plugins/helpful`.

## Usage

Drop the snippet into any template or snippet where you want the feedback block to appear:

```php
<?php snippet('helpful') ?>
```

You can override the labels per call if needed:

```php
<?php snippet('helpful', [
    'question' => 'Was this guide useful?',
    'yesLabel' => 'Yes',
    'noLabel' => 'No',
    'confirmation' => 'Thanks for your feedback.',
]) ?>
```

The snippet emits semantic, classed markup with no styling. Target the BEM-like classes in your CSS:

-   `helpful`
-   `helpful__question`
-   `helpful__actions`
-   `helpful__button`
-   `helpful__confirmation`

## How it works

-   The form submits to `/helpful` using POST.
-   Each form render includes a signed token (HMAC + TTL) so blind POSTs fail.
-   Votes are deduplicated by `pageId + IP hash` within a time window (default 24h).
-   Rate limits throttle excessive traffic (per-IP and per-IP-per-page).
-   A Kirby session value is set after a successful vote to show the "already voted" state for that visitor.
-   If the session cookie is missing, the UI falls back to the IP-dedupe cache so it will not show buttons for blocked votes.
-   Page-level counts are stored on the page itself (default fields: `helpful_yes` and `helpful_no`).

Note: counts are written via normal Kirby page updates. The target fields must exist in the page blueprint, and the current request must have permission to update the page unless `lemmon.helpful.counts.impersonate` is set.

## HTMX enhancement (optional)

The plugin does not load HTMX. If you include HTMX yourself, enable it in config to swap the feedback block without a full page reload:

```php
return [
    'lemmon.helpful.htmx.enabled' => true,
    'lemmon.helpful.htmx.target' => 'this',
    'lemmon.helpful.htmx.swap' => 'outerHTML',
];
```

## Storage

By default, every valid vote is appended to `site/logs/helpful/votes.jsonl` as JSON lines. You can disable storage or change the location via config.

## Configuration

Common options (set in `site/config/config.php`):

```php
return [
    'lemmon.helpful.enabled' => true,
    'lemmon.helpful.tokenTtl' => 1800,
    'lemmon.helpful.dedupe.window' => 86400,
    'lemmon.helpful.rateLimit.perIp' => 30,
    'lemmon.helpful.rateLimit.window' => 600,
    'lemmon.helpful.rateLimit.perIpPage' => 3,
    'lemmon.helpful.rateLimit.pageWindow' => 86400,
    'lemmon.helpful.session.enabled' => true,
    'lemmon.helpful.session.long' => true,
    'lemmon.helpful.counts.enabled' => true,
    'lemmon.helpful.counts.yesField' => 'helpful_yes',
    'lemmon.helpful.counts.noField' => 'helpful_no',
    'lemmon.helpful.counts.impersonate' => 'kirby',
    'lemmon.helpful.counts.language' => null,
    'lemmon.helpful.storage.enabled' => true,
    'lemmon.helpful.allowNoJs' => true,
];
```

Additional options you may want:

-   `lemmon.helpful.secret` - override the HMAC secret (defaults to Kirby content token). **Important:** In production, set a strong, random secret. See Production Considerations below.
-   `lemmon.helpful.ipAnonymize.enabled` - enable or disable IP truncation before hashing.
-   `lemmon.helpful.ipAnonymize.v4` / `lemmon.helpful.ipAnonymize.v6` - prefix lengths for truncation (defaults: /24 and /64).
-   `lemmon.helpful.storage.dir` / `lemmon.helpful.storage.file` - override the JSONL log location.
-   `lemmon.helpful.storeIpHash` - toggle IP hash storage in the log.
-   `lemmon.helpful.storeUserAgentHash` - toggle user-agent hash storage in the log.
-   `lemmon.helpful.labels.question` / `lemmon.helpful.labels.yes` / `lemmon.helpful.labels.no` / `lemmon.helpful.labels.confirmation` - global text overrides.
-   `lemmon.helpful.counts.enabled` - toggle page field updates for vote counts.
-   `lemmon.helpful.counts.yesField` / `lemmon.helpful.counts.noField` - override the page fields used to store counts.
-   `lemmon.helpful.counts.impersonate` - optional impersonation user for count updates (use `null` or `false` to disable).
-   `lemmon.helpful.counts.language` - optional language code to write counts into a specific translation.
-   `lemmon.helpful.logging.enabled` - enable development logging for debugging (disabled by default). Logs vote storage failures, rate limit hits, and token validation failures to Kirby's log system.

## Production Considerations

### Secret Management

The plugin uses HMAC-signed tokens to prevent blind POST spam. By default, it uses Kirby's content token, but **in production you should set a strong, random secret**:

```php
return [
    'lemmon.helpful.secret' => 'your-strong-random-secret-here',
];
```

Generate a secure secret using:

-   PHP: `bin2hex(random_bytes(32))`
-   Command line: `openssl rand -hex 32`

**Why this matters:** If the secret is compromised or predictable, attackers could forge tokens and bypass rate limiting. Keep your secret secure and never commit it to version control (use environment variables or secure config files).

### Development Logging

For debugging issues in development, you can enable logging:

```php
return [
    'lemmon.helpful.logging.enabled' => true,
];
```

This logs:

-   Vote storage failures (file permission issues, disk full, etc.)
-   Rate limit hits
-   Token validation failures

Logs are written to Kirby's log system (typically `site/logs/helpful.log`). **Keep logging disabled in production** to avoid exposing sensitive information and reduce I/O overhead.

## Roadmap

-   [ ] CSS class override - allow customizing class names via config or snippet parameters

## License

MIT License. See `LICENSE` for details.

---

Questions or ideas? File an issue or open a PR. This plugin is intentionally small and boring so it stays dependable.
