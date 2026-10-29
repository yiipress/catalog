<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Site\ContentWriter;
use YiiPress\Catalog\Tests\Support\TempTree;

use function file_get_contents;
use function yaml_parse;

final class ContentWriterTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create([
            'content/index.md' => "# Home\n",
            'content/data/site.yaml' => "name: Catalog\n",
            'content/themes/acme--old.md' => "# Old\n",
            'content/assets/logo.svg' => '<svg/>',
            'content/assets/extensions/acme/old/v1.0.0/1-a.png' => 'old',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testWritesDataCollectionsPagesAndAssets(): void
    {
        $data = self::data();

        new ContentWriter($this->tree->root . '/content')->write($data, [
            'assets/extensions/acme/dusk/v1.1.0/1-home.png' => 'PNG',
        ]);

        $content = $this->tree->root . '/content';
        self::assertSame($data, yaml_parse((string) file_get_contents($content . '/data/catalog.yaml')));
        self::assertSame(
            ['title' => 'Themes', 'permalink' => '/themes/:slug/', 'sort_by' => 'title', 'sort_order' => 'asc', 'entries_per_page' => 0, 'feed' => false],
            yaml_parse((string) file_get_contents($content . '/themes/_collection.yaml')),
        );
        self::assertFileExists($content . '/plugins/_collection.yaml');

        $page = (string) file_get_contents($content . '/themes/acme--dusk.md');
        self::assertStringStartsWith("---\n", $page);
        $front = yaml_parse(explode("---\n", $page)[1]);
        self::assertSame([
            'title' => 'Dusk: "quoted" & <b>',
            'date' => '2026-10-01',
            'slug' => 'acme--dusk',
            'permalink' => '/themes/acme/dusk/',
            'summary' => 'A dark theme.',
            'layout' => 'extension',
            'categories' => ['blog'],
            'extra' => ['id' => 'acme/dusk'],
        ], $front);

        self::assertSame('PNG', file_get_contents($content . '/assets/extensions/acme/dusk/v1.1.0/1-home.png'));
    }

    public function testReplacesOnlyGeneratedFiles(): void
    {
        new ContentWriter($this->tree->root . '/content')->write(self::data(), []);

        $content = $this->tree->root . '/content';
        self::assertFileDoesNotExist($content . '/themes/acme--old.md');
        self::assertFileDoesNotExist($content . '/assets/extensions/acme/old/v1.0.0/1-a.png');
        self::assertFileExists($content . '/index.md');
        self::assertFileExists($content . '/data/site.yaml');
        self::assertFileExists($content . '/assets/logo.svg');
    }

    public function testRefusesAssetPathsOutsideTheExtensionAssets(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentWriter($this->tree->root . '/content')->write(self::data(), ['assets/logo.svg' => 'x']);
    }

    public function testFallsBackToGenerationDateWithoutReleaseDate(): void
    {
        $data = self::data();
        $data['extensions']['acme/dusk']['release']['published_at'] = '';

        new ContentWriter($this->tree->root . '/content')->write($data, []);

        $page = (string) file_get_contents($this->tree->root . '/content/themes/acme--dusk.md');
        self::assertStringContainsString("date: \"2026-10-29\"", $page);
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        $types = [];
        foreach (['theme' => 'themes', 'processor' => 'processors', 'importer' => 'importers', 'plugin' => 'plugins'] as $type => $path) {
            $types[$type] = ['title' => ucfirst($path), 'path' => $path, 'installable' => true, 'extensions' => []];
        }
        $types['theme']['extensions'] = ['acme/dusk'];

        return [
            'generated_at' => '2026-10-29T12:00:00Z',
            'engine_versions' => ['0.2.0'],
            'types' => $types,
            'extensions' => [
                'acme/dusk' => [
                    'id' => 'acme/dusk',
                    'type' => 'theme',
                    'name' => 'Dusk: "quoted" & <b>',
                    'description' => 'A dark theme.',
                    'categories' => ['blog'],
                    'url' => '/themes/acme/dusk/',
                    'release' => ['tag' => 'v1.1.0', 'published_at' => '2026-10-01T10:00:00Z'],
                ],
            ],
            'withdrawn' => [],
        ];
    }
}
