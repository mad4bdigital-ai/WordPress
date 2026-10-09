<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only, bounded competitor public-page metadata observation. */
final class MAD4B_SCP_Competitor_Public_Observer {
    const CONTRACT = 'mad4b.competitor-public-observation.v1';
    const MAX_HTML_BYTES = 131072;
    const MAX_ROBOTS_BYTES = 32768;
    const CACHE_SECONDS = 3600;
    private static function fail( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function url_parts( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 || false !== strpos( $url, '#' ) ) return false;
        $p = parse_url( $url );
        return is_array( $p ) && isset( $p['host'], $p['scheme'] )
            && in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true )
            && ! isset( $p['user'] ) && ! isset( $p['pass'] ) && ! isset( $p['port'] )
            && ! preg_match( '/[?&](?:access_token|token|api_?key|secret|signature|password)=/i', $url ) ? $p : false;
    }

    private static function robots_allowed( $body, $path ) {
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_ROBOTS_BYTES ) return false;
        $active = false; $groups = array(); $matching = array(); $group = 0;
        foreach ( preg_split( '/\\r?\\n/', $body ) as $line ) {
            $line = trim( preg_replace( '/#.*$/', '', $line ) );
            if ( '' === $line ) { $active = false; continue; }
            if ( preg_match( '/^user-agent:\\s*(.+)$/i', $line, $m ) ) {
                $agent = strtolower( trim( $m[1] ) );
                if ( ! $active ) { ++$group; $groups[ $group ] = array(); }
                $active = true;
                if ( '*' === $agent || 'mad4bmarketresearch' === $agent ) $matching[ $group ] = true;
                continue;
            }
            if ( ! $active || ! isset( $matching[ $group ] ) ) continue;
            if ( preg_match( '/^(allow|disallow):\\s*(.*)$/i', $line, $m ) ) {
                $rule = trim( $m[2] );
                if ( '' === $rule ) continue;
                // Conservatively refuse unhandled wildcard rules rather than
                // interpreting a restrictive robots policy as permission.
                if ( false !== strpos( $rule, '*' ) || false !== strpos( $rule, '$' ) ) return false;
                $groups[ $group ][] = array( strtolower( $m[1] ), $rule );
            }
        }
        $winner = -1; $allowed = true;
        foreach ( $groups as $n => $rules ) {
            if ( ! isset( $matching[ $n ] ) ) continue;
            foreach ( $rules as $rule ) {
                if ( 0 !== strpos( $path, $rule[1] ) ) continue;
                $length = strlen( $rule[1] );
                if ( $length > $winner || ( $length === $winner && 'allow' === $rule[0] ) ) {
                    $winner = $length;
                    $allowed = 'allow' === $rule[0];
                }
            }
        }
        return $allowed;
    }

    private static function text_meta( $html, $key ) {
        if ( 'title' === $key ) {
            if ( ! preg_match( '/<title[^>]*>(.*?)<\\/title>/si', $html, $m ) ) return '';
            $v = $m[1];
        } else {
            // Attribute ordering is not authoritative; parse individual
            // short meta elements and compare their name/property attributes.
            $v = '';
            if ( preg_match_all( '/<meta\\s+[^>]{0,1500}>/si', $html, $all ) ) {
                foreach ( $all[0] as $tag ) {
                    if ( ! preg_match( '/(?:name|property)\\s*=\\s*["\\\']([^"\\\']+)["\\\']/i', $tag, $which ) || strtolower( $which[1] ) !== $key ) continue;
                    if ( preg_match( '/content\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i', $tag, $m ) ) { $v = $m[1]; break; }
                }
            }
        }
        return substr( trim( html_entity_decode( strip_tags( (string) $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 0, 600 );
    }

    public static function observe( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        $id = isset( $input['competitor_id'] ) ? (string) $input['competitor_id'] : '';
        $entry = isset( $registry['competitors'][ $id ] ) ? $registry['competitors'][ $id ] : array();
        if ( ! $entry || ! empty( $entry['disabled'] ) ) return self::fail( 'mad4b_competitor_unavailable', 'Competitor is absent or disabled.' );
        $origin = self::url_parts( isset( $entry['source_url'] ) ? $entry['source_url'] : '' );
        $url = isset( $input['url'] ) && '' !== (string) $input['url'] ? (string) $input['url'] : (string) $entry['source_url'];
        $target = self::url_parts( $url );
        if ( ! $origin || ! $target || ! hash_equals( strtolower( $origin['host'] ), strtolower( $target['host'] ) )
            || strtolower( $origin['scheme'] ) !== strtolower( $target['scheme'] ) )
            return self::fail( 'mad4b_competitor_observation_scope_denied', 'Page URL must match the configured competitor origin exactly.' );
        if ( ! function_exists( 'wp_safe_remote_get' ) || ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) )
            return self::fail( 'mad4b_competitor_observer_runtime_unavailable', 'WordPress safe HTTP and cache are required.' );
        $key = 'mad4b_comp_obs_' . hash( 'sha256', $id . '|' . $url );
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['contract'] ) && self::CONTRACT === $cached['contract'] )
            return array_merge( $cached, array( 'from_cache' => true ) );
        $origin_url = strtolower( $origin['scheme'] ) . '://' . strtolower( $origin['host'] );
        $args = array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => self::MAX_ROBOTS_BYTES,
            'headers' => array( 'User-Agent' => 'MAD4BMarketResearch/1.0', 'Accept' => 'text/plain' ) );
        $robots = wp_safe_remote_get( $origin_url . '/robots.txt', $args );
        if ( is_wp_error( $robots ) ) return self::fail( 'mad4b_competitor_robots_unavailable', 'Robots policy could not be verified.' );
        $code = wp_remote_retrieve_response_code( $robots );
        if ( 404 !== $code && 200 !== $code ) return self::fail( 'mad4b_competitor_robots_unavailable', 'Robots policy response is ambiguous or unavailable.' );
        $path = isset( $target['path'] ) && '' !== $target['path'] ? $target['path'] : '/';
        if ( 200 === $code && ! self::robots_allowed( wp_remote_retrieve_body( $robots ), $path ) )
            return self::fail( 'mad4b_competitor_robots_denied', 'Robots policy does not permit this observation path.' );
        $args['limit_response_size'] = self::MAX_HTML_BYTES;
        $args['headers']['Accept'] = 'text/html';
        $response = wp_safe_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) return self::fail( 'mad4b_competitor_fetch_failed', 'Public source could not be retrieved.' );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return self::fail( 'mad4b_competitor_page_not_public', 'Page did not return permitted public HTML.' );
        $mime = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( false === strpos( $mime, 'text/html' ) ) return self::fail( 'mad4b_competitor_mime_denied', 'Source did not return HTML.' );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body || strlen( $body ) >= self::MAX_HTML_BYTES ) return self::fail( 'mad4b_competitor_response_incomplete', 'HTML response absent or may be truncated.' );
        $result = array(
            'contract' => self::CONTRACT, 'competitor_id' => $id, 'source_url' => $url,
            'observed_at' => gmdate( 'c' ),
            'title' => self::text_meta( $body, 'title' ),
            'description' => self::text_meta( $body, 'description' ),
            'candidate_image_url' => self::text_meta( $body, 'og:image' ),
            'indicative_price_amount' => self::text_meta( $body, 'product:price:amount' ),
            'indicative_price_currency' => self::text_meta( $body, 'product:price:currency' ),
            'robots_checked' => true, 'from_cache' => false,
            'content_is_untrusted_reference_only' => true,
            'market_price_verified' => false, 'live_availability_verified' => false,
            'image_license_verified' => false, 'image_downloaded' => false,
            'raw_html_exposed' => false, 'commercial_reuse_authorized' => false,
            'read_only' => true, 'mutation_performed' => false,
        );
        set_transient( $key, $result, self::CACHE_SECONDS );
        return $result;
    }
}
