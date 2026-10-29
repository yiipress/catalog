<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Catalog\CatalogLoader;
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Catalog\Tests\Support\TempTree;
use YiiPress\Extension\ExtensionType;

use function array_map;
use function implode;

final class CatalogLoaderTest extends TestCase
{
    private const string CATEGORIES = "theme: [blog, docs]\nprocessor: [media]\n";

    private ?TempTree $tree = null;

    protected function tearDown(): void
    {
        $this->tree?->remove();
    }

    public function testLoadsEntriesSortedById(): void
    {
        $this->tree = TempTree::create([
            'categories.yaml' => self::CATEGORIES,
            'extensions/zeta/toc.yaml' => self::entry('https://github.com/zeta/toc', 'media'),
            'extensions/acme/dusk.yaml' => self::entry('https://github.com/acme/theme-dusk'),
        ]);

        $catalog = new CatalogLoader()->load($this->tree->root);

        self::assertSame(['acme/dusk', 'zeta/toc'], array_map(static fn($entry): string => (string) $entry->id, $catalog->entries));
        self::assertSame(['blog', 'docs'], $catalog->categories->for(ExtensionType::Theme));
    }

    public function testEmptyCatalogLoads(): void
    {
        $this->tree = TempTree::create(['categories.yaml' => self::CATEGORIES]);

        self::assertSame([], new CatalogLoader()->load($this->tree->root)->entries);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidTrees(): iterable
    {
        $dusk = self::entry('https://github.com/acme/theme-dusk');

        yield 'no categories file' => [['extensions/acme/dusk.yaml' => $dusk], 'file_missing categories.yaml'];
        yield 'invalid id in path' => [['extensions/Acme/dusk.yaml' => $dusk], 'id_invalid extensions/Acme/dusk.yaml'];
        yield 'reserved name' => [['extensions/acme/con.yaml' => self::entry('https://github.com/acme/con')], 'id_invalid extensions/acme/con.yaml'];
        yield 'wrong extension' => [['extensions/acme/dusk.yml' => $dusk], 'path_invalid extensions/acme/dusk.yml'];
        yield 'file at vendor level' => [['extensions/dusk.yaml' => $dusk], 'path_invalid extensions/dusk.yaml'];
        yield 'too deep' => [['extensions/acme/themes/dusk.yaml' => $dusk], 'path_invalid extensions/acme/themes'];
        yield 'invalid entry' => [['extensions/acme/dusk.yaml' => "categories: [blog]\n"], 'field_missing repository'];
        yield 'unknown category' => [
            ['extensions/acme/dusk.yaml' => self::entry('https://github.com/acme/theme-dusk', 'shop')],
            'value_invalid categories[0]',
        ];
        yield 'same repository twice' => [
            [
                'extensions/acme/dusk.yaml' => $dusk,
                'extensions/acme/dusk-copy.yaml' => self::entry('https://github.com/Acme/Theme-Dusk'),
            ],
            'repository_duplicate extensions/acme/dusk.yaml',
        ];
        yield 'vendor is not the owner' => [
            ['extensions/yiipress/dusk.yaml' => $dusk],
            'ownership_unproven extensions/yiipress/dusk.yaml',
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('invalidTrees')]
    public function testReportsInvalidTree(array $files, string $expected): void
    {
        if (!isset($files['categories.yaml']) && $expected !== 'file_missing categories.yaml') {
            $files['categories.yaml'] = self::CATEGORIES;
        }
        $this->tree = TempTree::create($files);

        try {
            new CatalogLoader()->load($this->tree->root);
        } catch (InvalidEntryException $exception) {
            $got = array_map(static fn($error): string => $error->code . ' ' . $error->field, $exception->errors());
            self::assertContains($expected, $got, 'Got: ' . implode('; ', $got));
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testOwnerMatchIgnoresCase(): void
    {
        $this->tree = TempTree::create([
            'categories.yaml' => self::CATEGORIES,
            'extensions/acme/dusk.yaml' => self::entry('https://github.com/ACME/theme-dusk'),
        ]);

        self::assertCount(1, new CatalogLoader()->load($this->tree->root)->entries);
    }

    public function testReviewedOwnershipRecordAllowsAnotherOwner(): void
    {
        $this->tree = TempTree::create([
            'categories.yaml' => self::CATEGORIES,
            'extensions/yiipress/dusk.yaml' => self::entry('https://github.com/acme/theme-dusk')
                . "ownership:\n  reviewed: https://github.com/yiipress/catalog/pull/3\n",
        ]);

        self::assertCount(1, new CatalogLoader()->load($this->tree->root)->entries);
    }

    public function testReportsErrorsOfEveryFile(): void
    {
        $this->tree = TempTree::create([
            'categories.yaml' => self::CATEGORIES,
            'extensions/acme/one.yaml' => "categories: [blog]\n",
            'extensions/acme/two.yaml' => "categories: [blog]\n",
        ]);

        try {
            new CatalogLoader()->load($this->tree->root);
        } catch (InvalidEntryException $exception) {
            self::assertCount(2, $exception->errors());
            return;
        }

        self::fail('No exception thrown.');
    }

    private static function entry(string $repository, string $category = 'blog'): string
    {
        return "repository: {$repository}\ncategories: [{$category}]\n";
    }
}
