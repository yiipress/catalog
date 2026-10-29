<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Support;

use YiiPress\Extension\Http\HttpClientInterface;
use YiiPress\Extension\Http\HttpResponse;
use YiiPress\Extension\Install\InstallException;

use function file_put_contents;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Answers from a URL map and records every requested URL.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    public array $requests = [];

    /** @var array<string, array{int, string, array<string, list<string>>}> */
    private array $responses = [];

    /**
     * @param array<string, list<string>> $headers Lowercase name => values.
     */
    public function on(string $url, int $status, string $body = '', array $headers = []): self
    {
        $this->responses[$url] = [$status, $body, $headers];

        return $this;
    }

    /**
     * Answers the release list of a repository with the given releases (tag => extra fields).
     *
     * @param array<string, array<string, mixed>> $releases
     */
    public function releases(string $repository, array $releases): self
    {
        $items = [];
        foreach ($releases as $tag => $fields) {
            $items[] = $fields + [
                'tag_name' => $tag,
                'draft' => false,
                'prerelease' => false,
                'published_at' => '2026-10-01T10:00:00Z',
                'html_url' => sprintf('https://github.com/%s/releases/tag/%s', $repository, $tag),
            ];
        }

        return $this->on(
            sprintf('https://api.github.com/repos/%s/releases?per_page=100', $repository),
            200,
            json_encode($items, JSON_THROW_ON_ERROR),
        );
    }

    public function manifest(string $repository, string $tag, string $yaml): self
    {
        return $this->on(sprintf('https://raw.githubusercontent.com/%s/%s/extension.yaml', $repository, $tag), 200, $yaml);
    }

    public function get(string $url, string $destination, int $maxBytes): HttpResponse
    {
        $this->requests[] = $url;
        if (!isset($this->responses[$url])) {
            throw new InstallException('network_failed', sprintf('No route to %s.', $url));
        }
        [$status, $body, $headers] = $this->responses[$url];
        file_put_contents($destination, $body);

        return new HttpResponse($status, $headers);
    }
}
