<?php
/**
 * Plugin Name: تراخيص ووردبريس
 * Description: إدارة الإضافات والقوالب المرخصة من WordPress Licenses
 * Version:     2.8.0
 * Author:      WordPress Licenses
 * Text Domain: wpl-client
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPL_SERVER_API_URL', 'https://wordpresslicenses.com/wp-json/wpl/v1' );
define( 'WPL_CLIENT_VERSION', '2.8.0' );
define( 'WPL_CLIENT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WPL_CLIENT_URL',     plugin_dir_url( __FILE__ ) );

require_once WPL_CLIENT_DIR . 'includes/class-wpl-server-client.php';

// A private/account package may carry its credential in an untracked bootstrap
// file. Import it once into a non-autoloaded option, then operate from runtime
// state. Public source/distributable ZIPs never embed account credentials.
WPL_Server_Client::bootstrap_package_credential();

// Compatibility for older internal classes that still read this constant.
// wp-config.php may define it first; otherwise the runtime resolver supplies it.
if ( ! defined( 'WPL_SERVER_API_KEY' ) ) {
    define( 'WPL_SERVER_API_KEY', WPL_Server_Client::credential() );
}

require_once WPL_CLIENT_DIR . 'includes/class-wpl-token.php';
require_once WPL_CLIENT_DIR . 'includes/class-wpl-database-maintenance.php';
require_once WPL_CLIENT_DIR . 'includes/class-wpl-license-health-monitor.php';
require_once WPL_CLIENT_DIR . 'includes/class-wpl-installer.php';
require_once WPL_CLIENT_DIR . 'includes/class-wpl-client-admin.php';

register_activation_hook( __FILE__, 'wpl_client_on_activate' );

if ( ! function_exists( 'wpl_client_on_activate' ) ) {
function wpl_client_on_activate( $network_wide = false ) {
    WPL_Server_Client::bootstrap_package_credential();
    WPL_Database_Maintenance::activate( $network_wide );
    WPL_License_Health_Monitor::activate( $network_wide );
    WPL_Token::generate();
    update_option( 'wpl_client_do_redirect',    true, false );
    update_option( 'wpl_client_do_register',    true, false );
    update_option( 'wpl_client_do_create_user', true, false );

    // امسح التحقق بس لو مفيش order serial متحقق
    if ( ! get_option('wpl_verified_order_number') && ! get_option('wpl_has_pending_request') ) {
        delete_option( 'wpl_serial_verified' );
    }
}
}

add_action( 'admin_init', function() {
    if ( get_option( 'wpl_client_do_create_user' ) ) {
        delete_option( 'wpl_client_do_create_user' );
        if ( ! WPL_Client_Admin::get_wpl_user_id() && get_option( 'wpl_access_token' ) ) {
            WPL_Client_Admin::create_wpl_user();
        }
    }
    if ( get_option( 'wpl_client_do_register' ) ) {
        delete_option( 'wpl_client_do_register' );
        WPL_Client_Admin::register_with_server_static();
    }
    if ( get_option( 'wpl_client_do_redirect' ) ) {
        delete_option( 'wpl_client_do_redirect' );
        wp_safe_redirect( admin_url( 'admin.php?page=wpl-client' ) );
        exit;
    }
});

new WPL_Client_Admin();
$wpl_installer = new WPL_Installer();
new WPL_License_Health_Monitor();

// WPL_Installer::__construct() already owns both admin-post handlers.
// Reusing one instance prevents duplicate callbacks and overlapping jobs.
add_action( 'wpl_bg_install_cron', [ $wpl_installer, 'cron_bg_install' ] );

// ====== File Change Check — امسح السيريال في كل مرة يتغير الملف ======
add_action( 'admin_init', function() {
    $current_mtime = (string) filemtime( __FILE__ );
    $stored_mtime  = get_option( 'wpl_client_mtime', '' );

    if ( $stored_mtime !== $current_mtime ) {
        update_option( 'wpl_client_mtime', $current_mtime );
        // امسح التحقق بس لو مفيش order serial متحقق أو pending request
        // لو العميل فعّل بسيريال أوردر → نفضله متحقق بعد التحديث
        if ( ! get_option('wpl_verified_order_number') && ! get_option('wpl_has_pending_request') ) {
            delete_option( 'wpl_serial_verified' );
        }
        // امسح كل transient تابع للإضافة من namespace واحد.
        WPL_Database_Maintenance::clear_transients();
    }
} );

add_action( 'wpl_daily_heartbeat', function() {
    WPL_Client_Admin::register_with_server_static();
} );

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'wpl_daily_heartbeat' ) ) {
        wp_schedule_event( time(), 'daily', 'wpl_daily_heartbeat' );
    }
} );

register_deactivation_hook( __FILE__, function( $network_wide = false ) {
    WPL_Database_Maintenance::deactivate( $network_wide );
    WPL_License_Health_Monitor::deactivate( $network_wide );
    wp_clear_scheduled_hook( 'wpl_daily_heartbeat' );
    WPL_Token::disable();
    WPL_Client_Admin::delete_wpl_user();

    $domain = parse_url( home_url(), PHP_URL_HOST );
    if ( ! empty( $domain ) ) {
        WPL_Server_Client::request( 'POST', '/deactivate', [
            'body'    => [ 'domain' => $domain ],
            'timeout' => 10,
        ] );
    }
} );

/**
 * حماية البلجن من التعطيل أو الحذف عند الدخول عبر الرابط المؤقت
 * ---------------------------------------------------------------
 * 1) إخفاء روابط "تعطيل" و"حذف" بصرياً من صفحة الإضافات
 * 2) حجب أي محاولة مباشرة عبر URL (deactivate / delete)
 */
