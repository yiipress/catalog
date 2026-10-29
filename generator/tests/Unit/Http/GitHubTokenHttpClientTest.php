<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Http\GitHubTokenHttpClient;
use YiiPress\Catalog\Tests\Support\LocalServer;
use YiiPress\Extension\Http\UrlPolicy;
use YiiPress\Extension\Install\InstallException;

use function file_exists;
use function file_get_contents;
use function hrtime;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function urlencode;

final class GitHubTokenHttpClientTest extends TestCase
{
    private const string TOKEN = 'ghs_secretTOKEN123';

    private static LocalServer $server;
    private string $file = '';

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'catalog-http-');
        self::assertNotFalse($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function testSendsTokenToTokenHost(): void
    {
        $response = self::client()->get(self::$server->url('/auth'), $this->file, 1000);

        self::assertSame(200, $response->status);
        self::assertSame('Bearer ' . self::TOKEN, file_get_contents($this->file));
        self::assertSame('4999', $response->header('x-ratelimit-remaining'));
    }

    public function testSendsNoTokenToOtherHosts(): void
    {
        self::client()->get(self::$server->url('/auth', 'localhost'), $this->file, 1000);

        self::assertSame('none', file_get_contents($this->file));
    }

    public function testDropsTokenWhenRedirectedToAnotherHost(): void
    {
        $target = urlencode(self::$server->url('/auth', 'localhost'));

        $response = self::client()->get(self::$server->url('/redirect?to=' . $target), $this->file, 1000);

        self::assertSame(200, $response->status);
        self::assertSame('none', file_get_contents($this->file));
    }

    public function testWithoutTokenSendsNone(): void
    {
        self::client('')->get(self::$server->url('/auth'), $this->file, 1000);

        self::assertSame('none', file_get_contents($this->file));
    }

    public function testSendsUserAgent(): void
    {
        self::client()->get(self::$server->url('/agent'), $this->file, 1000);

        self::assertSame('YiiPress-Catalog', file_get_contents($this->file));
    }

    public function testRefusesRedirectOutsidePolicy(): void
    {
        $target = urlencode('http://example.com/');

        try {
            self::client()->get(self::$server->url('/redirect?to=' . $target), $this->file, 1000);
        } catch (InstallException $exception) {
            self::assertSame('redirect_refused', $exception->errorCode);
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testStopsEndlessRedirects(): void
    {
        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('redirected more than');

        self::client()->get(self::$server->url('/loop'), $this->file, 1000);
    }

    public function testRefusesBodyOverLimitAndLeavesNoFile(): void
    {
        try {
            self::client()->get(self::$server->url('/big'), $this->file, 1000);
        } catch (InstallException $exception) {
            self::assertSame('download_too_large', $exception->errorCode);
            self::assertFileDoesNotExist($this->file);
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testReturnsErrorStatusWithBody(): void
    {
        $response = self::client()->get(self::$server->url('/missing'), $this->file, 1000);

        self::assertSame(404, $response->status);
        self::assertSame('{"message":"Not Found"}', file_get_contents($this->file));
    }

    public function testStopsAtRequestTimeout(): void
    {
        $client = new GitHubTokenHttpClient(self::TOKEN, ['127.0.0.1'], self::policy(), 1, 1);
        $start = hrtime(true);

        try {
            $client->get(self::$server->url('/slow'), $this->file, 1000);
        } catch (InstallException $exception) {
            self::assertSame('network_failed', $exception->errorCode);
            self::assertLessThan(2.5, (hrtime(true) - $start) / 1e9);
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testErrorsNeverContainTheToken(): void
    {
        try {
            self::client()->get('http://127.0.0.1:1/auth', $this->file, 1000);
        } catch (InstallException $exception) {
            self::assertSame('network_failed', $exception->errorCode);
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            return;
        }

        self::fail('No exception thrown.');
    }

    private static function client(string $token = self::TOKEN): GitHubTokenHttpClient
    {
        return new GitHubTokenHttpClient($token, ['127.0.0.1'], self::policy());
    }

    private static function policy(): UrlPolicy
    {
        return new UrlPolicy(['http'], ['127.0.0.1', 'localhost']);
    }
}
