<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Support;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $urls = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    private Closure $responder;

    /** @param callable(RequestInterface):ResponseInterface $responder */
    public function __construct(callable $responder)
    {
        $this->responder = Closure::fromCallable($responder);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->urls[] = (string) $request->getUri();
        $this->requests[] = $request;

        return ($this->responder)($request);
    }
}
