<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Http;

use Closure;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryingHttpClient implements ClientInterface
{
    private Closure $sleeper;

    /** @var list<string> */
    private array $retryableMethods;

    /**
     * @param callable(int):void|null $sleeper Receives delay in seconds.
     * @param list<string> $retryableMethods
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $maxAttempts = 3,
        ?callable $sleeper = null,
        array $retryableMethods = ['GET', 'HEAD'],
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('HTTP max attempts must be at least 1.');
        }

        $this->sleeper = $sleeper === null
            ? static function (int $seconds): void {
                sleep($seconds);
            }
            : Closure::fromCallable($sleeper);
        $this->retryableMethods = array_values(array_unique(array_map('strtoupper', $retryableMethods)));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), $this->retryableMethods, true)) {
            return $this->client->sendRequest($request);
        }

        $attempt = 0;
        while (true) {
            $attempt++;

            try {
                $response = $this->client->sendRequest($request);
            } catch (ClientExceptionInterface $exception) {
                if ($attempt >= $this->maxAttempts) {
                    throw $exception;
                }

                ($this->sleeper)($attempt * 2);
                continue;
            }

            $status = $response->getStatusCode();
            if ($status !== 429 && $status < 500) {
                return $response;
            }
            if ($attempt >= $this->maxAttempts) {
                return $response;
            }

            $retryAfter = trim($response->getHeaderLine('Retry-After'));
            $delay = ctype_digit($retryAfter) ? max(1, (int) $retryAfter) : $attempt * 2;
            ($this->sleeper)($delay);
        }
    }
}
