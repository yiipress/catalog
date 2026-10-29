<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Http;

use CurlHandle;
use YiiPress\Extension\Archive\InstallLimits;
use YiiPress\Extension\Http\HttpClientInterface;
use YiiPress\Extension\Http\HttpResponse;
use YiiPress\Extension\Http\UrlPolicy;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Release\GitHubReleaseSource;
use Yiisoft\Yii\Console\ExitCode;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function fclose;
use function fopen;
use function fwrite;
use function hrtime;
use function in_array;
use function intdiv;
use function is_string;
use function max;
use function parse_url;
use function preg_match;
use function rtrim;
use function sprintf;
use function strrpos;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function unlink;

use const CURLE_OPERATION_TIMEDOUT;
use const CURLE_WRITE_ERROR;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_PROTOCOLS_STR;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLOPT_WRITEFUNCTION;
use const PHP_URL_HOST;

/**
 * The engine's HTTP contract over ext-curl, with a GitHub token for the API.
 *
 * The token goes only to the token hosts (`api.github.com`) and never after a redirect to another host. Redirects
 * are followed here, one hop at a time, each checked by the URL policy. No message carries the token.
 */
final readonly class GitHubTokenHttpClient implements HttpClientInterface
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];
    private const string USER_AGENT = 'YiiPress-Catalog';

    /**
     * @param string $token GitHub token; empty for anonymous requests.
     * @param list<string> $tokenHosts Hosts that receive the token, lowercase.
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $token = '',
        private array $tokenHosts = ['api.github.com'],
        private UrlPolicy $policy = new UrlPolicy(),
        private float $connectTimeout = InstallLimits::CONNECT_TIMEOUT_SECONDS,
        private float $requestTimeout = InstallLimits::REQUEST_TIMEOUT_SECONDS,
    ) {}

    public function get(string $url, string $destination, int $maxBytes): HttpResponse
    {
        $current = $url;
        $deadline = hrtime(true) + (int) ($this->requestTimeout * 1e9);
        for ($redirects = 0; ; $redirects++) {
            $this->policy->check($current, $url);
            if ($current === '') {
                throw new InstallException('source_invalid', 'The download URL is empty.');
            }
            $response = $this->request($current, $destination, $maxBytes, $deadline);
            $location = $response->header('location');
            if (!in_array($response->status, self::REDIRECT_STATUSES, true) || $location === null) {
                return $response;
            }
            if ($redirects >= InstallLimits::REDIRECTS) {
                throw new InstallException('redirect_refused', sprintf(
                    'The download of %s was redirected more than %d times.',
                    $url,
                    InstallLimits::REDIRECTS,
                ));
            }
            $current = self::resolve($location, $current);
        }
    }

    /**
     * @param non-empty-string $url
     */
    private function request(string $url, string $destination, int $maxBytes, int $deadline): HttpResponse
    {
        $left = intdiv($deadline - hrtime(true), 1_000_000);
        if ($left <= 0) {
            throw $this->timedOut($url);
        }
        $target = @fopen($destination, 'wb');
        if ($target === false) {
            throw new InstallException('filesystem_failed', sprintf('Could not write "%s".', $destination));
        }

        $lines = [];
        $written = 0;
        $tooLarge = false;
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $this->headers($url),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ($this->connectTimeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, $left),
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $_handle, string $line) use (&$lines): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $lines = [];
                }
                $lines[] = rtrim($line, "\r\n");
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $_handle, string $chunk) use ($target, $maxBytes, &$written, &$tooLarge): int {
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                return fwrite($target, $chunk) === strlen($chunk) ? strlen($chunk) : 0;
            },
        ]);
        $ok = curl_exec($handle) !== false;
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        fclose($target);

        if ($ok) {
            return HttpResponse::fromHeaderLines($status, $lines);
        }
        @unlink($destination);
        if ($tooLarge && $errno === CURLE_WRITE_ERROR) {
            throw GitHubReleaseSource::tooLarge($url, $maxBytes);
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT && hrtime(true) >= $deadline) {
            throw $this->timedOut($url);
        }

        throw new InstallException(
            'network_failed',
            sprintf('The download of %s failed: %s.', $url, rtrim($error, '.')),
            ExitCode::UNAVAILABLE,
        );
    }

    /**
     * @return list<string>
     */
    private function headers(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($this->token === '' || !is_string($host) || !in_array(strtolower($host), $this->tokenHosts, true)) {
            return [];
        }

        return ['Authorization: Bearer ' . $this->token];
    }

    private function timedOut(string $url): InstallException
    {
        return new InstallException(
            'network_failed',
            sprintf('The download of %s timed out after %s seconds.', $url, $this->requestTimeout),
            ExitCode::UNAVAILABLE,
        );
    }

    private static function resolve(string $location, string $current): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($current);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }
}
