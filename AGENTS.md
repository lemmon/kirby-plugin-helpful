# Repository Guidelines

## Plugin Purpose & Scope

- Provide a minimal, privacy-friendly feedback block for Kirby CMS that asks "Was this page helpful?" with Yes/No actions.
- Implement as a plain HTML form POST with no JavaScript required; optional HTMX enhancement can be enabled by users.
- Collect feedback with robust security: HMAC-signed tokens, rate limiting, IP-based deduplication, and optional session-based UX enhancement.
- Store votes in JSONL format (`site/logs/helpful/votes.jsonl`) and optionally update page content fields (`helpful_yes`, `helpful_no`).
- Prioritize privacy: anonymize IPs before hashing, never store raw IPs or personal data, GDPR-friendly defaults.
- Remain "boringly reliable" - work in restrictive environments, be accessible by default, avoid JS dependency chains.

## Project Structure & Module Organization

- `index.php` registers the Kirby plugin at `lemmon/helpful`, sets default options, registers the snippet, and defines the POST route handler.
- `src/Helpful.php` contains the core `Lemmon\Helpful\Helpful` class with all business logic: token generation/validation, rate limiting, deduplication, vote storage, and count updates.
- `snippets/helpful.php` renders the feedback form with semantic HTML, BEM-like classes, and optional HTMX attributes.
- `README.md` provides installation, usage, and configuration documentation for end users.
- Keep blueprints and additional snippets under `blueprints/` and `snippets/` if UI elements are introduced later.
- Assume the code always runs inside a Kirby instance; no guards for the global `kirby()` helper are needed.

## Coding Style & Naming Conventions

- Follow PSR-12 for PHP: four-space indentation, brace on the next line for classes and methods, and meaningful namespaces (e.g., `Lemmon\Helpful`).
- Use static methods for stateless operations; the `Helpful` class is a utility class, not instantiated.
- Name constants using `UPPER_SNAKE_CASE` with descriptive prefixes (e.g., `DEFAULT_TOKEN_TTL`, `CACHE_NAMESPACE`).
- Use protected methods for internal helpers; public methods form the API surface.
- Stick to ASCII punctuation in code, docs, and comments (prefer `--` over an em dash) so diffs stay predictable.
- Document non-trivial helpers with concise docblocks. Prefer descriptive method names such as `validateToken`, `checkRateLimits`.
- Reserve emojis for rare emphasis; moderate use is fine, but avoid emoji-driven lists.
- Use GitHub-style unchecked checkboxes (`- [ ]`) when documenting roadmap items to keep documentation consistent.

## Security & Privacy Considerations

- Always hash IPs before storage or comparison; never store raw IPs.
- Use HMAC-signed tokens with TTL to prevent blind POST spam.
- Implement rate limiting at multiple levels (per-IP, per-IP-per-page).
- Anonymize IPs by default (IPv4: /24, IPv6: /64) before hashing.
- Use `hash_equals()` for signature comparison to prevent timing attacks.
- Validate all inputs: page IDs, tokens, vote values.
- Session cookies are UX-only; validation relies on IP hash deduplication cache.

## Configuration Patterns

- Define all defaults as class constants (e.g., `DEFAULT_TOKEN_TTL`, `DEFAULT_RATE_PER_IP`).
- Use nested option arrays for related settings (e.g., `lemmon.helpful.rateLimit.perIp`).
- Provide sensible defaults; all features should work out-of-the-box.
- Document configuration options in `README.md` with examples.

## Testing Guidelines

- Add PHPUnit under `tests/` when functionality expands; start with configuration in `phpunit.xml.dist`.
- Name test classes after the class under test (`HelpfulTest`). Execute locally with `vendor/bin/phpunit`.
- Test token generation/validation, rate limiting, deduplication, IP anonymization, and vote storage.
- Maintain manual regression notes in `docs/testing.md` until automated coverage is available.
- Test both no-JS and HTMX flows.

## Commit & Pull Request Guidelines

- Follow the Conventional Commits spec (`fix:`, `refactor:`, `docs:`) and keep messages in the imperative mood.
- Use concise Conventional Commit summaries: `<type>(<scope>): <short action>`. Avoid verbose release blurbs in commit messages; keep release notes in CHANGELOG/release tagging.
- Ensure each commit addresses a single concern; couple tests with implementation, but leave unrelated formatting for a separate change.
- Reference related issues in commit bodies using `Refs #123` when applicable.
- PRs must summarize intent, list functional changes, and include screenshots or GIFs when UI elements are added.
- Prefer annotated tags for releases (author, date, message/signing) over lightweight tags.
- Annotated tags should use `vX.Y.Z - <concise headline>`; keep detailed notes in CHANGELOG/releases.

## Documentation Practices

- Add concise PHPDoc blocks where behavior is not immediately obvious, especially for helpers touching I/O streams or security-sensitive operations.
- Update `README.md` when adding configuration options or features.
- Document security implications of configuration changes.

## Request Flow & Response Handling

1. User loads page → snippet renders form with signed token
2. User submits form → POST to `/helpful` route
3. Server validates: token signature, TTL, page ID, deduplication, rate limits
4. If valid: store vote, update counts, set dedupe marker, set session (UX), respond
5. Response: HTMX users get partial HTML snippet; no-JS users get redirect (PRG pattern)

## Storage & Caching

- Votes stored in JSONL format: `site/logs/helpful/votes.jsonl` (configurable)
- Deduplication uses Kirby cache with namespace `lemmon.helpful`
- Rate limiting buckets stored in cache
- Cache configuration follows Kirby patterns: `['active' => true, 'type' => 'file']`

## Out of Scope (v1)

- Perfect bot detection
- Built-in JS framework loading
- UI styling or themes
- Rich analytics dashboards
- User attribution beyond IP hash
- Admin UI for viewing votes

## Future Ideas

See `IDEAS.md` for a comprehensive list of potential improvements, organized by priority and category. All ideas maintain the "boringly reliable" philosophy.

Keep this document updated when changing core behavior, configuration keys, or security practices; future agents will thank you.
