<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])->name(['*.php', 'catalog']);

return (new Config())
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS2.0' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
