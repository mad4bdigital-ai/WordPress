<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Existing PageSpeed Insights field-data network and bounded worker runtime.
 * Bound into Core Web Vitals with the original permission and scheduling paths.
 * No API key, lock, retry, rate, or remote-call policy is changed.
 */
trait MAD4B_SCP_Core_Web_Vitals_Workers_Trait {
    private static function fetch_strategy( $strategy, $site_url, $timeout = 35 ) {
        $args = array( 'url' => $site_url, 'strategy' => $strategy, 'category' => 'performance' );
        $api_key = self::api_key();
        if ( '' !== $api_key ) $args['key'] = $api_key;
        $url = add_query_arg( $args, self::API );
        // The endpoint is constant; do not follow redirects to arbitrary hosts.
        $request_started_at = microtime( true );
        $response = wp_remote_get( $url, array(
            // Two serial Lighthouse requests must fit the live PHP-FPM 100-second ceiling.
            'timeout' => max( 5, min( self::DEVICE_TIMEOUT, (int) $timeout ) ), 'redirection' => 0, 'limit_response_size' => 8388608,
            'headers' => array( 'Accept' => 'application/json' ),
            'user-agent' => 'MAD4B-Core-Web-Vitals/1.0',
        ) );
        $duration_ms = (int) round( ( microtime( true ) - $request_started_at ) * 1000 );
        if ( is_wp_error( $response ) ) {
            $message = strtolower( (string) $response->get_error_message() );
            $class = ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) )
                ? 'timeout' : ( false !== strpos( $message, 'ssl' ) ? 'tls_error'
                : ( false !== strpos( $message, 'resolve' ) ? 'dns_error' : 'transport_other' ) );
            return array(
                'ok' => false,
                'state' => 'transport_error',
                'error_class' => $class,
                'error_code' => sanitize_key( (string) $response->get_error_code() ),
                'duration_ms' => $duration_ms,
            );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $http ) {
            return array( 'ok' => false, 'state' => 429 === $http ? 'rate_limited' :
                ( 403 === $http ? 'provider_access_denied' : 'provider_http_error' ), 'http_status' => $http, 'duration_ms' => $duration_ms );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return array( 'ok' => false, 'state' => 'invalid_provider_response', 'duration_ms' => $duration_ms );
        }
        $origin_data = isset( $body['originLoadingExperience'] ) && is_array( $body['originLoadingExperience'] )
            ? $body['originLoadingExperience'] : array();
        $page_data = isset( $body['loadingExperience'] ) && is_array( $body['loadingExperience'] )
            ? $body['loadingExperience'] : array();
        $lighthouse = isset( $body['lighthouseResult'] ) && is_array( $body['lighthouseResult'] )
            ? $body['lighthouseResult'] : array();
        $score = $lighthouse['categories']['performance']['score'] ?? null;
        $score = is_numeric( $score ) && $score >= 0 && $score <= 1 ? (int) round( 100 * $score ) : null;
        $origin = self::assess_field_data( $origin_data );
        $page = self::assess_field_data( $page_data );
        if ( ! empty( $page_data['origin_fallback'] ) ) {
            $page['verdict'] = 'origin_fallback';
            $page['fallback_to_origin'] = true;
        }
        return array(
            'ok' => true,
            'state' => 'collected',
            'duration_ms' => $duration_ms,
            'snapshot' => array(
                'collected_at' => gmdate( 'c' ),
                'strategy' => $strategy,
                'url' => $site_url,
                'origin' => $origin,
                'homepage' => $page,
                'lighthouse_performance_score' => $score,
                'lighthouse_is_not_field_data' => true,
                'source' => 'google_pagespeed_insights_v5',
            ),
        );
    }


    /**
     * Daily and manual Cron events only schedule workers. No remote Google call
     * occurs in dispatch, admin-post or REST. Device jobs are chained rather
     * than parallel, so a slow Lighthouse run never consumes two PHP-FPM slots.
     */
    public static function dispatch_daily() {
        $life = self::lifecycle_status();
        if ( in_array( $life['state'], array( 'queued', 'running' ), true ) ||
            $life['worker_running'] || $life['device_next_run_epoch'] ) return;
        $record = get_option( self::OPTION, array() );
        if ( is_array( $record ) && (int) ( $record['cooldown_until_epoch'] ?? 0 ) > time() ) return;
        update_option( self::JOB_OPTION, array(
            'job_id' => wp_generate_uuid4(),
            'state' => 'queued',
            'entry_point' => 'daily',
            'queued_at' => gmdate( 'c' ),
        ), false );
        self::dispatch_queued();
    }

    public static function dispatch_queued() {
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || 'queued' !== ( $job['state'] ?? '' ) || empty( $job['job_id'] ) ) return;
        $url = self::site_url();
        if ( is_wp_error( $url ) ) {
            $job['state'] = 'failed';
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array( 'state' => 'invalid_site_url' );
            update_option( self::JOB_OPTION, $job, false );
            return;
        }
        $job['state'] = 'running';
        $job['started_at'] = gmdate( 'c' );
        $job['page_url'] = $url;
        $job['pending_devices'] = array( 'mobile', 'desktop' );
        $job['attempt_counts'] = array( 'mobile' => 0, 'desktop' => 0 );
        $job['result'] = array( 'device_states' => array(), 'attempts' => array() );
        update_option( self::JOB_OPTION, $job, false );
        $record = get_option( self::OPTION, array() );
        if ( ! is_array( $record ) ) $record = array();
        $record['attempts'] = array();
        $record['attempted_at'] = gmdate( 'c' );
        update_option( self::OPTION, $record, false );
        $scheduled = wp_schedule_single_event(
            time() + 5, self::DEVICE_HOOK, array( (string) $job['job_id'], 'mobile' ), true
        );
        if ( is_wp_error( $scheduled ) || false === $scheduled ) {
            $job['state'] = 'failed';
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array( 'state' => 'device_schedule_failed' );
            update_option( self::JOB_OPTION, $job, false );
        }
    }

    /** Single-device, bounded provider worker. Old successful snapshots are never deleted on failure. */
    public static function run_device( $job_id, $strategy ) {
        $job_id = (string) $job_id;
        $strategy = (string) $strategy;
        if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) return;
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id ||
            'running' !== ( $job['state'] ?? '' ) ||
            ( $job['pending_devices'][0] ?? '' ) !== $strategy ) return;
        $token = self::acquire_lock();
        if ( ! $token ) {
            // WordPress unschedules an event before firing it: explicitly requeue
            // on contention rather than silently dropping this device.
            wp_schedule_single_event( time() + 45, self::DEVICE_HOOK, array( $job_id, $strategy ) );
            return;
        }
        try {
            $job = get_option( self::JOB_OPTION, array() );
            if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id ||
                'running' !== ( $job['state'] ?? '' ) ) return;
            $job['active_device'] = $strategy;
            $job['attempt_counts'][ $strategy ] = 1 + (int) ( $job['attempt_counts'][ $strategy ] ?? 0 );
            $job['device_started_at'] = gmdate( 'c' );
            update_option( self::JOB_OPTION, $job, false );
            $url = self::site_url();
            if ( is_wp_error( $url ) || ( $job['page_url'] ?? '' ) !== $url ) {
                $result = array( 'ok' => false, 'state' => 'configuration_changed' );
            } else {
                $result = self::fetch_strategy( $strategy, $url, self::DEVICE_TIMEOUT );
            }
            // Reload after the long network request; a newer page setting must not
            // inherit field-data from a previous target.
            $current_url = self::site_url();
            if ( is_wp_error( $current_url ) || ( $job['page_url'] ?? '' ) !== $current_url ) {
                $result = array( 'ok' => false, 'state' => 'configuration_changed' );
            }
            $record = get_option( self::OPTION, array() );
            if ( ! is_array( $record ) ) $record = array();
            $record['contract'] = self::CONTRACT;
            $record['attempted_at'] = gmdate( 'c' );
            $record['attempts'] = is_array( $record['attempts'] ?? null ) ? $record['attempts'] : array();
            $record['devices'] = is_array( $record['devices'] ?? null ) ? $record['devices'] : array();
            $record['history'] = is_array( $record['history'] ?? null ) ? $record['history'] : array();
            $attempt = array(
                'state' => (string) ( $result['state'] ?? 'unknown' ),
                'duration_ms' => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : null,
                'error_code' => isset( $result['error_code'] ) ? sanitize_key( (string) $result['error_code'] ) : null,
                'error_class' => isset( $result['error_class'] ) ? sanitize_key( (string) $result['error_class'] ) : null,
                'http_status' => isset( $result['http_status'] ) ? (int) $result['http_status'] : null,
                'at' => gmdate( 'c' ),
            );
            $record['attempts'][ $strategy ] = $attempt;
            if ( ! empty( $result['ok'] ) ) {
                $record['devices'][ $strategy ] = $result['snapshot'];
                $record['history'][] = array(
                    'date' => gmdate( 'Y-m-d' ), 'strategy' => $strategy,
                    'url' => $url,
                    'origin_verdict' => $result['snapshot']['origin']['verdict'],
                    'origin_lcp' => $result['snapshot']['origin']['metrics']['lcp']['p75'],
                    'origin_inp' => $result['snapshot']['origin']['metrics']['inp']['p75'],
                    'origin_cls' => $result['snapshot']['origin']['metrics']['cls']['p75'],
                );
                $record['history'] = array_slice( $record['history'], -self::MAX_HISTORY * 2 );
            }
            $record['last_attempt_has_errors'] = false;
            foreach ( $record['attempts'] as $latest ) {
                if ( 'collected' !== ( $latest['state'] ?? '' ) ) $record['last_attempt_has_errors'] = true;
            }
            if ( 'rate_limited' === $attempt['state'] ) {
                $record['cooldown_until_epoch'] = max( (int) ( $record['cooldown_until_epoch'] ?? 0 ), time() + 900 );
            } elseif ( 'provider_access_denied' === $attempt['state'] ) {
                $record['cooldown_until_epoch'] = max( (int) ( $record['cooldown_until_epoch'] ?? 0 ), time() + 1800 );
            }
            update_option( self::OPTION, $record, false );
            $job = get_option( self::JOB_OPTION, array() );
            if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id ||
                'running' !== ( $job['state'] ?? '' ) ) return;
            $job['result']['device_states'][ $strategy ] = $attempt['state'];
            $job['result']['attempts'][ $strategy ] = $attempt;
            $job['device_completed_at'] = gmdate( 'c' );
            array_shift( $job['pending_devices'] );
            $retryable = 'transport_error' === $attempt['state']
                && (int) ( $job['attempt_counts'][ $strategy ] ?? 0 ) < 2;
            if ( $retryable ) $job['pending_devices'][] = $strategy;
            if ( ! empty( $job['pending_devices'] ) ) {
                $next = (string) $job['pending_devices'][0];
                // Delay a second attempt to avoid a tight PSI retry loop.
                $delay = (int) ( $job['attempt_counts'][ $next ] ?? 0 ) > 0 ? self::RETRY_DELAY : 5;
                $scheduled = wp_schedule_single_event(
                    time() + $delay, self::DEVICE_HOOK, array( $job_id, $next ), true
                );
                if ( is_wp_error( $scheduled ) || false === $scheduled ) {
                    $job['state'] = 'failed';
                    $job['completed_at'] = gmdate( 'c' );
                    $job['result']['state'] = 'next_device_schedule_failed';
                }
            } else {
                $collected = count( array_filter(
                    $job['result']['device_states'],
                    static function( $state ) { return 'collected' === $state; }
                ) );
                $job['state'] = 2 === $collected ? 'completed' : ( $collected ? 'partial' : 'failed' );
                $job['completed_at'] = gmdate( 'c' );
                $job['result']['collected_devices'] = $collected;
                if ( 'failed' === $job['state'] && empty( $record['cooldown_until_epoch'] ) ) {
                    $record['cooldown_until_epoch'] = time() + 120;
                    update_option( self::OPTION, $record, false );
                }
            }
            update_option( self::JOB_OPTION, $job, false );
        } catch ( Throwable $e ) {
            // Never expose provider error strings, which can contain URL query credentials.
            $job = get_option( self::JOB_OPTION, array() );
            if ( is_array( $job ) && ( $job['job_id'] ?? '' ) === $job_id ) {
                $job['state'] = 'failed';
                $job['completed_at'] = gmdate( 'c' );
                $job['result']['state'] = 'device_worker_exception';
                update_option( self::JOB_OPTION, $job, false );
            }
        } finally {
            self::release_lock( $token );
        }
    }

    private static function acquire_lock() {
        global $wpdb;
        $now = time();
        $prior = get_option( self::LOCK, array() );
        if ( is_array( $prior ) && ! empty( $prior['expires'] ) && (int) $prior['expires'] < $now ) {
            $deleted = $wpdb->delete(
                $wpdb->options,
                array( 'option_name' => self::LOCK, 'option_value' => maybe_serialize( $prior ) ),
                array( '%s', '%s' )
            );
            if ( 1 === (int) $deleted ) wp_cache_delete( self::LOCK, 'options' );
        }
        $token = wp_generate_uuid4();
        return add_option( self::LOCK, array( 'token' => $token, 'expires' => $now + self::LOCK_TTL ), '', false )
            ? $token : false;
    }

    private static function release_lock( $token ) {
        global $wpdb;
        $current = get_option( self::LOCK, array() );
        if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
            $deleted = $wpdb->delete(
                $wpdb->options,
                array( 'option_name' => self::LOCK, 'option_value' => maybe_serialize( $current ) ),
                array( '%s', '%s' )
            );
            if ( 1 === (int) $deleted ) wp_cache_delete( self::LOCK, 'options' );
        }
    }

    public static function refresh() {
        $token = self::acquire_lock();
        if ( ! $token ) return array( 'state' => 'already_running' );
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || 'queued' !== ( $job['state'] ?? '' ) ) {
            $job = array(
                'job_id' => wp_generate_uuid4(),
                'entry_point' => 'scheduled_cron',
                'queued_at' => gmdate( 'c' ),
            );
        }
        $job['state'] = 'running';
        $job['started_at'] = gmdate( 'c' );
        update_option( self::JOB_OPTION, $job, false );
        try {
            $record = get_option( self::OPTION, array() );
            if ( ! is_array( $record ) ) $record = array();
            $record['attempted_at'] = gmdate( 'c' );
            $record['contract'] = self::CONTRACT;
            $record['devices'] = is_array( $record['devices'] ?? null ) ? $record['devices'] : array();
            $record['attempts'] = array();
            $record['history'] = is_array( $record['history'] ?? null ) ? $record['history'] : array();
            $site_url = self::site_url();
            if ( is_wp_error( $site_url ) ) {
                $record['attempts']['site'] = array( 'state' => 'invalid_site_url', 'at' => gmdate( 'c' ) );
            } else {
                $provider_unavailable = false;
                foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
                    $result = $provider_unavailable
                        ? array('ok'=>false,'state'=>'deferred_provider_unavailable','duration_ms'=>0)
                        : self::fetch_strategy( $strategy, $site_url );
                    if (in_array($result['state'], array('transport_error','rate_limited','provider_access_denied'), true)) {
                        $provider_unavailable = true;
                    }
                    $record['attempts'][ $strategy ] = array(
                        'state' => $result['state'],
                        'duration_ms' => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : null,
                        'error_code' => isset( $result['error_code'] ) ? sanitize_key( (string) $result['error_code'] ) : null,
                        'error_class' => isset( $result['error_class'] ) ? sanitize_key( (string) $result['error_class'] ) : null,
                        'http_status' => isset( $result['http_status'] ) ? (int) $result['http_status'] : null,
                        'at' => gmdate( 'c' ),
                    );
                    if ( ! empty( $result['ok'] ) ) {
                        $record['devices'][ $strategy ] = $result['snapshot'];
                        $record['history'][] = array(
                            'date' => gmdate( 'Y-m-d' ), 'strategy' => $strategy,
                            'url' => $site_url,
                            'origin_verdict' => $result['snapshot']['origin']['verdict'],
                            'origin_lcp' => $result['snapshot']['origin']['metrics']['lcp']['p75'],
                            'origin_inp' => $result['snapshot']['origin']['metrics']['inp']['p75'],
                            'origin_cls' => $result['snapshot']['origin']['metrics']['cls']['p75'],
                        );
                    }
                    // Persist per-device evidence even if the next provider request fails.
                    update_option( self::OPTION, $record, false );
                }
            }
            $record['history'] = array_slice( $record['history'], -self::MAX_HISTORY * 2 );
            $record['last_attempt_has_errors'] = false;
            $collected = 0;
            $cooldown = 0;
            foreach ( $record['attempts'] as $attempt ) {
                if ( 'collected' === $attempt['state'] ) { ++$collected; continue; }
                $record['last_attempt_has_errors'] = true;
                $cooldown = max( $cooldown, 'rate_limited' === $attempt['state'] ? 900 :
                    ( 'provider_access_denied' === $attempt['state'] ? 1800 :
                    ( 'transport_error' === $attempt['state'] || 'deferred_provider_unavailable' === $attempt['state'] ? 600 : 120 ) ) );
            }
            $record['cooldown_until_epoch'] = $cooldown ? time() + $cooldown : 0;
            update_option( self::OPTION, $record, false );
            $job['state'] = 2 === $collected ? 'completed' : ( $collected ? 'partial' : 'failed' );
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array(
                'collected_devices' => $collected,
                'attempts' => $record['attempts'],
                'cooldown_until_epoch' => $record['cooldown_until_epoch'],
            );
            update_option( self::JOB_OPTION, $job, false );
            return self::status();
        } catch ( Throwable $e ) {
            // Do not include sensitive provider errors or request URLs in any status.
            $job['state'] = 'failed';
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array( 'state' => 'worker_exception' );
            update_option( self::JOB_OPTION, $job, false );
            return array( 'state' => 'worker_exception' );
        } finally {
            self::release_lock( $token );
        }
    }

}
