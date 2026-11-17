<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use InvalidArgumentException;

/**
 * Provides Zimbabwe E.164 phone normalization (+263...) and HMAC-SHA256 blind indexing
 * for fast lookup over encrypted PII columns.
 */
trait HasEncryptedPii
{
    /**
     * Normalize a Zimbabwean or international phone number to strict E.164 (+2637...).
     */
    public static function normalizeZimbabwePhone(string $rawPhone): string
    {
        $digits = preg_replace('/[^\d]/', '', $rawPhone) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Phone number cannot be empty.');
        }

        // Convert local 077... / 071... / 078... to 2637...
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '263' . substr($digits, 1);
        } elseif (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '263' . $digits;
        }

        return '+' . $digits;
    }

    /**
     * Compute a deterministic HMAC-SHA256 blind index for a phone number.
     */
    public static function hashPhone(string $rawPhone): string
    {
        $normalized = self::normalizeZimbabwePhone($rawPhone);
        $appKey = (string) config('app.key', 'betterlink-default-hmac-key');

        return hash_hmac('sha256', $normalized, $appKey);
    }
}
