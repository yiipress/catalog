<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Support;

/**
 * Valid `extension.yaml` texts for tests.
 */
final class Manifests
{
    public static function theme(string $id, string $version, string $yiipress = '>=0.2.0 <1.0', string $extra = ''): string
    {
        return <<<YAML
            manifest: 1
            id: {$id}
            type: theme
            name: Dusk
            description: A dark theme for blogs.
            version: {$version}
            yiipress: "{$yiipress}"
            license: BSD-3-Clause
            authors:
              - name: Acme
            {$extra}
            YAML;
    }
}
