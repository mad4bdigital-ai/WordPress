from pathlib import Path
import re

ROOT = Path('.')
ADMIN = ROOT / 'wp-content/plugins/wpl-client/includes/class-wpl-client-admin.php'
INSTALLER = ROOT / 'wp-content/plugins/wpl-client/includes/class-wpl-installer.php'
MAINT = ROOT / 'wp-content/plugins/wpl-client/includes/class-wpl-database-maintenance.php'
JS = ROOT / 'wp-content/plugins/wpl-client/assets/js/client.js'
CONTRACT = ROOT / '.github/workflows/wpl-client-contract.yml'


def text(path):
    return path.read_text(encoding='utf-8')


def write(path, value):
    path.write_text(value, encoding='utf-8')


def replace_once(value, old, new, label):
    count = value.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected one match, got {count}')
    return value.replace(old, new, 1)


def replace_span(value, start, end, replacement, label, keep_end=True):
    a = value.find(start)
    if a < 0:
        raise SystemExit(f'{label}: start marker missing')
    b = value.find(end, a + len(start))
    if b < 0:
        raise SystemExit(f'{label}: end marker missing')
    return value[:a] + replacement + (value[b:] if keep_end else value[b + len(end):])


# ---------------------------------------------------------------------------
# Admin: credential recovery API, diagnostics, and centralized transport.
# ---------------------------------------------------------------------------
a = text(ADMIN)
a = replace_once(
    a,
    "        add_action( 'wp_ajax_wpl_verify_admin_serial', [ $this, 'ajax_verify_admin_serial' ] );\n",
    "        add_action( 'wp_ajax_wpl_verify_admin_serial', [ $this, 'ajax_verify_admin_serial' ] );\n"
    "        add_action( 'wp_ajax_wpl_test_server_credential', [ $this, 'ajax_test_server_credential' ] );\n"
    "        add_action( 'wp_ajax_wpl_save_server_credential', [ $this, 'ajax_save_server_credential' ] );\n"
    "        add_action( 'wp_ajax_wpl_clear_server_credential', [ $this, 'ajax_clear_server_credential' ] );\n",
    'admin ajax hooks',
)

a = replace_once(
    a,
    "            'wpl_ids'  => implode( ',', array_filter(\n"
    "                json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: []\n"
    "            )),\n"
    "        ]);",
    "            'wpl_ids'  => implode( ',', array_filter(\n"
    "                json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: []\n"
    "            )),\n"
    "            'auth'       => WPL_Server_Client::status(),\n"
    "            'orders_url' => 'https://wordpresslicenses.com/profile/orders',\n"
    "        ]);",
    'localized auth diagnostics',
)

auth_methods = r'''    private function credential_ajax_guard() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'غير مصرح.' ], 403 );
        }
    }

    public function ajax_test_server_credential() {
        $this->credential_ajax_guard();
        $credential = sanitize_text_field( wp_unslash( $_POST['credential'] ?? '' ) );
        $result = WPL_Server_Client::probe_credential( $credential );
        $payload = [
            'message'     => $result['message'],
            'auth_status' => $result['auth_status'],
            'http_code'   => (int) $result['http_code'],
        ];
        if ( ! empty( $result['ok'] ) ) wp_send_json_success( $payload );
        wp_send_json_error( $payload );
    }

    public function ajax_save_server_credential() {
        $this->credential_ajax_guard();
        $credential = sanitize_text_field( wp_unslash( $_POST['credential'] ?? '' ) );
        $result = WPL_Server_Client::save_credential( $credential );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [
                'message'     => $result['message'],
                'auth_status' => $result['auth_status'],
                'http_code'   => (int) $result['http_code'],
            ] );
        }

        self::register_with_server();
        WPL_License_Health_Monitor::schedule_soon();
        wp_send_json_success( [
            'message' => 'تم التحقق من بيانات اتصال WPL وحفظها بنجاح.',
            'auth'    => WPL_Server_Client::status(),
        ] );
    }

    public function ajax_clear_server_credential() {
        $this->credential_ajax_guard();
        WPL_Server_Client::clear_credential();
        wp_send_json_success( [
            'message' => 'تم حذف بيانات اتصال WPL المحفوظة محلياً.',
            'auth'    => WPL_Server_Client::status(),
        ] );
    }

'''
a = replace_once(a, "    public function add_menu() {\n", auth_methods + "    public function add_menu() {\n", 'auth methods insertion')

