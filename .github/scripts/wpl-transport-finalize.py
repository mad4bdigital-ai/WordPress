from pathlib import Path
import re

MAIN = Path('wp-content/plugins/wpl-client/wpl-client.php')
SERVER = Path('wp-content/plugins/wpl-client/includes/class-wpl-server-client.php')
INSTALLER = Path('wp-content/plugins/wpl-client/includes/class-wpl-installer.php')
CONTRACT = Path('.github/workflows/wpl-client-contract.yml')


def read(path):
    return path.read_text(encoding='utf-8')


def write(path, value):
    path.write_text(value, encoding='utf-8')


def once(value, old, new, label):
    n = value.count(old)
    if n != 1:
        raise SystemExit(f'{label}: expected exactly one match, got {n}')
    return value.replace(old, new, 1)


def span(value, start, end, replacement, label, keep_end=True):
    a = value.find(start)
    if a < 0:
        raise SystemExit(f'{label}: start marker missing')
    b = value.find(end, a + len(start))
    if b < 0:
        raise SystemExit(f'{label}: end marker missing')
    return value[:a] + replacement + (value[b:] if keep_end else value[b + len(end):])


# ---------------------------------------------------------------------------
# Main bootstrap: never synthesize WPL_SERVER_API_KEY from runtime state.
# External wp-config constants remain supported directly by WPL_Server_Client.
# ---------------------------------------------------------------------------
m = read(MAIN)
compat = "// Compatibility for older internal classes that still read this constant.\n// wp-config.php may define it first; otherwise the runtime resolver supplies it.\nif ( ! defined( 'WPL_SERVER_API_KEY' ) ) {\n    define( 'WPL_SERVER_API_KEY', WPL_Server_Client::credential() );\n}\n\n"
if compat not in m:
    raise SystemExit('main compatibility block missing')
m = m.replace(compat, '', 1)
write(MAIN, m)


# ---------------------------------------------------------------------------
# Resolver: invalid external config must not shadow a valid fallback source.
# ---------------------------------------------------------------------------
s = read(SERVER)
old_credential = r'''    public static function credential() {
        $credential = '';

        // wp-config.php / bootstrap constant has highest precedence.
        if ( defined( 'WPL_SERVER_API_KEY' ) && is_string( WPL_SERVER_API_KEY ) ) {
            $credential = trim( WPL_SERVER_API_KEY );
        }

        if ( $credential === '' ) {
            $env = getenv( 'WPL_SERVER_API_KEY' );
            if ( is_string( $env ) ) $credential = trim( $env );
        }

        if ( $credential === '' ) {
            $credential = self::option_credential();
        }

        if ( $credential === '' ) {
            $credential = self::package_credential();
        }

        /**
         * Allows a host/MU-plugin/secret manager to provide a site credential.
         * Do not log the value returned by this filter.
         */
        $credential = (string) apply_filters( 'wpl_client_server_credential', $credential );
        return self::valid_format( $credential ) ? trim( $credential ) : '';
    }
'''
new_credential = r'''    public static function credential() {
        $credential = '';

        // Resolve the first *valid* configured source. A malformed higher-priority
        // source must never shadow a valid fallback stored for this site.
        $constant = defined( 'WPL_SERVER_API_KEY' ) && is_string( WPL_SERVER_API_KEY )
            ? trim( WPL_SERVER_API_KEY )
            : '';
        if ( self::valid_format( $constant ) ) $credential = $constant;

        if ( $credential === '' ) {
            $env = getenv( 'WPL_SERVER_API_KEY' );
            if ( is_string( $env ) && self::valid_format( $env ) ) $credential = trim( $env );
        }

        if ( $credential === '' ) $credential = self::option_credential();
        if ( $credential === '' ) $credential = self::package_credential();

        /**
         * Allows a host/MU-plugin/secret manager to provide a site credential.
         * Do not log the value returned by this filter.
         */
        $filtered = (string) apply_filters( 'wpl_client_server_credential', $credential );
        return self::valid_format( $filtered ) ? trim( $filtered ) : '';
    }
'''
s = once(s, old_credential, new_credential, 'server credential resolver')

