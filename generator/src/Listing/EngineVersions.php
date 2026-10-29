<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Listing;

use Composer\Semver\Semver;
use YiiPress\Catalog\Fetch\ReleaseInfo;

use function array_map;
use function count;
use function array_values;
use function explode;

/**
 * The YiiPress versions a compatibility table is checked against.
 */
final readonly class EngineVersions
{
    public const int LINES = 5;

    /**
     * The newest patch of each `MAJOR.MINOR` line, newest lines first, at most `$lines`.
     *
     * @param list<ReleaseInfo> $releases Stable YiiPress releases.
     * @return list<string>
     */
    public static function pick(array $releases, int $lines = self::LINES): array
    {
        $picked = [];
        foreach (Semver::rsort(array_map(static fn(ReleaseInfo $release): string => $release->version, $releases)) as $version) {
            [$major, $minor] = explode('.', $version);
            $picked[$major . '.' . $minor] ??= $version;
            if (count($picked) === $lines) {
                break;
            }
        }

        return array_values($picked);
    }
}
