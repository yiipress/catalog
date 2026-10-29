<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Catalog\Categories;
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Extension\ExtensionType;

final class CategoriesTest extends TestCase
{
    public function testReadsCategoriesPerType(): void
    {
        $categories = Categories::fromYaml("theme: [blog, docs]\nprocessor: [media]\nimporter: []\nplugin: [seo]\n");

        self::assertSame(['blog', 'docs'], $categories->for(ExtensionType::Theme));
        self::assertSame(['media'], $categories->for(ExtensionType::Processor));
        self::assertSame([], $categories->for(ExtensionType::Importer));
        self::assertTrue($categories->isKnown('seo'));
        self::assertFalse($categories->isKnown('shop'));
    }

    public function testTypeMissingFromFileHasNoCategories(): void
    {
        self::assertSame([], Categories::fromYaml("theme: [blog]\n")->for(ExtensionType::Plugin));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'unknown type' => ["widget: [a]\n"];
        yield 'bad name' => ["theme: [Blog]\n"];
        yield 'not a list' => ["theme: blog\n"];
        yield 'repeated' => ["theme: [blog, blog]\n"];
    }

    #[DataProvider('invalidFiles')]
    public function testRejectsInvalidFile(string $yaml): void
    {
        $this->expectException(InvalidEntryException::class);
        $this->expectExceptionMessage('categories.yaml');

        Categories::fromYaml($yaml);
    }
}
