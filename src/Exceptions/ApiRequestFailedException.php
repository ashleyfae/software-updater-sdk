<?php

namespace AshleyFae\SoftwareUpdater\Exceptions;

class ApiRequestFailedException extends ApiException
{
    public static function fromResponse(int $statusCode, string $responseBody): static
    {
        $message = static::parseServerMessage($responseBody)
            ?? "API request failed with HTTP {$statusCode}.";

        return new static($message, $statusCode);
    }

    public static function connectionError(string $wpErrorMessage): static
    {
        return new static("API connection failed: {$wpErrorMessage}", 0);
    }

    private static function parseServerMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return null;
    }
}
