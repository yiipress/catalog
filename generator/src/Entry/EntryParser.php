<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Entry;

use YiiPress\Extension\ExtensionId;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Manifest\FieldReader;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Manifest\YamlDocumentLoader;
use YiiPress\Extension\Release\GitHubRepository;

use function array_diff;
use function array_map;
use function array_unique;
use function array_values;
use function checkdate;
use function get_debug_type;
use function is_bool;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * Reads `extensions/<vendor>/<name>.yaml`: the catalog-owned fields of one extension.
 *
 * YAML safety (size, node count, one document) and field checks are the engine's, so entries and manifests follow
 * the same rules.
 */
final readonly class EntryParser
{
    public const string DIRECTORY = 'extensions';
    public const int MAX_CATEGORIES = 5;
    private const string CATEGORY_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    private const string DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/D';
    private const int MAX_REASON_LENGTH = 500;
    private const array FIELDS = ['repository', 'categories', 'status', 'official', 'ownership', 'history', 'withdrawn'];

    public static function file(ExtensionId $id): string
    {
        return sprintf('%s/%s/%s.yaml', self::DIRECTORY, $id->vendor, $id->name);
    }

    /**
     * @throws InvalidEntryException With every problem found.
     */
    public function parse(ExtensionId $id, string $yaml): CatalogEntry
    {
        $fields = new FieldReader();
        $root = new YamlDocumentLoader()->load($yaml, $fields);
        $entry = $root === null ? null : $this->read($id, $root, $fields);
        if ($entry === null || $fields->hasErrors()) {
            throw new InvalidEntryException(self::located(self::file($id), $fields->errors()));
        }

        return $entry;
    }

    /**
     * @param array<mixed> $root
     */
    private function read(ExtensionId $id, array $root, FieldReader $fields): ?CatalogEntry
    {
        $status = $this->status($root, $fields);
        $allowed = $status === EntryStatus::Withdrawn ? self::FIELDS : array_values(array_diff(self::FIELDS, ['withdrawn']));
        $fields->rejectUnknownKeys($root, $allowed, '');

        $url = $fields->string($root, 'repository', 'repository');
        $repository = $url === null ? null : self::repository($url, 'repository', $fields);
        $categories = $this->categories($root, $fields);
        $official = $this->official($root, $fields);
        $ownership = $this->ownership($root, $fields);
        $history = $this->history($root, $fields);
        $withdrawal = $status === EntryStatus::Withdrawn ? $this->withdrawal($root, $fields) : null;

        if ($repository === null || $status === null) {
            return null;
        }

        return new CatalogEntry($id, $repository, $categories, $status, $official, $ownership, $history, $withdrawal);
    }

    /**
     * @param array<mixed> $root
     */
    private function status(array $root, FieldReader $fields): ?EntryStatus
    {
        $value = $fields->string($root, 'status', 'status', required: false) ?? EntryStatus::Listed->value;
        $allowed = array_map(static fn(EntryStatus $status): string => $status->value, EntryStatus::cases());

        $value = $fields->oneOf($value, $allowed, 'status');
        return $value === null ? null : EntryStatus::from($value);
    }

    /**
     * @param array<mixed> $root
     * @return list<string>
     */
    private function categories(array $root, FieldReader $fields): array
    {
        $items = $fields->list($root, 'categories', 'categories', 1, self::MAX_CATEGORIES);
        if ($items === null) {
            return [];
        }

        $strings = $fields->strings($items, 'categories');
        $categories = [];
        foreach ($strings as $index => $category) {
            if (preg_match(self::CATEGORY_PATTERN, $category) !== 1) {
                $fields->error(
                    'field_invalid',
                    FieldReader::path('categories', $index),
                    sprintf('"%s" must be lowercase letters and digits, words joined by "-"', $category),
                );
                continue;
            }
            $categories[$index] = $category;
        }
        $fields->unique($categories, 'categories');

        return array_values(array_unique($categories));
    }

    /**
     * @param array<mixed> $root
     */
    private function official(array $root, FieldReader $fields): bool
    {
        $value = $root['official'] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        $fields->error('field_type', 'official', sprintf('must be true or false, got %s', get_debug_type($value)));
        return false;
    }

    /**
     * @param array<mixed> $root
     */
    private function ownership(array $root, FieldReader $fields): ?string
    {
        $ownership = $fields->mapping($root, 'ownership', 'ownership', required: false);
        if ($ownership === null) {
            return null;
        }
        $fields->rejectUnknownKeys($ownership, ['reviewed'], 'ownership');
        $record = $fields->string($ownership, 'reviewed', 'ownership.reviewed');

        return $record === null ? null : $fields->httpsUrl($record, 'ownership.reviewed');
    }

    /**
     * @param array<mixed> $root
     * @return list<Transfer>
     */
    private function history(array $root, FieldReader $fields): array
    {
        $items = $fields->list($root, 'history', 'history', 1, 100, required: false) ?? [];
        $history = [];
        foreach ($items as $index => $item) {
            $path = FieldReader::path('history', $index);
            $row = $fields->asMapping($item, $path);
            if ($row === null) {
                continue;
            }
            $fields->rejectUnknownKeys($row, ['date', 'from', 'record'], $path);
            $date = self::date($row, $path . '.date', $fields);
            $from = $fields->string($row, 'from', $path . '.from');
            $from = $from === null ? null : self::repository($from, $path . '.from', $fields);
            $record = $fields->string($row, 'record', $path . '.record');
            $record = $record === null ? null : $fields->httpsUrl($record, $path . '.record');
            if ($date !== null && $from !== null && $record !== null) {
                $history[] = new Transfer($date, $from, $record);
            }
        }

        return $history;
    }

    /**
     * @param array<mixed> $root
     */
    private function withdrawal(array $root, FieldReader $fields): ?Withdrawal
    {
        $details = $fields->mapping($root, 'withdrawn', 'withdrawn');
        if ($details === null) {
            return null;
        }
        $fields->rejectUnknownKeys($details, ['date', 'reason'], 'withdrawn');
        $date = self::date($details, 'withdrawn.date', $fields);
        $reason = $fields->string($details, 'reason', 'withdrawn.reason');
        $reason = $reason === null ? null : $fields->text($reason, 'withdrawn.reason', self::MAX_REASON_LENGTH);

        return $date === null || $reason === null ? null : new Withdrawal($date, $reason);
    }

    /**
     * @param array<mixed> $map
     */
    private static function date(array $map, string $field, FieldReader $fields): ?string
    {
        $key = substr($field, strrpos($field, '.') + 1);
        $value = $fields->string($map, $key, $field);
        if ($value === null) {
            return null;
        }
        if (preg_match(self::DATE_PATTERN, $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        $fields->error('field_invalid', $field, sprintf('"%s" must be a date written as "YYYY-MM-DD"', $value));
        return null;
    }

    private static function repository(string $url, string $field, FieldReader $fields): ?GitHubRepository
    {
        try {
            return GitHubRepository::fromUrl($url);
        } catch (InstallException $exception) {
            $fields->error($exception->errorCode, $field, $exception->getMessage());
            return null;
        }
    }

    /**
     * Rewrites messages so they name the entry file instead of `extension.yaml`.
     *
     * @param list<ManifestError> $errors
     * @return list<ManifestError>
     */
    private static function located(string $file, array $errors): array
    {
        $prefix = 'extension.yaml';

        return array_map(static function (ManifestError $error) use ($file, $prefix): ManifestError {
            $message = $error->field === '' && str_starts_with($error->message, $prefix)
                ? $file . substr($error->message, strlen($prefix))
                : $file . ': ' . $error->message;

            return new ManifestError($error->code, $error->field, $message);
        }, $errors);
    }
}
