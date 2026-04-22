<?php

declare(strict_types=1);

namespace Lemmon\Helpful;

use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Http\Cookie;
use Throwable;

/**
 * "Was this page helpful?" feedback widget -- opinionated, near-zero-config.
 *
 * Public API: handle(), token(), validateToken(), hasVoted(), counts().
 * Page identity is the Kirby page UUID string from `$page->uuid()->toString()` (e.g. `page://…`), not the content path / id.
 *
 * Defaults below are intentionally hardcoded. If you need to change them,
 * fork the plugin -- options for every knob was the old mistake.
 */
class Helpful
{
    private const CACHE_NAMESPACE  = 'lemmon.helpful';
    private const STORAGE_FILENAME = 'votes.jsonl';
    private const SESSION_PREFIX   = 'helpful.voted.';

    private const TOKEN_TTL        = 1800;   // 30 min
    private const DEDUPE_WINDOW    = 86400;  // 24 h
    private const RATE_PER_IP      = 30;
    private const RATE_WINDOW      = 600;    // 10 min
    private const RATE_PER_IP_PAGE = 3;
    private const RATE_PAGE_WINDOW = 86400;  // 24 h
    private const COUNTS_CACHE_TTL = 300;    // 5 min
    private const IPV4_PREFIX      = 24;     // /24
    private const IPV6_PREFIX      = 64;     // /64

    // Exposed for snippet defaults / i18n fallback.
    public const DEFAULT_QUESTION     = 'Was this page helpful?';
    public const DEFAULT_YES_LABEL    = 'Yes';
    public const DEFAULT_NO_LABEL     = 'No';
    public const DEFAULT_CONFIRMATION = 'Thanks for your feedback.';

    // --- Public API ------------------------------------------------------

    /**
     * Handle POST to the /helpful route.
     *
     * Every failure mode returns the same shape (confirmation via HTMX,
     * redirect otherwise) so probing POSTs can't distinguish outcomes.
     */
    public static function handle(): mixed
    {
        $request = \kirby()->request();
        $data    = $request->data();
        $pageUri = self::str($data['page'] ?? '');
        $token   = self::str($data['token'] ?? '');
        $value   = strtolower(self::str($data['value'] ?? ''));
        $page    = $pageUri !== '' ? \page($pageUri) : null;
        $isHtmx  = strtolower((string) $request->header('HX-Request')) === 'true';

        if (
            !$page
            || !in_array($value, ['yes', 'no'], true)
            || !self::validateToken($token, $pageUri)
        ) {
            return self::respond($isHtmx, $page);
        }

        $ip = self::clientIp();

        if (self::isDuplicateVote($pageUri, $ip)) {
            self::rememberVoted($pageUri);
            return self::respond($isHtmx, $page);
        }

        if (!self::checkRateLimits($pageUri, $ip)) {
            return self::respond($isHtmx, $page);
        }

        self::storeVote($pageUri, $value, $ip);
        self::markDuplicate($pageUri, $ip);
        self::rememberVoted($pageUri);

        return self::respond($isHtmx, $page);
    }

    /**
     * Aggregate vote counts for a page UUID string (`$page->uuid()->toString()`), derived from the JSONL log.
     *
     * Memoised in Kirby's cache; a successful vote invalidates the entry
     * for that page, and any missed invalidation self-heals within the TTL.
     *
     * @return array{yes: int, no: int}
     */
    public static function counts(string $pageUri): array
    {
        if ($pageUri === '') {
            return ['yes' => 0, 'no' => 0];
        }

        $cache = self::cache();
        $key   = 'counts.' . hash('sha1', $pageUri);
        $hit   = $cache->get($key);

        if (is_array($hit) && isset($hit['yes'], $hit['no'])) {
            return ['yes' => (int) $hit['yes'], 'no' => (int) $hit['no']];
        }

        $counts = self::readCounts($pageUri);
        $cache->set($key, $counts, (int) ceil(self::COUNTS_CACHE_TTL / 60));

        return $counts;
    }