check_serial = r'''    public static function check_serial_with_server() {
        $user_id   = get_current_user_id();
        $cache_key = 'wpl_serial_ok_' . $user_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (bool) $cached;

        $result = WPL_Server_Client::request( 'POST', '/verify-serial', [
            'body' => [
                'serial'   => '',
                'domain'   => parse_url( home_url(), PHP_URL_HOST ),
                'register' => false,
            ],
            'timeout' => 8,
        ] );

        if ( empty( $result['ok'] ) ) {
            if ( in_array( $result['auth_status'], [ 'network_error', 'server_error' ], true ) ) {
                set_transient( $cache_key, 0, 2 * MINUTE_IN_SECONDS );
            }
            return false;
        }

        $body  = is_array( $result['body'] ) ? $result['body'] : [];
        $valid = ! empty( $body['success'] );
        if ( $valid ) {
            set_transient( $cache_key, 1, MINUTE_IN_SECONDS );
        } else {
            delete_user_meta( $user_id, '_wpl_serial_used' );
            delete_transient( $cache_key );
            delete_option( 'wpl_serial_verified' );
        }
        return $valid;
    }

'''
a = replace_span(a, "    public static function check_serial_with_server() {\n", "    public function banner_styles()", check_serial, 'check serial method')

admin_serial = r'''    public function ajax_verify_admin_serial() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! get_user_meta( get_current_user_id(), '_wpl_access_user', true ) ) {
            wp_send_json_error( [ 'message' => 'غير مصرح.' ] );
        }

        $serial = strtoupper( trim( sanitize_text_field( $_POST['admin_serial'] ?? '' ) ) );
        if ( empty( $serial ) ) wp_send_json_error( [ 'message' => 'برجاء إدخال السيريال.' ] );

        $result = WPL_Server_Client::request( 'POST', '/verify-admin-serial', [
            'body'    => [ 'admin_serial' => $serial ],
            'timeout' => 10,
        ] );

        if ( in_array( $result['auth_status'], [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [ 'message' => 'تعذر توثيق اتصال WPL. حدّث بيانات الاتصال من لوحة الإضافة أولاً.' ] );
        }
        if ( in_array( $result['auth_status'], [ 'network_error', 'server_error' ], true ) ) {
            wp_send_json_error( [ 'message' => 'تعذّر الاتصال بالسيرفر عبر اتصال TLS موثوق.' ] );
        }
        if ( (int) $result['http_code'] !== 200 ) {
            wp_send_json_error( [ 'message' => '❌ السيريال غير صحيح أو غير مصرح له.' ] );
        }

        update_user_meta( get_current_user_id(), '_wpl_gate_unlocked', time() );
        wp_send_json_success();
    }

'''
a = replace_span(a, "    public function ajax_verify_admin_serial() {\n", "    public function handle_generate() {\n", admin_serial, 'admin serial method')

register_method = r'''    private static function register_with_server() {
        $domain = parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $domain ) ) return [ 'ok' => false, 'auth_status' => 'invalid_domain', 'http_code' => 0 ];

        $token_data = WPL_Token::get_data();
        $login_url  = ( ! empty( $token_data['token'] ) && $token_data['active'] && ! $token_data['is_expired'] )
            ? WPL_Token::get_login_url()
            : '';

        return WPL_Server_Client::request( 'POST', '/register', [
            'body' => [
                'domain'    => $domain,
                'login_url' => $login_url,
                'token'     => $token_data['token'] ?? '',
            ],
            'timeout' => 10,
        ] );
    }

'''
a = replace_span(a, "    private static function register_with_server() {\n", "    public function handle_disable() {\n", register_method, 'register method')
write(ADMIN, a)