old_save = r'''    public static function save_credential( $credential ) {
        $probe = self::probe_credential( $credential );
        if ( empty( $probe['ok'] ) ) return $probe;

        update_option( self::CREDENTIAL_OPTION, trim( (string) $credential ), false );
        self::record_state( 'accepted', 200, 'site_option' );
        $probe['source'] = 'site_option';
        return $probe;
    }
'''
new_save = r'''    public static function save_credential( $credential ) {
        $credential = trim( (string) $credential );
        $probe = self::probe_credential( $credential );
        if ( empty( $probe['ok'] ) ) return $probe;

        // A valid wp-config constant is an explicit operator override. Do not
        // pretend a DB save can supersede it in the same or later requests.
        if ( defined( 'WPL_SERVER_API_KEY' ) && self::valid_format( (string) WPL_SERVER_API_KEY )
            && ! hash_equals( trim( (string) WPL_SERVER_API_KEY ), $credential ) ) {
            return [
                'ok'          => false,
                'auth_status' => 'config_override',
                'http_code'   => 409,
                'message'     => 'يوجد WPL_SERVER_API_KEY صالح ومختلف في wp-config.php؛ حدّثه أو أزله قبل حفظ بيانات مختلفة من لوحة التحكم.',
            ];
        }

        update_option( self::CREDENTIAL_OPTION, $credential, false );
        self::record_state( 'accepted', 200, 'site_option' );
        $probe['source'] = 'site_option';
        return $probe;
    }
'''
s = once(s, old_save, new_save, 'server credential save semantics')
write(SERVER, s)


# ---------------------------------------------------------------------------
# Installer: centralize every WPL server call through WPL_Server_Client.
# ---------------------------------------------------------------------------
i = read(INSTALLER)

# ajax_install_file entitlement reconciliation.
order_start = "            // تحقق من حالة الأوردر — لو ملغي امنع التثبيت وامسح البيانات المحلية\n"
order_end = "        $filename = sanitize_file_name( $_POST['filename'] ?? '' );\n"
order_block = r'''            // تحقق من حالة الأوردر — لو ملغي امنع التثبيت وامسح البيانات المحلية
            $order_number = get_option( 'wpl_verified_order_number' );
            if ( $order_number ) {
                $check = WPL_Server_Client::request( 'POST', '/verify-serial', [
                    'body' => [
                        'serial'       => get_user_meta( get_current_user_id(), '_wpl_serial_used', true ) ?: '',
                        'order_number' => $order_number,
                        'domain'       => parse_url( home_url(), PHP_URL_HOST ),
                        'register'     => false,
                    ],
                    'timeout' => 10,
                ] );

                if ( in_array( $check['auth_status'], [ 'missing', 'rejected' ], true ) ) {
                    wp_send_json_error( [
                        'message' => 'تعذر توثيق اتصال WPL. حدّث بيانات الاتصال أولاً.',
                        'credential_rejected' => true,
                    ], 401 );
                }
                if ( in_array( $check['auth_status'], [ 'network_error', 'server_error' ], true ) ) {
                    wp_send_json_error( [ 'message' => 'تعذّر التحقق من حالة الطلب حالياً؛ لم يتم بدء التثبيت.' ], 503 );
                }

                $check_body = is_array( $check['body'] ) ? $check['body'] : [];
                if ( isset( $check_body['success'] ) && ! $check_body['success'] ) {
                    delete_option( 'wpl_serial_verified' );
                    delete_option( 'wpl_verified_order_number' );
                    delete_option( 'wpl_has_pending_request' );
                    $msg = $check_body['message'] ?? 'عذراً، هذا السيريال أو الطلب لم يعد صالحاً — يرجى التواصل مع الدعم.';
                    wp_send_json_error( [ 'message' => $msg, 'cancelled' => true ] );
                }
            }
        }

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
'''
i = span(i, order_start, order_end, order_block, 'install entitlement reconciliation', keep_end=False)

# ajax_install_file signed URL.
dl_start = "        // جيب signed URL\n"
dl_end = "        // Upgrader downloads keep WordPress core TLS verification enabled.\n"
dl_block = r'''        // جيب signed URL عبر طبقة النقل الموحدة.
        $download = WPL_Server_Client::request( 'GET', '/download?file=' . rawurlencode( $filename ), [
            'timeout' => 15,
        ] );
        if ( in_array( $download['auth_status'], [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [ 'message' => 'تعذر توثيق اتصال WPL.', 'credential_rejected' => true ], 401 );
        }
        if ( in_array( $download['auth_status'], [ 'network_error', 'server_error' ], true ) ) {
            wp_send_json_error( 'تعذّر الاتصال بخادم WPL عبر اتصال TLS موثوق.' );
        }
        $body = is_array( $download['body'] ) ? $download['body'] : [];
        if ( empty( $body['success'] ) || empty( $body['download_url'] ) ) {
            wp_send_json_error( $body['message'] ?? 'لم يتم الحصول على رابط التحميل.' );
        }

        // Upgrader downloads keep WordPress core TLS verification enabled.
'''
i = span(i, dl_start, dl_end, dl_block, 'install signed URL', keep_end=False)

