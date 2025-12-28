<?php

declare(strict_types=1);

namespace Lemmon\Helpful;

use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use Kirby\Http\Cookie;
use Kirby\Http\Request;
use Kirby\Session\Session;

class Helpful
{
    public const CACHE_NAMESPACE = 'lemmon.helpful';
    public const DEFAULT_TOKEN_TTL = 1800;
    public const DEFAULT_RATE_PER_IP = 30;
    public const DEFAULT_RATE_WINDOW = 600;
    public const DEFAULT_RATE_PER_IP_PAGE = 3;
    public const DEFAULT_RATE_PAGE_WINDOW = 86400;
    public const DEFAULT_DEDUPE_WINDOW = 86400;
    public const DEFAULT_SESSION_PREFIX = 'helpful.voted.';
    public const DEFAULT_SESSION_LONG = true;
    public const DEFAULT_STORAGE_ENABLED = true;
    public const DEFAULT_STORAGE_FILE = 'votes.jsonl';
    public const DEFAULT_COUNTS_ENABLED = true;
    public const DEFAULT_COUNT_FIELD_YES = 'helpful_yes';
    public const DEFAULT_COUNT_FIELD_NO = 'helpful_no';
    public const DEFAULT_COUNT_IMPERSONATE = 'kirby';
    public const DEFAULT_QUESTION = 'Was this page helpful?';
    public const DEFAULT_YES_LABEL = 'Yes';
    public const DEFAULT_NO_LABEL = 'No';
    public const DEFAULT_CONFIRMATION = 'Thanks for your feedback.';
    public const DEFAULT_IP_ANONYMIZE_ENABLED = true;
    public const DEFAULT_IP_ANONYMIZE_V4 = 24;
    public const DEFAULT_IP_ANONYMIZE_V6 = 64;
    public const CLOCK_SKEW_TOLERANCE = 60;
    public const DEFAULT_LOGGING_ENABLED = false;

    /**
     * Handle POST request to the helpful feedback endpoint.
     *
     * Validates the request, checks rate limits and deduplication, stores the vote,
     * updates page counts, and returns an appropriate response (HTMX partial or redirect).
     *
     * @return string|\Kirby\Http\Response Response content or redirect
     */
    public static function handle()
    {
        $request = \kirby()->request();
        $data = $request->data();
        $pageId = static::stringValue($data['pageId'] ?? '');
        $token = static::stringValue($data['token'] ?? '');
        $value = strtolower(static::stringValue($data['value'] ?? ''));
        $page = $pageId !== '' ? \page($pageId) : null;
        $isHtmx = static::isHtmx($request);

        if (static::isEnabled() === false) {
            return static::respond($isHtmx, $page);
        }

        if ($page === null) {
            return static::respond($isHtmx, null);
        }

        if (static::allowNoJs() === false && $isHtmx === false) {
            return static::respond($isHtmx, $page);
        }

        if ($value !== 'yes' && $value !== 'no') {
            return static::respond($isHtmx, $page);
        }

        if (static::validateToken($token, $pageId) === false) {
            static::log('Token validation failed', ['pageId' => $pageId]);
            return static::respond($isHtmx, $page);
        }

        $ip = static::clientIp();

        if (static::isDuplicateVote($pageId, $ip) === true) {
            static::rememberVoted($pageId);
            return static::respond($isHtmx, $page);
        }

        if (static::checkRateLimits($pageId, $ip) === false) {
            static::log('Rate limit exceeded', ['pageId' => $pageId]);
            return static::respond($isHtmx, $page);
        }

        static::storeVote($pageId, $value, $ip, static::userAgent());
        static::updateCounts($page, $value);
        static::markDuplicate($pageId, $ip);
        static::rememberVoted($pageId);

        return static::respond($isHtmx, $page);
    }

    /**
     * Generate a signed token for a page to prevent blind POST spam.
     *
     * Token includes page ID, timestamp, and nonce, signed with HMAC.
     * Returns empty string on failure.
     *
     * @param string $pageId The page identifier
     * @return string Base64url-encoded payload with signature, or empty string on failure
     */
    public static function token(string $pageId): string
    {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            $nonce = bin2hex(hash('sha256', uniqid((string) mt_rand(), true), true));
        }

        $payload = [
            'pageId' => $pageId,
            'issuedAt' => time(),
            'nonce' => $nonce,
        ];

        $payloadJson = json_encode($payload);

        if ($payloadJson === false) {
            return '';
        }

        $payloadEncoded = static::base64urlEncode($payloadJson);
        $signature = static::sign($payloadEncoded);

