<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts API keys, bearer tokens, WhatsApp tokens, and database passwords from log records.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    private const SECRET_PATTERNS = [
        '/sk-ant-[A-Za-z0-9\-_]{10,}/' => '[REDACTED_ANTHROPIC_KEY]',
        '/nvapi-[A-Za-z0-9\-_]{16,}/' => '[REDACTED_NVIDIA_NIM_KEY]',
        '/AIza[0-9A-Za-z\-_]{20,}/' => '[REDACTED_GOOGLE_KEY]',
        '/AQ\.[A-Za-z0-9\-_]{20,}/' => '[REDACTED_VERTEX_KEY]',
        '/EAA[A-Za-z0-9]{20,}/' => '[REDACTED_META_TOKEN]',
        '/Bearer\s+[A-Za-z0-9\.\-_]{16,}/i' => 'Bearer [REDACTED]',
        '/(password|secret|api_key|access_token|token)=([^\s&"\']+)/i' => '$1=[REDACTED]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = self::redactString($record->message);
        $context = self::redactArray($record->context);
        $extra = self::redactArray($record->extra);

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    public static function redactString(string $value): string
    {
        $result = $value;
        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $result) ?? $result;
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function redactArray(array $data): array
    {
        $sensitiveKeys = ['password', 'secret', 'api_key', 'access_token', 'token', 'authorization', 'x-api-key'];
        $out = [];

        foreach ($data as $key => $val) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $out[$key] = '[REDACTED]';
                continue;
            }

            if (is_string($val)) {
                $out[$key] = self::redactString($val);
            } elseif (is_array($val)) {
                $out[$key] = self::redactArray($val);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
