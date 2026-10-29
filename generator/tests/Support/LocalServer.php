<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Support;

use RuntimeException;

use function fclose;
use function fsockopen;
use function is_resource;
use function proc_open;
use function proc_terminate;
use function random_int;
use function usleep;

/**
 * The PHP built-in server with `http-router.php`, on a random local port.
 */
final class LocalServer
{
    /** @var resource|null */
    private $process;

    private function __construct(public readonly int $port, mixed $process)
    {
        /** @var resource $process */
        $this->process = $process;
    }

    public static function start(): self
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/http-router.php'],
                [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                continue;
            }
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    return new self($port, $process);
                }
                usleep(20_000);
            }
            proc_terminate($process);
        }

        throw new RuntimeException('Could not start the local HTTP server.');
    }

    public function url(string $path, string $host = '127.0.0.1'): string
    {
        return 'http://' . $host . ':' . $this->port . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
        }
        $this->process = null;
    }
}