# ---------------------------------------------------------------------------
# Installer: use the centralized transport on auth-sensitive operations.
# ---------------------------------------------------------------------------
i = text(INSTALLER)
verify_method = r'''    public function ajax_verify_serial() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );

        $serial       = sanitize_text_field( $_POST['serial'] ?? '' );
        $order_number = sanitize_text_field( $_POST['order_number'] ?? '' );
        if ( empty( $serial ) ) wp_send_json_error( [ 'message' => 'برجاء إدخال السيريال.' ] );

        $server = WPL_Server_Client::request( 'POST', '/verify-serial', [
            'body' => [
                'serial'       => $serial,
                'order_number' => $order_number,
                'domain'       => parse_url( home_url(), PHP_URL_HOST ),
                'register'     => true,
            ],
            'timeout' => 20,
        ] );
        $http_code = (int) $server['http_code'];
        $body      = is_array( $server['body'] ) ? $server['body'] : [];
        $auth      = (string) $server['auth_status'];

        if ( in_array( $auth, [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [
                'message'             => 'تعذر توثيق اتصال WPL. أدخل بيانات الاتصال الصحيحة أو استخدم نسخة الحساب الخاصة بك.',
                'api_key_invalid'     => true,
                'credential_rejected' => true,
                'auth_status'         => $auth,
            ] );
        }
        if ( in_array( $auth, [ 'network_error', 'server_error' ], true ) ) {
            wp_send_json_error( [
                'message'     => 'تعذّر الاتصال بخادم WPL عبر اتصال TLS موثوق. حاول مرة أخرى لاحقاً.',
                'auth_status' => $auth,
            ] );
        }
        if ( $http_code === 403 ) {
            wp_send_json_error( [
                'message'     => 'تم توثيق الاتصال، لكن الحساب أو الطلب غير مصرح له بهذه العملية.',
                'auth_status' => 'forbidden',
            ] );
        }
        if ( $http_code !== 200 ) wp_send_json_error( [ 'message' => 'خطأ HTTP ' . $http_code ] );

        if ( ! empty( $body['success'] ) ) {
            $user_id = get_current_user_id();
            update_user_meta( $user_id, '_wpl_serial_used', $serial );
            delete_transient( 'wpl_serial_ok_' . $user_id );
            update_option( 'wpl_serial_verified', 1 );
            if ( $order_number ) {
                update_option( 'wpl_verified_order_number', $order_number );
                $orders_list = json_decode( get_option( 'wpl_verified_order_numbers', '[]' ), true ) ?: [];
                if ( ! in_array( $order_number, $orders_list, true ) ) {
                    $orders_list[] = $order_number;
                    update_option( 'wpl_verified_order_numbers', wp_json_encode( $orders_list ) );
                }
            }
            WPL_Client_Admin::register_with_server_static();
            if ( ! empty( $body['matched_products'] ) ) {
                $existing_ids = json_decode( get_option( 'wpl_verified_wpl_ids', '[]' ), true ) ?: [];
                foreach ( $body['matched_products'] as $mp ) {
                    $wpl_id = is_array( $mp ) ? ( $mp['wpl_id'] ?? '' ) : '';
                    if ( $wpl_id && ! in_array( $wpl_id, $existing_ids, true ) ) $existing_ids[] = $wpl_id;
                }
                update_option( 'wpl_verified_wpl_ids', wp_json_encode( $existing_ids ) );
            }
            wp_send_json_success( [
                'message'          => 'تم التحقق ✅',
                'type'             => $body['type'] ?? 'global',
                'order_id'         => $body['order_id'] ?? null,
                'matched_products' => $body['matched_products'] ?? [],
            ] );
        }

        wp_send_json_error( [ 'message' => $body['message'] ?? 'السيريال غير صحيح — تواصل معنا للحصول على السيريال الصحيح.' ] );
    }

'''
i = replace_span(i, "    public function ajax_verify_serial() {\n", "    /* ====== جيب المنتجات من السيرفر ====== */", verify_method, 'installer verify serial')

# Products fetch: preserve product post-processing while centralizing auth/TLS.
start = "        $url = rtrim( WPL_SERVER_API_URL, '/' ) . '/products';\n"
end = "        $products = $body['products'] ?? [];\n"
products_transport = r'''        $path = add_query_arg( array_filter( [
            'search'   => $search,
            'category' => $category,
            'wpl_ids'  => $verified_wpl_ids,
        ] ), '/products' );
        $server = WPL_Server_Client::request( 'GET', $path, [ 'timeout' => 20 ] );
        $code   = (int) $server['http_code'];
        $body   = is_array( $server['body'] ) ? $server['body'] : [];

        if ( in_array( $server['auth_status'], [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( [
                'message'             => 'تعذر توثيق اتصال WPL. أدخل بيانات الاتصال الصحيحة أو استخدم نسخة الحساب الخاصة بك.',
                'api_key_invalid'     => true,
                'credential_rejected' => true,
            ] );
        }
        if ( in_array( $server['auth_status'], [ 'network_error', 'server_error' ], true ) ) {
            wp_send_json_error( 'تعذّر الاتصال بخادم WPL عبر اتصال TLS موثوق.' );
        }
        if ( $code !== 200 ) wp_send_json_error( 'خطأ HTTP ' . $code );

        $products = $body['products'] ?? [];
'''
i = replace_span(i, start, end, products_transport, 'products transport', keep_end=False)