        return $payloadEncoded . '.' . $signature;
    }

    /**
     * Validate a signed token for a page.
     *
     * Checks token format, signature, TTL, and that it matches the page ID.
     * Uses hash_equals() to prevent timing attacks.
     *
     * @param string $token The token to validate
     * @param string $pageId The expected page identifier
     * @return bool True if token is valid, false otherwise
     */
    public static function validateToken(#[\SensitiveParameter] string $token, string $pageId): bool
    {
        if ($token === '' || $pageId === '') {
            return false;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return false;
        }

        [$payloadEncoded, $signature] = $parts;
        $payloadJson = static::base64urlDecode($payloadEncoded);

        if ($payloadJson === false) {
            return false;
        }

        $payload = json_decode($payloadJson, true);

        if (is_array($payload) === false) {
            return false;
        }

        if (($payload['pageId'] ?? null) !== $pageId) {
            return false;
        }

        $nonce = $payload['nonce'] ?? null;

        if (is_string($nonce) === false || $nonce === '') {
            return false;
        }

        $issuedAt = $payload['issuedAt'] ?? null;

        if (is_int($issuedAt) === false) {
            if (is_string($issuedAt) === false || ctype_digit($issuedAt) === false) {
                return false;
            }

            $issuedAt = (int) $issuedAt;
        }

        $now = time();
        $ttl = static::tokenTtl();

        if ($issuedAt > ($now + self::CLOCK_SKEW_TOLERANCE)) {
            return false;
        }

        if (($now - $issuedAt) > $ttl) {
            return false;
        }

        $expected = static::sign($payloadEncoded);

        return hash_equals($expected, $signature);
    }

    /**
     * Check if a visitor has already voted on a page.
     *
     * Checks both session data (UX optimization) and IP hash deduplication cache.
     * Falls back to IP dedupe when session is unavailable.
     *
     * @param string $pageId The page identifier
     * @return bool True if vote exists within the deduplication window, false otherwise
     */
    public static function hasVoted(string $pageId): bool
    {
        if ($pageId === '') {
            return false;
        }

        $dedupe = static::isDuplicateVote($pageId, static::clientIp());

        if (static::sessionEnabled() === false) {
            return $dedupe;
        }

        if (static::hasSessionCookie() === false) {
            return $dedupe;
        }

        $session = static::sessionForRead();

        if ($session === null) {
            return $dedupe;
        }

        $key = static::sessionKey($pageId);
        $votedAt = $session->get($key);

        if (is_int($votedAt) === false) {
            if (is_string($votedAt) === false || ctype_digit($votedAt) === false) {
                return $dedupe;
            }

            $votedAt = (int) $votedAt;
        }

        $window = static::dedupeWindow();

        if ($window <= 0) {
            return $dedupe;
        }

        $now = time();

        if ($votedAt > ($now + self::CLOCK_SKEW_TOLERANCE)) {
            $session->remove($key);
            return $dedupe;
        }

        if (($now - $votedAt) <= $window) {
            return true;
        }

        $session->remove($key);
        return $dedupe;
    }

    /**
     * Check if the helpful plugin is enabled.
     *
     * @return bool True if enabled, false otherwise
     */
    public static function isEnabled(): bool
    {
        return (bool) \option('lemmon.helpful.enabled', true);
    }

    protected static function allowNoJs(): bool
    {
        return (bool) \option('lemmon.helpful.allowNoJs', true);
    }

    protected static function tokenTtl(): int
    {
        return max(0, (int) \option('lemmon.helpful.tokenTtl', self::DEFAULT_TOKEN_TTL));
    }

    protected static function sign(string $payload): string
    {
        $signature = hash_hmac('sha256', $payload, static::secret(), true);
        return static::base64urlEncode($signature);
    }

    protected static function secret(): string
    {
        $secret = \option('lemmon.helpful.secret');

        if (is_string($secret) === true && $secret !== '') {
            return $secret;
        }

        return \kirby()->contentToken(null, 'lemmon.helpful');
    }

    protected static function sessionEnabled(): bool
    {
        $session = \option('lemmon.helpful.session', []);
        $session = is_array($session) ? $session : [];

        return (bool) ($session['enabled'] ?? true);
    }

    protected static function sessionLong(): bool
    {
        $session = \option('lemmon.helpful.session', []);
        $session = is_array($session) ? $session : [];

        return (bool) ($session['long'] ?? self::DEFAULT_SESSION_LONG);
    }

    protected static function sessionKeyPrefix(): string
    {
        $session = \option('lemmon.helpful.session', []);
        $session = is_array($session) ? $session : [];
        $prefix = $session['keyPrefix'] ?? self::DEFAULT_SESSION_PREFIX;
        $prefix = is_string($prefix) ? $prefix : self::DEFAULT_SESSION_PREFIX;
        $prefix = preg_replace('/[^A-Za-z0-9_.-]/', '_', $prefix) ?? self::DEFAULT_SESSION_PREFIX;

        return $prefix !== '' ? $prefix : self::DEFAULT_SESSION_PREFIX;
    }

    protected static function sessionKey(string $pageId): string
    {
        return static::sessionKeyPrefix() . hash('sha1', $pageId);
    }

    protected static function sessionForRead(): null|Session
    {
        if (static::hasSessionCookie() === false) {
            return null;
        }

        return \kirby()->session(['detect' => true]);
    }

    protected static function sessionForWrite(): null|Session
    {
        if (static::sessionEnabled() === false) {
            return null;
        }

        return \kirby()->session([
            'detect' => true,
            'long' => static::sessionLong(),
        ]);
    }

    protected static function rememberVoted(string $pageId): void
    {
        if ($pageId === '' || static::sessionEnabled() === false) {
            return;
        }

        $window = static::dedupeWindow();

        if ($window <= 0) {
            return;
        }

        $session = static::sessionForWrite();

        if ($session === null) {
            return;
        }

        $session->set(static::sessionKey($pageId), time());
    }

    protected static function hasSessionCookie(): bool
    {
        $cookieName = \option('session.cookieName', 'kirby_session');
        $cookieName = is_string($cookieName) && $cookieName !== '' ? $cookieName : 'kirby_session';

        return Cookie::get($cookieName) !== null;
    }

    protected static function storageFile(): string
    {
        $storage = \option('lemmon.helpful.storage', []);
        $storage = is_array($storage) ? $storage : [];
        $dir = $storage['dir'] ?? null;
        $file = $storage['file'] ?? self::DEFAULT_STORAGE_FILE;

        $file = is_string($file) && $file !== '' ? $file : self::DEFAULT_STORAGE_FILE;

        if (is_string($dir) === false || $dir === '') {
            $root = \kirby()->root('logs');
            $root = is_string($root) && $root !== '' ? $root : \kirby()->root('site') . '/logs';
            $dir = rtrim($root, '/\\') . '/helpful';
        }

        return rtrim($dir, '/\\') . '/' . $file;
    }

    protected static function storageEnabled(): bool
    {
        $storage = \option('lemmon.helpful.storage', []);
        $storage = is_array($storage) ? $storage : [];

        return (bool) ($storage['enabled'] ?? self::DEFAULT_STORAGE_ENABLED);
    }

    protected static function storeVote(
        string $pageId,
        string $value,
        null|string $ip,
        null|string $userAgent,
    ): void {
        if (static::storageEnabled() === false) {
            return;
        }

        $ipHash = static::hashIp($ip);

        $entry = [
            'pageId' => $pageId,
            'value' => $value,
            'timestamp' => time(),
        ];

        if (static::storeIpHash() === true) {
            if ($ipHash !== '') {
                $entry['ipHash'] = $ipHash;
            }
        }

        if (static::storeUserAgentHash() === true) {
            $entry['userAgentHash'] = static::hashUserAgent($userAgent);
        }

        $payload = json_encode($entry);

        if ($payload === false) {
            return;
        }

        try {
            F::append(static::storageFile(), $payload . PHP_EOL);
        } catch (\Throwable $exception) {
            static::log('Vote storage failed', [
                'pageId' => $pageId,
                'error' => $exception->getMessage(),
            ]);
            return;
        }
    }

    protected static function updateCounts(Page $page, string $value): void
    {
        if (static::countsEnabled() === false) {
            return;
        }

        $field = static::countField($value);

        if ($field === null) {
            return;
        }

        $counts = static::countsConfig();
        $language = $counts['language'] ?? null;
        $impersonate = $counts['impersonate'] ?? self::DEFAULT_COUNT_IMPERSONATE;
        $impersonate = is_string($impersonate) ? trim($impersonate) : null;

        $update = static function () use ($page, $field, $language) {
            if (is_string($language) === true && $language !== '') {
                $content = $page->content($language);
                $current = (int) $content->get($field)->value();
                $page->update([$field => $current + 1], $language);
                return;
            }

            $page->increment($field);
        };

        try {
            if ($impersonate !== null && $impersonate !== '') {
                \kirby()->impersonate($impersonate, $update);
                return;
            }

            $update();
        } catch (\Throwable $exception) {
            return;
        }
    }

    protected static function storeIpHash(): bool
    {
        return (bool) \option('lemmon.helpful.storeIpHash', true);
    }

    protected static function storeUserAgentHash(): bool
    {
        return (bool) \option('lemmon.helpful.storeUserAgentHash', false);
    }

    protected static function countsConfig(): array
    {
        $counts = \option('lemmon.helpful.counts', []);
        return is_array($counts) ? $counts : [];
    }

    protected static function countsEnabled(): bool
    {
        $counts = static::countsConfig();
        return (bool) ($counts['enabled'] ?? self::DEFAULT_COUNTS_ENABLED);
    }

    protected static function countField(string $value): null|string
    {
        $counts = static::countsConfig();
        $yesField = $counts['yesField'] ?? self::DEFAULT_COUNT_FIELD_YES;
        $noField = $counts['noField'] ?? self::DEFAULT_COUNT_FIELD_NO;

        $field = match ($value) {
            'yes' => $yesField,
            'no' => $noField,
            default => null,
        };

        if (is_string($field) === false) {
            return null;
        }

        $field = trim($field);

        return $field !== '' ? $field : null;
    }

    protected static function hashIp(null|string $ip): string
    {
        $ip = static::normalizeIp($ip);

        if ($ip === null || $ip === '') {
            return '';
        }

        return hash_hmac('sha256', $ip, static::secret());
    }

    protected static function hashUserAgent(null|string $userAgent): string
    {
        $userAgent ??= '';
        return hash_hmac('sha256', $userAgent, static::secret());
    }

    protected static function checkRateLimits(string $pageId, null|string $ip): bool
    {
        $rateLimit = \option('lemmon.helpful.rateLimit', []);
        $rateLimit = is_array($rateLimit) ? $rateLimit : [];
        $perIp = max(0, (int) ($rateLimit['perIp'] ?? self::DEFAULT_RATE_PER_IP));
        $window = max(0, (int) ($rateLimit['window'] ?? self::DEFAULT_RATE_WINDOW));
        $perIpPage = max(0, (int) ($rateLimit['perIpPage'] ?? self::DEFAULT_RATE_PER_IP_PAGE));
        $pageWindow = max(0, (int) ($rateLimit['pageWindow'] ?? self::DEFAULT_RATE_PAGE_WINDOW));

        if ($perIp === 0 || $window === 0) {
            $perIp = 0;
        }

        if ($perIpPage === 0 || $pageWindow === 0) {
            $perIpPage = 0;
        }

        $ipHash = static::hashIp($ip);

        if ($ipHash === '') {
            return true;
        }

        if ($perIp > 0 && $window > 0) {
            if (static::allowBucket('rate.ip.' . $ipHash, $perIp, $window) === false) {
                return false;
            }
        }

        if ($perIpPage > 0 && $pageWindow > 0) {
            $pageHash = hash('sha1', $pageId);

            if (
                static::allowBucket(
                    'rate.ip_page.' . $ipHash . '.' . $pageHash,
                    $perIpPage,
                    $pageWindow,
                ) === false
            ) {
                return false;
            }
        }

        return true;
    }

    protected static function isDuplicateVote(string $pageId, null|string $ip): bool
    {
        if ($pageId === '') {
            return false;
        }

        $window = static::dedupeWindow();

        if ($window <= 0) {
            return false;
        }

        $ipHash = static::hashIp($ip);

        if ($ipHash === '') {
            return false;
        }

        return static::cache()->get(static::dedupeKey($pageId, $ipHash)) !== null;
    }

    protected static function markDuplicate(string $pageId, null|string $ip): void
    {
        if ($pageId === '') {
            return;
        }

        $window = static::dedupeWindow();

        if ($window <= 0) {
            return;
        }

        $ipHash = static::hashIp($ip);

        if ($ipHash === '') {
            return;
        }

        $ttlMinutes = max(1, (int) ceil($window / 60));
        static::cache()->set(static::dedupeKey($pageId, $ipHash), time(), $ttlMinutes);
    }

    protected static function dedupeKey(string $pageId, string $ipHash): string
    {
        return 'dedupe.ip_page.' . $ipHash . '.' . hash('sha1', $pageId);
    }

    protected static function dedupeWindow(): int
    {
        $dedupe = \option('lemmon.helpful.dedupe', []);
        $dedupe = is_array($dedupe) ? $dedupe : [];
        $window = $dedupe['window'] ?? self::DEFAULT_DEDUPE_WINDOW;

        return max(0, (int) $window);
    }

    protected static function allowBucket(string $key, int $limit, int $window): bool
    {
        $cache = static::cache();
        $now = time();
        $bucket = $cache->get($key);

        if (is_array($bucket) === false || isset($bucket['count'], $bucket['reset']) === false) {
            $bucket = [
                'count' => 0,
                'reset' => $now + $window,
            ];
        }

        if ($bucket['reset'] <= $now) {
            $bucket = [
                'count' => 0,
                'reset' => $now + $window,
            ];
        }

        if ($bucket['count'] >= $limit) {
            return false;
        }

        $bucket['count']++;
        $ttlMinutes = max(1, (int) ceil(($bucket['reset'] - $now) / 60));
        $cache->set($key, $bucket, $ttlMinutes);

        return true;
    }

    protected static function cache()
    {
        $config = \option('lemmon.helpful.cache');
        $config = is_array($config) ? $config : null;

        return \kirby()->cache(self::CACHE_NAMESPACE, $config);
    }

    protected static function isHtmx(Request $request): bool
    {
        $header = $request->header('HX-Request');
        return strtolower((string) $header) === 'true';
    }

    protected static function respond(bool $isHtmx, null|Page $page)
    {
        if ($isHtmx === true) {
            return $page instanceof Page ? static::renderSnippet($page) : '';
        }

        $url = $page instanceof Page ? $page->url() : \site()->url();
        return \go($url);
    }

    protected static function renderSnippet(Page $page): string
    {
        return (string) \snippet('helpful', ['page' => $page], true);
    }

    protected static function clientIp(): null|string
    {
        $ip = \kirby()->visitor()->ip();
        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    protected static function userAgent(): null|string
    {
        $userAgent = \kirby()->visitor()->userAgent();
        return is_string($userAgent) && $userAgent !== '' ? $userAgent : null;
    }

    protected static function base64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function base64urlDecode(string $value)
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    protected static function stringValue($value): string
    {
        if (is_string($value) === true) {
            return trim($value);
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        return '';
    }

    protected static function normalizeIp(null|string $ip): null|string
    {
        $ip = is_string($ip) ? trim($ip) : '';

        if ($ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $config = \option('lemmon.helpful.ipAnonymize', []);

        if (is_bool($config) === true) {
            $config = ['enabled' => $config];
        }

        $config = is_array($config) ? $config : [];
        $enabled = $config['enabled'] ?? self::DEFAULT_IP_ANONYMIZE_ENABLED;

        if ($enabled === false) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $prefix = (int) ($config['v4'] ?? self::DEFAULT_IP_ANONYMIZE_V4);
            return static::truncateIpv4($ip, $prefix);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $prefix = (int) ($config['v6'] ?? self::DEFAULT_IP_ANONYMIZE_V6);
            return static::truncateIpv6($ip, $prefix);
        }

        return $ip;
    }

    protected static function truncateIpv4(string $ip, int $prefix): string
    {
        $prefix = max(0, min(32, $prefix));

        if ($prefix === 32) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        $data = unpack('N', $packed);

        if ($data === false || !isset($data[1])) {
            return $ip;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $data[1] & $mask;

        $result = long2ip($network);
        return $result !== false ? $result : $ip;
    }

    protected static function truncateIpv6(string $ip, int $prefix): string
    {
        $prefix = max(0, min(128, $prefix));

        if ($prefix === 128) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        $bytes = unpack('C*', $packed);

        if ($bytes === false) {
            return $ip;
        }

        $bits = $prefix;

        for ($i = 1; $i <= 16; $i++) {
            if ($bits >= 8) {
                $bits -= 8;
                continue;
            }

            if ($bits <= 0) {
                $bytes[$i] = 0;
                continue;
            }

            $mask = 0xFF << (8 - $bits);
            $bytes[$i] &= $mask;
            $bits = 0;
        }

        $packed = pack('C*', ...array_values($bytes));

        $result = inet_ntop($packed);
        return $result !== false ? $result : $ip;
    }

    /**
     * Check if development logging is enabled.
     *
     * @return bool True if logging is enabled, false otherwise
     */
    protected static function loggingEnabled(): bool
    {
        $logging = \option('lemmon.helpful.logging', []);
        $logging = is_array($logging) ? $logging : [];

        return (bool) ($logging['enabled'] ?? self::DEFAULT_LOGGING_ENABLED);
    }

    /**
     * Log a development message if logging is enabled.
     *
     * Logs to Kirby's log system with context data. Only logs when
     * `lemmon.helpful.logging.enabled` is true.
     *
     * @param string $message The log message
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    protected static function log(string $message, array $context = []): void
    {
        if (static::loggingEnabled() === false) {
            return;
        }

        $context['plugin'] = 'lemmon.helpful';
        \kirby()->log('helpful', $message, $context);
    }
}
