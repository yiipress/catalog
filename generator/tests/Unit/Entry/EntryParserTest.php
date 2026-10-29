<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Entry;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Entry\EntryParser;
use YiiPress\Catalog\Entry\EntryStatus;
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Extension\ExtensionId;

use function array_map;
use function implode;

final class EntryParserTest extends TestCase
{
    public function testParsesMinimalEntry(): void
    {
        $entry = new EntryParser()->parse(self::id(), "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n");

        self::assertSame('acme/dusk', (string) $entry->id);
        self::assertSame('https://github.com/acme/theme-dusk', $entry->repository->url());
        self::assertSame(['blog'], $entry->categories);
        self::assertSame(EntryStatus::Listed, $entry->status);
        self::assertFalse($entry->official);
        self::assertNull($entry->ownershipRecord);
        self::assertSame([], $entry->history);
        self::assertNull($entry->withdrawal);
    }

    public function testParsesFullEntry(): void
    {
        $yaml = <<<'YAML'
            repository: https://github.com/dusk-org/theme-dusk
            categories: [blog, docs]
            status: abandoned
            official: true
            ownership:
              reviewed: https://github.com/yiipress/catalog/pull/12
            history:
              - date: "2026-11-02"
                from: https://github.com/acme/theme-dusk
                record: https://github.com/yiipress/catalog/pull/40
            YAML;

        $entry = new EntryParser()->parse(self::id(), $yaml);

        self::assertSame(EntryStatus::Abandoned, $entry->status);
        self::assertTrue($entry->official);
        self::assertSame('https://github.com/yiipress/catalog/pull/12', $entry->ownershipRecord);
        self::assertCount(1, $entry->history);
        self::assertSame('2026-11-02', $entry->history[0]->date);
        self::assertSame('https://github.com/acme/theme-dusk', $entry->history[0]->from->url());
        self::assertSame('https://github.com/yiipress/catalog/pull/40', $entry->history[0]->record);
    }