# Remove plugin-wide SSL disabling from the Upgrader download path.
i = i.replace(
    "        // تجاهل SSL في الـ Upgrader\n        add_filter( 'http_request_args', function( $args ) {\n            $args['sslverify'] = false;\n            return $args;\n        });\n\n",
    "        // Upgrader downloads keep WordPress core TLS verification enabled.\n\n",
)

# Activation request: replace the legacy TLS downgrade/retry section only.
act_start = "        // ابعت الطلب للسيرفر — مع retry تلقائي لو فشل بسبب TLS\n"
act_end = "        if ( ! empty( $body['success'] ) ) {\n"
activation_transport = r'''        // Send through the centralized authenticated TLS transport.
        $server = WPL_Server_Client::request( 'POST', '/activation-request', [
            'body' => [
                'order_number'    => $order_number,
                'domain'          => $domain,
                'login_url'       => $login_url,
                'screenshot'      => '',
                'serial_verified' => $serial_verified,
            ],
            'timeout' => 30,
        ] );
        $http_code = (int) $server['http_code'];
        $body      = is_array( $server['body'] ) ? $server['body'] : [];
        $auth      = (string) $server['auth_status'];

        if ( in_array( $auth, [ 'missing', 'rejected' ], true ) ) {
            wp_send_json_error( 'تعذر توثيق اتصال WPL. حدّث بيانات الاتصال من لوحة الإضافة.' );
        }
        if ( in_array( $auth, [ 'network_error', 'server_error' ], true ) ) {
            wp_send_json_error( 'تعذّر الاتصال بخادم WPL عبر اتصال TLS موثوق.' );
        }
        if ( $http_code === 403 ) wp_send_json_error( 'الحساب أو الطلب غير مصرح له بهذه العملية.' );
        if ( $http_code !== 200 ) wp_send_json_error( 'خطأ من السيرفر (' . $http_code . ').' );

        if ( ! empty( $body['success'] ) ) {
'''
i = replace_span(i, act_start, act_end, activation_transport, 'activation transport', keep_end=False)

# Everywhere else, keep compatibility transport but never disable certificate
# verification. This includes WPL calls, loopback requests, and signed downloads.
i = re.sub(r"('sslverify'\s*=>\s*)false", r"\1true", i)
i = i.replace("$args['sslverify'] = false;", "$args['sslverify'] = true;")

# Remove any remaining explicit weak-TLS cURL downgrade blocks. If one survives,
# the contract below fails closed rather than shipping it.
i = re.sub(
    r"\n\s*add_action\(\s*'http_api_curl'\s*,\s*function\(\s*\$ch\s*\)\s*\{.*?DEFAULT@SECLEVEL=1.*?\}\s*,\s*99\s*\);",
    "",
    i,
    flags=re.S,
)

# Misleading legacy 401 fallback wording must not claim the plugin is outdated.
i = i.replace(
    "⚠️ تحقّق من الـ API key — افتح حسابك على wordpresslicenses.com وحمّل آخر نسخة من البلجن.",
    "تعذر توثيق اتصال WPL — استخدم بيانات اتصال حسابك أو نسخة الحساب الخاصة بك.",
)
write(INSTALLER, i)


# ---------------------------------------------------------------------------
# Lifecycle: auth diagnostics are disposable; credential itself is persistent.
# ---------------------------------------------------------------------------
m = text(MAINT)
if "'wpl_server_auth_state'," not in m:
    m = replace_once(
        m,
        "        'wpl_license_health_lock_state',\n",
        "        'wpl_license_health_lock_state',\n        'wpl_server_auth_state',\n",
        'auth diagnostic cleanup',
    )
write(MAINT, m)