add_action( 'admin_init', function() {
    // شغّال بس على مستخدمي الرابط المؤقت
    if ( ! get_user_meta( get_current_user_id(), '_wpl_access_user', true ) ) return;

    $plugin_file = plugin_basename( __FILE__ );

    // 1) إخفاء روابط التعطيل والحذف بصرياً
    add_filter( 'plugin_action_links_' . $plugin_file, function( $actions ) {
        unset( $actions['deactivate'], $actions['delete'] );
        return $actions;
    }, 20 );

    // 2) حجب التعطيل المباشر عبر URL: ?action=deactivate&plugin=...
    if (
        isset( $_GET['action'], $_GET['plugin'] ) &&
        in_array( $_GET['action'], [ 'deactivate', 'delete-plugin' ], true ) &&
        plugin_basename( WPL_CLIENT_DIR . 'wpl-client.php' ) === $_GET['plugin']
    ) {
        wp_die(
            '<p style="font-family:sans-serif;font-size:15px;direction:rtl">⛔ غير مسموح — لا يمكنك تعطيل أو حذف إضافة تراخيص ووردبريس عبر هذا الرابط.</p>',
            'غير مسموح',
            [ 'response' => 403, 'back_link' => true ]
        );
    }

    // 3) حجب الـ Bulk Actions — شيل البلجن من $_POST['checked'] قبل ما WordPress يقرأه
    add_action( 'load-plugins.php', function() use ( $plugin_file ) {
        if ( ! isset( $_POST['action'], $_POST['checked'] ) ) return;
        $action = $_POST['action'] !== '-1' ? $_POST['action'] : ( $_POST['action2'] ?? '-1' );
        if ( ! in_array( $action, [ 'deactivate-selected', 'delete-selected' ], true ) ) return;
        $_POST['checked'] = array_values( array_filter(
            (array) $_POST['checked'],
            fn( $p ) => $p !== $plugin_file
        ) );
    } );
} );

/**
 * ============================================================
 * Domain Ownership Verification Endpoint
 * ============================================================
 * السيرفر بيسأل البلجن للتأكد إن الدومين فعلاً مسجّل بلجن تراخيص ووردبريس.
 * الطريقة:
 *   - السيرفر بيبعت POST مع challenge code و X-WPL-Key
 *   - الكلاينت بيتحقّق من بيانات اتصال WPL الحالية
 *   - لو صح، بيرد بنفس الـ challenge + domain + timestamp
 *   - لو غلط، بيرفض 401
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wpl-client/v1', '/verify-ownership', [
        'methods'             => 'POST',
        'callback'            => 'wpl_handle_ownership_verification',
        'permission_callback' => '__return_true', // الـ auth جوه الـ callback
        'args'                => [
            'challenge' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);
});

if ( ! function_exists( 'wpl_handle_ownership_verification' ) ) {
function wpl_handle_ownership_verification( $request ) {
    // 1. تحقّق من بيانات اتصال WPL الحالية. إذا لم تكن مهيأة نفشل مغلقاً.
    $expected_key = WPL_Server_Client::credential();
    $provided_key = (string) $request->get_header( 'X-WPL-Key' );
    if ( $expected_key === '' || $provided_key === '' || ! hash_equals( $expected_key, $provided_key ) ) {
        return new WP_Error( 'wpl_unauthorized', 'Unauthorized', [ 'status' => 401 ] );
    }

    // 2. اقرأ الـ challenge
    $challenge = sanitize_text_field( $request->get_param( 'challenge' ) );
    if ( empty( $challenge ) || strlen( $challenge ) > 128 ) {
        return new WP_Error( 'wpl_invalid_challenge', 'Invalid challenge', [ 'status' => 400 ] );
    }

    // 3. ردّ بالـ challenge + معلومات الدومين (كدليل إن البلجن موجود فعلاً)
    return rest_ensure_response([
        'success'   => true,
        'challenge' => $challenge,
        'domain'    => parse_url( home_url(), PHP_URL_HOST ),
        'version'   => WPL_CLIENT_VERSION,
        'timestamp' => time(),
    ]);
}
}
