<?php
namespace ETG\DynamicFilterSEOBridge\Identifiers;

/**
 * Case-preserving normalizer for WordPress/JetEngine field and meta keys.
 *
 * Do not use sanitize_key() for runtime field identity: it lower-cases input and
 * can collapse two distinct keys. This contract intentionally permits only the
 * conservative characters used by WordPress/JetEngine field names.
 */
final class FieldKey {
    const MAX_LENGTH = 120;

    public static function normalize($value): string {
        if (!is_scalar($value)) { return ''; }
        $value = trim((string) $value);
        if ('' === $value || strlen($value) > self::MAX_LENGTH) { return ''; }
        return preg_match('/\A[A-Za-z0-9_.-]+\z/', $value) ? $value : '';
    }
}