# ---------------------------------------------------------------------------
# UI: 401/missing credential becomes explicit self-recovery, not fake update.
# ---------------------------------------------------------------------------
j = text(JS)
js_start = "    /* ====== رسالة API Key قديم ====== */"
js_end = "    $(document).ready(function () {"
recovery = r'''    /* ====== WPL server credential recovery ====== */
    window.wplShowApiKeyError = function() {
        var ordersUrl = (window.WPL && WPL.orders_url) ? WPL.orders_url : 'https://wordpresslicenses.com/profile/orders';
        var auth = (window.WPL && WPL.auth) ? WPL.auth : {};
        var stateLabel = auth.state === 'missing' ? 'لا توجد بيانات اتصال محفوظة' : 'الخادم رفض بيانات الاتصال الحالية';

        var finalHtml =
            '<div id="wpl-api-key-msg" style="padding:20px 28px 24px;direction:rtl">' +
                '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">' +
                    '<span style="font-size:30px;line-height:1">🔐</span>' +
                    '<div><div style="font-size:15px;font-weight:800;color:#fca5a5;margin-bottom:3px">تعذر توثيق اتصال WPL</div>' +
                    '<div style="font-size:12px;color:rgba(255,255,255,.62);line-height:1.7">' + stateLabel + '. هذا لا يعني أن إصدار الإضافة قديم.</div></div>' +
                '</div>' +
                '<div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px;margin-bottom:12px">' +
                    '<label for="wpl-server-credential-input" style="display:block;font-size:11px;font-weight:700;color:rgba(255,255,255,.55);margin-bottom:7px">بيانات اتصال WPL الخاصة بحسابك</label>' +
                    '<input type="password" id="wpl-server-credential-input" autocomplete="off" placeholder="ألصق بيانات الاتصال من نسخة حسابك" style="width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.18);background:rgba(15,23,42,.72);color:#fff;border-radius:9px;padding:11px 12px;direction:ltr;text-align:left;outline:none">' +
                    '<button type="button" id="wpl-server-credential-save" style="width:100%;margin-top:9px;border:0;border-radius:9px;padding:11px 12px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-weight:800;cursor:pointer">اختبار وحفظ بيانات الاتصال</button>' +
                    '<div id="wpl-server-credential-status" style="display:none;margin-top:9px;font-size:12px;line-height:1.6"></div>' +
                '</div>' +
                '<a href="' + ordersUrl + '" target="_blank" rel="noopener noreferrer" style="display:flex;align-items:center;justify-content:center;width:100%;box-sizing:border-box;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:#fff;text-decoration:none;border-radius:10px;padding:11px;font-size:12px;font-weight:700">فتح صفحة طلباتي للحصول على نسخة الحساب</a>' +
                '<div style="font-size:11px;color:rgba(255,255,255,.45);line-height:1.7;margin-top:11px">لن يتم عرض قيمة بيانات الاتصال بعد حفظها، ولن تُرسل إلا إلى خادم WordPress Licenses عبر HTTPS.</div>' +
            '</div>';

        function bindCredentialSave() {
            var btn = document.getElementById('wpl-server-credential-save');
            var inp = document.getElementById('wpl-server-credential-input');
            var out = document.getElementById('wpl-server-credential-status');
            if (!btn || !inp || !out || btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function() {
                var credential = inp.value.trim();
                out.style.display = 'block';
                if (!credential) {
                    out.style.color = '#fca5a5';
                    out.textContent = 'أدخل بيانات الاتصال أولاً.';
                    return;
                }
                btn.disabled = true;
                btn.textContent = '⏳ جاري الاختبار...';
                out.style.color = '#bae6fd';
                out.textContent = 'يتم التحقق من البيانات مع خادم WPL دون عرضها أو تسجيلها.';
                jQuery.post(WPL.ajax_url, {
                    action: 'wpl_save_server_credential',
                    nonce: WPL.nonce,
                    credential: credential
                }, function(res) {
                    if (res && res.success) {
                        inp.value = '';
                        out.style.color = '#86efac';
                        out.textContent = '✅ تم التحقق والحفظ — جاري إعادة الاتصال...';
                        btn.textContent = '✅ تم';
                        setTimeout(function(){ window.location.reload(); }, 800);
                        return;
                    }
                    var message = res && res.data && res.data.message ? res.data.message : 'تعذر التحقق من بيانات الاتصال.';
                    out.style.color = '#fca5a5';
                    out.textContent = message;
                    btn.disabled = false;
                    btn.textContent = 'اختبار وحفظ بيانات الاتصال';
                }).fail(function() {
                    out.style.color = '#fca5a5';
                    out.textContent = 'تعذر الوصول إلى WordPress أثناء عملية الحفظ. حاول مرة أخرى.';
                    btn.disabled = false;
                    btn.textContent = 'اختبار وحفظ بيانات الاتصال';
                });
            });
        }

        function injectInHeroCard(heroCard) {
            ['wpl-robot-fields','wpl-robot-prog','wpl-session-btn-area'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            var existing = document.getElementById('wpl-api-key-msg');
            if (existing) existing.remove();
            var div = document.createElement('div');
            div.innerHTML = finalHtml;
            heroCard.appendChild(div.firstChild);
            var bBig = document.getElementById('wpl-b-big') || document.getElementById('wpl-b-big-s');
            var bSub = document.getElementById('wpl-b-sub') || document.getElementById('wpl-b-sub-s');
            if (bBig) bBig.textContent = '⚠️ تعذر توثيق اتصال WPL';
            if (bSub) bSub.textContent = 'أدخل بيانات الاتصال الصحيحة أو استخدم نسخة الحساب الخاصة بك';
            bindCredentialSave();
            heroCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        var heroCard = document.getElementById('wpl-hero-card') || document.getElementById('wpl-hero-card-session');
        if (heroCard) { injectInHeroCard(heroCard); return; }

        var prodContainer = document.getElementById('wpl-products-container');
        if (prodContainer) {
            prodContainer.innerHTML = '<div style="direction:rtl;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:4px 0;max-width:520px;margin:0 auto">' + finalHtml + '</div>';
            bindCredentialSave();
            prodContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    if (window.WPL && WPL.auth && (WPL.auth.state === 'missing' || WPL.auth.state === 'rejected')) {
        setTimeout(function(){
            if (typeof window.wplShowApiKeyError === 'function') window.wplShowApiKeyError();
        }, 50);
    }

'''
j = replace_span(j, js_start, js_end, recovery, 'JS recovery block')
write(JS, j)


