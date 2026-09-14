<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPL_Token {

    const META_TOKEN      = 'wpl_access_token';
    const META_CREATED    = 'wpl_token_created';
    const META_ACTIVE     = 'wpl_token_active';
    const META_USE_COUNT  = 'wpl_token_use_count';
    const META_LAST_USED  = 'wpl_token_last_used';
    const EXPIRY_SECS     = 3 * DAY_IN_SECONDS; // 72 ساعة (3 أيام)

    public static function maybe_generate() {
        if ( ! get_option( self::META_TOKEN ) ) self::generate();
    }

    public static function generate() {
        $token = wp_generate_password( 32, false );
        update_option( self::META_TOKEN,     $token );
        update_option( self::META_CREATED,   time() );
        update_option( self::META_ACTIVE,    1 );
        update_option( self::META_USE_COUNT, 0 );
        update_option( self::META_LAST_USED, 0 );
        return $token;
    }

    public static function disable() {
        update_option( self::META_ACTIVE, 0 );
    }

    public static function record_usage() {
        $count = (int) get_option( self::META_USE_COUNT, 0 );
        update_option( self::META_USE_COUNT, $count + 1 );
        update_option( self::META_LAST_USED, time() );
    }

    public static function get_data() {
        $token     = get_option( self::META_TOKEN,     '' );
        $created   = get_option( self::META_CREATED,   0 );
        $active    = (bool) get_option( self::META_ACTIVE, 0 );
        $use_count = (int) get_option( self::META_USE_COUNT, 0 );
        $last_used = (int) get_option( self::META_LAST_USED, 0 );

        $expires_at   = $created + self::EXPIRY_SECS;
        $is_expired   = time() > $expires_at;
        $seconds_left = max( 0, $expires_at - time() );

        return [
            'token'           => $token,
            'created'         => $created,
            'active'          => $active && ! $is_expired,
            'is_expired'      => $is_expired,
            'expires_at'      => $expires_at,
            'seconds_left'    => $seconds_left,
            'expires_human'   => self::human_time_left( $seconds_left ),
            'use_count'       => $use_count,
            'last_used'       => $last_used,
            'last_used_human' => $last_used ? self::human_time_ago( $last_used ) : null,
        ];
    }

    public static function get_login_url() {
        $data = self::get_data();
        if ( empty( $data['token'] ) ) return '';
        return add_query_arg( [ 'wpl_token' => $data['token'] ], admin_url( 'admin.php?page=wpl-client' ) );
    }

    public static function validate( $token ) {
        $data = self::get_data();
        if ( empty( $data['token'] ) )                  return false;
        if ( ! hash_equals( $data['token'], $token ) )  return false;
        if ( ! $data['active'] || $data['is_expired'] ) return false;
        return true;
    }

    private static function human_time_left( $seconds ) {
        if ( $seconds <= 0 )             return 'منتهي الصلاحية';
        if ( $seconds < 3600 )           return round( $seconds / 60 ) . ' دقيقة';
        if ( $seconds < DAY_IN_SECONDS ) return round( $seconds / 3600 ) . ' ساعة';
        return round( $seconds / DAY_IN_SECONDS ) . ' يوم';
    }

    private static function human_time_ago( $timestamp ) {
        $diff = time() - $timestamp;
        if ( $diff < 60 )                return 'منذ أقل من دقيقة';
        if ( $diff < 3600 )              return 'منذ ' . round( $diff / 60 ) . ' دقيقة';
        if ( $diff < DAY_IN_SECONDS )    return 'منذ ' . round( $diff / 3600 ) . ' ساعة';
        return 'منذ ' . round( $diff / DAY_IN_SECONDS ) . ' يوم';
    }
}
