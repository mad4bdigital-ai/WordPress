<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Optional direct Google Docs conditional-write transport for plain,
 * single-run "field_key: value" paragraphs. This is NOT a general document
 * editor and does not support Sheets (which lack equivalent Docs CAS).
 * A separate, trusted server-side OAuth provider must supply a scoped token.
 */
final class MAD4B_SCP_Activity_Google_Docs_Adapter {
    const MAX_RESPONSE_BYTES = 524288;
    private static function error( $id, $message ) { return new WP_Error( $id, $message ); }
    public static function register( $adapters, $profile ) {
        if ( ! is_array( $adapters ) ) $adapters = array();
        if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) return $adapters;
        $connection = MAD4B_SCP_Google_Drive_Context::connection_status();
        if ( empty( $connection['read_available'] ) ) return $adapters;
        $adapter = array( 'read' => array( __CLASS__, 'read' ),
            'conditional_write' => false, 'readback' => true,
            'scope' => 'managed_google_docs_single_run_named_fields_v1' );
        if ( ! empty( $connection['write_available'] ) ) {
            $adapter['write'] = array( __CLASS__, 'write' );
            $adapter['conditional_write'] = true;
        }
        // OAuth custody, encryption, refresh and provider scope detection are
        // entirely owned by the preexisting MAD4B Google Drive Context.
        $adapters['google_drive'] = $adapter;
        return $adapters;
    }
    private static function request( $method, $source, $binding, $payload = null ) {
        if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ||
            ! method_exists( 'MAD4B_SCP_Google_Drive_Context', 'activity_docs_request' ) )
            return self::error( 'mad4b_drive_managed_transport_unavailable',
                'Existing MAD4B managed Google Drive OAuth transport is not available.' );
        $json = MAD4B_SCP_Google_Drive_Context::activity_docs_request(
            $method, $source, $binding, $payload );
        if ( is_wp_error( $json ) ) return $json;
        $bytes = wp_json_encode( $json );
        if ( ! is_array( $json ) || ! is_string( $bytes ) || strlen( $bytes ) >= self::MAX_RESPONSE_BYTES )
            return self::error( 'mad4b_drive_response_invalid', 'Managed Google Docs response exceeds a safe bound.' );
        return $json;
    }
    private static function document( $source, $binding ) {
        if ( ! isset( $source['resource_kind'], $source['source_ref'] ) ||
            'drive_document' !== $source['resource_kind'] ||
            ! preg_match( '/^[A-Za-z0-9_-]{8,180}$/D', (string) $source['source_ref'] ) ||
            ! isset( $source['purpose'] ) || 'record_data' !== $source['purpose'] )
            return self::error( 'mad4b_drive_document_mapping_denied', 'Only explicitly mapped record-data Docs with exact file IDs are supported.' );
        $id = $source['source_ref'];
        $doc = self::request( 'GET', $source, $binding );
        if ( is_wp_error( $doc ) ) return $doc;
        if ( ! isset( $doc['documentId'], $doc['revisionId'], $doc['body']['content'] ) ||
            $doc['documentId'] !== $id || ! is_array( $doc['body']['content'] ) ||
            isset( $doc['tabs'] ) )
            return self::error( 'mad4b_drive_multitab_or_identity_denied', 'Unsupported Docs structure, tabs or mismatched file identity.' );
        return array( 'doc' => $doc );
    }
    private static function utf16_length( $s ) {
        if ( ! function_exists( 'mb_convert_encoding' ) ) return false;
        $converted = mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' );
        return is_string( $converted ) ? strlen( $converted ) / 2 : false;
    }
    private static function extract( $doc, $source, $fields ) {
        $selectors = array();
        foreach ( $fields as $field ) {
            $selector = isset( $source['field_bindings'][ $field ]['provider_field'] )
                ? $source['field_bindings'][ $field ]['provider_field'] : $field;
            if ( ! is_string( $selector ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,120}$/D', $selector ) )
                return self::error( 'mad4b_drive_field_selector_invalid', 'A simple exact named field selector is required for Google Docs.' );
            $selectors[ $field ] = $selector;
        }
        $rows = array(); $values = array();
        foreach ( $doc['body']['content'] as $item ) {
            if ( ! isset( $item['paragraph']['elements'], $item['startIndex'] ) ) continue;
            $elements = $item['paragraph']['elements'];
            if ( ! is_array( $elements ) || count( $elements ) !== 1 ||
                ! isset( $elements[0]['textRun']['content'], $elements[0]['startIndex'] ) ) continue;
            $run = (string) $elements[0]['textRun']['content'];
            foreach ( $selectors as $field => $selector ) {
                $prefix = $selector . ': ';
                if ( 0 !== strpos( $run, $prefix ) ) continue;
                if ( isset( $values[ $field ] ) )
                    return self::error( 'mad4b_drive_field_ambiguous', 'Duplicate exact named field paragraphs.' );
                if ( substr( $run, -1 ) !== "\n" )
                    return self::error( 'mad4b_drive_field_paragraph_invalid', 'Field paragraph requires a newline boundary.' );
                $value = substr( $run, strlen( $prefix ), -1 );
                if ( strlen( $value ) > 4000 )
                    return self::error( 'mad4b_drive_field_unbounded', 'Google Docs field exceeds maximum length.' );
                $prefix_u16 = self::utf16_length( $prefix );
                $value_u16 = self::utf16_length( $value );
                if ( false === $prefix_u16 || false === $value_u16 )
                    return self::error( 'mad4b_drive_utf16_unavailable', 'UTF-16 conversion support is required to edit Docs indexes.' );
                $start = (int) $elements[0]['startIndex'] + $prefix_u16;
                $values[ $field ] = $value;
                $rows[ $field ] = array( 'start' => $start, 'end' => $start + $value_u16 );
            }
        }
        if ( array_diff( $fields, array_keys( $values ) ) )
            return self::error( 'mad4b_drive_field_missing', 'Google Docs lacks one or more mapped named field paragraphs.' );
        return array( 'values' => $values, 'rows' => $rows );
    }
    public static function read( $source, $fields, $binding ) {
        $bundle = self::document( $source, $binding );
        if ( is_wp_error( $bundle ) ) return $bundle;
        $parsed = self::extract( $bundle['doc'], $source, $fields );
        if ( is_wp_error( $parsed ) ) return $parsed;
        return array( 'resource_id' => $source['source_ref'],
            'revision' => (string) $bundle['doc']['revisionId'],
            'observed_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
            'state' => 'present', 'fields' => $parsed['values'] );
    }
    public static function write( $source, $field, $value, $expected, $binding ) {
        $bundle = self::document( $source, $binding );
        if ( is_wp_error( $bundle ) ) return $bundle;
        $doc = $bundle['doc'];
        if ( ! isset( $expected['revision'] ) ||
            ! hash_equals( (string) $expected['revision'], (string) $doc['revisionId'] ) )
            return self::error( 'mad4b_drive_docs_cas_changed', 'Document revision differs from the approved snapshot.' );
        $fields = array_keys( $expected['fields'] );
        $parsed = self::extract( $doc, $source, $fields );
        if ( is_wp_error( $parsed ) ) return $parsed;
        if ( ! array_key_exists( $field, $parsed['rows'] ) || ! is_string( $value ) ||
            strlen( $value ) > 4000 || false !== strpos( $value, "\n" ) )
            return self::error( 'mad4b_drive_docs_value_invalid', 'Google Docs field must be a bounded single-line string.' );
        if ( (string) $parsed['values'][ $field ] === $value )
            return self::read( $source, $fields, $binding );
        $start = $parsed['rows'][ $field ]['start'];
        $end = $parsed['rows'][ $field ]['end'];
        $requests = array();
        if ( $end > $start ) $requests[] = array( 'deleteContentRange' =>
            array( 'range' => array( 'startIndex' => $start, 'endIndex' => $end ) ) );
        if ( '' !== $value ) $requests[] = array( 'insertText' =>
            array( 'location' => array( 'index' => $start ), 'text' => $value ) );
        if ( !$requests ) return self::error( 'mad4b_drive_docs_noop_ambiguous', 'Nothing to write.' );
        $response = self::request( 'POST', $source, $binding,
            array( 'requests' => $requests,
                'writeControl' => array( 'requiredRevisionId' => $doc['revisionId'] ) ) );
        if ( is_wp_error( $response ) ) return $response;
        $back = self::read( $source, $fields, $binding );
        if ( is_wp_error( $back ) || ! isset( $back['fields'][ $field ] ) ||
            $back['fields'][ $field ] !== $value )
            return self::error( 'mad4b_drive_docs_write_unverified', 'Google Docs postwrite readback does not match exact value.' );
        return $back;
    }
}
if ( function_exists( 'add_filter' ) )
    add_filter( 'mad4b_activity_sync_adapters', array( 'MAD4B_SCP_Activity_Google_Docs_Adapter', 'register' ), 20, 2 );
