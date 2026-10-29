<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Build;

use YiiPress\Catalog\Catalog\Catalog;
use YiiPress\Catalog\Catalog\CatalogLoader;
use YiiPress\Catalog\Catalog\ChangeRules;
use YiiPress\Catalog\Catalog\Maintainers;
use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Entry\EntryStatus;
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Catalog\Fetch\ExtensionFetcher;
use YiiPress\Catalog\Fetch\FetchFailedException;
use YiiPress\Catalog\Listing\ListingBuilder;
use YiiPress\Extension\Manifest\ManifestError;

use function array_map;
use function array_merge;
use function file_get_contents;
use function is_file;
use function sprintf;

/**
 * The pull request check: metadata rules, change rules against the base branch, and the releases of every new or
 * changed entry. It reads YAML and fetches files; it never runs extension code.
 */
final readonly class Checker
{
    public function __construct(
        private CatalogLoader $loader,
        private ExtensionFetcher $fetcher,
    ) {}

    /**
     * @param string $root The pull request checkout.
     * @param string|null $baseRoot The base branch checkout; `null` checks every entry with no change rules.
     * @param string|null $author GitHub login of the pull request author; maintainers come from the base branch.
     */
    public function check(string $root, ?string $baseRoot, ?string $author): CheckReport
    {
        try {
            $head = $this->loader->load($root);
        } catch (InvalidEntryException $exception) {
            return new CheckReport(self::messages($exception->errors()), []);
        }

        $errors = [];
        $base = null;
        if ($baseRoot !== null) {
            try {
                $base = $this->loader->load($baseRoot);
            } catch (InvalidEntryException $exception) {
                $errors[] = 'The base branch has invalid metadata, so change rules were not checked: ' . $exception->getMessage();
            }
        }
        if ($base !== null) {
            $maintainers = Maintainers::fromCodeowners(self::read($baseRoot . '/' . Maintainers::FILE));
            $byMaintainer = $author !== null && $maintainers->includes($author);
            $errors = array_merge($errors, self::messages(new ChangeRules()->check($base, $head, $byMaintainer)));
        }

        $builder = new ListingBuilder($head->categories, []);
        $checked = [];
        foreach ($this->changed($base, $head) as $entry) {
            $checked[] = (string) $entry->id;
            try {
                $listing = $builder->build($this->fetcher->fetch($entry));
            } catch (FetchFailedException $exception) {
                $errors[] = $exception->getMessage();
                continue;
            }
            foreach ($listing->problems as $problem) {
                $errors[] = self::problem($problem, $listing->fetched->releases);
            }
        }

        return new CheckReport($errors, $checked);
    }

    /**
     * New or changed entries that are not withdrawn; every one without a base.
     *
     * @return list<CatalogEntry>
     */
    private function changed(?Catalog $base, Catalog $head): array
    {
        $before = [];
        foreach ($base->entries ?? [] as $entry) {
            $before[(string) $entry->id] = $entry;
        }
        $changed = [];
        foreach ($head->entries as $entry) {
            $old = $before[(string) $entry->id] ?? null;
            if ($entry->status !== EntryStatus::Withdrawn && ($base === null || $old === null || $old != $entry)) {
                $changed[] = $entry;
            }
        }

        return $changed;
    }

    /**
     * @param list<\YiiPress\Catalog\Fetch\ReleaseRecord> $releases
     */
    private static function problem(ManifestError $problem, array $releases): string
    {
        $message = sprintf('[%s] %s', $problem->code, $problem->message);
        if ($problem->code !== 'release_missing') {
            return $message;
        }
        foreach ($releases as $release) {
            foreach ($release->problems as $releaseProblem) {
                $message .= sprintf("\n  %s: [%s] %s", $release->info->tag, $releaseProblem->code, $releaseProblem->message);
            }
        }

        return $message;
    }

    /**
     * @param list<ManifestError> $errors
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        return array_map(static fn(ManifestError $error): string => sprintf('[%s] %s', $error->code, $error->message), $errors);
    }

    private static function read(string $file): string
    {
        $content = is_file($file) ? @file_get_contents($file) : false;

        return $content === false ? '' : $content;
    }
}
