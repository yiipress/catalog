<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use InvalidArgumentException;
use RuntimeException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match;
use function rename;
use function sprintf;
use function uniqid;

/**
 * Files under one directory, by relative key. In Actions the directory is kept between runs with `actions/cache`.
 *
 * Writes go through a temporary file and a rename, so a killed run leaves no half-written entry.
 */
final readonly class FileCache
{
    private const string KEY_PATTERN = '~^(?:[A-Za-z0-9][A-Za-z0-9._-]*/)*[A-Za-z0-9][A-Za-z0-9._-]*$~D';

    public function __construct(private string $directory) {}

    public function get(string $key): ?string
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $content = @file_get_contents($file);

        return $content === false ? null : $content;
    }

    public function set(string $key, string $content): void
    {
        $file = $this->path($key);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create the cache directory "%s".', $directory));
        }
        $temporary = $file . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($temporary, $content) === false || !@rename($temporary, $file)) {
            throw new RuntimeException(sprintf('Could not write the cache file "%s".', $file));
        }
    }

    private function path(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || preg_match('~(^|/)\.\.?(/|$)~', $key) === 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid cache key.', $key));
        }

        return $this->directory . '/' . $key;
    }
}
