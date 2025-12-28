<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Helpful.php';

use Lemmon\Helpful\Helpful;

Kirby::plugin('lemmon/helpful', [
    'options' => [
        'enabled' => true,
        'tokenTtl' => Helpful::DEFAULT_TOKEN_TTL,
        'rateLimit' => [
            'perIp' => Helpful::DEFAULT_RATE_PER_IP,
            'window' => Helpful::DEFAULT_RATE_WINDOW,
            'perIpPage' => Helpful::DEFAULT_RATE_PER_IP_PAGE,
            'pageWindow' => Helpful::DEFAULT_RATE_PAGE_WINDOW,
        ],
        'dedupe' => [
            'window' => Helpful::DEFAULT_DEDUPE_WINDOW,
        ],
        'allowNoJs' => true,
        'cache' => [
            'active' => true,
            'type' => 'file',
        ],
        'session' => [
            'enabled' => true,
            'long' => Helpful::DEFAULT_SESSION_LONG,
            'keyPrefix' => Helpful::DEFAULT_SESSION_PREFIX,
        ],
        'ipAnonymize' => [
            'enabled' => Helpful::DEFAULT_IP_ANONYMIZE_ENABLED,
            'v4' => Helpful::DEFAULT_IP_ANONYMIZE_V4,
            'v6' => Helpful::DEFAULT_IP_ANONYMIZE_V6,
        ],
        'storage' => [
            'enabled' => Helpful::DEFAULT_STORAGE_ENABLED,
            'dir' => null,
            'file' => Helpful::DEFAULT_STORAGE_FILE,
        ],
        'counts' => [
            'enabled' => Helpful::DEFAULT_COUNTS_ENABLED,
            'yesField' => Helpful::DEFAULT_COUNT_FIELD_YES,
            'noField' => Helpful::DEFAULT_COUNT_FIELD_NO,
            'impersonate' => Helpful::DEFAULT_COUNT_IMPERSONATE,
            'language' => null,
        ],
        'storeIpHash' => true,
        'storeUserAgentHash' => false,
        'htmx' => [
            'enabled' => false,
            'target' => 'this',
            'swap' => 'outerHTML',
        ],
        'labels' => [
            'question' => Helpful::DEFAULT_QUESTION,
            'yes' => Helpful::DEFAULT_YES_LABEL,
            'no' => Helpful::DEFAULT_NO_LABEL,
            'confirmation' => Helpful::DEFAULT_CONFIRMATION,
        ],
        'logging' => [
            'enabled' => Helpful::DEFAULT_LOGGING_ENABLED,
        ],
    ],
    'snippets' => [
        'helpful' => __DIR__ . '/snippets/helpful.php',
    ],
    'routes' => [
        [
            'pattern' => 'helpful',
            'method' => 'POST',
            'action' => Helpful::handle(...),
        ],
    ],
]);
