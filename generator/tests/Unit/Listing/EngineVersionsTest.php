<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Listing;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Fetch\ReleaseInfo;
use YiiPress\Catalog\Listing\EngineVersions;

use function array_map;

final class EngineVersionsTest extends TestCase
{
    public function testPicksNewestPatchOfEachMinorLine(): void
    {
        $versions = EngineVersions::pick(self::releases(['0.3.1', '0.3.0', '0.2.4', '0.2.3', '0.1.13', '0.1.12', '0.0.9', '0.0.1']), 3);

        self::assertSame(['0.3.1', '0.2.4', '0.1.13'], $versions);
    }

    public function testKeepsFewerWhenThereAreFewerLines(): void
    {
        self::assertSame(['1.0.0'], EngineVersions::pick(self::releases(['1.0.0']), 5));
        self::assertSame([], EngineVersions::pick([], 5));
    }

    public function testDefaultIsFiveLines(): void
    {
        $versions = EngineVersions::pick(self::releases(['0.7.0', '0.6.0', '0.5.0', '0.4.0', '0.3.0', '0.2.0', '0.1.0']));

        self::assertSame(['0.7.0', '0.6.0', '0.5.0', '0.4.0', '0.3.0'], $versions);
    }

    /**
     * @param list<string> $versions
     * @return list<ReleaseInfo>
     */
    private static function releases(array $versions): array
    {
        return array_map(static fn(string $version): ReleaseInfo => new ReleaseInfo($version, $version, '', ''), $versions);
    }
}
