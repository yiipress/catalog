<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Catalog\Catalog;
use YiiPress\Catalog\Catalog\Categories;
use YiiPress\Catalog\Catalog\ChangeRules;
use YiiPress\Catalog\Entry\EntryParser;
use YiiPress\Extension\ExtensionId;

use function array_map;
use function implode;
use function str_replace;

final class ChangeRulesTest extends TestCase
{
    private const string DUSK = "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n";
    private const string TOMBSTONE = "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\nstatus: withdrawn\n"
        . "withdrawn: {date: \"2026-01-01\", reason: Gone}\n";

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, bool, list<string>}>
     */
    public static function changes(): iterable
    {
        $moved = "repository: https://github.com/acme/dusk\ncategories: [blog]\n";
        $movedRecorded = $moved . "history:\n  - {date: \"2026-02-01\", from: https://github.com/acme/theme-dusk,"
            . " record: https://github.com/yiipress/catalog/pull/9}\n";

        yield 'new plain entry by anyone' => [[], ['acme/dusk' => self::DUSK], false, []];
        yield 'category change by anyone' => [['acme/dusk' => self::DUSK], ['acme/dusk' => "repository: https://github.com/acme/theme-dusk\ncategories: [docs]\n"], false, []];
        yield 'removal' => [['acme/dusk' => self::DUSK], [], true, ['entry_removed extensions/acme/dusk.yaml']];
        yield 'new official entry by a contributor' => [[], ['acme/dusk' => self::DUSK . "official: true\n"], false, ['maintainer_only extensions/acme/dusk.yaml#official']];
        yield 'new official entry by a maintainer' => [[], ['acme/dusk' => self::DUSK . "official: true\n"], true, []];
        yield 'official set by a contributor' => [['acme/dusk' => self::DUSK], ['acme/dusk' => self::DUSK . "official: true\n"], false, ['maintainer_only extensions/acme/dusk.yaml#official']];
        yield 'status set by a contributor' => [['acme/dusk' => self::DUSK], ['acme/dusk' => self::DUSK . "status: abandoned\n"], false, ['maintainer_only extensions/acme/dusk.yaml#status']];
        yield 'ownership set by a contributor' => [
            [],
            ['acme/dusk' => self::DUSK . "ownership: {reviewed: https://github.com/yiipress/catalog/pull/1}\n"],
            false,
            ['maintainer_only extensions/acme/dusk.yaml#ownership'],
        ];
        yield 'move without history' => [['acme/dusk' => self::DUSK], ['acme/dusk' => $moved], true, ['transfer_unrecorded extensions/acme/dusk.yaml']];
        yield 'move with history by a maintainer' => [['acme/dusk' => self::DUSK], ['acme/dusk' => $movedRecorded], true, []];
        yield 'move with history by a contributor' => [['acme/dusk' => self::DUSK], ['acme/dusk' => $movedRecorded], false, ['maintainer_only extensions/acme/dusk.yaml#history']];
        yield 'tombstone reused for another repository' => [
            ['acme/dusk' => self::TOMBSTONE],
            ['acme/dusk' => $movedRecorded],
            true,
            ['tombstone_reused extensions/acme/dusk.yaml'],
        ];
        yield 'tombstone reinstated by a maintainer' => [['acme/dusk' => self::TOMBSTONE], ['acme/dusk' => self::DUSK], true, []];
        $rewritten = str_replace('reason: Gone', 'reason: Use acme/other instead', self::TOMBSTONE);
        yield 'withdrawal rewritten by a contributor' => [
            ['acme/dusk' => self::TOMBSTONE],
            ['acme/dusk' => $rewritten],
            false,
            ['maintainer_only extensions/acme/dusk.yaml#withdrawn'],
        ];
        yield 'withdrawal rewritten by a maintainer' => [['acme/dusk' => self::TOMBSTONE], ['acme/dusk' => $rewritten], true, []];
        yield 'tombstone removed' => [['acme/dusk' => self::TOMBSTONE], [], true, ['entry_removed extensions/acme/dusk.yaml']];
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string> $head
     * @param list<string> $expected
     */
    #[DataProvider('changes')]
    public function testChecksChange(array $base, array $head, bool $byMaintainer, array $expected): void
    {
        $errors = new ChangeRules()->check(self::catalog($base), self::catalog($head), $byMaintainer);

        $got = array_map(static fn($error): string => $error->code . ' ' . $error->field, $errors);
        self::assertSame($expected, $got, 'Got: ' . implode('; ', $got));
    }

    public function testMessageNamesTheFileAndTheRule(): void
    {
        $errors = new ChangeRules()->check(self::catalog(['acme/dusk' => self::DUSK]), self::catalog([]), true);

        self::assertStringContainsString('extensions/acme/dusk.yaml', $errors[0]->message);
        self::assertStringContainsString('status: withdrawn', $errors[0]->message);
    }

    /**
     * @param array<string, string> $files ID => entry YAML.
     */
    private static function catalog(array $files): Catalog
    {
        $entries = [];
        foreach ($files as $id => $yaml) {
            $extensionId = ExtensionId::tryFromString($id);
            self::assertNotNull($extensionId);
            $entries[] = new EntryParser()->parse($extensionId, $yaml);
        }

        return new Catalog($entries, Categories::fromYaml("theme: [blog, docs]\n"));
    }
}
