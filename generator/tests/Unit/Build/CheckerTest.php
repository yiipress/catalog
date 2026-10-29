<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Build\Services;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Catalog\Tests\Support\Manifests;
use YiiPress\Catalog\Tests\Support\TempTree;

final class CheckerTest extends TestCase
{
    private const string CATEGORIES = "theme: [blog]\n";
    private const string DUSK = "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n";

    /** @var list<TempTree> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $tree) {
            $tree->remove();
        }
    }

    public function testValidNewEntryPasses(): void
    {
        $base = $this->tree(['categories.yaml' => self::CATEGORIES, '.github/CODEOWNERS' => "* @samdark\n"]);
        $head = $this->tree(['categories.yaml' => self::CATEGORIES, 'extensions/acme/dusk.yaml' => self::DUSK]);

        $report = $this->checker(self::http())->check($head->root, $base->root, 'acme-dev');

        self::assertSame([], $report->errors);
        self::assertSame(['acme/dusk'], $report->checked);
    }

    public function testChecksOnlyChangedEntriesAgainstGitHub(): void
    {
        $files = ['categories.yaml' => self::CATEGORIES, 'extensions/acme/dusk.yaml' => self::DUSK];
        $base = $this->tree($files);
        $head = $this->tree($files + ['extensions/acme/dawn.yaml' => "repository: https://github.com/acme/dawn\ncategories: [blog]\n"]);
        $http = self::http()
            ->releases('acme/dawn', ['v1.0.0' => []])
            ->manifest('acme/dawn', 'v1.0.0', Manifests::theme('acme/dawn', '1.0.0'));

        $report = $this->checker($http)->check($head->root, $base->root, 'samdark');

        self::assertSame([], $report->errors);
        self::assertSame(['acme/dawn'], $report->checked);
    }

    public function testReportsMetadataErrors(): void
    {
        $head = $this->tree(['categories.yaml' => self::CATEGORIES, 'extensions/acme/dusk.yaml' => "categories: [blog]\n"]);

        $report = $this->checker(self::http())->check($head->root, null, null);

        self::assertStringContainsString('repository: is required', $report->errors[0]);
    }

    public function testReportsMaintainerOnlyChangeByContributor(): void
    {
        $base = $this->tree(['categories.yaml' => self::CATEGORIES, '.github/CODEOWNERS' => "* @samdark\n"]);
        $head = $this->tree(['categories.yaml' => self::CATEGORIES, 'extensions/acme/dusk.yaml' => self::DUSK . "official: true\n"]);

        $report = $this->checker(self::http())->check($head->root, $base->root, 'acme-dev');

        self::assertStringContainsString('only a catalog maintainer may change official', $report->errors[0]);
    }

    public function testMaintainerFromBaseCodeownersMayChangeThem(): void
    {
        $base = $this->tree(['categories.yaml' => self::CATEGORIES, '.github/CODEOWNERS' => "* @samdark\n"]);
        $head = $this->tree([
            'categories.yaml' => self::CATEGORIES,
            '.github/CODEOWNERS' => "* @acme-dev\n",
            'extensions/acme/dusk.yaml' => self::DUSK . "official: true\n",
        ]);

        self::assertSame([], $this->checker(self::http())->check($head->root, $base->root, 'samdark')->errors);
        self::assertNotSame([], $this->checker(self::http())->check($head->root, $base->root, 'acme-dev')->errors);
    }

    public function testReportsReleaseProblems(): void
    {
        $head = $this->tree(['categories.yaml' => self::CATEGORIES, 'extensions/acme/dusk.yaml' => self::DUSK]);
        $http = self::http()->manifest('acme/theme-dusk', 'v1.0.0', Manifests::theme('acme/other', '1.0.0'));

        $report = $this->checker($http)->check($head->root, null, null);

        self::assertStringContainsString('release_missing', $report->errors[0]);
        self::assertStringContainsString('declares id acme/other', $report->errors[0]);
    }

    public function testReportsUnreachableRepository(): void
    {
        $head = $this->tree(['categories.yaml' => self::CATEGORIES, 'extensions/acme/dusk.yaml' => self::DUSK]);

        $report = $this->checker(new FakeHttpClient())->check($head->root, null, null);

        self::assertStringContainsString('could not read the releases', $report->errors[0]);
    }

    public function testWithdrawnEntriesAreNotFetched(): void
    {
        $head = $this->tree([
            'categories.yaml' => self::CATEGORIES,
            'extensions/acme/dusk.yaml' => self::DUSK . "status: withdrawn\nwithdrawn: {date: \"2026-01-01\", reason: Gone}\n",
        ]);

        $report = $this->checker(new FakeHttpClient())->check($head->root, null, null);

        self::assertSame([], $report->errors);
        self::assertSame([], $report->checked);
    }

    private static function http(): FakeHttpClient
    {
        return new FakeHttpClient()
            ->releases('acme/theme-dusk', ['v1.0.0' => []])
            ->manifest('acme/theme-dusk', 'v1.0.0', Manifests::theme('acme/dusk', '1.0.0'));
    }

    /**
     * @param array<string, string> $files
     */
    private function tree(array $files): TempTree
    {
        return $this->trees[] = TempTree::create($files);
    }

    private function checker(FakeHttpClient $http): \YiiPress\Catalog\Build\Checker
    {
        $cache = TempTree::create();
        $this->trees[] = $cache;

        return Services::create($http, $cache->root, '2026-10-29T12:00:00Z')->checker();
    }
}
