<?php

declare(strict_types=1);

namespace XRatesApi\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use XRatesApi\Client;
use XRatesApi\Exceptions\ApiException;
use XRatesApi\Exceptions\AuthenticationException;
use XRatesApi\Exceptions\RateLimitException;
use XRatesApi\Exceptions\ValidationException;

class ClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private function clientWithResponses(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack]);

        return new Client('test-key', $guzzle);
    }

    private function lastRequest(): Request
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_constructor_rejects_empty_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function test_latest_sends_authorization_header_and_query(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode([
                'success' => true,
                'base' => 'USD',
                'rates' => ['EUR' => 0.864, 'GBP' => 0.79],
            ])),
        ]);

        $result = $client->latest('USD', ['EUR', 'GBP']);

        $this->assertSame(0.864, $result['rates']['EUR']);
        $req = $this->lastRequest();
        $this->assertSame('Bearer test-key', $req->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $req->getHeaderLine('Accept'));
        $this->assertStringStartsWith('xratesapi-php/', $req->getHeaderLine('User-Agent'));
        $this->assertStringContainsString('base=USD', $req->getUri()->getQuery());
        $this->assertStringContainsString('symbols=EUR%2CGBP', $req->getUri()->getQuery());
        $this->assertStringEndsWith('/api/v1/latest', $req->getUri()->getPath());
    }

    public function test_latest_omits_symbols_when_empty(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['rates' => []])),
        ]);

        $client->latest('EUR');

        $this->assertStringNotContainsString('symbols', $this->lastRequest()->getUri()->getQuery());
    }

    public function test_historical_validates_date_format(): void
    {
        $client = new Client('test-key');

        $this->expectException(\InvalidArgumentException::class);
        $client->historical('not-a-date');
    }

    public function test_historical_hits_correct_path(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['rates' => []])),
        ]);

        $client->historical('2026-05-01', 'USD', ['EUR']);

        $this->assertStringEndsWith('/api/v1/2026-05-01', $this->lastRequest()->getUri()->getPath());
    }

    public function test_convert_sends_amount(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['result' => 86.4])),
        ]);

        $result = $client->convert('USD', 'EUR', 100.0);

        $this->assertSame(86.4, $result['result']);
        $query = $this->lastRequest()->getUri()->getQuery();
        $this->assertStringContainsString('from=USD', $query);
        $this->assertStringContainsString('to=EUR', $query);
        $this->assertStringContainsString('amount=100', $query);
    }

    public function test_timeseries_passes_dates(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['rates' => []])),
        ]);

        $client->timeseries('2026-01-01', '2026-01-31', 'USD', ['EUR']);

        $query = $this->lastRequest()->getUri()->getQuery();
        $this->assertStringContainsString('start_date=2026-01-01', $query);
        $this->assertStringContainsString('end_date=2026-01-31', $query);
        $this->assertStringContainsString('base=USD', $query);
    }

    public function test_status_does_not_require_extra_params(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['status' => 'operational'])),
        ]);

        $result = $client->status();

        $this->assertSame('operational', $result['status']);
    }

    public function test_401_throws_authentication_exception(): void
    {
        $client = $this->clientWithResponses([
            new Response(401, [], json_encode(['message' => 'Unauthenticated.'])),
        ]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');
        $client->latest();
    }

    public function test_429_throws_rate_limit_exception(): void
    {
        $client = $this->clientWithResponses([
            new Response(429, [], json_encode(['message' => 'Too Many Requests'])),
        ]);

        $this->expectException(RateLimitException::class);
        $client->latest();
    }

    public function test_422_throws_validation_exception_with_payload(): void
    {
        $payload = ['message' => 'Validation failed', 'errors' => ['base' => ['Invalid currency']]];
        $client = $this->clientWithResponses([
            new Response(422, [], json_encode($payload)),
        ]);

        try {
            $client->latest('XXX');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Validation failed', $e->getMessage());
            $this->assertSame($payload, $e->payload());
        }
    }

    public function test_500_throws_generic_api_exception(): void
    {
        $client = $this->clientWithResponses([
            new Response(500, [], json_encode(['message' => 'Server error'])),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Server error');
        $client->latest();
    }

    public function test_non_json_response_throws_api_exception(): void
    {
        $client = $this->clientWithResponses([
            new Response(200, [], '<html>nginx</html>'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');
        $client->latest();
    }
}
