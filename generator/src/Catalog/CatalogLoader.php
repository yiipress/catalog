<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Catalog;

use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Entry\EntryParser;
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Extension\ExtensionId;
use YiiPress\Extension\Manifest\ManifestError;

use function array_push;
use function file_get_contents;
use function is_dir;
use function is_file;
use function preg_match;
use function scandir;
use function sprintf;
use function strtolower;

/**
 * Reads `categories.yaml` and every `extensions/<vendor>/<name>.yaml` of a catalog checkout and checks the rules
 * that span files: the path is the ID, one entry per repository, and the ID vendor owns the repository.
 */
final readonly class CatalogLoader
{
    /**
     * @throws InvalidEntryException With every problem of every file.
     */
    public function load(string $root): Catalog
    {
        $errors = [];
        $categories = $this->categories($root, $errors);
        $entries = [];
        foreach ($this->files($root, $errors) as $file => $id) {
            try {
                $entries[] = new EntryParser()->parse($id, $this->read($root . '/' . $file));
            } catch (InvalidEntryException $exception) {
                array_push($errors, ...$exception->errors());
            }
        }
        if ($categories !== null) {
            foreach ($entries as $entry) {
                $this->checkCategories($entry, $categories, $errors);
            }
        }
        $this->checkRepositories($entries, $errors);
        foreach ($entries as $entry) {
            $this->checkOwnership($entry, $errors);
        }

        if ($errors !== [] || $categories === null) {
            throw new InvalidEntryException($errors);
        }

        return new Catalog($entries, $categories);
    }

    /**
     * @param list<ManifestError> $errors
     */
    private function categories(string $root, array &$errors): ?Categories
    {
        $file = $root . '/' . Categories::FILE;
        if (!is_file($file)) {
            $errors[] = new ManifestError('file_missing', Categories::FILE, Categories::FILE . ' is missing.');
            return null;
        }
        try {
            return Categories::fromYaml($this->read($file));
        } catch (InvalidEntryException $exception) {
            array_push($errors, ...$exception->errors());
            return null;
        }
    }

    /**
     * Entry files in a stable order (byte order of the path), keyed by the path relative to the root.
     *
     * @param list<ManifestError> $errors
     * @return array<string, ExtensionId>
     */
    private function files(string $root, array &$errors): array
    {
        $files = [];
        $directory = EntryParser::DIRECTORY;
        foreach ($this->list($root . '/' . $directory) as $vendor) {
            $vendorPath = $directory . '/' . $vendor;
            if (!is_dir($root . '/' . $vendorPath)) {
                $errors[] = self::pathError($vendorPath, 'must be a directory named after the ID vendor');
                continue;
            }
            foreach ($this->list($root . '/' . $vendorPath) as $name) {
                $path = $vendorPath . '/' . $name;
                if (is_dir($root . '/' . $path) || preg_match('/^(.+)\.yaml$/D', $name, $match) !== 1) {
                    $errors[] = self::pathError($path, 'must be a file named <name>.yaml');
                    continue;
                }
                $id = ExtensionId::tryFromString($vendor . '/' . $match[1]);
                if ($id === null) {
                    $errors[] = new ManifestError('id_invalid', $path, sprintf(
                        '%s: "%s/%s" is not a valid extension ID: lowercase letters and digits, words joined by "-",'
                        . ' no reserved names.',
                        $path,
                        $vendor,
                        $match[1],
                    ));
                    continue;
                }
                $files[$path] = $id;
            }
        }

        return $files;
    }

    /**
     * @param list<ManifestError> $errors
     */
    private function checkCategories(CatalogEntry $entry, Categories $categories, array &$errors): void
    {
        foreach ($entry->categories as $index => $category) {
            if (!$categories->isKnown($category)) {
                $errors[] = new ManifestError('value_invalid', 'categories[' . $index . ']', sprintf(
                    '%s: categories[%d]: "%s" is not in %s.',
                    $entry->file(),
                    $index,
                    $category,
                    Categories::FILE,
                ));
            }
        }
    }

    /**
     * @param list<CatalogEntry> $entries
     * @param list<ManifestError> $errors
     */
    private function checkRepositories(array $entries, array &$errors): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            $key = strtolower((string) $entry->repository);
            if (isset($seen[$key])) {
                $errors[] = new ManifestError('repository_duplicate', $entry->file(), sprintf(
                    '%s: repository %s is already listed by %s; one package has one entry.',
                    $entry->file(),
                    $entry->repository->url(),
                    $seen[$key],
                ));
                continue;
            }
            $seen[$key] = $entry->file();
        }
    }

    /**
     * @param list<ManifestError> $errors
     */
    private function checkOwnership(CatalogEntry $entry, array &$errors): void
    {
        if ($entry->ownershipRecord !== null || strtolower($entry->repository->owner) === $entry->id->vendor) {
            return;
        }

        $errors[] = new ManifestError('ownership_unproven', $entry->file(), sprintf(
            '%s: the ID vendor "%s" is not the owner of %s. Use an ID under "%s", or ask a maintainer to review'
            . ' the ownership and add ownership.reviewed.',
            $entry->file(),
            $entry->id->vendor,
            $entry->repository->url(),
            strtolower($entry->repository->owner),
        ));
    }

    /**
     * @return list<string>
     */
    private function list(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $names = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && $name !== '.gitkeep') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function read(string $file): string
    {
        $content = @file_get_contents($file);

        return $content === false ? '' : $content;
    }

    private static function pathError(string $path, string $rule): ManifestError
    {
        return new ManifestError('path_invalid', $path, sprintf('%s: %s.', $path, $rule));
    }
}