    /**
     * Issue a signed, timestamped token for a page UUID string.
     *
     * Compact form: "<payload>.<signature>" (both base64url). Payload is
     * a JSON object { page, issuedAt, nonce } where `page` is `page://…`.
     * Throws if random_bytes() is unavailable.
     */
    public static function token(string $pageUri): string
    {
        $payload = json_encode([
            'page'     => $pageUri,
            'issuedAt' => time(),
            'nonce'    => bin2hex(random_bytes(16)),
        ]);

        if ($payload === false) {
            return '';
        }

        $encoded = self::b64url($payload);

        return $encoded . '.' . self::sign($encoded);
    }

    public static function validateToken(
        #[\SensitiveParameter] string $token,
        string $pageUri
    ): bool {
        if ($token === '' || $pageUri === '' || substr_count($token, '.') !== 1) {
            return false;
        }

        [$encoded, $signature] = explode('.', $token);
        $json = self::b64urlDecode($encoded);

        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        $signedPage = $payload['page'] ?? $payload['pageId'] ?? null;

        if (!is_string($signedPage) || $signedPage !== $pageUri) {
            return false;
        }

        $issuedAt = (int) ($payload['issuedAt'] ?? 0);

        if ($issuedAt <= 0 || (time() - $issuedAt) > self::TOKEN_TTL) {
            return false;
        }

        return hash_equals(self::sign($encoded), $signature);
    }

    /**
     * Has this visitor already voted on this page (UUID string) within the dedupe window?
     *
     * IP-hash cache is authoritative; the session is a UX shortcut so the
     * widget flips to "Thanks" instantly on the visitor's own browser.
     */
    public static function hasVoted(string $pageUri): bool
    {
        if ($pageUri === '') {
            return false;
        }

        if (self::isDuplicateVote($pageUri, self::clientIp())) {
            return true;
        }

        if (!self::hasSessionCookie()) {
            return false;
        }

        $session = \kirby()->session(['detect' => true]);
        $votedAt = (int) $session->get(self::sessionKey($pageUri), 0);

        return $votedAt > 0 && (time() - $votedAt) <= self::DEDUPE_WINDOW;
    }

    // --- Internals -------------------------------------------------------

    private static function sign(string $payload): string
    {
        return self::b64url(hash_hmac('sha256', $payload, self::secret(), true));
    }

    private static function secret(): string
    {
        $override = \option('lemmon.helpful.secret');

        return is_string($override) && $override !== ''
            ? $override
            : \kirby()->contentToken(null, self::CACHE_NAMESPACE);
    }

    private static function sessionKey(string $pageUri): string
    {
        return self::SESSION_PREFIX . hash('sha1', $pageUri);
    }

    private static function rememberVoted(string $pageUri): void
    {
        \kirby()->session(['detect' => true, 'long' => true])
            ->set(self::sessionKey($pageUri), time());
    }

    private static function hasSessionCookie(): bool
    {
        $name = \option('session.cookieName', 'kirby_session');
        $name = is_string($name) && $name !== '' ? $name : 'kirby_session';

        return Cookie::get($name) !== null;
    }

    // --- Storage (JSONL) -------------------------------------------------

    private static function storageDir(): string
    {
        $override = \option('lemmon.helpful.storage.dir');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/\\');
        }

        $root = \kirby()->root('storage');

        if (!is_string($root) || $root === '') {
            $root = \kirby()->root('site') . '/storage';
        }

