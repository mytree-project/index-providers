<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyTree\IndexProviders\Http\DefaultHttpClientFactory;
use MyTree\IndexProviders\Http\RedirectingHttpClient;
use MyTree\IndexProviders\Http\RetryingHttpClient;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpClientTest extends TestCase
{
    public function testRetries429WithRetryAfterWithoutRealSleep(): void
    {
        $responses = [
            new Response(429, ['Retry-After' => '7']),
            new Response(200, [], 'ok'),
        ];
        $http = new FakeHttpClient(
            static function (RequestInterface $request) use (&$responses): ResponseInterface {
                $response = array_shift($responses);
                self::assertInstanceOf(ResponseInterface::class, $response);

                return $response;
            },
        );
        $sleeps = [];
        $client = new RetryingHttpClient(
            $http,
            maxAttempts: 3,
            sleeper: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $response = $client->sendRequest(new Request('GET', 'https://example.test/data'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([7], $sleeps);
        self::assertSame(2, $http->calls);
        self::assertSame($http->requests[0], $http->requests[1], 'Retry must replay the same immutable request.');
    }

    public function testRetriesTransportFailureWithoutRealSleep(): void
    {
        $attempt = 0;
        $http = new FakeHttpClient(static function (RequestInterface $request) use (&$attempt): ResponseInterface {
            $attempt++;
            if ($attempt === 1) {
                throw new ConnectException('temporary failure', $request);
            }

            return new Response(200, [], 'ok');
        });
        $sleeps = [];
        $client = new RetryingHttpClient(
            $http,
            maxAttempts: 3,
            sleeper: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        self::assertSame(200, $client->sendRequest(new Request('GET', 'https://example.test/data'))->getStatusCode());
        self::assertSame([2], $sleeps);
        self::assertSame(2, $http->calls);
    }

    public function testRewindsSeekableRequestBodyBeforeRetry(): void
    {
        $attempt = 0;
        $bodies = [];
        $http = new FakeHttpClient(static function (RequestInterface $request) use (&$attempt, &$bodies): ResponseInterface {
            $attempt++;
            $bodies[] = (string) $request->getBody();

            return $attempt === 1 ? new Response(503) : new Response(200);
        });
        $client = new RetryingHttpClient(
            $http,
            maxAttempts: 2,
            sleeper: static function (int $seconds): void {
            },
            retryableMethods: ['POST'],
        );

        self::assertSame(200, $client->sendRequest(new Request('POST', 'https://example.test/search', [], 'a=1'))->getStatusCode());
        self::assertSame(['a=1', 'a=1'], $bodies);
    }

    public function testFollowsGetRedirectAndResolvesRelativeLocation(): void
    {
        $http = new FakeHttpClient(static function (RequestInterface $request): ResponseInterface {
            return $request->getUri()->getPath() === '/start'
                ? new Response(302, ['Location' => '/next'])
                : new Response(200, [], 'done');
        });
        $client = new RedirectingHttpClient($http, 5);

        $response = $client->sendRequest(new Request('GET', 'https://example.test/start'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'https://example.test/start',
            'https://example.test/next',
        ], $http->urls);
    }

    public function testDoesNotAutomaticallyRedirectPostRequests(): void
    {
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(302, ['Location' => '/next']),
        );
        $client = new RedirectingHttpClient($http, 5);

        $response = $client->sendRequest(new Request('POST', 'https://example.test/start', [], 'a=1'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $http->calls);
    }

    public function testDefaultFactoryReturnsPsr18Client(): void
    {
        $client = DefaultHttpClientFactory::create(
            sleeper: static function (int $seconds): void {
            },
        );

        self::assertInstanceOf(ClientInterface::class, $client);
    }
}
