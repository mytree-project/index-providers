<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @deprecated Use a PSR-18 ClientInterface directly or DefaultHttpClientFactory::create().
 */
final class NativeHttpClient implements ClientInterface
{
    private readonly ClientInterface $client;

    /** @param callable(int):void|null $sleeper */
    public function __construct(
        int $timeoutSeconds = 60,
        int $maxRetries = 3,
        string $userAgent = DefaultHttpClientFactory::DEFAULT_USER_AGENT,
        ?callable $sleeper = null,
    ) {
        $this->client = DefaultHttpClientFactory::create(
            timeoutSeconds: $timeoutSeconds,
            maxAttempts: $maxRetries,
            userAgent: $userAgent,
            sleeper: $sleeper,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }
}
