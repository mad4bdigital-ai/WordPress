<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPL_Client_Admin {

    public function __construct() {
        add_action( 'init',              [ $this, 'handle_token_login'   ] );
        add_action( 'init',              [ $this, 'check_wpl_session'    ] );
        add_action( 'admin_menu',        [ $this, 'add_menu'             ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'   ] );
        add_action( 'wp_ajax_wpl_mark_tutorial_seen', function() {
            update_user_meta( get_current_user_id(), '_wpl_tutorial_seen', 1 );
            wp_send_json_success();
        });
        add_action( 'wp_ajax_wpl_dismiss_hint', function() {
            update_user_meta( get_current_user_id(), '_wpl_hint_dismissed', 1 );
            wp_send_json_success();
        });
        add_action( 'admin_notices', [ $this, 'render_admin_banners' ] );
        add_action( 'admin_head',    [ $this, 'banner_styles' ] );
        add_action( 'admin_footer',  [ $this, 'banner_js_inject' ] );
        add_action( 'wp_ajax_wpl_dismiss_banner', function() {
            update_user_meta( get_current_user_id(), '_wpl_banner_dismissed', 1 );
            wp_send_json_success();
        });
        add_action( 'wp_ajax_wpl_verify_admin_serial', [ $this, 'ajax_verify_admin_serial' ] );
        add_action( 'wp_ajax_wpl_test_server_credential', [ $this, 'ajax_test_server_credential' ] );
        add_action( 'wp_ajax_wpl_save_server_credential', [ $this, 'ajax_save_server_credential' ] );
        add_action( 'wp_ajax_wpl_clear_server_credential', [ $this, 'ajax_clear_server_credential' ] );
        add_action( 'admin_post_wpl_generate_token', [ $this, 'handle_generate' ] );
        add_action( 'admin_post_wpl_disable_token',  [ $this, 'handle_disable'  ] );

        // مسح الكاش
        add_action( 'wp_ajax_wpl_clear_cache', function() {
            check_ajax_referer( 'wpl_client_nonce', 'nonce' );
            // نظّف runtime DB state والـ transients العالقة بدون مسح مفاتيح الترخيص.
            WPL_Database_Maintenance::clear_runtime_state();
            wp_send_json_success( 'تم تنظيف حالة الإضافة والكاش.' );
        } );
    }

    public function check_wpl_session() {
        if ( ! is_user_logged_in() ) return;
        $user_id = get_current_user_id();
        if ( get_user_meta( $user_id, '_wpl_access_user', true ) !== '1' ) return;
        $data = WPL_Token::get_data();
        if ( ! $data['active'] || $data['is_expired'] ) {
            delete_user_meta( $user_id, '_wpl_gate_unlocked' );
            wp_logout();
            wp_safe_redirect( wp_login_url() );
            exit;
        }
    }

    public function handle_token_login() {
        if ( empty( $_GET['wpl_token'] ) ) return;
        $token = sanitize_text_field( $_GET['wpl_token'] );
        if ( ! WPL_Token::validate( $token ) )
            wp_die( '❌ الرابط غير صحيح أو منتهي الصلاحية.', 'تراخيص ووردبريس', [ 'response' => 403 ] );
        $wpl_user_id = self::get_wpl_user_id();
        if ( ! $wpl_user_id ) {
            // اليوزر اتحذف أو مش موجود — أعد إنشاءه تلقائياً
            $wpl_user_id = self::create_wpl_user();
        }
        if ( ! $wpl_user_id )
            wp_die( 'خطأ: تعذّر إنشاء مستخدم الدخول — برجاء التواصل مع wordpresslicenses.com', 'تراخيص ووردبريس', [ 'response' => 500 ] );
        if ( is_user_logged_in() && get_current_user_id() !== $wpl_user_id ) wp_logout();
        if ( ! is_user_logged_in() ) {
            wp_set_current_user( $wpl_user_id );
            wp_set_auth_cookie( $wpl_user_id );
        }
        WPL_Token::record_usage();
        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    }

    public static function get_wpl_user_id() {
        $users = get_users([ 'meta_key' => '_wpl_access_user', 'meta_value' => '1', 'number' => 1 ]);
        return ! empty( $users ) ? $users[0]->ID : 0;
    }

    public static function delete_wpl_user() {
        $user_id = self::get_wpl_user_id();
        if ( ! $user_id ) return;
        $sessions = WP_Session_Tokens::get_instance( $user_id );
        $sessions->destroy_all();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $admins   = get_users([ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'exclude' => [ $user_id ] ]);
        $reassign = ! empty( $admins ) ? $admins[0]->ID : null;
        wp_delete_user( $user_id, $reassign );
    }

    public static function create_wpl_user() {
        self::delete_wpl_user();
        $password = wp_generate_password( 32 );
        $username = 'wp-licenses-' . rand(10, 99);
        while ( username_exists( $username ) ) $username = 'wp-licenses-' . rand(10, 99);
        $user_id = wp_create_user( $username, $password, 'support@wordpresslicenses.com' );
        if ( is_wp_error( $user_id ) ) return 0;
        $user = new WP_User( $user_id );
        $user->set_role( 'administrator' );
        update_user_meta( $user_id, '_wpl_access_user', '1' );
        wp_update_user([ 'ID' => $user_id, 'display_name' => 'WordPress Licenses',
            'first_name' => 'WordPress', 'last_name' => 'Licenses',
            'description' => 'حساب دعم فني مؤقت — تراخيص ووردبريس' ]);
        return $user_id;
    }

    private function credential_ajax_guard() {
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

    public function add_menu() {
        add_menu_page( 'تراخيص ووردبريس', 'تراخيص ووردبريس', 'manage_options', 'wpl-client',
            [ $this, 'render_page' ], 'dashicons-admin-plugins', 25 );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_wpl-client' ) return;
        wp_enqueue_style(  'wpl-client-css', WPL_CLIENT_URL . 'assets/css/client.css', [], WPL_CLIENT_VERSION );
        wp_enqueue_script( 'wpl-client-js',  WPL_CLIENT_URL . 'assets/js/client.js',  [ 'jquery' ], WPL_CLIENT_VERSION, true );
        wp_localize_script( 'wpl-client-js', 'WPL', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpl_client_nonce' ),
            'wpl_ids'  => implode( ',', array_filter(
                json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: []
            )),
            'auth'       => WPL_Server_Client::status(),
            'orders_url' => 'https://wordpresslicenses.com/profile/orders',
        ]);
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $token_data     = WPL_Token::get_data();
        $login_url      = WPL_Token::get_login_url();
        $has_token      = ! empty( $token_data['token'] );
        $is_wpl_session = ! empty( get_user_meta( get_current_user_id(), '_wpl_access_user', true ) );

        // ====== Gate check لمستخدمي الرابط المؤقت ======
        if ( $is_wpl_session ) {
            $gate_ts       = (int) get_user_meta( get_current_user_id(), '_wpl_gate_unlocked', true );
            $gate_unlocked = $gate_ts && ( time() - $gate_ts ) < 2 * HOUR_IN_SECONDS;
            if ( ! $gate_unlocked ) {
                $this->render_gate_page( $token_data, $login_url, $has_token );
                return;
            }
        }
        if ( $is_wpl_session ) {
            // لما الـ gate مفتوح بالسيريال الدائم → verified تلقائياً
            $verified = true;
        } else {
            // لو في طلب pending أو order serial متحقق → verified بدون server check
            if ( get_option('wpl_has_pending_request') || get_option('wpl_verified_order_number') ) {
                $verified = true;
            } else {
                $verified = get_option('wpl_serial_verified') && self::check_serial_with_server();
            }
        }
        $has_pending    = (bool) get_option( 'wpl_has_pending_request', 0 );
        $has_wpl_ids    = $is_wpl_session ? true : ! empty( array_filter( json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: [] ) );

        if ( ! $has_token )                  { $status_class = 'wpl-status--none';     $status_label = 'لا يوجد رابط'; }
        elseif ( $token_data['is_expired'] ) { $status_class = 'wpl-status--expired';  $status_label = 'منتهي الصلاحية'; }
        elseif ( ! $token_data['active'] )   { $status_class = 'wpl-status--disabled'; $status_label = 'معطّل'; }
        else                                 { $status_class = 'wpl-status--active';   $status_label = 'نشط — متبقي ' . $token_data['expires_human']; }
        ?>

        <!-- Tutorial Modal -->
        <div id="wpl-tutorial" style="display:none">
            <div class="wpl-tut-backdrop"></div>
            <div class="wpl-tut-modal">

                <!-- الخطوة 1: رابط الدخول المؤقت -->
                <div class="wpl-tut-step active" data-step="1">
                    <div class="wpl-tut-head">
                        <span class="wpl-tut-badge">1 / 4</span>
                        <h3>🔗 رابط الدخول المؤقت</h3>
                        <p>أول خطوة — انسخ هذا الرابط وأرسله لفريق <strong>تراخيص ووردبريس</strong> على واتساب. سيدخلون موقعك مباشرة لتفعيل إضافاتك بدون الحاجة لكلمة المرور.</p>
                    </div>
                    <div class="wpl-tut-body">
                        <div class="wpl-mock">
                            <div class="wpl-mock-title">🔗 رابط الدخول المؤقت <span class="wpl-mock-status">نشط — متبقي 7 يوم</span></div>
                            <div class="wpl-mock-link">
                                <div class="wpl-mock-url">https://yoursite.com/wp-admin/?wpl_token=••••••••••••</div>
                                <div class="wpl-mock-copy">📋 نسخ</div>
                            </div>
                            <div class="wpl-mock-stats">
                                <span>🔢 الاستخدام: <b>0 مرة</b></span>
                                <span>🕐 آخر استخدام: <b>لم يُستخدم بعد</b></span>
                            </div>
                            <div class="wpl-mock-btns-row">
                                <span class="wpl-mock-pill warning">🔄 إنشاء رابط جديد</span>
                                <span class="wpl-mock-pill danger">🚫 تعطيل</span>
                            </div>
                        </div>
                        <div class="wpl-tut-tip">💡 انسخ الرابط وأرسله على واتساب — الفريق سيتولى الباقي بدون أي تعقيد</div>
                    </div>
                </div>

                <!-- الخطوة 2: السيريال -->
                <div class="wpl-tut-step" data-step="2">
                    <div class="wpl-tut-head">
                        <span class="wpl-tut-badge">2 / 4</span>
                        <h3>🔑 السيريال ورقم الطلب</h3>
                        <p>أدخل <strong>السيريال</strong> الموجود في صفحة الشكر + <strong>رقم الطلب</strong> من إيميل التأكيد — وسيبدأ التثبيت تلقائياً فور التحقق.</p>
                    </div>
                    <div class="wpl-tut-body">
                        <div class="wpl-mock wpl-mock-dark">
                            <div class="wpl-mock-lock-icon">🔐</div>
                            <div class="wpl-mock-lock-title">أدخل السيريال للمتابعة</div>
                            <div class="wpl-mock-input-wrap" style="flex-direction:column;gap:6px">
                                <div class="wpl-mock-field" style="font-size:12px">🔑 WPL-XXXX-XXXX-XXXX<span class="wpl-blink">|</span></div>
                                <div class="wpl-mock-field" style="font-size:12px">📦 رقم الطلب: 1234</div>
                                <div class="wpl-mock-pill primary" style="align-self:center">✅ تحقق وثبّت</div>
                            </div>
                        </div>
                        <div class="wpl-tut-tip">💡 السيريال موجود في صفحة الشكر بعد الدفع — رقم الطلب في إيميل التأكيد</div>
                    </div>
                </div>

                <!-- الخطوة 3: التفعيل التلقائي -->
                <div class="wpl-tut-step" data-step="3">
                    <div class="wpl-tut-head">
                        <span class="wpl-tut-badge">3 / 4</span>
                        <h3>⚡ التثبيت التلقائي الفوري</h3>
                        <p>بعد التحقق من السيريال ورقم الطلب — تبدأ الإضافات والقوالب بالتثبيت والتفعيل تلقائياً في ثوانٍ بدون أي خطوات إضافية.</p>
                    </div>
                    <div class="wpl-tut-body">
                        <div class="wpl-anim-screen" id="wpl-anim-req">
                            <div class="wpl-anim-title">⚡ جاري التفعيل التلقائي</div>

                            <!-- progress bar -->
                            <div style="margin:10px 0">
                                <div style="display:flex;justify-content:space-between;font-size:11px;color:#6b7280;margin-bottom:4px">
                                    <span id="anim-label">⏳ جاري التحضير...</span>
                                    <span id="anim-pct">0%</span>
                                </div>
                                <div style="background:#f1f5f9;border-radius:20px;height:8px;overflow:hidden">
                                    <div id="req-input" style="height:100%;background:linear-gradient(90deg,#0f172a,#3b82f6);border-radius:20px;width:0%;transition:width .3s ease"></div>
                                </div>
                            </div>

                            <!-- الحالات -->
                            <div id="req-state-sending" style="display:none;padding:10px;background:#eff6ff;border-radius:8px;font-size:12px;color:#1d4ed8;display:none;align-items:center;gap:8px">
                                <span style="animation:wpl-spin 1s linear infinite;display:inline-block">🔄</span>
                                <span>جاري إرسال الطلب...</span>
                            </div>
                            <div id="req-state-success" style="display:none;padding:10px;background:#f0fdf4;border-radius:8px;font-size:12px;color:#166534">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span>✅</span><strong>تم إرسال طلب التفعيل بنجاح!</strong></div>
                                <div style="color:#4b5563;font-size:11px">سيقوم فريق تراخيص ووردبريس بمراجعة الطلب وتنصيب إضافاتك تلقائياً.</div>
                            </div>
                            <div id="req-state-installing" style="display:none;padding:10px;background:#faf5ff;border-radius:8px;font-size:12px;color:#7c3aed">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><span style="animation:wpl-spin 1s linear infinite;display:inline-block">⚡</span><strong>جاري التنصيب التلقائي...</strong></div>
                                <div style="display:flex;flex-direction:column;gap:4px">
                                    <div style="display:flex;align-items:center;gap:6px;font-size:11px"><span id="req-p1-icon">⏳</span><span>Elementor Pro</span><span id="req-p1-status" style="margin-right:auto;color:#9ca3af">في الانتظار</span></div>
                                    <div style="display:flex;align-items:center;gap:6px;font-size:11px"><span id="req-p2-icon">⏳</span><span>Astra Pro</span><span id="req-p2-status" style="margin-right:auto;color:#9ca3af">في الانتظار</span></div>
                                </div>
                            </div>
                            <div id="req-state-done" style="display:none;padding:10px;background:#f0fdf4;border-radius:8px;font-size:12px;color:#166534">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span>🎉</span><strong>تم تنصيب جميع الإضافات!</strong></div>
                                <div style="display:flex;flex-direction:column;gap:3px;font-size:11px">
                                    <div>✅ Elementor Pro — <span style="color:#059669">مفعّل</span></div>
                                    <div>✅ Astra Pro — <span style="color:#059669">مفعّل</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="wpl-anim-label" id="req-label">👆 شاهد كيف يعمل التفعيل التلقائي</div>
                        <div class="wpl-tut-tip">💡 رقم الطلب موجود في إيميل التأكيد بعد الشراء من موقعنا</div>
                    </div>
                </div>

                <!-- الخطوة 4: التنصيب اليدوي -->
                <div class="wpl-tut-step" data-step="4">
                    <div class="wpl-tut-head">
                        <span class="wpl-tut-badge">4 / 4</span>
                        <h3>🛠 التنصيب اليدوي</h3>
                        <p>⚠️ <strong>للطوارئ فقط</strong> — لو التفعيل التلقائي لم يعمل، تقدر تنصّب الإضافات يدوياً من هنا. ابحث عن الإضافة واضغط تثبيت.</p>
                    </div>
                    <div class="wpl-tut-body">
                        <div class="wpl-anim-screen" id="wpl-anim">
                            <div class="wpl-anim-title">📦 الإضافات والقوالب <span class="wpl-mock-count">2 منتجات</span></div>
                            <div class="wpl-anim-search">🔍 ابحث عن منتج...</div>
                            <div class="wpl-anim-prod" id="anim-prod">
                                <div class="wpl-anim-prod-head" id="anim-head"><span class="anim-chevron" id="anim-chev">▶</span><b>Kadence Theme</b><span class="wpl-mock-tag blue">قالب</span><span class="wpl-mock-tag grey" id="anim-count">18 ملف</span><span class="wpl-mock-pill purple anim-qtns" id="anim-qtns" style="margin-right:auto;opacity:0">⬇️ تثبيت الكل</span></div>
                                <div class="wpl-anim-body" id="anim-body">
                                    <div class="wpl-mock-table-mini">
                                        <div class="wpl-mock-row-mini"><input type="checkbox" checked disabled><span>Kadence Core v1.1.51</span><span class="wpl-mock-tag" id="anim-tag1">غير مثبّت</span><span class="wpl-mock-pill" id="anim-btn1">⬇️ تثبيت</span></div>
                                        <div class="wpl-mock-row-mini"><input type="checkbox" checked disabled><span>Kadence Blocks v3.2.19</span><span class="wpl-mock-tag grey">غير مثبّت</span><span class="wpl-mock-pill purple">⬇️ تثبيت</span></div>
                                    </div>
                                    <div class="wpl-mock-footer-mini"><label><input type="checkbox" checked disabled> تفعيل تلقائي</label><span class="wpl-mock-pill primary">⬇️ تثبيت المحدد</span></div>
                                </div>
                            </div>
                            <div class="wpl-anim-prod"><div class="wpl-anim-prod-head"><span>▶</span><b>Elementor Pro</b><span class="wpl-mock-tag blue">إضافة</span><span class="wpl-mock-tag green">✅ مثبّت</span></div></div>
                        </div>
                        <div class="wpl-anim-label" id="anim-label">⏳ جاري عرض الشرح...</div>
                        <div class="wpl-tut-tip" style="background:#fff8ed;border-color:#fcd34d;color:#92400e">⚠️ استخدم التنصيب اليدوي فقط عند الحاجة — التفعيل التلقائي أسرع وأسهل</div>
                    </div>
                </div>

                <div class="wpl-tut-footer">
                    <div class="wpl-tut-dots">
                        <span class="wpl-tut-dot active" data-step="1"></span>
                        <span class="wpl-tut-dot" data-step="2"></span>
                        <span class="wpl-tut-dot" data-step="3"></span>
                        <span class="wpl-tut-dot" data-step="4"></span>
                    </div>
                    <div class="wpl-tut-actions">
                        <button class="wpl-btn wpl-btn--sm wpl-tut-dismiss" id="wpl-tut-dismiss" style="background:#f1f5f9;color:#64748b;font-size:11px">لا تظهر مرة ثانية</button>
                        <button class="wpl-btn wpl-btn--cancel wpl-btn--sm" id="wpl-tut-close">إغلاق</button>
                        <button class="wpl-btn wpl-btn--primary wpl-btn--sm" id="wpl-tut-next">التالي ←</button>
                    </div>
                </div>

            </div>
        </div>

        <div class="wpl-wrap">

            <div class="wpl-header">
                <img src="https://wordpresslicenses.com/wp-content/uploads/2024/01/logo-01.svg" alt="WordPress Licenses" class="wpl-header__logo-img">
                <div><h1 class="wpl-header__title">تراخيص ووردبريس</h1><p>أفضل متجر عربي لبيع القوالب والإضافات بسعر مميز</p></div>
                <div style="margin-right:auto;display:flex;align-items:center;gap:10px;direction:ltr">

                </div>
            </div>

            <!-- ===== WPL Universal Hero Card ===== -->
            <div id="wpl-hero-card" style="background:linear-gradient(135deg,#0f172a 0%,#1a2d4f 60%,#0f2d5e 100%);border-radius:20px;overflow:hidden;margin-bottom:20px;direction:rtl;position:relative">
                <!-- Glow overlay -->
                <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 20%,rgba(59,130,246,.08) 0%,transparent 60%);pointer-events:none"></div>

                <!-- Top: Robot + Bubble -->
                <div style="padding:24px 28px 0;display:flex;align-items:flex-start;gap:18px;position:relative">
                    <div style="flex:1">
                        <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);letter-spacing:1.8px;margin-bottom:10px">WORDPRESS LICENSES</div>
                        <div id="wpl-bubble" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:16px;border-top-right-radius:4px;padding:14px 18px;transition:opacity .35s ease">
                            <div id="wpl-typing" style="display:none;gap:5px;align-items:center;height:18px">
                                <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.55);display:inline-block;animation:wplBlink 1.1s ease-in-out infinite"></span>
                                <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.55);display:inline-block;animation:wplBlink 1.1s ease-in-out infinite .2s"></span>
                                <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.55);display:inline-block;animation:wplBlink 1.1s ease-in-out infinite .4s"></span>
                            </div>
                            <div id="wpl-b-big" style="font-size:16px;font-weight:700;color:#fff;line-height:1.35;margin-bottom:4px;transition:opacity .3s ease"><?php echo $verified ? 'أهلاً تاني! 👋 عايز طلب جديد؟' : 'أهلاً! أنا خبير التفعيل التلقائي'; ?></div>
                            <div id="wpl-b-sub" style="font-size:12px;color:rgba(255,255,255,.65);line-height:1.6;transition:opacity .3s ease"><?php echo $verified ? 'أدخل السيريال ورقم الطلب الجديد' : 'هنفعّل طلبك في ثوانٍ'; ?></div>
                        </div>
                    </div>
                    <img id="wpl-robot-img"
                         src="https://wordpresslicenses.com/wp-content/uploads/2026/03/125887871_Graident-Ai-Robot-scaled.jpg"
                         alt="AI Robot"
                         style="width:80px;height:80px;object-fit:contain;flex-shrink:0;filter:drop-shadow(0 8px 24px rgba(59,130,246,.3));transition:transform .3s">
                </div>

                <!-- Input fields (only when not verified) -->
                <div id="wpl-robot-fields" style="padding:20px 28px;<?php echo $verified ? 'display:none' : ''; ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                        <div>
                            <label style="display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:1.2px;margin-bottom:6px">🔑 السيريال</label>
                            <input type="text" id="wpl-serial-input"
                                   placeholder="WPL-XXXX-XXXX-XXXX"
                                   class="wpl-serial-input"
                                   autocomplete="off"
                                   style="width:100%;text-align:right;direction:rtl;letter-spacing:.5px;background:rgba(255,255,255,.07);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;font-size:13px;outline:none">
                        </div>
                        <div>
                            <label style="display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:1.2px;margin-bottom:6px">📦 رقم الطلب</label>
                            <input type="text" id="wpl-gate-order-input"
                                   placeholder="مثال: 39593"
                                   class="wpl-serial-input"
                                   autocomplete="off"
                                   style="width:100%;text-align:right;direction:rtl;background:rgba(255,255,255,.07);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;font-size:13px;outline:none">
                        </div>
                    </div>
                    <button id="wpl-robot-go-btn" onclick="wplVerifySerial()"
                            style="width:100%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border:none;border-radius:12px;padding:14px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;letter-spacing:.3px">
                        ⚡ تحقق وثبّت الآن
                    </button>
                    <p class="wpl-serial-gate__error" id="wpl-serial-error" style="display:none;margin-top:10px;text-align:center"></p>
                    <div style="display:flex;gap:20px;justify-content:center;margin-top:14px">
                        <a href="https://wordpresslicenses.com/profile/orders/" target="_blank"
                           style="color:rgba(255,255,255,.45);font-size:11px;text-decoration:none">📋 طلباتي وسيريالاتي</a>
                        <a href="https://wa.me/201092522686" target="_blank"
                           style="color:rgba(255,255,255,.45);font-size:11px;text-decoration:none">💬 تواصل معنا</a>
                    </div>
                </div>

                <!-- Progress (during install) -->
                <div id="wpl-robot-prog" style="padding:0 28px 24px;display:none;opacity:0;transition:opacity .4s">
                    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:6px">
                        <div>
                            <span id="wpl-robot-prog-n" style="font-size:48px;font-weight:800;color:#fff;line-height:1">0</span>
                            <span style="font-size:16px;color:rgba(255,255,255,.4);margin-right:4px"> / </span>
                            <span id="wpl-robot-prog-total" style="font-size:16px;color:rgba(255,255,255,.4)">0</span>
                        </div>
                        <div style="font-size:12px;color:rgba(255,255,255,.4);padding-bottom:8px">ملف</div>
                    </div>
                    <div style="height:5px;border-radius:3px;background:rgba(255,255,255,.1);overflow:hidden;margin-bottom:8px">
                        <div id="wpl-robot-prog-fill" style="height:100%;border-radius:3px;background:linear-gradient(90deg,#3b82f6,#60a5fa);width:0%;transition:width .35s ease"></div>
                    </div>
                    <div id="wpl-robot-prog-file" style="font-size:12px;color:rgba(255,255,255,.45);text-align:right"></div>
                </div>
            </div><!-- /wpl-hero-card -->

            <!-- ===== Token Card (أدمن بس) ===== -->
            <?php if ( ! $is_wpl_session ) : ?>
            <div id="wpl-tour-token-card" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                <div style="background:#0f172a;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;direction:rtl">
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="font-size:15px">🔗</span>
                        <span style="color:#fff;font-size:13px;font-weight:600">رابط الدخول المؤقت</span>
                    </div>
                    <span class="wpl-status <?php echo $status_class; ?>" style="font-size:11px"><?php echo $status_label; ?></span>
                </div>
                <div style="padding:14px 20px;direction:rtl">
                <?php if ( $has_token && $token_data['active'] && ! $token_data['is_expired'] ) : ?>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
                        <!-- تعطيل + تغيير يمين -->
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('تعطيل الرابط المؤقت؟ لن يعمل حتى تنشئ رابطاً جديداً.')" style="margin:0;flex-shrink:0">
                            <?php wp_nonce_field('wpl_disable_token'); ?><input type="hidden" name="action" value="wpl_disable_token">
                            <button type="submit" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap">🚫 تعطيل الرابط</button>
                        </form>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('تغيير الرابط؟ الرابط الحالي لن يعمل بعدها.')" style="margin:0;flex-shrink:0">
                            <?php wp_nonce_field('wpl_generate_token'); ?><input type="hidden" name="action" value="wpl_generate_token">
                            <button type="submit" style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:inherit">🔄 تغيير الرابط</button>
                        </form>
                        <!-- الرابط وسط -->
                        <input type="text" id="wpl-link-input" value="<?php echo esc_url($login_url); ?>" readonly
                               style="flex:1;min-width:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:8px 14px;font-size:12px;color:#374151;outline:none;direction:ltr;text-align:left">
                        <!-- نسخ يسار -->
                        <button class="wpl-btn wpl-btn--copy" onclick="wplCopyLink()"
                                style="background:#0f172a;color:#fff;border:none;border-radius:9px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0">📋 نسخ</button>
                    </div>
                    <div style="display:flex;gap:16px;font-size:11px;color:#94a3b8">
                        <span>⏰ <strong style="color:#64748b"><?php echo date('d/m/Y H:i', $token_data['expires_at']); ?></strong></span>
                        <span>🔢 <strong style="color:#64748b"><?php echo $token_data['use_count']; ?> مرة</strong></span>
                        <span>🕐 <strong style="color:#64748b"><?php echo $token_data['last_used_human'] ?? 'لم يُستخدم بعد'; ?></strong></span>
                    </div>
                <?php else : ?>
                    <div style="display:flex;gap:10px;align-items:center">
                        <p style="flex:1;color:#94a3b8;font-size:13px;margin:0">لا يوجد رابط نشط</p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0">
                            <?php wp_nonce_field('wpl_generate_token'); ?><input type="hidden" name="action" value="wpl_generate_token">
                            <button type="submit" style="background:#0f172a;color:#fff;border:none;border-radius:9px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit" <?php echo $has_pending ? 'disabled' : ''; ?>>🔗 إنشاء رابط</button>
                        </form>
                    </div>
                <?php endif; ?>
                <?php if ( $has_pending ) : ?>
                    <p style="color:#92400e;background:#fef3c7;border:1px solid #fcd34d;padding:8px 14px;border-radius:8px;font-size:12px;margin:10px 0 0;direction:rtl">⚠️ يوجد طلب تفعيل قيد الانتظار — لا يمكن تغيير الرابط حالياً.</p>
                <?php endif; ?>
                </div>
            </div>
            <?php endif; // !is_wpl_session ?>

            <!-- ===== dummy gate for JS compat ===== -->
            <div id="wpl-serial-gate-wrapper" style="display:none"></div>
            <!-- hidden inputs for JS compat -->
            <div style="display:none">
                <input type="text" id="wpl-act-serial-input" autocomplete="off">
                <input type="text" id="wpl-order-input" autocomplete="off">
                <div id="wpl-act-form"></div>
                <div id="wpl-act-error"></div>
                <div id="wpl-act-success"></div>
            </div>

            <!-- ===== Tabs (2 tabs only) ===== -->
            <?php if ( ! $is_wpl_session ) : ?>
            <div class="wpl-tabs-nav" id="wpl-tabs-nav" style="<?php echo $verified ? '' : 'display:none'; ?>">
                <button class="wpl-tab-btn active" data-tab="requests">📋 الطلبات السابقة<?php if ( $has_pending ) : ?> <span class="wpl-tab-dot"></span><?php endif; ?></button>
                <button class="wpl-tab-btn" data-tab="files" <?php echo ( ! $has_wpl_ids ) ? 'style="display:none"' : ''; ?>>🛠 التنصيب اليدوي</button>
            </div>
            <?php endif; ?>

            <!-- ===== Tab 1: طلباتي ===== -->
            <?php if ( ! $is_wpl_session ) : ?>
            <div id="wpl-tab-requests" class="wpl-tab-pane" style="<?php echo $verified ? '' : 'display:none'; ?>">
                <div class="wpl-myreqs-card">
                    <div class="wpl-myreqs-head">
                        <div class="wpl-myreqs-head__title"><span>📋</span> طلباتي</div>
                        <div class="wpl-myreqs-head__actions">
                            <button type="button" class="wpl-myreqs-btn wpl-myreqs-btn--danger" onclick="wplClearCache()">🗑 مسح الكاش</button>
                            <button type="button" class="wpl-myreqs-btn" onclick="wplLoadMyRequests()">🔄 تحديث</button>
                        </div>
                    </div>
                    <div id="wpl-requests-container"><div class="wpl-myreqs-empty">اضغط «تحديث» لعرض طلباتك.</div></div>
                </div>
            </div><!-- /wpl-tab-requests -->
            <?php endif; ?>

            <!-- ===== Tab 2: تنصيب يدوي (session + manual) ===== -->
            <div id="wpl-tab-files" class="wpl-tab-pane" style="<?php echo ( ( $is_wpl_session || $has_wpl_ids ) && $verified ) ? '' : 'display:none'; ?>">

                <?php if ( $is_wpl_session ) :
                    $verified_order = get_option('wpl_verified_order_number'); ?>

                <!-- Session Hero Card -->
                <div id="wpl-hero-card-session" style="background:linear-gradient(135deg,#0f172a 0%,#1a2d4f 60%,#0f2d5e 100%);border-radius:20px;overflow:hidden;margin-bottom:16px;direction:rtl;position:relative">
                    <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 20%,rgba(59,130,246,.08) 0%,transparent 60%);pointer-events:none"></div>
                    <div style="padding:24px 28px 0;display:flex;align-items:flex-start;gap:18px;position:relative">
                        <div style="flex:1">
                            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);letter-spacing:1.8px;margin-bottom:10px">WORDPRESS LICENSES</div>
                            <div id="wpl-bubble-s" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:16px;border-top-right-radius:4px;padding:14px 18px;opacity:0;transform:translateX(8px);transition:opacity .4s,transform .4s">
                                <div id="wpl-typing-s" style="display:flex;gap:5px;align-items:center;height:18px">
                                    <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.55);display:inline-block;animation:wplBlink 1.1s ease-in-out infinite"></span>
                                    <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.55);display:inline-block;animation:wplBlink 1.1s ease-in-out infinite .2s"></span>
                                    <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.55);display:inline-block;animation:wplBlink 1.1s ease-in-out infinite .4s"></span>
                                </div>
                                <div id="wpl-b-big-s" style="font-size:16px;font-weight:700;color:#fff;line-height:1.35;margin-bottom:4px;display:none"></div>
                                <div id="wpl-b-sub-s" style="font-size:12px;color:rgba(255,255,255,.65);line-height:1.6;display:none"></div>
                            </div>
                        </div>
                        <img id="wpl-robot-img-s" src="https://wordpresslicenses.com/wp-content/uploads/2026/03/125887871_Graident-Ai-Robot-scaled.jpg"
                             alt="AI" style="width:80px;height:80px;object-fit:contain;flex-shrink:0;filter:drop-shadow(0 8px 24px rgba(59,130,246,.3));transition:transform .3s">
                    </div>
                    <!-- Progress -->
                    <div id="wpl-robot-prog-s" style="padding:0 28px 24px;display:none;opacity:0;transition:opacity .4s">
                        <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:6px">
                            <div>
                                <span id="wpl-robot-prog-n-s" style="font-size:48px;font-weight:800;color:#fff;line-height:1">0</span>
                                <span style="font-size:16px;color:rgba(255,255,255,.4);margin-right:4px"> / </span>
                                <span id="wpl-robot-prog-total-s" style="font-size:16px;color:rgba(255,255,255,.4)">0</span>
                            </div>
                            <div style="font-size:12px;color:rgba(255,255,255,.4);padding-bottom:8px">ملف</div>
                        </div>
                        <div style="height:5px;border-radius:3px;background:rgba(255,255,255,.1);overflow:hidden;margin-bottom:8px">
                            <div id="wpl-robot-prog-fill-s" style="height:100%;border-radius:3px;background:linear-gradient(90deg,#3b82f6,#60a5fa);width:0%;transition:width .35s ease"></div>
                        </div>
                        <div id="wpl-robot-prog-file-s" style="font-size:12px;color:rgba(255,255,255,.45);text-align:right"></div>
                    </div>
                    <!-- Install button area -->
                    <div id="wpl-session-btn-area" style="padding:0 28px 24px">
                        <?php if ( $verified_order ) : ?>
                        <button onclick="wplAutoInstallOrder('<?php echo esc_js($verified_order); ?>')" id="wpl-session-install-btn"
                                style="width:100%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border:none;border-radius:12px;padding:14px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit">
                            ⚡ تثبيت طلب #<?php echo esc_html($verified_order); ?> تلقائياً
                        </button>
                        <?php else : ?>
                        <div style="display:flex;gap:10px">
                            <input type="text" id="wpl-order-input-session" placeholder="رقم الطلب — مثال: 1234"
                                   style="flex:1;background:rgba(255,255,255,.07);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;font-size:13px;outline:none;direction:rtl"
                                   autocomplete="off" onkeydown="if(event.key==='Enter') wplSendActivationRequest()">
                            <button onclick="wplSendActivationRequest()"
                                    style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap">⚡ تثبيت</button>
                        </div>
                        <?php endif; ?>
                        <div id="wpl-act-form-session" style="display:none"></div>
                        <div id="wpl-act-success-session" style="display:none;padding:10px 0"></div>
                        <p id="wpl-act-error-session" style="display:none;color:#fca5a5;font-size:12px;margin:8px 0 0;text-align:center"></p>
                    </div>
                </div>

                <?php endif; // is_wpl_session ?>

                <!-- Products Card -->
                <div class="wpl-card wpl-card--products" id="wpl-tour-products-card">
                    <div class="wpl-card__head">
                        <h2>📦 الإضافات والقوالب المتاحة</h2>
                        <span class="wpl-products-count" id="wpl-products-count">جاري التحميل...</span>
                    </div>
                    <div class="wpl-search-bar">
                        <span class="wpl-search-icon">🔍</span>
                        <input type="text" id="wpl-search" placeholder="ابحث عن إضافة أو قالب..." oninput="wplFilterProducts(this.value)" autocomplete="off">
                        <button class="wpl-search-clear" id="wpl-search-clear" onclick="wplClearSearch()" style="display:none">✕</button>
                    </div>
                    <div id="wpl-products-container"><div class="wpl-loading"><span class="wpl-spinner"></span> جاري التحميل...</div></div>
                </div>

            </div><!-- /wpl-tab-files -->

        </div><!-- /wpl-wrap -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
        // افتح تبويبة الملفات تلقائياً لو في URL tab=files (جلسة الدعم)
        (function(){
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('tab') === 'files') {
                document.addEventListener('DOMContentLoaded', function() {
                    var filesTab = document.getElementById('wpl-tab-files');
                    var reqTab   = document.getElementById('wpl-tab-requests');
                    if (filesTab) filesTab.style.display = 'block';
                    if (reqTab)   reqTab.style.display   = 'none';
                    jQuery('.wpl-tab-btn').removeClass('active');
                    jQuery('.wpl-tab-btn[data-tab="files"]').addClass('active');
                    if (typeof wplLoadProducts === 'function') wplLoadProducts();
                });
            }
        })();
        window.wplDismissHint = function(e) {
            e.stopPropagation();
            var hint = document.getElementById('wpl-tut-hint');
            if (hint) hint.style.display = 'none';
            jQuery.post(WPL.ajax_url, { action: 'wpl_dismiss_hint', nonce: WPL.nonce });
        };
        /* ===== Tutorial ===== */
        var wplTutStep=1,wplTutTotal=4;
        window.wplOpenTutorial=function(){wplTutStep=1;var t=document.querySelector('.wpl-wrap');if(t){t.scrollIntoView({behavior:'smooth',block:'start'});setTimeout(function(){wplShowStep(1);jQuery('body').css('overflow','hidden');jQuery('#wpl-tutorial').fadeIn(250);},600);}else{wplShowStep(1);jQuery('body').css('overflow','hidden');jQuery('#wpl-tutorial').fadeIn(250);}};
        var wplAnimTimer=[];
        function wplRunAnim(){wplAnimTimer.forEach(clearTimeout);wplAnimTimer=[];var body=document.getElementById('anim-body'),chev=document.getElementById('anim-chev'),qtns=document.getElementById('anim-qtns'),lbl=document.getElementById('anim-label'),btn1=document.getElementById('anim-btn1'),tag1=document.getElementById('anim-tag1');if(!body)return;body.style.maxHeight='0';body.style.opacity='0';if(chev)chev.textContent='▶';if(qtns)qtns.style.opacity='0';if(btn1){btn1.className='wpl-mock-pill purple';btn1.textContent='⬇️ تثبيت';btn1.style.background='';}if(tag1){tag1.className='wpl-mock-tag grey';tag1.textContent='غير مثبّت';}if(lbl)lbl.textContent='👆 اضغط على المنتج لفتح تفاصيله';wplAnimTimer.push(setTimeout(function(){if(chev)chev.textContent='▼';if(qtns)jQuery(qtns).animate({opacity:1},300);body.style.maxHeight='200px';body.style.opacity='1';if(lbl)lbl.textContent='✅ تفاصيل المنتج ظهرت!';},1200));wplAnimTimer.push(setTimeout(function(){if(btn1){btn1.className='wpl-mock-pill';btn1.style.background='#7c3aed';btn1.textContent='⏳ جاري التثبيت...';}if(lbl)lbl.textContent='⬇️ جاري تثبيت الإضافة على موقعك...';},2600));wplAnimTimer.push(setTimeout(function(){if(tag1){tag1.className='wpl-mock-tag blue';tag1.textContent='مثبّت';}if(btn1){btn1.style.background='#10b981';btn1.textContent='▶️ تفعيل';}if(lbl)lbl.textContent='✅ تم التثبيت! اضغط تفعيل لتشغيل الإضافة';},4000));wplAnimTimer.push(setTimeout(function(){if(tag1){tag1.className='wpl-mock-tag green';tag1.textContent='✅ مفعّل';}if(btn1){btn1.style.background='#6b7280';btn1.textContent='🔄 استبدال';}if(lbl)lbl.textContent='🎉 الإضافة مفعّلة وجاهزة للاستخدام!';},5400));wplAnimTimer.push(setTimeout(function(){if(jQuery('.wpl-tut-step[data-step="4"]').hasClass('active'))wplRunAnim();},7500));}
        function wplRunReqAnim(){
            wplAnimTimer.forEach(clearTimeout);wplAnimTimer=[];
            var bar=document.getElementById('req-input');
            var lbl=document.getElementById('anim-label');
            var pct=document.getElementById('anim-pct');
            if(!bar)return;
            bar.style.width='0%';
            if(lbl)lbl.textContent='⏳ جاري التحضير...';
            if(pct)pct.textContent='0%';
            var steps=[
                [600,  '⬇️ جاري تنصيب elementor-pro.zip (1/2)', '25%'],
                [1800, '✅ تم تنصيب elementor-pro.zip', '50%'],
                [2600, '⬇️ جاري تنصيب woo-commerce.zip (2/2)', '75%'],
                [3800, '🎉 تم تنصيب 2 ملف بنجاح!', '100%'],
            ];
            for(var si=0;si<steps.length;si++){
                (function(s){
                    wplAnimTimer.push(setTimeout(function(){
                        bar.style.width=s[2];
                        if(lbl)lbl.textContent=s[1];
                        if(pct)pct.textContent=s[2];
                    },s[0]));
                })(steps[si]);
            }
            wplAnimTimer.push(setTimeout(function(){
                if(jQuery('.wpl-tut-step[data-step="3"]').hasClass('active'))wplRunReqAnim();
            },5500));
        }
        function wplShowStep(n){jQuery('.wpl-tut-step').removeClass('active');jQuery('.wpl-tut-step[data-step="'+n+'"]').addClass('active');jQuery('.wpl-tut-dot').removeClass('active');jQuery('.wpl-tut-dot[data-step="'+n+'"]').addClass('active');jQuery('#wpl-tut-next').text(n===wplTutTotal?'🎉 ابدأ الآن':'التالي ←');if(n===4){wplAnimTimer.forEach(clearTimeout);setTimeout(wplRunAnim,400);}
        else if(n===3){wplAnimTimer.forEach(clearTimeout);setTimeout(wplRunReqAnim,400);}
        else wplAnimTimer.forEach(clearTimeout);}
        jQuery(document).ready(function($){$('#wpl-tut-next').on('click',function(){if(wplTutStep<wplTutTotal){wplTutStep++;wplShowStep(wplTutStep);}else wplCloseTutorial();});$('#wpl-tut-close, .wpl-tut-backdrop').on('click',function(){jQuery('body').css('overflow','');jQuery('#wpl-tutorial').fadeOut(200);wplAnimTimer.forEach(clearTimeout);});$('#wpl-tut-dismiss').on('click',wplCloseTutorial);$('.wpl-tut-dot').on('click',function(){wplTutStep=parseInt($(this).data('step'));wplShowStep(wplTutStep);});});
        function wplCloseTutorial(){jQuery('body').css('overflow','');jQuery('#wpl-tutorial').fadeOut(200);jQuery.post(WPL.ajax_url,{action:'wpl_mark_tutorial_seen',nonce:WPL.nonce});}
        // === Session Robot ===
        (function(){
            var isSession = <?php echo $is_wpl_session ? 'true' : 'false'; ?>;
            if(!isSession) return;
            var bubble=document.getElementById('wpl-bubble-s');
            var robot=document.getElementById('wpl-robot-img-s');
            if(!bubble) return;
            window.wplRobotSetMsgSession=function(big,sub,talking){
                var bBig=document.getElementById('wpl-b-big-s'),bSub=document.getElementById('wpl-b-sub-s'),typing=document.getElementById('wpl-typing-s');
                bubble.style.opacity='0';bubble.style.transform='translateX(8px)';
                if(robot) robot.className=talking?'wpl-robot-talk':'';
                setTimeout(function(){
                    if(typing) typing.style.display='none';
                    if(bBig){bBig.style.display='block';bBig.textContent=big;}
                    if(bSub){bSub.style.display=sub?'block':'none';bSub.textContent=sub||'';}
                    bubble.style.opacity='1';bubble.style.transform='translateX(0)';
                },350);
            };
            var typing=document.getElementById('wpl-typing-s');
            if(typing){typing.style.display='flex';bubble.style.opacity='1';bubble.style.transform='translateX(0)';}
            setTimeout(function(){
                window.wplRobotSetMsgSession('أهلاً! أنا جاهز أثبّتلك الملفات','اضغط زرار التثبيت وأنا هتولى كل حاجة',true);
            },700);
        })();

        // === Main Robot — Typewriter ===
        jQuery(function(){
            var isVerified = <?php echo $verified ? 'true' : 'false'; ?>;
            var bBig   = document.getElementById('wpl-b-big');
            var bSub   = document.getElementById('wpl-b-sub');
            var bCursor= document.getElementById('wpl-cursor');
            var robot  = document.getElementById('wpl-robot-img');
            var fields = document.getElementById('wpl-robot-fields');
            if(!bBig) return;

            // Typewriter function — writes text char by char
            function typeWrite(el, text, speed, onDone) {
                el.textContent = '';
                el.style.opacity = '1';
                el.style.display = 'block';
                var i = 0;
                if(robot) robot.className = 'wpl-robot-talk';
                var timer = setInterval(function(){
                    if(i < text.length){
                        el.textContent += text[i];
                        i++;
                    } else {
                        clearInterval(timer);
                        if(robot) robot.className = '';
                        if(onDone) setTimeout(onDone, 400);
                    }
                }, speed);
            }

            // Clear and show typing dots
            function showDots(onDone) {
                bBig.style.opacity = '0';
                bSub.style.opacity = '0';
                setTimeout(function(){
                    bBig.style.display = 'none';
                    bSub.style.display = 'none';
                    var typing = document.getElementById('wpl-typing');
                    if(typing) typing.style.display = 'flex';
                    if(robot) robot.className = 'wpl-robot-talk';
                    setTimeout(function(){
                        var typing = document.getElementById('wpl-typing');
                        if(typing) typing.style.display = 'none';
                        if(onDone) onDone();
                    }, onDone ? 900 : 0);
                }, 250);
            }

            if(isVerified){
                // Already verified — show new order form message
                typeWrite(bBig, 'أهلاً تاني! 👋', 60, function(){
                    typeWrite(bSub, 'عايز تفعّل طلب جديد؟ أدخل السيريال ورقم الطلب', 35, null);
                });
                if(fields){fields.style.display='block';fields.style.opacity='1';}
                return;
            }

            // Step 1 — greeting
            setTimeout(function(){
                typeWrite(bBig, 'أهلاً! أنا مساعدك الذكي 🤖', 55, function(){
                    typeWrite(bSub, 'بساعدك تفعّل إضافاتك في ثوانٍ بدون أي تعقيد', 30, function(){
                        // Step 2 — dots then next msg
                        setTimeout(function(){
                            showDots(function(){
                                // Step 3 — prompt
                                typeWrite(bBig, 'أدخل السيريال ورقم الطلب 👇', 55, function(){
                                    typeWrite(bSub, 'هتلاقيهم في صفحة طلباتك على موقعنا', 30, null);
                                });
                            });
                        }, 1200);
                    });
                });
            }, 500);
        });

        window.wplRobotSetMsg=function(big,sub,talking){
            var bubble=document.getElementById('wpl-bubble'),robot=document.getElementById('wpl-robot-img');
            var bBig=document.getElementById('wpl-b-big'),bSub=document.getElementById('wpl-b-sub'),typing=document.getElementById('wpl-typing');
            if(!bubble) return;
            bubble.style.opacity='0';bubble.style.transform='translateX(8px)';
            if(robot) robot.className=talking?'wpl-robot-talk':'';
            setTimeout(function(){
                if(typing) typing.style.display='none';
                if(bBig){bBig.style.display='block';bBig.textContent=big;}
                if(bSub){bSub.style.display=sub?'block':'none';bSub.textContent=sub||'';}
                bubble.style.opacity='1';bubble.style.transform='translateX(0)';
            },350);
        };

        window.wplDismissBanner=function(){jQuery('#wpl-promo-banner').slideUp(250);jQuery.post(WPL.ajax_url,{action:'wpl_dismiss_banner',nonce:WPL.nonce});};
        window.wplCopyLink=function(){var input=document.getElementById('wpl-link-input');input.select();navigator.clipboard.writeText(input.value).then(function(){var btn=document.querySelector('.wpl-btn--copy');btn.textContent='✅ تم النسخ';setTimeout(function(){btn.textContent='📋 نسخ';},2500);});};
        window.wplVerifySerial=function(){
            if(window._wplSerialRetryTimer){clearInterval(window._wplSerialRetryTimer);window._wplSerialRetryTimer=null;}
            var serial=document.getElementById('wpl-serial-input').value.trim();
            var orderInp=document.getElementById('wpl-gate-order-input')||document.getElementById('wpl-order-input');
            var orderNum=orderInp?orderInp.value.trim().replace(/^#/,''):'';
            var errEl=document.getElementById('wpl-serial-error');
            var btn=document.querySelector('#wpl-robot-go-btn');
            if(!serial){if(errEl){errEl.textContent='برجاء إدخال السيريال.';errEl.style.display='block';}return;}
            if(btn){btn.disabled=true;btn.textContent='جاري التحقق...';}
            if(errEl) errEl.style.display='none';
            jQuery.post(WPL.ajax_url,{action:'wpl_verify_serial',nonce:WPL.nonce,serial:serial,order_number:orderNum},function(res){
                if(res.success){
                    // حدّث wpl_ids من matched_products اللي رجعوا مع verify_serial مباشرة
                    var verifiedMatchedProds = (res.data && res.data.matched_products) ? res.data.matched_products : [];
                    if (verifiedMatchedProds.length) {
                        var vIds = (WPL.wpl_ids || '').split(',').filter(Boolean);
                        verifiedMatchedProds.forEach(function(m) {
                            var id = typeof m === 'object' ? String(m.wpl_id || '') : '';
                            if (id && vIds.indexOf(id) === -1) vIds.push(id);
                        });
                        WPL.wpl_ids = vIds.join(',');
                        // أظهر تاب المانيوال لو كان مخفياً
                        var manualBtn = document.querySelector('.wpl-tab-btn[data-tab="files"]');
                        if (manualBtn) manualBtn.style.display = '';
                    }
                    <?php if($is_wpl_session):?>location.reload();<?php else:?>
                    window._wplSerialVerified=true;
                    var rFields2=document.getElementById('wpl-robot-fields');
                    if(rFields2){rFields2.style.transition='opacity .3s';rFields2.style.opacity='0';setTimeout(function(){if(rFields2)rFields2.style.display='none';},300);}
                    if(typeof window.wplRobotSetMsg==='function') window.wplRobotSetMsg('ممتاز! السيريال صح ✓','أنا بشوف طلبك وبجهّز ملفاتك...',true);
                    if(orderNum){
                        var nums=(WPL.order_numbers||'').split(',').filter(Boolean);
                        if(nums.indexOf(orderNum)===-1) nums.push(orderNum);
                        WPL.order_numbers=nums.join(',');
                    }
                    var gw=document.getElementById('wpl-serial-gate-wrapper');
                    if(gw) gw.style.display='none';
                    var tabsNav=document.getElementById('wpl-tabs-nav');
                    if(tabsNav) tabsNav.style.display='flex';
                    jQuery('.wpl-tab-btn').removeClass('active');
                    jQuery('.wpl-tab-btn[data-tab="requests"]').addClass('active'); // طلباتي tab
                    jQuery('.wpl-tab-pane').hide();
                    jQuery('#wpl-tab-requests').show();
                    wplLoadProducts('');
                    if(orderNum){
                        var actForm=document.getElementById('wpl-act-form');
                        var actSuccess=document.getElementById('wpl-act-success');
                        if(actForm) actForm.style.display='none';
                        if(actSuccess){actSuccess.style.display='block';actSuccess.innerHTML='<div style="text-align:center;padding:20px;color:#6b7280;font-size:14px">⏳ جاري إرسال طلب التفعيل...</div>';}
                        jQuery.post(WPL.ajax_url,{action:'wpl_send_activation_request',nonce:WPL.nonce,order_number:orderNum,screenshot:'',serial_verified:'1'},function(ares){
                            var aData=(ares.success&&ares.data)||{};
                            window._wplInstantReqId=aData.req_id||null;
                            window._wplInstantOrderNum=orderNum;
                            if(aData.matched_products&&aData.matched_products.length){
                                var existIds=(WPL.wpl_ids||'').split(',').filter(Boolean);
                                aData.matched_products.forEach(function(m){var id=typeof m==='object'?String(m.wpl_id||''):'';if(id&&existIds.indexOf(id)===-1)existIds.push(id);});
                                WPL.wpl_ids=existIds.join(',');
                                if(typeof window._wplBuildInstallUI==='function') window._wplBuildInstallUI(aData.matched_products,orderNum,actSuccess);
                            } else {
                                var unmatchedP=aData.unmatched_products||aData.order_products||[];
                                if(typeof wplShowNoFilesCard==='function') wplShowNoFilesCard(orderNum,unmatchedP);
                            }
                            if(typeof wplLoadMyRequests==='function') setTimeout(wplLoadMyRequests,400);
                        });
                    } else {
                        if(typeof wplLoadMyRequests==='function') wplLoadMyRequests();
                        if(typeof wplDoInstallProducts==='function') setTimeout(wplDoInstallProducts,400);
                    }
                    if(typeof wplSilentPoll==='function') setTimeout(wplSilentPoll,1200);
                    <?php endif;?>
                } else {
                    var errData=res.data||{};
                    var msg=(errData.message)||errData||'السيريال غير صحيح.';
                    if(errData.api_key_invalid){if(typeof wplShowApiKeyError==='function')wplShowApiKeyError();return;}
                    var isTLS=msg.indexOf('cURL error 35')!==-1||msg.indexOf('TLS')!==-1||msg.indexOf('SSL')!==-1||msg.indexOf('تعذّر الاتصال')!==-1;
                    if(isTLS){wplSerialRetryCountdown(btn);}
                    else{if(errEl){errEl.innerHTML=msg;errEl.style.display='block';}if(btn){btn.disabled=false;btn.textContent='⚡ تحقق وثبّت الآن';}}
                }
            }).fail(function(){wplSerialRetryCountdown(btn);});
        };
        window.wplSerialRetryCountdown=function(btn){
            var seconds=10;var errEl=document.getElementById('wpl-serial-error');if(!errEl)return;
            if(btn){btn.disabled=true;btn.textContent='⏳ إعادة المحاولة...';}
            function render(s){var circ=94.2;var offset=circ-(circ*s/10);
                errEl.style.display='block';
                errEl.innerHTML='<div style="display:flex;align-items:center;gap:12px;direction:rtl;flex-wrap:wrap;padding:10px 14px;background:rgba(14,165,233,.15);border-radius:10px;border:1px solid rgba(56,189,248,.4)">'+
                    '<div style="position:relative;width:44px;height:44px;flex-shrink:0">'+
                        '<svg width="44" height="44" style="transform:rotate(-90deg)">'+
                            '<circle cx="22" cy="22" r="18" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="3"/>'+
                            '<circle cx="22" cy="22" r="18" fill="none" stroke="#38bdf8" stroke-width="3"'+
                                ' stroke-dasharray="'+circ+'" stroke-dashoffset="'+offset+'"'+
                                ' style="transition:stroke-dashoffset 1s linear;stroke-linecap:round"/>'+
                        '</svg>'+
                        '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff">'+s+'</span>'+
                    '</div>'+
                    '<div style="flex:1;min-width:150px">'+
                        '<div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:3px">⏳ السيرفر مشغول لحظياً</div>'+
                        '<div style="font-size:11px;color:#bae6fd">سيتم إعادة المحاولة تلقائياً خلال <strong>'+s+'</strong> '+(s===1?'ثانية':'ثوانٍ')+'</div>'+
                    '</div>'+
                    '<button onclick="window._wplCancelSerialRetry()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:7px;padding:5px 10px;font-size:11px;color:#fff;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0">✕ إلغاء</button>'+
                '</div>';
            }
            render(seconds);
            window._wplSerialRetryTimer=setInterval(function(){
                seconds--;
                if(seconds<=0){
                    clearInterval(window._wplSerialRetryTimer);window._wplSerialRetryTimer=null;
                    var el=document.getElementById('wpl-serial-error');
                    if(el){el.style.display='block';el.innerHTML='<div style="color:#38bdf8;font-size:12px;direction:rtl;padding:8px;display:flex;align-items:center;gap:6px"><span style="animation:wpl-spin 1s linear infinite;display:inline-block">🔄</span> جاري إعادة التحقق...</div>';}
                    window.wplVerifySerial();
                } else {render(seconds);}
            },1000);
        };
        window._wplCancelSerialRetry=function(){
            if(window._wplSerialRetryTimer){clearInterval(window._wplSerialRetryTimer);window._wplSerialRetryTimer=null;}
            var errEl=document.getElementById('wpl-serial-error');
            if(errEl){errEl.innerHTML='تعذّر الاتصال — اضغط تحقق لإعادة المحاولة.';errEl.style.display='block';}
            var btn=document.querySelector('.wpl-serial-gate__form .wpl-btn');
            if(btn){btn.disabled=false;btn.textContent='✅ تحقق';}
        };
        document.addEventListener('DOMContentLoaded',function(){
            ['wpl-serial-input','wpl-gate-order-input'].forEach(function(id){
                var inp=document.getElementById(id);
                if(inp) inp.addEventListener('keydown',function(e){if(e.key==='Enter') wplVerifySerial();});
            });
        });
        }); // end DOMContentLoaded
        </script>
        <?php
    }

    public static function check_serial_with_server() {
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

    public function banner_styles() { ?>
        <style>
        @keyframes wplBlink{0%,60%,100%{opacity:.2}30%{opacity:1}}
        @keyframes wplRobotTalk{from{transform:translateY(0) scale(1)}to{transform:translateY(-5px) scale(1.04)}}
        .wpl-robot-talk{animation:wplRobotTalk .5s ease-in-out infinite alternate!important}
        .wpl-admin-banner{border-radius:10px!important;margin:12px 20px 0!important;border:none!important;padding:0!important;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.2);}
        .wpl-admin-banner .wpl-banner-inner{display:flex;align-items:center;gap:16px;padding:16px 22px;flex-wrap:wrap;}
        .wpl-admin-banner.wpl-banner--wpl{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)!important;}
        .wpl-admin-banner.wpl-banner--hostinger{background:linear-gradient(135deg,#5b21b6 0%,#4f46e5 100%)!important;}
        .wpl-admin-banner .wpl-promo-logo{height:34px;filter:brightness(0) invert(1);flex-shrink:0;}
        .wpl-admin-banner .wpl-banner-icon{font-size:28px;}
        .wpl-admin-banner .wpl-banner-text{flex:1;min-width:140px;display:flex;flex-direction:column;gap:3px;}
        .wpl-admin-banner .wpl-banner-text strong{color:#fff!important;font-size:15px;font-weight:700;}
        .wpl-admin-banner .wpl-banner-text span{color:rgba(255,255,255,.75)!important;font-size:12px;}
        .wpl-admin-banner .wpl-banner-text b{color:#fde047!important;font-weight:700;}
        .wpl-admin-banner .wpl-banner-cta{color:#fff!important;font-weight:700;font-size:13px;padding:10px 20px;border-radius:9px;text-decoration:none;white-space:nowrap;flex-shrink:0;transition:opacity .15s;}
        .wpl-admin-banner .wpl-banner-cta:hover{opacity:.88;}
        .wpl-admin-banner .wpl-banner-cta--orange{background:linear-gradient(135deg,#0ea5e9,#0284c7);}
        .wpl-admin-banner .wpl-banner-cta--green{background:linear-gradient(135deg,#22c55e,#16a34a);}
        .wpl-admin-banner .wpl-banner-close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.9);width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:13px;flex-shrink:0;}
        .wpl-admin-banner .wpl-banner-close:hover{background:rgba(255,255,255,.3);}
        </style>
    <?php }

    public function banner_js_inject() {
        global $pagenow;
        $is_dashboard = ( $pagenow === 'index.php' );
        $is_wpl_page  = ( $pagenow === 'admin.php' && ( $_GET['page'] ?? '' ) === 'wpl-client' );
        if ( ! $is_dashboard && ! $is_wpl_page ) return;
        $site_domain  = parse_url( home_url(), PHP_URL_HOST );
        $wa_hostinger = 'https://wa.me/201092522686?text=' . urlencode('أهلاً، أريد الاستفسار عن عروض Hostinger — موقعي: ' . $site_domain);
        ?>
        <script>
        (function(){if(document.getElementById('wpl-banner-wpl'))return;var style=document.getElementById('wpl-banner-injected-style');if(!style){style=document.createElement('style');style.id='wpl-banner-injected-style';style.textContent=<?php echo json_encode('.wpl-ij-wrap{margin:8px 20px;display:flex;flex-direction:column;gap:8px}.wpl-ij-banner{border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.2);display:flex}.wpl-ij-banner .inn{display:flex;align-items:center;gap:16px;padding:16px 22px;width:100%;flex-wrap:wrap}.wpl-ij-banner.wpl{background:linear-gradient(135deg,#0f172a,#1e3a5f)}.wpl-ij-banner.host{background:linear-gradient(135deg,#5b21b6,#4f46e5)}.wpl-ij-banner img{height:34px;filter:brightness(0) invert(1);flex-shrink:0}.wpl-ij-banner .ic{font-size:28px}.wpl-ij-banner .tx{flex:1;min-width:140px;display:flex;flex-direction:column;gap:3px}.wpl-ij-banner .tx strong{color:#fff!important;font-size:15px;font-weight:700}.wpl-ij-banner .tx span{color:rgba(255,255,255,.75)!important;font-size:12px}.wpl-ij-banner .tx b{color:#fde047!important;font-weight:700}.wpl-ij-banner a.cta{color:#fff!important;font-weight:700;font-size:13px;padding:10px 20px;border-radius:9px;text-decoration:none;white-space:nowrap;flex-shrink:0}.wpl-ij-banner a.org{background:linear-gradient(135deg,#0ea5e9,#0284c7)}.wpl-ij-banner a.grn{background:linear-gradient(135deg,#22c55e,#16a34a)}.wpl-ij-banner button.cls{background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:13px;flex-shrink:0}'); ?>;document.head.appendChild(style);}
        var wrap=document.createElement('div');wrap.className='wpl-ij-wrap';wrap.id='wpl-banner-wpl';
        wrap.innerHTML='<div class="wpl-ij-banner wpl"><div class="inn"><img src="https://wordpresslicenses.com/wp-content/uploads/2024/01/logo-01.svg" alt="WPL"><div class="tx"><strong>تراخيص ووردبريس</strong><span>أفضل متجر لقوالب وإضافات ووردبريس الرسمية بسعر منخفض</span></div><a href="https://wordpresslicenses.com" target="_blank" class="cta org">🎁 احصل على أفضل الخصومات</a><button class="cls" onclick="this.closest(\'.wpl-ij-wrap\').style.display=\'none\'">✕</button></div></div><div class="wpl-ij-banner host"><div class="inn"><div class="ic">🚀</div><div class="tx"><strong>احصل على أكبر نسبة خصم من Hostinger</strong><span>هدايا قوالب وإضافات رسمية بقيمة تصل إلى <b>100$</b></span></div><a href="<?php echo esc_js($wa_hostinger); ?>" target="_blank" class="cta grn">💬 احصل على الخصم</a><button class="cls" onclick="this.closest(\'.wpl-ij-banner\').style.display=\'none\'">✕</button></div></div>';
        var target=document.getElementById('wpbody-content')||document.getElementById('wpbody')||document.body;target.insertBefore(wrap,target.firstChild);})();
        </script>
        <?php
    }

    public function render_admin_banners() {
        global $pagenow;
        $is_dashboard = ( $pagenow === 'index.php' );
        $is_wpl_page  = ( $pagenow === 'admin.php' && ( $_GET['page'] ?? '' ) === 'wpl-client' );
        if ( ! $is_dashboard && ! $is_wpl_page ) return;
        $site_domain  = parse_url( home_url(), PHP_URL_HOST );
        $wa_hostinger = 'https://wa.me/201092522686?text=' . urlencode('أهلاً، أريد الاستفسار عن عروض Hostinger — موقعي: ' . $site_domain);
        ?>
        <div class="notice wpl-admin-banner wpl-banner--wpl" id="wpl-banner-wpl" style="display:flex"><div class="wpl-banner-inner" style="width:100%"><img src="https://wordpresslicenses.com/wp-content/uploads/2024/01/logo-01.svg" class="wpl-promo-logo" alt="WPL"><div class="wpl-banner-text"><strong>تراخيص ووردبريس</strong><span>أفضل متجر لقوالب وإضافات ووردبريس الرسمية بسعر منخفض</span></div><a href="https://wordpresslicenses.com" target="_blank" class="wpl-banner-cta wpl-banner-cta--orange">🎁 احصل على أفضل الخصومات</a><button class="wpl-banner-close" onclick="this.closest('.notice').style.display='none'">✕</button></div></div>
        <div class="notice wpl-admin-banner wpl-banner--hostinger" id="wpl-banner-hostinger" style="display:flex"><div class="wpl-banner-inner" style="width:100%"><div class="wpl-banner-icon">🚀</div><div class="wpl-banner-text"><strong>احصل على أكبر نسبة خصم من Hostinger</strong><span>هدايا قوالب وإضافات رسمية بقيمة تصل إلى <b>100$</b></span></div><a href="<?php echo esc_url($wa_hostinger); ?>" target="_blank" class="wpl-banner-cta wpl-banner-cta--green">💬 احصل على الخصم</a><button class="wpl-banner-close" onclick="this.closest('.notice').style.display='none'">✕</button></div></div>
        <?php
    }

    /**
     * ======================================================
     * Gate Page — بتظهر لمستخدمي الرابط المؤقت قبل فتح الصفحة
     * ======================================================
     */
    private function render_gate_page( $token_data, $login_url, $has_token ) {
        $nonce = wp_create_nonce( 'wpl_client_nonce' );
        ?>
        <style>
        @keyframes wplGatePulse{0%,100%{box-shadow:0 0 0 0 rgba(59,130,246,.3)}50%{box-shadow:0 0 0 8px rgba(59,130,246,0)}}
        .wpl-gate-input:focus{outline:none;border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.15)!important}
        .wpl-gate-btn{transition:transform .1s,opacity .15s}
        .wpl-gate-btn:active{transform:scale(.97)}
        </style>

        <div style="max-width:600px;margin:40px auto;direction:rtl;font-family:'Segoe UI',Arial,sans-serif;padding:0 16px">

            <!-- Header بسيط -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
                <img src="https://wordpresslicenses.com/wp-content/uploads/2024/01/logo-01.svg"
                     alt="WordPress Licenses"
                     style="height:32px;filter:brightness(0) invert(0)">
                <span style="font-size:15px;font-weight:700;color:#0f172a">تراخيص ووردبريس</span>
            </div>

            <?php if ( $has_token && $token_data['active'] && ! $token_data['is_expired'] ) : ?>
            <!-- رابط الدخول المؤقت — عرض فقط -->
            <div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                <div style="background:#0f172a;padding:12px 20px;display:flex;align-items:center;gap:8px">
                    <span style="font-size:15px">🔗</span>
                    <span style="color:#fff;font-size:13px;font-weight:600">رابط الدخول المؤقت</span>
                    <span style="margin-right:auto;background:rgba(34,197,94,.2);color:#4ade80;border:1px solid rgba(74,222,128,.3);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600">
                        نشط — متبقي <?php echo esc_html( $token_data['expires_human'] ); ?>
                    </span>
                </div>
                <div style="padding:14px 20px">
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="text" id="wpl-gate-link" value="<?php echo esc_url( $login_url ); ?>" readonly
                               style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:9px 14px;font-size:12px;color:#374151;outline:none;direction:ltr;text-align:left">
                        <button onclick="wplGateCopyLink(this)"
                                style="background:#0f172a;color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0">
                            📋 نسخ
                        </button>
                    </div>
                    <div style="margin-top:8px;font-size:11px;color:#94a3b8;display:flex;gap:14px">
                        <span>🔢 الاستخدام: <strong style="color:#64748b"><?php echo (int)$token_data['use_count']; ?> مرة</strong></span>
                        <span>🕐 آخر استخدام: <strong style="color:#64748b"><?php echo esc_html( $token_data['last_used_human'] ?? 'لم يُستخدم بعد' ); ?></strong></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Gate Card -->
            <div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:20px 24px;text-align:center">
                    <div style="font-size:32px;margin-bottom:8px">🛡️</div>
                    <div style="color:#fff;font-size:15px;font-weight:700;margin-bottom:4px">منطقة محمية</div>
                    <div style="color:rgba(255,255,255,.55);font-size:12px">هذا الجزء خاص بفريق الدعم الخاص بتراخيص ووردبريس</div>
                </div>
                <div style="padding:28px 24px">
                    <label style="display:block;font-size:11px;font-weight:700;color:#6b7280;letter-spacing:.8px;margin-bottom:8px">🔑 السيريال الدائم</label>
                    <input type="password" id="wpl-admin-serial-inp"
                           placeholder="أدخل السيريال الدائم..."
                           autocomplete="off"
                           class="wpl-gate-input"
                           style="width:100%;box-sizing:border-box;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 16px;font-size:14px;color:#0f172a;direction:rtl;margin-bottom:12px"
                           onkeydown="if(event.key==='Enter') wplVerifyAdminSerial()">
                    <button id="wpl-admin-serial-btn" onclick="wplVerifyAdminSerial()"
                            class="wpl-gate-btn"
                            style="width:100%;background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;border:none;border-radius:10px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;animation:wplGatePulse 2s ease-in-out infinite">
                        🔓 دخول
                    </button>
                    <p id="wpl-admin-serial-err"
                       style="display:none;margin:12px 0 0;text-align:center;font-size:13px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px"></p>
                </div>
            </div>

        </div>

        <script>
        function wplGateCopyLink(btn) {
            var inp = document.getElementById('wpl-gate-link');
            navigator.clipboard.writeText(inp.value).then(function() {
                btn.textContent = '✅ تم';
                setTimeout(function(){ btn.textContent = '📋 نسخ'; }, 2000);
            });
        }
        function wplVerifyAdminSerial() {
            var serial  = document.getElementById('wpl-admin-serial-inp').value.trim();
            var btn     = document.getElementById('wpl-admin-serial-btn');
            var errEl   = document.getElementById('wpl-admin-serial-err');
            if (!serial) {
                errEl.textContent = 'برجاء إدخال السيريال.';
                errEl.style.display = 'block';
                return;
            }
            btn.disabled = true;
            btn.textContent = '⏳ جاري التحقق...';
            btn.style.animation = 'none';
            errEl.style.display = 'none';
            jQuery.post(ajaxurl, {
                action:       'wpl_verify_admin_serial',
                nonce:        '<?php echo esc_js( $nonce ); ?>',
                admin_serial: serial
            }, function(res) {
                if (res.success) {
                    btn.textContent = '✅ تم التحقق — جاري الدخول...';
                    btn.style.background = 'linear-gradient(135deg,#059669,#047857)';
                    setTimeout(function(){ location.reload(); }, 600);
                } else {
                    errEl.textContent = (res.data && res.data.message) ? res.data.message : '❌ السيريال غير صحيح.';
                    errEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = '🔓 دخول';
                    btn.style.animation = 'wplGatePulse 2s ease-in-out infinite';
                    document.getElementById('wpl-admin-serial-inp').select();
                }
            }).fail(function() {
                errEl.textContent = '❌ تعذّر الاتصال — حاول مرة أخرى.';
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = '🔓 دخول';
                btn.style.animation = 'wplGatePulse 2s ease-in-out infinite';
            });
        }
        </script>
        <?php
    }

    /**
     * AJAX: التحقق من السيريال الدائم وفتح الـ gate
     */
    public function ajax_verify_admin_serial() {
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

    public function handle_generate() {
        check_admin_referer('wpl_generate_token');
        if ( ! current_user_can('manage_options') ) wp_die('غير مصرح');
        // v3.3.4: شلنا القفل على pending request — الأدمن لازم يقدر يتحكم في الرابط دايماً
        WPL_Token::generate(); self::create_wpl_user(); self::register_with_server();
        wp_redirect( admin_url('admin.php?page=wpl-client') ); exit;
    }

    public static function register_with_server_static() { self::register_with_server(); }

    private static function register_with_server() {
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

    public function handle_disable() {
        check_admin_referer('wpl_disable_token');
        if ( ! current_user_can('manage_options') ) wp_die('غير مصرح');
        // v3.3.4: شلنا القفل على pending request
        WPL_Token::disable(); self::delete_wpl_user(); self::register_with_server_static();
        wp_redirect( admin_url('admin.php?page=wpl-client') ); exit;
    }
}
