<?php
namespace ETG\DynamicFilterSEOBridge\Identifiers;

/**
 * Case-preserving JetSmartFilters/JetEngine Query ID normalizer.
 *
 * Query IDs are identifiers, not WordPress slugs. Lower-casing them changes
 * identity on JetSmartFilters surfaces, so invalid values are rejected rather
 * than rewritten.
 */
final class QueryId {
    const MAX_LENGTH = 80;

    public static function normalize( $value ): string {
        if ( ! is_scalar( $value ) ) { return ''; }
        $value = trim( (string) $value );
        if ( '' === $value || strlen( $value ) > self::MAX_LENGTH ) { return ''; }
        return preg_match( '/\A[A-Za-z0-9_-]+\z/', $value ) ? $value : '';
    }

    /**
     * Produce a lower-case presentation-token segment without collapsing two
     * Query IDs that differ only by case. Existing lower-case IDs keep their
     * historical token names; mixed-case IDs gain a deterministic digest suffix.
     */
    public static function tokenKey( $value ): string {
        $queryId = self::normalize( $value );
        if ( '' === $queryId ) { return ''; }
        $lower = strtolower( $queryId );
        if ( $lower === $queryId ) { return $queryId; }
        return $lower . '-' . substr( hash( 'sha256', $queryId ), 0, 16 );
    }

    private function __construct() {}
}
