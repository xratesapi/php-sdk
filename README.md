# XRates PHP SDK

Official PHP client for the [XRates](https://xratesapi.com) exchange rate API. Multi-source FX rates aggregated from ECB, CBR, FloatRates, fawazahmed0 and IMF, served via a single REST endpoint.

[![Latest Stable Version](https://poser.pugx.org/xratesapi/php-sdk/v)](https://packagist.org/packages/xratesapi/php-sdk)
[![Total Downloads](https://poser.pugx.org/xratesapi/php-sdk/downloads)](https://packagist.org/packages/xratesapi/php-sdk)
[![PHP Version Require](https://poser.pugx.org/xratesapi/php-sdk/require/php)](https://packagist.org/packages/xratesapi/php-sdk)
[![License](https://poser.pugx.org/xratesapi/php-sdk/license)](https://packagist.org/packages/xratesapi/php-sdk)

- **Documentation:** <https://xratesapi.com/docs>
- **Free tier:** 100 requests/month, no credit card — [sign up](https://xratesapi.com/register)
- **Status:** <https://xratesapi.com/status>

## Requirements

- PHP 8.1+
- Guzzle 7.5+ (or any PSR-compatible HTTP client)

## Installation

```bash
composer require xratesapi/php-sdk
```

## Quick start

```php
use XRatesApi\Client;

$client = new Client('YOUR_API_KEY');

// Latest rates: rates[T] = "X T per 1 base"
$response = $client->latest('USD', ['EUR', 'GBP']);
// ['base' => 'USD', 'rates' => ['EUR' => 0.864, 'GBP' => 0.79], ...]

// Historical
$response = $client->historical('2026-05-01', 'USD', ['EUR']);

// Convert an amount
$response = $client->convert('USD', 'EUR', 100.0);
// ['from' => 'USD', 'to' => 'EUR', 'amount' => 100, 'result' => 86.4]

// Time-series
$response = $client->timeseries('2026-01-01', '2026-01-31', 'USD', ['EUR']);

// Rate fluctuation
$response = $client->fluctuation('2026-01-01', '2026-01-31', 'USD', ['EUR']);

// Currencies
$response = $client->currencies();

// Public status (no API key required, but the client sends one anyway)
$response = $client->status();
```

## Error handling

Every HTTP error maps to one of four exception types, all extending `XRatesApi\Exceptions\ApiException`:

| HTTP | Exception | When |
|---|---|---|
| 401 / 403 | `AuthenticationException` | Missing or invalid API key |
| 422 | `ValidationException` | Bad request parameters; `->payload()` has the full error array |
| 429 | `RateLimitException` | Hit the per-plan rate limit |
| other | `ApiException` | Network errors, 5xx, unexpected responses |

```php
use XRatesApi\Exceptions\RateLimitException;
use XRatesApi\Exceptions\ApiException;

try {
    $rates = $client->latest('USD', ['EUR']);
} catch (RateLimitException $e) {
    // back off and retry
} catch (ApiException $e) {
    // log and surface to caller
    error_log("XRates: " . $e->getMessage());
}
```

## Custom HTTP client

The constructor accepts any `\GuzzleHttp\ClientInterface`, so you can plug in your own configured client (timeouts, retry middleware, proxy, etc.):

```php
$guzzle = new \GuzzleHttp\Client([
    'timeout' => 5,
    'connect_timeout' => 2,
]);

$client = new Client('YOUR_API_KEY', $guzzle);
```

## Custom base URL

Useful for tests or self-hosted deployments:

```php
$client = new Client('YOUR_API_KEY', null, 'https://staging.xratesapi.com');
```

## Versioning

This SDK follows semver. The `Client::VERSION` constant is sent in the `User-Agent` header on every request, which helps us debug integration issues if you contact support.

## Contributing

PRs welcome. Run the tests:

```bash
composer install
composer test
```

## License

MIT — see `LICENSE` file.
