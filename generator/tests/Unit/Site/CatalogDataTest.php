<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Catalog\Categories;
use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Entry\EntryParser;
use YiiPress\Catalog\Fetch\FetchedExtension;
use YiiPress\Catalog\Fetch\ReleaseInfo;
use YiiPress\Catalog\Fetch\ReleaseRecord;
use YiiPress\Catalog\Listing\Listing;
use YiiPress\Catalog\Listing\ListingBuilder;
use YiiPress\Catalog\Site\CatalogData;
use YiiPress\Catalog\Tests\Support\Manifests;
use YiiPress\Extension\ExtensionId;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Manifest\ManifestParser;

use function ltrim;

final class CatalogDataTest extends TestCase
{
    public function testDescribesAListedTheme(): void
    {
        $extra = "homepage: https://acme.example/dusk\ntheme:\n  intended_use: Personal blogs\n  screenshots: [screenshots/home.png]\n  demo: https://acme.example/demo\n";
        $listing = self::listing(self::entry("official: true\n"), [
            self::release('v1.1.0', Manifests::theme('acme/dusk', '1.1.0', '>=0.2.0 <1.0', $extra)),
            new ReleaseRecord(new ReleaseInfo('v1.0.0', '1.0.0', '2026-09-01T00:00:00Z', 'https://github.com/acme/theme-dusk/releases/tag/v1.0.0'), null, [
                new ManifestError('manifest_missing', '', 'Release v1.0.0 has no extension.yaml at the repository root.'),
            ]),
        ]);

        $data = new CatalogData()->build([$listing], [], ['0.2.0', '0.1.13'], '2026-10-29T12:00:00Z', [
            'acme/dusk' => ['screenshots/home.png' => '/assets/extensions/acme/dusk/v1.1.0/home.png'],
        ]);

        self::assertSame('2026-10-29T12:00:00Z', $data['generated_at']);
        self::assertSame(['0.2.0', '0.1.13'], $data['engine_versions']);
        self::assertSame(['acme/dusk'], $data['types']['theme']['extensions']);
        self::assertSame([], $data['types']['plugin']['extensions']);
        self::assertTrue($data['types']['theme']['installable']);
        self::assertFalse($data['types']['importer']['installable']);

        $dusk = $data['extensions']['acme/dusk'];
        self::assertSame('Dusk', $dusk['name']);
        self::assertSame('theme', $dusk['type']);
        self::assertSame('BSD-3-Clause', $dusk['license']);
        self::assertSame([['name' => 'Acme', 'url' => null]], $dusk['authors']);
        self::assertSame('https://github.com/acme/theme-dusk', $dusk['repository']);
        self::assertSame('https://acme.example/dusk', $dusk['homepage']);
        self::assertTrue($dusk['official']);
        self::assertSame('listed', $dusk['status']);
        self::assertSame('/themes/acme/dusk/', $dusk['url']);
        self::assertSame('yiipress get:theme --url=https://github.com/acme/theme-dusk', $dusk['install']);
        self::assertSame(['tag' => 'v1.1.0', 'version' => '1.1.0', 'published_at' => '2026-10-01T00:00:00Z', 'url' => 'https://github.com/acme/theme-dusk/releases/tag/v1.1.0', 'yiipress' => '>=0.2.0 <1.0'], $dusk['release']);
        self::assertSame(['0.2.0' => 'v1.1.0', '0.1.13' => null], $dusk['latest_compatible']);
        self::assertSame('Personal blogs', $dusk['theme']['intended_use']);
        self::assertSame(['/assets/extensions/acme/dusk/v1.1.0/home.png'], $dusk['theme']['screenshots']);
        self::assertSame('https://acme.example/demo', $dusk['theme']['demo']);
        self::assertSame('minimal', $dusk['theme']['fallback']);
        self::assertNull($dusk['processor']);
        self::assertCount(2, $dusk['releases']);
        self::assertTrue($dusk['releases'][0]['installable']);
        self::assertSame(['0.2.0' => true, '0.1.13' => false], $dusk['releases'][0]['compatible']);
        self::assertFalse($dusk['releases'][1]['installable']);
        self::assertSame(['Release v1.0.0 has no extension.yaml at the repository root.'], $dusk['releases'][1]['problems']);
        self::assertFalse($dusk['releases'][1]['unread']);
        self::assertSame('2026-10-29T12:00:00Z', $dusk['fetched_at']);
        self::assertFalse($dusk['stale']);
    }

