<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Listing;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Catalog\Categories;
use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Entry\EntryParser;
use YiiPress\Catalog\Fetch\FetchedExtension;
use YiiPress\Catalog\Fetch\ReleaseInfo;
use YiiPress\Catalog\Fetch\ReleaseRecord;
use YiiPress\Catalog\Listing\Listing;
use YiiPress\Catalog\Listing\ListingBuilder;
use YiiPress\Catalog\Tests\Support\Manifests;
use YiiPress\Extension\ExtensionId;
use YiiPress\Extension\ExtensionType;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Manifest\ManifestParser;

use function array_map;
use function ltrim;

final class ListingBuilderTest extends TestCase
{
    public function testTakesTypeAndDetailsFromNewestValidRelease(): void
    {
        $listing = self::build([
            self::broken('v1.2.0'),
            self::valid('v1.1.0', '1.1.0', '>=0.2.0 <1.0'),
            self::valid('v1.0.0', '1.0.0', '>=0.1.0 <0.2'),
        ], ['0.2.0', '0.1.13']);

        self::assertSame(ExtensionType::Theme, $listing->type);
        self::assertSame('1.1.0', $listing->current?->version);
        self::assertSame([], $listing->problems);
    }

    public function testCompatibilityPerReleaseAndEngineVersion(): void
    {
        $listing = self::build([
            self::valid('v1.1.0', '1.1.0', '>=0.2.0 <1.0'),
            self::valid('v1.0.0', '1.0.0', '>=0.1.0 <0.2'),
            self::broken('v0.9.0'),
        ], ['0.2.0', '0.1.13']);

        self::assertSame(['0.2.0', '0.1.13'], $listing->engineVersions);
        self::assertSame(['0.2.0' => true, '0.1.13' => false], $listing->compatibility['v1.1.0']);
        self::assertSame(['0.2.0' => false, '0.1.13' => true], $listing->compatibility['v1.0.0']);
        self::assertSame(['0.2.0' => false, '0.1.13' => false], $listing->compatibility['v0.9.0']);
        self::assertSame(['0.2.0' => 'v1.1.0', '0.1.13' => 'v1.0.0'], $listing->latestCompatible);
    }

    public function testNoCompatibleReleaseForAnEngineVersionIsNull(): void
    {
        $listing = self::build([self::valid('v1.0.0', '1.0.0', '>=0.2.0 <1.0')], ['0.1.13']);

        self::assertSame(['0.1.13' => null], $listing->latestCompatible);
        self::assertSame([], $listing->problems);
    }

    public function testCategoryOutsideTheTypeIsAProblem(): void
    {
        $listing = self::build([self::valid('v1.0.0', '1.0.0')], ['0.2.0'], 'media');

        self::assertSame(['category_type categories[0]'], self::problems($listing->problems));
    }

    public function testNoInstallableReleaseIsAProblem(): void
    {
        $listing = self::build([self::broken('v1.0.0')], ['0.2.0']);

        self::assertNull($listing->type);
        self::assertNull($listing->current);
        self::assertSame(['release_missing '], self::problems($listing->problems));
    }

    public function testNoReleaseAtAllIsAProblem(): void
    {
        self::assertSame(['release_missing '], self::problems(self::build([], ['0.2.0'])->problems));
    }

    public function testTypeChangeBetweenReleasesIsAProblem(): void
    {
        $processor = <<<'YAML'
            manifest: 1
            id: acme/dusk
            type: processor
            name: Dusk
            description: Processor.
            version: 1.1.0
            yiipress: ">=0.2.0"
            license: MIT
            authors: [{name: Acme}]
            processor:
              entry_points: [{path: dusk.php, stages: [after_markdown]}]
            YAML;
        $listing = self::build([
            new ReleaseRecord(new ReleaseInfo('v1.1.0', '1.1.0', '', ''), new ManifestParser()->parse($processor)),
            self::valid('v1.0.0', '1.0.0'),
        ], ['0.2.0'], 'media');

        self::assertSame(['type_changed '], self::problems($listing->problems));
    }

    /**
     * @param list<ReleaseRecord> $releases
     * @param list<string> $engineVersions
     */
    private static function build(array $releases, array $engineVersions, string $category = 'blog'): Listing
    {
        $fetched = new FetchedExtension(self::entry($category), $releases, '2026-10-29T12:00:00Z');
        $categories = Categories::fromYaml("theme: [blog]\nprocessor: [media]\n");

        return new ListingBuilder($categories, $engineVersions)->build($fetched);
    }

    private static function valid(string $tag, string $version, string $yiipress = '>=0.2.0 <1.0'): ReleaseRecord
    {
        return new ReleaseRecord(
            new ReleaseInfo($tag, $version, '2026-10-01T00:00:00Z', 'https://github.com/acme/theme-dusk/releases/tag/' . $tag),
            new ManifestParser()->parse(Manifests::theme('acme/dusk', $version, $yiipress)),
        );
    }

    private static function broken(string $tag): ReleaseRecord
    {
        return new ReleaseRecord(new ReleaseInfo($tag, ltrim($tag, 'v'), '', ''), null, [new ManifestError('manifest_missing', '', 'No manifest.')]);
    }

    private static function entry(string $category): CatalogEntry
    {
        $id = ExtensionId::tryFromString('acme/dusk');
        self::assertNotNull($id);

        return new EntryParser()->parse($id, "repository: https://github.com/acme/theme-dusk\ncategories: [{$category}]\n");
    }

    /**
     * @param list<ManifestError> $problems
     * @return list<string>
     */
    private static function problems(array $problems): array
    {
        return array_map(static fn(ManifestError $problem): string => $problem->code . ' ' . $problem->field, $problems);
    }
}
