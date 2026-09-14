<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RedirectingHttpClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $maxRedirects = 5,
    ) {
        if ($this->maxRedirects < 0) {
            throw new InvalidArgumentException('HTTP max redirects cannot be negative.');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $this->client->sendRequest($request);
        }

        $redirects = 0;
        $current = $request;

        while (true) {
            $response = $this->client->sendRequest($current);
            $status = $response->getStatusCode();
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = trim($response->getHeaderLine('Location'));
            if ($location === '') {
                return $response;
            }
            if ($redirects >= $this->maxRedirects) {
                return $response;
            }

            $redirects++;
            $uri = UriResolver::resolve($current->getUri(), new Uri($location));
            $current = $current->withUri($uri);
        }
    }
}