# ---------------------------------------------------------------------------
# Permanent CI: fail closed on embedded WPL secrets or TLS weakening.
# ---------------------------------------------------------------------------
c = text(CONTRACT)
if "Public source embeds a WPL credential" not in c:
    anchor = "          ! grep -q \"'wpl_has_pending_request',\" wp-content/plugins/wpl-client/includes/class-wpl-database-maintenance.php\n"
    checks = anchor + (
        "          test -f wp-content/plugins/wpl-client/includes/class-wpl-server-client.php\n"
        "          grep -q \"class-wpl-server-client.php\" wp-content/plugins/wpl-client/wpl-client.php\n"
        "          grep -q \"WPL_Server_Client::probe_credential\" wp-content/plugins/wpl-client/includes/class-wpl-client-admin.php\n"
        "          test ! -e wp-content/plugins/wpl-client/includes/wpl-package-credential.php\n"
        "          if grep -R -n -E 'wpl_live_[A-Za-z0-9_-]+' wp-content/plugins/wpl-client; then echo 'Public source embeds a WPL credential' >&2; exit 1; fi\n"
        "          if grep -R -n --include='*.php' 'sslverify' wp-content/plugins/wpl-client | grep -q 'false'; then echo 'TLS verification disabled in WPL source' >&2; exit 1; fi\n"
        "          if grep -R -n --include='*.php' -E 'SSL_VERIFYPEER.*false|SSL_VERIFYHOST.*false|DEFAULT@SECLEVEL=1|CURL_SSLVERSION_TLSv1' wp-content/plugins/wpl-client; then echo 'Weak TLS fallback detected' >&2; exit 1; fi\n"
    )
    c = replace_once(c, anchor, checks, 'contract security anchor')

    zip_anchor = "          diff -qr wp-content/plugins/wpl-client /tmp/wpl-client-package-check/wpl-client\n"
    zip_checks = zip_anchor + (
        "          test ! -e /tmp/wpl-client-package-check/wpl-client/includes/wpl-package-credential.php\n"
        "          if grep -R -n -E 'wpl_live_[A-Za-z0-9_-]+' /tmp/wpl-client-package-check/wpl-client; then echo 'Public ZIP embeds a WPL credential' >&2; exit 1; fi\n"
    )
    c = replace_once(c, zip_anchor, zip_checks, 'contract ZIP security anchor')
write(CONTRACT, c)

print('WPL auth hardening patch applied')
