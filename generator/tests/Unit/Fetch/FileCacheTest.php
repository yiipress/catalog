<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Fetch;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Fetch\FileCache;
use YiiPress\Catalog\Tests\Support\TempTree;

final class FileCacheTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create();
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testStoresAndReads(): void
    {
        $cache = new FileCache($this->tree->root . '/cache');

        self::assertNull($cache->get('manifests/acme/dusk/v1.0.0.yaml'));
        $cache->set('manifests/acme/dusk/v1.0.0.yaml', 'id: acme/dusk');

        self::assertSame('id: acme/dusk', $cache->get('manifests/acme/dusk/v1.0.0.yaml'));
        self::assertSame('id: acme/dusk', new FileCache($this->tree->root . '/cache')->get('manifests/acme/dusk/v1.0.0.yaml'));
        self::assertFileExists($this->tree->root . '/cache/manifests/acme/dusk/v1.0.0.yaml');
    }

    public function testRefusesKeysLeavingTheDirectory(): void
    {
        $cache = new FileCache($this->tree->root . '/cache');

        $this->expectException(\InvalidArgumentException::class);

        $cache->set('../outside', 'x');
    }
}
