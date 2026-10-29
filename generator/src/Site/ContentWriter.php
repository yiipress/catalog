<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Site;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function preg_match;
use function rmdir;
use function sprintf;
use function str_replace;
use function substr;
use function unlink;
use function yaml_emit;

use const YAML_UTF8_ENCODING;

/**
 * Writes the generated part of the site's `content/`: the data file, one collection per type, one page per
 * extension and the extension assets. It removes only what it generates; hand-written pages stay.
 */
final readonly class ContentWriter
{
    public const string DATA_FILE = 'data/catalog.yaml';
    public const string ASSETS = 'assets/extensions';

    public function __construct(private string $contentDirectory) {}

    /**
     * @param array<string, mixed> $data From {@see CatalogData::build()}.
     * @param array<string, string> $assets Path under `content/` (inside `assets/extensions/`) => bytes.
     */
    public function write(array $data, array $assets): void
    {
        foreach ($assets as $path => $_bytes) {
            self::checkAssetPath($path);
        }
        $this->clear();

        $this->put(self::DATA_FILE, self::yaml($data));
        $generatedDate = substr(self::string($data, 'generated_at'), 0, 10);
        $types = is_array($data['types'] ?? null) ? $data['types'] : [];
        $extensions = is_array($data['extensions'] ?? null) ? $data['extensions'] : [];
        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }
            $path = self::string($type, 'path');
            $this->put($path . '/_collection.yaml', self::yaml([
                'title' => self::string($type, 'title'),
                'permalink' => '/' . $path . '/:slug/',
                'sort_by' => 'title',
                'sort_order' => 'asc',
                'entries_per_page' => 0,
                'feed' => false,
            ]));
            foreach (is_array($type['extensions'] ?? null) ? $type['extensions'] : [] as $id) {
                $extension = is_string($id) && is_array($extensions[$id] ?? null) ? $extensions[$id] : null;
                if ($extension !== null) {
                    $slug = str_replace('/', '--', $id);
                    $this->put($path . '/' . $slug . '.md', self::page($id, $slug, $extension, $generatedDate));
                }
            }
        }
        foreach ($assets as $path => $bytes) {
            $this->put($path, $bytes);
        }
    }

    /**
     * @param array<mixed> $extension
     */
    private static function page(string $id, string $slug, array $extension, string $generatedDate): string
    {
        $release = is_array($extension['release'] ?? null) ? $extension['release'] : [];
        $published = substr(self::string($release, 'published_at'), 0, 10);

        return "---\n" . self::yaml([
            'title' => self::string($extension, 'name'),
            'date' => $published !== '' ? $published : $generatedDate,
            'slug' => $slug,
            'permalink' => self::string($extension, 'url'),
            'summary' => self::string($extension, 'description'),
            'layout' => 'extension',
            'categories' => is_array($extension['categories'] ?? null) ? $extension['categories'] : [],
            'extra' => ['id' => $id],
        ], document: false) . "---\n";
    }

    private function clear(): void
    {
        $generated = [self::DATA_FILE, self::ASSETS];
        foreach (CatalogData::TYPES as $type) {
            $generated[] = $type['path'];
        }
        foreach ($generated as $path) {
            $this->remove($this->contentDirectory . '/' . $path);
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function put(string $path, string $content): void
    {
        $file = $this->contentDirectory . '/' . $path;
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $directory));
        }
        if (@file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write "%s".', $file));
        }
    }

    private static function checkAssetPath(string $path): void
    {
        if (preg_match('~^assets/extensions/(?:[A-Za-z0-9][A-Za-z0-9._-]*/)+[A-Za-z0-9][A-Za-z0-9._-]*$~D', $path) !== 1
            || preg_match('~(^|/)\.\.?(/|$)~', $path) === 1
        ) {
            throw new InvalidArgumentException(sprintf('"%s" is not a path inside %s/.', $path, self::ASSETS));
        }
    }

    /**
     * @param array<mixed> $value
     */
    private static function yaml(array $value, bool $document = true): string
    {
        $yaml = yaml_emit($value, YAML_UTF8_ENCODING);
        // yaml_emit() wraps the text in "---\n" and "...\n"; front matter needs the bare mapping.
        $body = substr($yaml, 4, -4);

        return $document ? $yaml : $body;
    }

    /**
     * @param array<mixed> $map
     */
    private static function string(array $map, string $key): string
    {
        return is_string($map[$key] ?? null) ? $map[$key] : '';
    }
}
