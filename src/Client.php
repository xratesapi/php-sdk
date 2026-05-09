<?php

declare(strict_types=1);

namespace XRatesApi;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use XRatesApi\Exceptions\ApiException;
use XRatesApi\Exceptions\AuthenticationException;
use XRatesApi\Exceptions\RateLimitException;
use XRatesApi\Exceptions\ValidationException;

/**
 * Thin HTTP client wrapping the XRates REST API.
 *
 * The client is intentionally stateless beyond the API key — every call
 * is a single HTTP request, errors map cleanly to one of four exception
 * types, and the constructor accepts any PSR-compatible HTTP client so
 * you can swap Guzzle for whatever you already have wired up.
 */
class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://xratesapi.com';

    private ClientInterface $http;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(
        string $apiKey,
        ?ClientInterface $http = null,
        string $baseUrl = self::DEFAULT_BASE_URL
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('XRates API key is required.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new GuzzleClient([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Latest rates. `rates[T]` is "X T per 1 base".
     *
     * @param array<int, string>|null $symbols Restrict response to these target codes.
     * @return array<string, mixed>
     */
    public function latest(string $base = 'USD', ?array $symbols = null): array
    {
        return $this->get('/api/v1/latest', $this->params($base, $symbols));
    }

    /**
     * Historical rates for a specific date (YYYY-MM-DD).
     *
     * @param array<int, string>|null $symbols
     * @return array<string, mixed>
     */
    public function historical(string $date, string $base = 'USD', ?array $symbols = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("Date must be YYYY-MM-DD, got: $date");
        }

        return $this->get("/api/v1/$date", $this->params($base, $symbols));
    }

    /**
     * Convert an amount between two currencies at the latest rate.
     *
     * @return array<string, mixed>
     */
    public function convert(string $from, string $to, float $amount, ?string $date = null): array
    {
        $params = ['from' => $from, 'to' => $to, 'amount' => $amount];
        if ($date !== null) {
            $params['date'] = $date;
        }

        return $this->get('/api/v1/convert', $params);
    }

    /**
     * Time-series of rates between two dates.
     *
     * @param array<int, string>|null $symbols
     * @return array<string, mixed>
     */
    public function timeseries(
        string $startDate,
        string $endDate,
        string $base = 'USD',
        ?array $symbols = null
    ): array {
        $params = $this->params($base, $symbols);
        $params['start_date'] = $startDate;
        $params['end_date'] = $endDate;

        return $this->get('/api/v1/timeseries', $params);
    }

    /**
     * Rate fluctuation between two dates.
     *
     * @param array<int, string>|null $symbols
     * @return array<string, mixed>
     */
    public function fluctuation(
        string $startDate,
        string $endDate,
        string $base = 'USD',
        ?array $symbols = null
    ): array {
        $params = $this->params($base, $symbols);
        $params['start_date'] = $startDate;
        $params['end_date'] = $endDate;

        return $this->get('/api/v1/fluctuation', $params);
    }

    /**
     * List of supported currencies.
     *
     * @return array<string, mixed>
     */
    public function currencies(): array
    {
        return $this->get('/api/v1/currencies', []);
    }

    /**
     * Public status endpoint — no authentication needed but the client
     * sends the token anyway for consistency.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->get('/api/v1/status', []);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->http->request('GET', $this->baseUrl . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                    'User-Agent' => 'xratesapi-php/' . self::VERSION,
                ],
                'query' => $query,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new ApiException(
                'Network error talking to XRates: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new ApiException("Unexpected non-JSON response from XRates (HTTP $status).");
        }

        if ($status === 200) {
            return $decoded;
        }

        $message = $decoded['message'] ?? $decoded['error'] ?? 'XRates API error';

        match (true) {
            $status === 401 || $status === 403 => throw new AuthenticationException($message, $status),
            $status === 422 => throw new ValidationException($message, $status, $decoded),
            $status === 429 => throw new RateLimitException($message, $status),
            default => throw new ApiException($message, $status),
        };
    }

    /**
     * @param array<int, string>|null $symbols
     * @return array<string, mixed>
     */
    private function params(string $base, ?array $symbols): array
    {
        $params = ['base' => $base];
        if ($symbols !== null && $symbols !== []) {
            $params['symbols'] = implode(',', $symbols);
        }

        return $params;
    }
}
