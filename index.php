<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Helpful.php';

use Lemmon\Helpful\Helpful;

Kirby::plugin('lemmon/helpful', [
    'options' => [
        'secret'  => null,
        'storage' => [
            'dir' => null,
        ],
        // Kirby resolves `kirby()->cache('lemmon.helpful')` to this option
        // key via AppCaches::cacheOptionsKey(): no cache subname means the
        // option key is plain `cache`. The explicit `prefix` skips Kirby's
        // default `{indexUrl-slug}/lemmon/helpful` so HTTP and CLI don't
        // end up with separate cache directories.
        'cache' => [
            'active' => true,
            'type'   => 'file',
            'prefix' => 'lemmon/helpful',
        ],
    ],
    'snippets' => [
        'helpful' => __DIR__ . '/snippets/helpful.php',
    ],
    'routes' => [
        [
            'pattern' => 'helpful',
            'method'  => 'POST',
            'action'  => fn (...$args) => Helpful::handle(...$args),
        ],
    ],
]);
