<?php

declare(strict_types=1);

namespace XRatesApi\Exceptions;

class ValidationException extends ApiException
{
    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $message, int $code, array $payload)
    {
        parent::__construct($message, $code);
        $this->payload = $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
