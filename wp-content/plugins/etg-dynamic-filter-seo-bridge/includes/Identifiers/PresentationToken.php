<?php
namespace ETG\DynamicFilterSEOBridge\Identifiers;

require_once __DIR__ . '/FieldKey.php';

/** Canonical presentation-token identity with case-sensitive field suffixes. */
final class PresentationToken {
    const MAX_LENGTH = 220;

    public static function normalize($value): string {
        if (!is_scalar($value)) { return ''; }
        $raw = trim((string)$value);
        if ('' === $raw || strlen($raw) > self::MAX_LENGTH) { return ''; }

        if (preg_match('/\Atermmeta:([^:]+):(.+)\z/i', $raw, $m)) {
            $role = self::key($m[1]);
            $field = FieldKey::normalize($m[2]);
            return '' !== $role && '' !== $field ? 'termmeta:' . $role . ':' . $field : '';
        }

        $token = strtolower($raw);
        return preg_match('/\A[a-z0-9_:\-.]+\z/', $token) ? $token : '';
    }

    public static function isTermMeta(string $token): bool {
        return 0 === stripos($token, 'termmeta:');
    }

    private static function key($value): string {
        $value = strtolower(trim((string)$value));
        return preg_match('/\A[a-z0-9_-]+\z/', $value) ? $value : '';
    }
}
