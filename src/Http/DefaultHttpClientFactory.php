<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Http;

use GuzzleHttp\Client;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;

final class DefaultHttpClientFactory
{
    public const DEFAULT_USER_AGENT = 'MyTree-Index-Providers/0.1 (personal genealogy research)';

    /**
     * @param callable(int):void|null $sleeper Receives retry delay in seconds.
     * @param list<string> $retryableMethods
     */
    public static function create(
        int $timeoutSeconds = 60,
        int $maxAttempts = 3,
        string $userAgent = self::DEFAULT_USER_AGENT,
        ?callable $sleeper = null,
        array $retryableMethods = ['GET', 'HEAD'],
    ): ClientInterface {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('HTTP timeout must be at least 1 second.');
        }

        $transport = new Client([
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $timeoutSeconds,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => '*/*',
                'Connection' => 'close',
            ],
        ]);

        $redirecting = new RedirectingHttpClient($transport, 5);

        return new RetryingHttpClient(
            $redirecting,
            maxAttempts: $maxAttempts,
            sleeper: $sleeper,
            retryableMethods: $retryableMethods,
        );
    }
}