        return rtrim($root, '/\\') . '/helpful';
    }

    private static function storageFile(): string
    {
        return self::storageDir() . '/' . self::STORAGE_FILENAME;
    }

    /** @return array{yes: int, no: int} */
    private static function readCounts(string $pageUri): array
    {
        $file = self::storageFile();

        if (!is_file($file)) {
            return ['yes' => 0, 'no' => 0];
        }

        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return ['yes' => 0, 'no' => 0];
        }

        $yes = $no = 0;

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);

            if (!is_array($entry)) {
                continue;
            }

            $rowPage = $entry['page'] ?? $entry['pageId'] ?? null;

            if ($rowPage !== $pageUri) {
                continue;
            }

            match ($entry['value'] ?? null) {
                'yes'   => $yes++,
                'no'    => $no++,
                default => null,
            };
        }

        fclose($handle);

        return ['yes' => $yes, 'no' => $no];
    }

    private static function storeVote(string $pageUri, string $value, ?string $ip): void
    {
        $entry = [
            'page'      => $pageUri,
            'value'     => $value,
            'timestamp' => time(),
            'ipHash'    => self::hashIp($ip),
        ];

        $payload = json_encode($entry);

        if ($payload === false) {
            return;
        }

        try {
            F::append(self::storageFile(), $payload . PHP_EOL);
            self::cache()->remove('counts.' . hash('sha1', $pageUri));
        } catch (Throwable) {
            // Dedupe + rate-limit still protect us; silent drop is fine.
        }
    }

    // --- Rate limiting & dedupe ------------------------------------------

    private static function checkRateLimits(string $pageUri, ?string $ip): bool
    {
        $ipHash = self::hashIp($ip);

        if ($ipHash === '') {
            return true;
        }

        if (!self::allowBucket('rate.ip.' . $ipHash, self::RATE_PER_IP, self::RATE_WINDOW)) {
            return false;
        }

        $key = 'rate.ip_page.' . $ipHash . '.' . hash('sha1', $pageUri);

        return self::allowBucket($key, self::RATE_PER_IP_PAGE, self::RATE_PAGE_WINDOW);
    }

    private static function allowBucket(string $key, int $limit, int $windowSeconds): bool
    {
        $cache  = self::cache();
        $now    = time();
        $bucket = $cache->get($key);

        if (!is_array($bucket) || ($bucket['reset'] ?? 0) <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        if ($bucket['count'] >= $limit) {
            return false;
        }

        $bucket['count']++;
        $cache->set($key, $bucket, (int) ceil(($bucket['reset'] - $now) / 60));

        return true;
    }

    private static function isDuplicateVote(string $pageUri, ?string $ip): bool
    {
        $ipHash = self::hashIp($ip);

        return $ipHash !== '' && self::cache()->get(self::dedupeKey($pageUri, $ipHash)) !== null;
    }

    private static function markDuplicate(string $pageUri, ?string $ip): void
    {
        $ipHash = self::hashIp($ip);

        if ($ipHash === '') {
            return;
        }

        self::cache()->set(
            self::dedupeKey($pageUri, $ipHash),
            time(),
            (int) ceil(self::DEDUPE_WINDOW / 60)
        );
    }

    private static function dedupeKey(string $pageUri, string $ipHash): string
    {
        return 'dedupe.ip_page.' . $ipHash . '.' . hash('sha1', $pageUri);
    }

    /**
     * Resolve our Kirby cache bucket.
     *
     * `kirby()->cache('lemmon.helpful')` is mapped to the plugin option
     * `cache` by AppCaches::cacheOptionsKey() -- configured in index.php.
     * No inline config is needed (and Kirby's signature does not accept
     * one; any extra positional arg would be silently dropped).
     */
    private static function cache()
    {
        return \kirby()->cache(self::CACHE_NAMESPACE);
    }

    // --- HTTP response ---------------------------------------------------

    private static function respond(bool $isHtmx, ?Page $page): mixed
    {
        if ($isHtmx) {
            return $page ? (string) \snippet('helpful', ['page' => $page], true) : '';
        }

        return \go($page ? $page->url() : \site()->url());
    }

    // --- IP handling -----------------------------------------------------

    private static function clientIp(): ?string
    {
        $ip = \kirby()->visitor()->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private static function hashIp(?string $ip): string
    {
        $normalized = self::anonymizeIp($ip);

        return $normalized === null ? '' : hash_hmac('sha256', $normalized, self::secret());
    }

    /**
     * Anonymize an IP to /24 (IPv4) or /64 (IPv6).
     *
     * Both prefixes fall on byte boundaries, so we can use trivial
     * byte-level operations instead of bitmasking math.
     */
    private static function anonymizeIp(?string $ip): ?string
    {
        $ip = is_string($ip) ? trim($ip) : '';

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) ?: null;
    }

    // --- Utilities -------------------------------------------------------

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $value): string|false
    {
        $pad = strlen($value) % 4;

        if ($pad !== 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
