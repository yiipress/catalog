<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * A directory tree in the system temp directory, removed by {@see remove()}.
 */
final readonly class TempTree
{
    private function __construct(public string $root) {}

    /**
     * @param array<string, string> $files Relative path => content.
     */
    public static function create(array $files = []): self
    {
        $tree = new self(sys_get_temp_dir() . '/catalog-test-' . uniqid('', true));
        if (!mkdir($tree->root, 0o777, true)) {
            throw new RuntimeException('Could not create ' . $tree->root);
        }
        foreach ($files as $path => $content) {
            $tree->write($path, $content);
        }

        return $tree;
    }

    public function write(string $path, string $content): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $content);
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