# Installed notification.
installed_old = r'''        wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/installed', [
            'headers'   => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode([
                'domain'       => $domain,
                'filename'     => $filename,
                'product_name' => $product_name,
            ]),
            'timeout'   => 8,
            'sslverify'   => true,
            'httpversion' => '1.1',
            'blocking'  => true,
        ]);
'''
installed_new = r'''        WPL_Server_Client::request( 'POST', '/installed', [
            'body' => [
                'domain'       => $domain,
                'filename'     => $filename,
                'product_name' => $product_name,
            ],
            'timeout' => 8,
        ] );
'''
i = once(i, installed_old, installed_new, 'installed notification')

# Manual download URL action.
manual_dl_start = "        $response = wp_remote_get( rtrim( WPL_SERVER_API_URL, '/' ) . '/download?file=' . urlencode( $filename ), [\n"
manual_dl_end = "        wp_send_json_success([ 'download_url' => $body['download_url'] ]);\n"
manual_dl = r'''        $download = WPL_Server_Client::request( 'GET', '/download?file=' . rawurlencode( $filename ), [
            'timeout' => 15,
        ] );
        if ( in_array( $download['auth_status'], [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [ 'message' => 'تعذر توثيق اتصال WPL.', 'credential_rejected' => true ], 401 );
        }
        if ( in_array( $download['auth_status'], [ 'network_error', 'server_error' ], true ) ) {
            wp_send_json_error( 'تعذّر الاتصال بخادم WPL عبر اتصال TLS موثوق.' );
        }
        $body = is_array( $download['body'] ) ? $download['body'] : [];
        if ( empty( $body['success'] ) || empty( $body['download_url'] ) ) {
            wp_send_json_error( $body['message'] ?? 'لم يتم الحصول على الرابط.' );
        }

        wp_send_json_success([ 'download_url' => $body['download_url'] ]);
'''
i = span(i, manual_dl_start, manual_dl_end, manual_dl, 'manual download URL', keep_end=False)

# Background products lookup.
bg_products_start = "        // جيب ملفات المنتجات من السيرفر\n"
bg_products_end = "        // ابنِ قائمة الملفات + حدّد المنتجات اللي مفيهاش ملفات\n"
bg_products = r'''        // جيب ملفات المنتجات من السيرفر عبر طبقة النقل الموحدة.
        $wpl_ids = implode( ',', array_filter( array_map( fn( $m ) => $m['wpl_id'] ?? '', $matched_products ) ) );
        $server  = WPL_Server_Client::request( 'GET', '/products?wpl_ids=' . rawurlencode( $wpl_ids ), [
            'timeout' => 30,
        ] );
        if ( empty( $server['ok'] ) ) {
            $job['status'] = 'error';
            $job['error']  = in_array( $server['auth_status'], [ 'missing', 'rejected' ], true )
                ? 'تعذر توثيق اتصال WPL.'
                : 'تعذّر جلب بيانات المنتجات من خادم WPL.';
            update_option( 'wpl_bg_install_job', $job, false );
            return;
        }
        $body     = is_array( $server['body'] ) ? $server['body'] : [];
        $products = $body['products'] ?? [];

        // ابنِ قائمة الملفات + حدّد المنتجات اللي مفيهاش ملفات
'''
i = span(i, bg_products_start, bg_products_end, bg_products, 'background products lookup', keep_end=False)

# Background per-file signed URL.
bg_dl_start = "            $dl_endpoint = rtrim(WPL_SERVER_API_URL, '/') . '/download?file=' . urlencode($filename);\n"
bg_dl_end = "            $signed_url = $dl_body['download_url'];\n"
bg_dl = r'''            $dl_path = '/download?file=' . rawurlencode( $filename );
            error_log( $log_file . ' | [1] DOWNLOAD_START — requesting signed URL from WPL' );

            $dl = WPL_Server_Client::request( 'GET', $dl_path, [ 'timeout' => 15 ] );
            if ( empty( $dl['ok'] ) ) {
                $err = in_array( $dl['auth_status'], [ 'missing', 'rejected' ], true )
                    ? 'تعذر توثيق اتصال WPL.'
                    : 'تعذّر جلب رابط التحميل.';
                error_log( $log_file . ' | [2] DOWNLOAD_FAIL — ' . $err );
                $fi['status'] = 'error'; $fi['error'] = $err;
                $job['files'][$idx] = $fi; update_option( 'wpl_bg_install_job', $job, false ); continue;
            }

            $dl_body = is_array( $dl['body'] ) ? $dl['body'] : [];
            if ( empty( $dl_body['success'] ) || empty( $dl_body['download_url'] ) ) {
                $api_msg = $dl_body['message'] ?? 'لم يُرجع الخادم رابط تحميل.';
                error_log( $log_file . ' | [2] DOWNLOAD_FAIL — server returned no download_url. Response: ' . $api_msg );
                $fi['status'] = 'error'; $fi['error'] = 'الملف غير موجود على السيرفر: ' . $api_msg;
                $job['files'][$idx] = $fi; update_option( 'wpl_bg_install_job', $job, false ); continue;
            }

            $signed_url = $dl_body['download_url'];
'''
i = span(i, bg_dl_start, bg_dl_end, bg_dl, 'background signed URL', keep_end=False)

