<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Catalog;

use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Entry\EntryStatus;
use YiiPress\Extension\Manifest\ManifestError;

use function sprintf;
use function strtolower;

/**
 * Rules for a change of the catalog, comparing a pull request with its base branch.
 *
 * - An entry is never deleted: withdrawing keeps a tombstone, so an ID is never given to another package.
 * - `official`, `status`, `ownership` and `history` are maintainer-only.
 * - A new repository URL needs a `history` row naming the old one.
 * - A withdrawn ID may come back only for the same repository.
 */
final readonly class ChangeRules
{
    /**
     * @return list<ManifestError>
     */
    public function check(Catalog $base, Catalog $head, bool $byMaintainer): array
    {
        $before = self::byId($base);
        $after = self::byId($head);
        $errors = [];
        foreach ($before as $id => $old) {
            if (!isset($after[$id])) {
                $errors[] = new ManifestError('entry_removed', $old->file(), sprintf(
                    '%s was removed. Entries are never deleted; a maintainer sets status: withdrawn instead.',
                    $old->file(),
                ));
            }
        }
        foreach ($after as $id => $new) {
            $old = $before[$id] ?? null;
            if (!$byMaintainer) {
                $this->checkMaintainerFields($old, $new, $errors);
            }
            if ($old !== null) {
                $this->checkRepository($old, $new, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param list<ManifestError> $errors
     */
    private function checkMaintainerFields(?CatalogEntry $old, CatalogEntry $new, array &$errors): void
    {
        $changed = [
            'official' => $new->official !== ($old->official ?? false),
            'status' => $new->status !== ($old->status ?? EntryStatus::Listed),
            'ownership' => $new->ownershipRecord !== $old?->ownershipRecord,
            'history' => $new->history != ($old->history ?? []),
        ];
        foreach ($changed as $field => $isChanged) {
            if ($isChanged) {
                $errors[] = new ManifestError('maintainer_only', $new->file() . '#' . $field, sprintf(
                    '%s: only a catalog maintainer may change %s.',
                    $new->file(),
                    $field,
                ));
            }
        }
    }

    /**
     * @param list<ManifestError> $errors
     */
    private function checkRepository(CatalogEntry $old, CatalogEntry $new, array &$errors): void
    {
        if (self::same($old, $new)) {
            return;
        }
        if ($old->status === EntryStatus::Withdrawn) {
            $errors[] = new ManifestError('tombstone_reused', $new->file(), sprintf(
                '%s is withdrawn; its ID cannot be used for another repository (%s).',
                $new->file(),
                $new->repository->url(),
            ));
            return;
        }
        $last = $new->history[0] ?? null;
        if ($last === null || strtolower((string) $last->from) !== strtolower((string) $old->repository)) {
            $errors[] = new ManifestError('transfer_unrecorded', $new->file(), sprintf(
                '%s: the repository changed from %s; add a history row with from: %s and the review record.',
                $new->file(),
                $old->repository->url(),
                $old->repository->url(),
            ));
        }
    }

    private static function same(CatalogEntry $old, CatalogEntry $new): bool
    {
        return strtolower((string) $old->repository) === strtolower((string) $new->repository);
    }

    /**
     * @return array<string, CatalogEntry>
     */
    private static function byId(Catalog $catalog): array
    {
        $entries = [];
        foreach ($catalog->entries as $entry) {
            $entries[(string) $entry->id] = $entry;
        }

        return $entries;
    }
}