    public function testParsesWithdrawnEntry(): void
    {
        $yaml = <<<'YAML'
            repository: https://github.com/acme/theme-dusk
            categories: [blog]
            status: withdrawn
            withdrawn:
              date: "2026-12-01"
              reason: The author asked to remove it.
            YAML;

        $entry = new EntryParser()->parse(self::id(), $yaml);

        self::assertSame(EntryStatus::Withdrawn, $entry->status);
        self::assertNotNull($entry->withdrawal);
        self::assertSame('2026-12-01', $entry->withdrawal->date);
        self::assertSame('The author asked to remove it.', $entry->withdrawal->reason);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEntries(): iterable
    {
        $base = "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n";

        yield 'not yaml' => ["repository: [\n", 'yaml_invalid'];
        yield 'list at top' => ["- a\n", 'not_mapping'];
        yield 'missing repository' => ["categories: [blog]\n", 'field_missing repository'];
        yield 'missing categories' => ["repository: https://github.com/acme/theme-dusk\n", 'field_missing categories'];
        yield 'not github' => ["repository: https://gitlab.com/acme/dusk\ncategories: [blog]\n", 'source_unsupported repository'];
        yield 'bad github url' => ["repository: https://github.com/acme\ncategories: [blog]\n", 'source_invalid repository'];
        yield 'empty categories' => ["repository: https://github.com/acme/theme-dusk\ncategories: []\n", 'field_invalid categories'];
        yield 'too many categories' => [
            "repository: https://github.com/acme/theme-dusk\ncategories: [a, b, c, d, e, f]\n",
            'field_invalid categories',
        ];
        yield 'bad category' => ["repository: https://github.com/acme/theme-dusk\ncategories: [Blog Themes]\n", 'field_invalid categories[0]'];
        yield 'repeated category' => ["repository: https://github.com/acme/theme-dusk\ncategories: [blog, blog]\n", 'field_duplicate categories[1]'];
        yield 'unknown field' => [$base . "name: Dusk\n", 'field_unknown name'];
        yield 'bad status' => [$base . "status: pending\n", 'value_invalid status'];
        yield 'official not bool' => [$base . "official: yes please\n", 'field_type official'];
        yield 'ownership not https' => [$base . "ownership:\n  reviewed: http://example.com/x\n", 'url_invalid ownership.reviewed'];
        yield 'ownership unknown key' => [$base . "ownership:\n  by: someone\n", 'field_unknown ownership.by'];
        yield 'history bad date' => [
            $base . "history:\n  - {date: yesterday, from: https://github.com/a/b, record: https://github.com/yiipress/catalog/pull/1}\n",
            'field_invalid history[0].date',
        ];
        yield 'history bad from' => [
            $base . "history:\n  - {date: \"2026-01-01\", from: https://example.com/a/b, record: https://github.com/yiipress/catalog/pull/1}\n",
            'source_unsupported history[0].from',
        ];
        yield 'history missing record' => [
            $base . "history:\n  - {date: \"2026-01-01\", from: https://github.com/a/b}\n",
            'field_missing history[0].record',
        ];
        yield 'withdrawn without details' => [$base . "status: withdrawn\n", 'field_missing withdrawn'];
        yield 'details without withdrawn' => [
            $base . "withdrawn: {date: \"2026-01-01\", reason: Gone}\n",
            'field_unknown withdrawn',
        ];
        yield 'withdrawn bad date' => [
            $base . "status: withdrawn\nwithdrawn: {date: \"2026-13-01\", reason: Gone}\n",
            'field_invalid withdrawn.date',
        ];
    }

    #[DataProvider('invalidEntries')]
    public function testReportsInvalidEntry(string $yaml, string $expected): void
    {
        try {
            new EntryParser()->parse(self::id(), $yaml);
        } catch (InvalidEntryException $exception) {
            $got = array_map(
                static fn($error): string => $error->field === '' ? $error->code : $error->code . ' ' . $error->field,
                $exception->errors(),
            );
            self::assertContains($expected, $got, 'Got: ' . implode('; ', $got));
            self::assertStringContainsString('extensions/acme/dusk.yaml', $exception->getMessage());
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testReportsEveryErrorAtOnce(): void
    {
        try {
            new EntryParser()->parse(self::id(), "categories: []\nstatus: gone\n");
        } catch (InvalidEntryException $exception) {
            self::assertCount(3, $exception->errors());
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testRootErrorNamesTheEntryFile(): void
    {
        try {
            new EntryParser()->parse(self::id(), "- a\n");
        } catch (InvalidEntryException $exception) {
            self::assertStringStartsWith('extensions/acme/dusk.yaml must be a mapping', $exception->errors()[0]->message);
            return;
        }

        self::fail('No exception thrown.');
    }

    /**
     * Aliases expanding past the engine's YAML node limit are refused. This also guards the engine pin: the rejected
     * engine commit 51049f1 let this file through.
     */
    public function testRefusesEntryOverTheYamlNodeLimit(): void
    {
        $yaml = "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n"
            . "a: &a [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]\n"
            . "b: &b [*a, *a, *a, *a, *a, *a, *a, *a, *a, *a]\n"
            . "c: &c [*b, *b, *b, *b, *b, *b, *b, *b, *b, *b]\n"
            . "d: [*c, *c, *c, *c, *c, *c, *c, *c, *c, *c]\n";

        try {
            new EntryParser()->parse(self::id(), $yaml);
        } catch (InvalidEntryException $exception) {
            self::assertContains('file_too_large', array_map(
                static fn($error): string => $error->code,
                $exception->errors(),
            ));
            return;
        }

        self::fail('No exception thrown.');
    }

    private static function id(): ExtensionId
    {
        $id = ExtensionId::tryFromString('acme/dusk');
        self::assertNotNull($id);

        return $id;
    }
}
