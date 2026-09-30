<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class SensitiveDataRedactor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'auth_token',
        'access_token',
        'internal_service_secret',
        'api_key',
        'approval_token',
        'authorization',
        'bearer',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->redactArray($record->context);
        $extra = $this->redactArray($record->extra);

        return $record->with(context: $context, extra: $extra);
    }

    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            
            if ($this->isSensitiveKey($normalizedKey)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            } elseif (is_string($value) && (str_starts_with($value, 'Bearer ') || str_starts_with($value, 'eyJh'))) {
                // Redact bearer tokens or raw JWTs appearing as values
                $data[$key] = '[REDACTED_JWT]';
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }
        return false;
    }
}