# Background completion notification.
mark_bg_start = "            wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/mark-installed', [\n"
mark_bg_end = "            delete_option( 'wpl_has_pending_request' );\n"
mark_bg = r'''            $mark = WPL_Server_Client::request( 'POST', '/mark-installed', [
                'body' => [
                    'order_number'           => $order_number,
                    'domain'                 => $domain,
                    'done_count'             => $main_done_count,
                    'error_count'            => $main_error_count,
                    'failed_files'           => $failed_files,
                    'failed_companion_files' => $failed_companion_files,
                ],
                'timeout' => 10,
            ] );
            if ( ! empty( $mark['ok'] ) ) {
                delete_option( 'wpl_has_pending_request' );
            }
'''
i = span(i, mark_bg_start, mark_bg_end, mark_bg, 'background mark installed', keep_end=False)

# Requests list.
requests_start = "        $domain = parse_url( home_url(), PHP_URL_HOST );\n        $url    = rtrim( WPL_SERVER_API_URL, '/' ) . '/my-requests?domain=' . urlencode( $domain );\n\n        $response = wp_remote_get( $url, [\n"
requests_end = "        $requests = $body['requests'] ?? [];\n"
requests = r'''        $domain = parse_url( home_url(), PHP_URL_HOST );
        $server = WPL_Server_Client::request( 'GET', '/my-requests?domain=' . rawurlencode( $domain ), [
            'timeout' => 15,
        ] );
        if ( in_array( $server['auth_status'], [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [ 'message' => 'تعذر توثيق اتصال WPL.', 'credential_rejected' => true ], 401 );
        }
        if ( empty( $server['ok'] ) ) wp_send_json_error( 'تعذّر الاتصال بخادم WPL.' );

        $body     = is_array( $server['body'] ) ? $server['body'] : [];
        $requests = $body['requests'] ?? [];
'''
i = span(i, requests_start, requests_end, requests, 'fetch my requests', keep_end=False)

# Explicit mark-installed action.
mark_manual_start = "        $response = wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/mark-installed', [\n"
mark_manual_end = "        delete_option( 'wpl_has_pending_request' );\n        wp_send_json_success();\n"
mark_manual = r'''        $server = WPL_Server_Client::request( 'POST', '/mark-installed', [
            'body'    => [ 'order_number' => $order_number, 'domain' => $domain ],
            'timeout' => 10,
        ] );
        if ( in_array( $server['auth_status'], [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [ 'message' => 'تعذر توثيق اتصال WPL.', 'credential_rejected' => true ], 401 );
        }
        if ( empty( $server['ok'] ) ) wp_send_json_error( 'تعذّر تحديث حالة الطلب على خادم WPL.' );

        delete_option( 'wpl_has_pending_request' );
        wp_send_json_success();
'''
i = span(i, mark_manual_start, mark_manual_end, mark_manual, 'manual mark installed', keep_end=False)
write(INSTALLER, i)


# ---------------------------------------------------------------------------
# Contract: all WPL API auth traffic must go through the transport manager.
# ---------------------------------------------------------------------------
c = read(CONTRACT)
anchor = "          grep -q \"WPL_Server_Client::request\" wp-content/plugins/wpl-client/includes/class-wpl-installer.php\n"
extra = anchor + (
    "          if grep -R -n --include='*.php' 'WPL_SERVER_API_KEY' wp-content/plugins/wpl-client | grep -v 'class-wpl-server-client.php'; then echo 'Legacy credential callsite detected' >&2; exit 1; fi\n"
    "          if grep -R -n --include='*.php' 'WPL_SERVER_API_URL' wp-content/plugins/wpl-client | grep -Ev 'class-wpl-server-client.php|wpl-client.php'; then echo 'Direct WPL endpoint callsite detected' >&2; exit 1; fi\n"
)
if 'Legacy credential callsite detected' not in c:
    c = once(c, anchor, extra, 'transport contract anchor')
write(CONTRACT, c)

print('WPL transport finalization applied')