    public function testMarksReleasesWhoseManifestCouldNotBeRead(): void
    {
        $listing = self::listing(self::entry(), [
            self::release('v1.1.0', Manifests::theme('acme/dusk', '1.1.0')),
            new ReleaseRecord(new ReleaseInfo('v1.0.0', '1.0.0', '2026-09-01T00:00:00Z', 'https://github.com/acme/theme-dusk/releases/tag/v1.0.0'), null, [
                new ManifestError('network_failed', '', 'GitHub did not answer.'),
            ]),
        ]);

        $data = new CatalogData()->build([$listing], [], ['0.2.0'], '2026-10-29T12:00:00Z', []);

        $releases = $data['extensions']['acme/dusk']['releases'];
        self::assertFalse($releases[0]['unread']);
        self::assertTrue($releases[1]['unread']);
        self::assertFalse($releases[1]['installable']);
    }

    public function testListsWithdrawnIdsWithoutDetails(): void
    {
        $tombstone = self::entry("status: withdrawn\nwithdrawn: {date: \"2026-12-01\", reason: Gone.}\n");

        $data = new CatalogData()->build([], [$tombstone], [], '2026-10-29T12:00:00Z', []);

        self::assertSame([], $data['extensions']);
        self::assertSame([['id' => 'acme/dusk', 'date' => '2026-12-01', 'reason' => 'Gone.']], $data['withdrawn']);
    }

    public function testSkipsListingsWithoutValidRelease(): void
    {
        $listing = self::listing(self::entry(), [
            new ReleaseRecord(new ReleaseInfo('v1.0.0', '1.0.0', '', ''), null, [new ManifestError('manifest_missing', '', 'x')]),
        ]);

        self::assertSame([], new CatalogData()->build([$listing], [], [], 'now', [])['extensions']);
    }

    public function testAbandonedEntryIsKeptAndMarked(): void
    {
        $listing = self::listing(self::entry("status: abandoned\n"), [self::release('v1.0.0', Manifests::theme('acme/dusk', '1.0.0'))]);

        $data = new CatalogData()->build([$listing], [], ['0.2.0'], 'now', []);

        self::assertSame('abandoned', $data['extensions']['acme/dusk']['status']);
    }

    public function testDescribesAProcessorWithExampleTexts(): void
    {
        $yaml = <<<'YAML'
            manifest: 1
            id: acme/dusk
            type: processor
            name: Dusk TOC
            description: Adds a table of contents.
            version: 1.0.0
            yiipress: ">=0.2.0"
            license: MIT
            authors: [{name: Acme, url: "https://acme.example"}]
            processor:
              entry_points: [{path: toc.php, stages: [after_markdown]}]
              examples: [{input: examples/in.md, output: examples/out.html}]
            YAML;
        $listing = new ListingBuilder(Categories::fromYaml("processor: [blog]\n"), ['0.2.0'])
            ->build(new FetchedExtension(self::entry(), [self::release('v1.0.0', $yaml)], 'now'));

        $data = new CatalogData()->build([$listing], [], ['0.2.0'], 'now', [], [
            'acme/dusk' => ['examples/in.md' => '# Hi', 'examples/out.html' => '<h1>Hi</h1>'],
        ]);

        $dusk = $data['extensions']['acme/dusk'];
        self::assertSame('/processors/acme/dusk/', $dusk['url']);
        self::assertSame('yiipress get:processor --url=https://github.com/acme/theme-dusk', $dusk['install']);
        self::assertSame([['name' => 'Acme', 'url' => 'https://acme.example']], $dusk['authors']);
        self::assertNull($dusk['theme']);
        self::assertSame([
            'entry_points' => [['path' => 'toc.php', 'stages' => ['after_markdown']]],
            'examples' => [['input' => '# Hi', 'output' => '<h1>Hi</h1>']],
        ], $dusk['processor']);
        self::assertSame(['acme/dusk'], $data['types']['processor']['extensions']);
    }

    private static function release(string $tag, string $yaml): ReleaseRecord
    {
        return new ReleaseRecord(
            new ReleaseInfo($tag, ltrim($tag, 'v'), '2026-10-01T00:00:00Z', 'https://github.com/acme/theme-dusk/releases/tag/' . $tag),
            new ManifestParser()->parse($yaml),
        );
    }

    /**
     * @param list<ReleaseRecord> $releases
     */
    private static function listing(CatalogEntry $entry, array $releases): Listing
    {
        return new ListingBuilder(Categories::fromYaml("theme: [blog]\n"), ['0.2.0', '0.1.13'])
            ->build(new FetchedExtension($entry, $releases, '2026-10-29T12:00:00Z'));
    }

    private static function entry(string $extra = ''): CatalogEntry
    {
        $id = ExtensionId::tryFromString('acme/dusk');
        self::assertNotNull($id);

        return new EntryParser()->parse($id, "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n" . $extra);
    }
}
