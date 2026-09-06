<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPL_Installer {

    public function __construct() {
        add_action( 'wp_ajax_wpl_fetch_products',          [ $this, 'ajax_fetch_products'          ] );
        add_action( 'wp_ajax_wpl_verify_serial',           [ $this, 'ajax_verify_serial'           ] );
        add_action( 'wp_ajax_wpl_install_file',            [ $this, 'ajax_install_file'            ] );
        add_action( 'wp_ajax_wpl_toggle_plugin',           [ $this, 'ajax_toggle_plugin'           ] );
        add_action( 'wp_ajax_wpl_get_download_url',        [ $this, 'ajax_get_download_url'        ] );
        add_action( 'wp_ajax_wpl_send_activation_request', [ $this, 'ajax_send_activation_request' ] );
        add_action( 'wp_ajax_wpl_fetch_my_requests',       [ $this, 'ajax_fetch_my_requests'       ] );
        add_action( 'wp_ajax_wpl_mark_installed',          [ $this, 'ajax_mark_installed'          ] );
        add_action( 'wp_ajax_wpl_get_bg_status',           [ $this, 'ajax_get_bg_status'           ] );

        // ✅ v3.3.4 CRITICAL FIX: الـ hook ده كان مفقود!
        // بدون الـ add_action ده، الـ non-blocking POST بيوصل لـ WordPress
        // لكن WordPress ما بيعرفش يوجّهه لـ handle_bg_install_process،
        // فـ الـ bg install مكنش بيبدأ أبداً (ده السبب الحقيقي للتعلّق على "نزلت 0 من 3")
        add_action( 'admin_post_wpl_bg_install_process',        [ $this, 'handle_bg_install_process' ] );
        add_action( 'admin_post_nopriv_wpl_bg_install_process', [ $this, 'handle_bg_install_process' ] );
    }

    /* ====== التحقق من السيريال ====== */
    public function ajax_verify_serial() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );

        $serial       = sanitize_text_field( $_POST['serial']       ?? '' );
        $order_number = sanitize_text_field( $_POST['order_number'] ?? '' );
        if ( empty( $serial ) ) wp_send_json_error( ['message' => 'برجاء إدخال السيريال.'] );

        $domain       = parse_url( home_url(), PHP_URL_HOST );
        $endpoint     = rtrim( WPL_SERVER_API_URL, '/' ) . '/verify-serial';
        $request_body = wp_json_encode( [
            'serial'       => $serial,
            'order_number' => $order_number,
            'domain'       => $domain,
            'register'     => true,
        ]);
        $request_args = [
            'headers'     => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Content-Type' => 'application/json' ],
            'body'        => $request_body,
            'timeout'     => 20,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ];

        $response = wp_remote_post( $endpoint, $request_args );

        // retry تلقائي لو فشل بسبب TLS
        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            if ( strpos( $err, 'cURL error 35' ) !== false ||
                 strpos( $err, 'SSL' ) !== false ||
                 strpos( $err, 'TLS' ) !== false ||
                 strpos( $err, 'tls' ) !== false ) {
                add_action( 'http_api_curl', function( $ch ) {
                    curl_setopt( $ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1 );
                    curl_setopt( $ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1' );
                }, 99 );
                $request_args['httpversion'] = '1.0';
                $response = wp_remote_post( $endpoint, $request_args );
            }
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( ['message' => 'تعذّر الاتصال: ' . $response->get_error_message()] );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code === 401 ) {
            // ✅ استخدم رسالة السيرفر لو موجودة (مثلاً: "حدّث البلجن من حسابك")
            $server_msg = is_array( $body ) && ! empty( $body['message'] )
                ? $body['message']
                : '⚠️ تحقّق من الـ API key — افتح حسابك على wordpresslicenses.com وحمّل آخر نسخة من البلجن.';
            wp_send_json_error( [ 'message' => $server_msg, 'api_key_invalid' => true ] );
        }
        if ( $http_code !== 200 ) wp_send_json_error( ['message' => 'خطأ HTTP ' . $http_code] );
        if ( ! is_array( $body ) ) wp_send_json_error( ['message' => 'خطأ في الاستجابة من السيرفر.'] );

        if ( ! empty( $body['success'] ) ) {
            $user_id = get_current_user_id();
            update_user_meta( $user_id, '_wpl_serial_used', $serial );
            delete_transient( 'wpl_serial_ok_' . $user_id );
            update_option( 'wpl_serial_verified', 1 );
            // حفظ رقم الطلب في list
            if ( $order_number ) {
                update_option( 'wpl_verified_order_number', $order_number ); // backward compat
                $orders_list = json_decode( get_option('wpl_verified_order_numbers', '[]'), true ) ?: [];
                if ( ! in_array( $order_number, $orders_list, true ) ) {
                    $orders_list[] = $order_number;
                    update_option( 'wpl_verified_order_numbers', json_encode( $orders_list ) );
                }
            }
            WPL_Client_Admin::register_with_server_static();
            // خزّن matched_products من verify_serial عشان المانيوال tab يعرفهم
            if ( ! empty( $body['matched_products'] ) ) {
                $existing_ids = json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: [];
                foreach ( $body['matched_products'] as $mp ) {
                    $wpl_id = is_array( $mp ) ? ( $mp['wpl_id'] ?? '' ) : '';
                    if ( $wpl_id && ! in_array( $wpl_id, $existing_ids, true ) ) {
                        $existing_ids[] = $wpl_id;
                    }
                }
                update_option( 'wpl_verified_wpl_ids', json_encode( $existing_ids ) );
            }
            wp_send_json_success( [
                'message'          => 'تم التحقق ✅',
                'type'             => $body['type']     ?? 'global',
                'order_id'         => $body['order_id'] ?? null,
                'matched_products' => $body['matched_products'] ?? [],
            ]);
        } else {
            $msg = $body['message'] ?? 'السيريال غير صحيح — تواصل معنا للحصول على السيريال الصحيح.';
            wp_send_json_error( ['message' => $msg] );
        }
    }

    /* ====== جيب المنتجات من السيرفر ====== */
    public function ajax_fetch_products() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'install_plugins' ) ) wp_send_json_error( 'غير مصرح', 403 );

        // تحقق من صلاحية الوصول للمنتجات server-side
        $is_wpl_session = ! empty( get_user_meta( get_current_user_id(), '_wpl_access_user', true ) );
        if ( $is_wpl_session ) {
            // فريق الدعم: دايماً مسموح
        } else {
            // مسموح لو: سيريال متحقق أو في pending request أو order number متحقق
            $allowed = get_option('wpl_serial_verified')
                    || get_option('wpl_has_pending_request')
                    || get_option('wpl_verified_order_number');
            if ( ! $allowed ) {
                wp_send_json_error( 'برجاء إدخال السيريال أولاً.', 403 );
            }
        }

        $search   = sanitize_text_field( $_POST['search']   ?? '' );
        $category = sanitize_key(        $_POST['category'] ?? '' );

        // جيب الـ wpl_ids المتحققة محلياً
        $is_wpl_session_fp = ! empty( get_user_meta( get_current_user_id(), '_wpl_access_user', true ) );
        $verified_wpl_ids  = '';
        if ( ! $is_wpl_session_fp ) {
            $ids_list         = json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: [];
            $verified_wpl_ids = implode( ',', array_filter( $ids_list ) );
            // لو مفيش IDs متحققة → المانيوال فاضي (لا نجيب كل المنتجات)
            if ( empty( $verified_wpl_ids ) ) {
                wp_send_json_success([ 'products' => [], 'files_changed' => false ]);
                return;
            }
        }

        $url = rtrim( WPL_SERVER_API_URL, '/' ) . '/products';
        $url = add_query_arg( array_filter([
            'search'   => $search,
            'category' => $category,
            'wpl_ids'  => $verified_wpl_ids,
        ]), $url );

        $response = wp_remote_get( $url, [
            'headers'   => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Accept' => 'application/json' ],
            'timeout'   => 20,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ]);

        if ( is_wp_error( $response ) )
            wp_send_json_error( 'تعذّر الاتصال: ' . $response->get_error_message() );

        $code     = wp_remote_retrieve_response_code( $response );
        $raw      = wp_remote_retrieve_body( $response );
        $body     = json_decode( $raw, true );

        if ( $code === 401 ) {
            $server_msg = is_array( $body ) && ! empty( $body['message'] )
                ? $body['message']
                : '⚠️ تحقّق من الـ API key — افتح حسابك على wordpresslicenses.com وحمّل آخر نسخة من البلجن.';
            wp_send_json_error( [ 'message' => $server_msg, 'api_key_invalid' => true ] );
        }
        if ( $code !== 200 ) wp_send_json_error( 'خطأ ' . $code . ': ' . substr( $raw, 0, 120 ) );
        if ( ! is_array( $body ) ) wp_send_json_error( 'Response غير صالح.' );

        $products = $body['products'] ?? [];

        // احسب hash للملفات — لو اتغير يطلب تفعيل من الأول
        $files_signature = md5( json_encode( array_map( function($p) {
            return array_map( fn($f) => $f['filename'], $p['files'] ?? [] );
        }, $products ) ) );

        $saved_signature = get_option('wpl_products_signature', '');
        $files_changed   = $saved_signature && $files_signature !== $saved_signature;

        if ( $files_signature ) {
            update_option( 'wpl_products_signature', $files_signature );
        }

        // أضف حالة كل ملف جوة كل منتج
        foreach ( $products as &$product ) {
            $is_theme = ( ( $product['category'] ?? '' ) === 'theme' );
            foreach ( $product['files'] as &$file ) {
                if ( $is_theme ) {
                    $status = $this->get_theme_status( $file['filename'] );
                } else {
                    $status = $this->get_plugin_status( $file['filename'] );
                }
                $file['status']      = $status['status'];
                $file['plugin_file'] = $status['plugin_file']; // للقالب: stylesheet (slug)
                $file['is_theme']    = $is_theme;
            }
        }

        wp_send_json_success( [
            'products'      => $products,
            'files_changed' => $files_changed,
        ] );
    }

    /* ====== تثبيت ملف واحد ====== */
    public function ajax_install_file() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'install_plugins' ) ) wp_send_json_error( 'غير مصرح', 403 );

        // نفس منطق التحقق — يمنع تثبيت الملفات بدون سيريال أو طلب تفعيل
        $is_wpl_session = ! empty( get_user_meta( get_current_user_id(), '_wpl_access_user', true ) );
        if ( $is_wpl_session ) {
            // فريق الدعم: دايماً مسموح
        } else {
            $allowed = get_option('wpl_serial_verified')
                    || get_option('wpl_has_pending_request')
                    || get_option('wpl_verified_order_number');
            if ( ! $allowed ) {
                wp_send_json_error( 'برجاء إدخال السيريال أولاً.', 403 );
            }

            // تحقق من حالة الأوردر — لو ملغي امنع التثبيت وامسح البيانات المحلية
            $order_number = get_option('wpl_verified_order_number');
            if ( $order_number ) {
                $domain   = parse_url( home_url(), PHP_URL_HOST );
                $response_check = wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/verify-serial', [
                    'headers'     => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Content-Type' => 'application/json' ],
                    'body'        => wp_json_encode([
                        'serial'       => get_user_meta( get_current_user_id(), '_wpl_serial_used', true ) ?: '',
                        'order_number' => $order_number,
                        'domain'       => $domain,
                        'register'     => false,
                    ]),
                    'timeout'     => 10,
                    'sslverify'   => false,
                    'httpversion' => '1.1',
                ]);
                if ( ! is_wp_error( $response_check ) ) {
                    $check_body = json_decode( wp_remote_retrieve_body( $response_check ), true );
                    if ( isset( $check_body['success'] ) && ! $check_body['success'] ) {
                        // السيريال ملغي أو غير صالح — امسح البيانات المحلية
                        delete_option('wpl_serial_verified');
                        delete_option('wpl_verified_order_number');
                        delete_option('wpl_has_pending_request');
                        $msg = $check_body['message'] ?? 'عذراً، هذا السيريال لطلب ملغي — يرجى التواصل مع الدعم.';
                        wp_send_json_error( ['message' => $msg, 'cancelled' => true] );
                    }
                }
            }
        }

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        $is_theme = ( sanitize_key( $_POST['is_theme'] ?? '' ) === '1' );
        if ( empty( $filename ) ) wp_send_json_error( 'اسم الملف مطلوب.' );

        // تحقق من الصلاحية المناسبة
        if ( $is_theme && ! current_user_can( 'install_themes' ) )
            wp_send_json_error( 'غير مصرح بتثبيت القوالب.', 403 );

        // جيب signed URL
        $response = wp_remote_get( rtrim( WPL_SERVER_API_URL, '/' ) . '/download?file=' . urlencode( $filename ), [
            'headers'   => [ 'X-WPL-Key' => WPL_SERVER_API_KEY ],
            'timeout'   => 15,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ]);

        if ( is_wp_error( $response ) ) wp_send_json_error( 'تعذّر الاتصال: ' . $response->get_error_message() );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['success'] ) || empty( $body['download_url'] ) )
            wp_send_json_error( 'لم يتم الحصول على رابط التحميل.' );

        // تجاهل SSL في الـ Upgrader
        add_filter( 'http_request_args', function( $args ) {
            $args['sslverify'] = false;
            return $args;
        });

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        WP_Filesystem();

        $skin = new WP_Ajax_Upgrader_Skin();

        // حمّل الـ ZIP مرة واحدة واكتشف النوع منه
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp_file = download_url( $body['download_url'] );
        if ( is_wp_error( $tmp_file ) )
            wp_send_json_error( 'فشل تحميل الملف: ' . $tmp_file->get_error_message() );

        $actual_is_theme = $this->detect_file_type( $tmp_file );

        if ( $actual_is_theme ) {
            if ( ! current_user_can( 'install_themes' ) )
                wp_send_json_error( 'غير مصرح بتثبيت القوالب.', 403 );

            if ( ! class_exists( 'Theme_Upgrader' ) ) {
                if ( file_exists( ABSPATH . 'wp-admin/includes/class-theme-upgrader.php' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
                } else {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                }
            }

            $upgrader = new Theme_Upgrader( $skin );

            // اكتشف لو القالب موجود — استخدم upgrade بدل install
            $existing_theme_slug = $this->find_existing_theme_slug( $filename );
            if ( $existing_theme_slug ) {
                // القالب موجود — استبدله
                $result = $upgrader->upgrade( $existing_theme_slug, [ 'source' => $tmp_file ] );
                if ( $result === false || is_wp_error( $result ) ) {
                    // fallback: install مع overwrite_package
                    $upgrader2 = new Theme_Upgrader( $skin );
                    $result    = $upgrader2->install( $tmp_file, [ 'overwrite_package' => true ] );
                    $upgrader  = $upgrader2;
                }
            } else {
                $result = $upgrader->install( $tmp_file, [ 'overwrite_package' => true ] );
            }

            if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
            if ( ! $result ) {
                $err = $skin->get_errors();
                wp_send_json_error( is_wp_error( $err ) ? $err->get_error_message() : 'فشل تثبيت القالب.' );
            }

            // جيب slug القالب
            $plugin_file = $existing_theme_slug ?: '';
            if ( ! $plugin_file && method_exists( $upgrader, 'theme_info' ) ) {
                $theme_obj   = $upgrader->theme_info();
                $plugin_file = $theme_obj ? $theme_obj->get_stylesheet() : '';
            }
            if ( ! $plugin_file ) {
                $base_slug        = strtolower( preg_replace( '/[-._\s]+/', '-',
                    preg_replace( '/\.[^.]+$/', '', pathinfo( $filename, PATHINFO_FILENAME ) )
                ));
                $installed_themes = wp_get_themes( [ 'errors' => false ] );
                foreach ( $installed_themes as $slug => $theme ) {
                    $sl = strtolower( $slug );
                    if ( $sl === $base_slug || strpos( $sl, $base_slug ) !== false || strpos( $base_slug, $sl ) !== false ) {
                        $plugin_file = $slug;
                        break;
                    }
                }
                if ( ! $plugin_file ) $plugin_file = $base_slug;
            }

        } else {
            // plugin أو addon
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            $upgrader = new Plugin_Upgrader( $skin );

            // اكتشف لو البلجن موجود — استخدم upgrade بدل install
            $existing_plugin_file = $this->find_existing_plugin_file( $filename );
            if ( $existing_plugin_file ) {
                // البلجن موجود — استبدله
                $result = $upgrader->upgrade( $existing_plugin_file, [ 'source' => $tmp_file ] );
                if ( $result === false || is_wp_error( $result ) ) {
                    // fallback: install مع overwrite_package
                    $upgrader2 = new Plugin_Upgrader( $skin );
                    $result    = $upgrader2->install( $tmp_file, [ 'overwrite_package' => true ] );
                    $upgrader  = $upgrader2;
                    if ( ! is_wp_error( $result ) && $result ) {
                        $existing_plugin_file = $upgrader->plugin_info() ?: $existing_plugin_file;
                    }
                }
            } else {
                $result = $upgrader->install( $tmp_file, [ 'overwrite_package' => true ] );
            }

            if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
            if ( ! $result ) {
                $err = $skin->get_errors();
                wp_send_json_error( is_wp_error( $err ) ? $err->get_error_message() : 'فشل التثبيت.' );
            }

            $plugin_file = $existing_plugin_file ?: $upgrader->plugin_info();

            // fallback لو plugin_info() رجعت null — أعد تحميل كاش البلجنز
            if ( ! $plugin_file ) {
                if ( ! function_exists( 'get_plugins' ) )
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';

                $raw_slug   = strtolower( preg_replace( '/\.zip$/i', '', $filename ) );
                $clean_slug = preg_replace( '/-[\d]+[\d.\-]*$/', '', $raw_slug );
                $short_slug = preg_replace( '/-(plugin|addon|pro|premium|sites|theme)$/i', '', $clean_slug );

                // أعد تحميل قائمة البلجنز عشان تشمل المثبّت للتو
                $all_plugins = get_plugins( '/' );
                foreach ( $all_plugins as $pf => $data ) {
                    $dir  = strtolower( explode( '/', $pf )[0] );
                    $name = strtolower( $data['Name'] ?? '' );
                    if (
                        $dir === $clean_slug || $dir === $short_slug ||
                        strpos( $clean_slug, $dir ) !== false ||
                        strpos( $dir, $short_slug ) !== false ||
                        ( $name && (
                            strpos( $name, $short_slug ) !== false ||
                            strpos( $short_slug, $name ) !== false
                        ))
                    ) {
                        $plugin_file = $pf;
                        break;
                    }
                }
            }
        }

        // حدّث is_theme في الـ response بالقيمة الفعلية
        $is_theme = $actual_is_theme;

        // امسح الـ tmp file
        @unlink( $tmp_file );

        // أبلغ السيرفر بالتثبيت قبل الرد
        $domain       = parse_url( home_url(), PHP_URL_HOST );
        $product_name = preg_replace( '/\.zip$/i', '', $filename );
        $product_name = str_replace( ['-','_'], ' ', $product_name );
        $product_name = ucwords( $product_name );

        wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/installed', [
            'headers'   => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode([
                'domain'       => $domain,
                'filename'     => $filename,
                'product_name' => $product_name,
            ]),
            'timeout'   => 8,
            'sslverify'   => false,
            'httpversion' => '1.1',
            'blocking'  => true,
        ]);

        wp_send_json_success([
            'message'     => 'تم التثبيت ✅',
            'plugin_file' => $plugin_file,
            'is_theme'    => $is_theme,
        ]);
    }

    /* ====== تفعيل / إيقاف ====== */
    public function ajax_toggle_plugin() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'activate_plugins' ) ) wp_send_json_error( 'غير مصرح', 403 );

        $plugin_file = sanitize_text_field( $_POST['plugin_file']   ?? '' );
        $action      = sanitize_key(        $_POST['toggle_action'] ?? 'activate' );
        $is_theme    = ( sanitize_key( $_POST['is_theme'] ?? '' ) === '1' );

        if ( empty( $plugin_file ) ) wp_send_json_error( 'مسار البلجن/القالب مطلوب.' );

        // تحقق فعلي — لو الـ slug موجود في wp_get_themes يبقى theme بغض النظر عن الـ flag
        if ( ! $is_theme ) {
            $all_themes = wp_get_themes( [ 'errors' => false ] );
            if ( isset( $all_themes[ $plugin_file ] ) ) {
                $is_theme = true;
            }
        }

        if ( $is_theme ) {
            // تفعيل/إلغاء تفعيل القالب
            if ( $action === 'activate' ) {
                if ( ! current_user_can( 'switch_themes' ) )
                    wp_send_json_error( 'غير مصرح بتغيير القالب.', 403 );

                // تحقق إن القالب موجود فعلاً
                $theme_obj = wp_get_theme( $plugin_file );
                if ( ! $theme_obj->exists() ) {
                    wp_send_json_error( 'القالب غير موجود: ' . $plugin_file );
                }

                // لو child theme — تحقق إن الـ parent متثبّت
                $parent = $theme_obj->get('Template');
                if ( $parent ) {
                    $parent_theme = wp_get_theme( $parent );
                    if ( ! $parent_theme->exists() ) {
                        wp_send_json_error(
                            'هذا القالب يتطلب تثبيت القالب الأساسي أولاً: ' . $parent .
                            ' — ثبّت ' . $parent . ' وفعّله قبل تفعيل هذا القالب.'
                        );
                    }
                }

                switch_theme( $plugin_file );
                $active_theme = get_option( 'stylesheet' );
                if ( $active_theme === $plugin_file ) {
                    wp_send_json_success([ 'status' => 'active' ]);
                } else {
                    wp_send_json_error( 'فشل تفعيل القالب — تأكد من تثبيت القالب الأساسي أولاً.' );
                }
            } else {
                // لا يوجد "deactivate" للقالب — نرجع installed فقط
                wp_send_json_success([ 'status' => 'installed' ]);
            }
        } else {
            if ( $action === 'activate' ) {
                ob_start();
                $result = activate_plugin( $plugin_file );
                ob_end_clean();
                if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
                wp_send_json_success([ 'status' => 'active' ]);
            } else {
                deactivate_plugins( $plugin_file );
                wp_send_json_success([ 'status' => 'installed' ]);
            }
        }
    }

    /* ====== رابط تحميل ZIP ====== */
    public function ajax_get_download_url() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'install_plugins' ) ) wp_send_json_error( 'غير مصرح', 403 );

        // نفس منطق التحقق
        $is_wpl_session = ! empty( get_user_meta( get_current_user_id(), '_wpl_access_user', true ) );
        if ( $is_wpl_session ) {
            // فريق الدعم: دايماً مسموح
        } else {
            $allowed = get_option('wpl_serial_verified')
                    || get_option('wpl_has_pending_request')
                    || get_option('wpl_verified_order_number');
            if ( ! $allowed ) {
                wp_send_json_error( 'برجاء إدخال السيريال أولاً.', 403 );
            }
        }

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( empty( $filename ) ) wp_send_json_error( 'اسم الملف مطلوب.' );

        $response = wp_remote_get( rtrim( WPL_SERVER_API_URL, '/' ) . '/download?file=' . urlencode( $filename ), [
            'headers'   => [ 'X-WPL-Key' => WPL_SERVER_API_KEY ],
            'timeout'   => 15,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ]);

        if ( is_wp_error( $response ) ) wp_send_json_error( 'تعذّر الاتصال.' );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['success'] ) || empty( $body['download_url'] ) )
            wp_send_json_error( 'لم يتم الحصول على الرابط.' );

        wp_send_json_success([ 'download_url' => $body['download_url'] ]);
    }

    /* ====== إرسال طلب التفعيل السريع ====== */
    public function ajax_send_activation_request() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'غير مصرح', 403 );

        $order_number    = sanitize_text_field( $_POST['order_number']    ?? '' );
        
        $serial_verified = ! empty( $_POST['serial_verified'] ) && $_POST['serial_verified'] === '1';
        // كمان تحقق من option محلي
        if ( ! $serial_verified ) {
            $serial_verified = (bool) get_option('wpl_serial_verified');
        }

        if ( empty( $order_number ) ) {
            wp_send_json_error( 'برجاء إدخال رقم الطلب.' );
        }

        // جهّز الـ login_url
        WPL_Token::maybe_generate();
        $login_url = WPL_Token::get_login_url();
        $domain    = parse_url( home_url(), PHP_URL_HOST );

        // سجّل مع السيرفر لو مش مسجّل
        WPL_Client_Admin::register_with_server_static();

        // ابعت الطلب للسيرفر — مع retry تلقائي لو فشل بسبب TLS
        $request_args = [
            'headers'     => [
                'X-WPL-Key'    => WPL_SERVER_API_KEY,
                'Content-Type' => 'application/json',
            ],
            'body'        => wp_json_encode([
                'order_number'   => $order_number,
                'domain'         => $domain,
                'login_url'      => $login_url,
                'screenshot'     => '',
                'serial_verified' => $serial_verified,
            ]),
            'timeout'     => 30,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ];

        $endpoint = rtrim( WPL_SERVER_API_URL, '/' ) . '/activation-request';
        $response = wp_remote_post( $endpoint, $request_args );

        // لو فشل بسبب TLS — حاول مرة تانية بـ HTTP/2 fallback
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            if ( strpos( $error_msg, 'cURL error 35' ) !== false ||
                 strpos( $error_msg, 'SSL' ) !== false ||
                 strpos( $error_msg, 'TLS' ) !== false ||
                 strpos( $error_msg, 'tls' ) !== false ) {

                // محاولة ثانية: HTTP/1.0 + disable SSL completely via filter
                add_action( 'http_api_curl', function( $ch ) {
                    curl_setopt( $ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1 );
                    curl_setopt( $ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1' );
                }, 99 );

                $request_args['httpversion'] = '1.0';
                $response = wp_remote_post( $endpoint, $request_args );
            }
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'تعذّر الاتصال: ' . $response->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code === 401 ) wp_send_json_error( 'خطأ في التحقق من الهوية.' );
        if ( $http_code !== 200 ) wp_send_json_error( 'خطأ من السيرفر (' . $http_code . ').' );

        if ( ! empty( $body['success'] ) ) {
            $auto_approved    = ! empty( $body['auto_approved'] );
            $matched_products = $body['matched_products'] ?? [];

            // خزّن الـ wpl_ids المتحققة محلياً — ده هو الفلتر الأساسي للمانيوال
            if ( ! empty( $matched_products ) ) {
                $existing_ids = json_decode( get_option('wpl_verified_wpl_ids', '[]'), true ) ?: [];
                foreach ( $matched_products as $mp ) {
                    $wpl_id = is_array($mp) ? ($mp['wpl_id'] ?? '') : '';
                    if ( $wpl_id && ! in_array($wpl_id, $existing_ids, true) ) {
                        $existing_ids[] = $wpl_id;
                    }
                }
                update_option( 'wpl_verified_wpl_ids', json_encode( $existing_ids ) );
            }

            // 🚀 ابدأ background install فوراً لو في ملفات
            if ( $auto_approved && ! empty( $matched_products ) ) {
                $unmatched = $body['unmatched_products'] ?? [];
                $no_files  = $body['no_files_products']  ?? []; // ✅ v2.7.3
                $this->queue_bg_install( $order_number, $matched_products, $unmatched, $no_files );
            }

            // دايماً حدد الـ flag عشان WPL session تقدر تجيب المنتجات وتثبّتها
            update_option( 'wpl_has_pending_request', 1 );

            wp_send_json_success([
                'message'             => $body['message']             ?? 'تم إرسال الطلب بنجاح!',
                'auto_approved'       => $auto_approved,
                'matched_products'    => $matched_products,
                'unmatched_products'  => $body['unmatched_products']  ?? [],
                'no_files_products'   => $body['no_files_products']   ?? [], // ✅ v2.7.3
                'order_products'      => $body['order_products']      ?? [],
                'req_id'              => $body['req_id']              ?? null,
            ]);
        } else {
            wp_send_json_error( $body['message'] ?? 'حدث خطأ غير متوقع.' );
        }
    }

/* ====== Background Install — تحريك المهمة في الخلفية ====== */
    public function queue_bg_install( $order_number, $matched_products, $unmatched_products = [], $no_files_products = [] ) {
        $token = wp_hash( 'wpl_bg_' . date('YmdH') . get_option('wpl_api_key') );
        $job   = [
            'order_number'       => $order_number,
            'matched_products'   => $matched_products,
            'unmatched_products' => array_values( $unmatched_products ),
            // ✅ v2.7.3: منتجات مسجّلة في WPL بس بدون ملفات → يدوي
            'no_files_products'  => array_values( $no_files_products ),
            'status'          => 'queued',
            'queued_at'       => time(),
            'started_at'      => 0,
            'finished_at'     => 0,
            'files'           => [],
            'total'           => 0,
            'done'            => 0,
            'missing_prods'   => [],
            'error'           => '',
        ];
        update_option( 'wpl_bg_install_job', $job, false );

        // ============================================================
        // ✅ v3.3.4: 3 طرق متوازية لتشغيل الـ bg install
        // ============================================================
        // Strategy 1 (الأهم): register_shutdown_function
        //   بعد ما الـ AJAX response يرجع للعميل، PHP process يكمل ويشغّل الـ install
        //   ده بيتم في نفس الـ process، بدون network call، فمضمون تماماً
        //
        // Strategy 2 (backup): Non-blocking POST (الطريقة القديمة)
        //   لسه موجودة كـ backup لو shutdown function فشلت
        //
        // Strategy 3 (backup): WP Cron
        //   يشتغل لما أي زائر يفتح الموقع، كـ safety net
        //
        // النتيجة: الـ install لازم يشتغل بأي طريقة من الـ 3
        // ============================================================

        // Strategy 1: shutdown function (الأكثر موثوقية)
        register_shutdown_function( function() {
            // يجب تأخير شوية عشان الـ AJAX response يرجع أولاً
            if ( function_exists('fastcgi_finish_request') ) {
                fastcgi_finish_request();
            }
            @set_time_limit(300);
            @ini_set('max_execution_time', 300);
            @ignore_user_abort(true);

            $this->run_bg_install();
        });

        // Strategy 2: Non-blocking POST (الطريقة القديمة — backup)
        wp_remote_post( admin_url('admin-post.php'), [
            'body'        => [
                'action'         => 'wpl_bg_install_process',
                '_wpl_bg_token'  => $token,
            ],
            'timeout'     => 1,
            'blocking'    => false,
            'sslverify'   => false,
            'httpversion' => '1.1',
            'cookies'     => is_array( $_COOKIE ) ? $_COOKIE : [],
        ]);

        // Strategy 3: WP Cron (backup لو shutdown فشلت)
        if ( ! wp_next_scheduled( 'wpl_bg_install_cron' ) ) {
            wp_schedule_single_event( time() + 3, 'wpl_bg_install_cron' );
        }
    }

    /**
     * ✅ v3.3.4: WP Cron handler للـ bg install
     */
    public function cron_bg_install() {
        $this->run_bg_install();
    }

    /* ====== جيب حالة الـ Background Job ====== */
    public function ajax_get_bg_status() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        $job = get_option( 'wpl_bg_install_job', [] );

        // ✅ v3.3.4: Recovery المحسّن — لو الـ job متعلق، شغّله مباشرة (مش non-blocking POST)
        if ( ! empty( $job ) ) {
            $now = time();

            // حالة: queued لأكتر من 15 ثانية → run مباشرة في نفس الـ request
            if ( ( $job['status'] ?? '' ) === 'queued' ) {
                $queued_since = $now - (int) ( $job['queued_at'] ?? $now );
                if ( $queued_since > 15 ) {
                    $job['recovery_attempts'] = ( $job['recovery_attempts'] ?? 0 ) + 1;
                    $job['last_recovery_at']  = $now;
                    update_option( 'wpl_bg_install_job', $job, false );

                    // register_shutdown_function عشان ما نأخرش response الـ AJAX
                    register_shutdown_function( function() {
                        if ( function_exists('fastcgi_finish_request') ) {
                            fastcgi_finish_request();
                        }
                        @set_time_limit(300);
                        @ignore_user_abort(true);
                        $this->run_bg_install();
                    });
                }
            }

            // حالة: running لأكتر من 5 دقايق بدون ما حتى يعرف total → اعتبره معطّل
            if ( ( $job['status'] ?? '' ) === 'running' ) {
                $started_since = $now - (int) ( $job['started_at'] ?? $now );
                $total         = (int) ( $job['total'] ?? 0 );

                if ( $started_since > 300 && $total === 0 ) {
                    $job['status']    = 'queued';
                    $job['queued_at'] = $now;
                    update_option( 'wpl_bg_install_job', $job, false );
                    register_shutdown_function( function() {
                        if ( function_exists('fastcgi_finish_request') ) {
                            fastcgi_finish_request();
                        }
                        @set_time_limit(300);
                        @ignore_user_abort(true);
                        $this->run_bg_install();
                    });
                }
            }
        }

        wp_send_json_success( ! empty($job) ? $job : ['status' => 'idle'] );
    }

    /* ====== تنفيذ الـ Background Install (admin-post HTTP endpoint) ====== */
    public function handle_bg_install_process() {
        $token    = sanitize_text_field( $_POST['_wpl_bg_token'] ?? '' );
        $api_key  = get_option( 'wpl_api_key' );
        $expected = wp_hash( 'wpl_bg_' . date('YmdH') . $api_key );
        $prev     = wp_hash( 'wpl_bg_' . date('YmdH', time() - 3600) . $api_key );

        if ( ! hash_equals($expected, $token) && ! hash_equals($prev, $token) ) {
            wp_die('Unauthorized', 403);
        }

        // أغلق الـ connection فوراً
        if ( function_exists('fastcgi_finish_request') ) {
            fastcgi_finish_request();
        } else {
            header('Connection: close');
            header('Content-Encoding: none');
            header('Content-Length: 0');
            @ob_end_clean(); flush();
        }

        @set_time_limit(300);
        @ignore_user_abort(true);
        $this->run_bg_install();
        return;
    }

    /**
     * ✅ v3.3.4: الشغل الفعلي للـ bg install — بدون أي HTTP/network dependencies
     * بيتنادى من shutdown function، cron، أو HTTP endpoint
     */
    public function run_bg_install() {
        @set_time_limit(300);
        @ini_set('max_execution_time', 300);

        // Atomic DB lock: shutdown, loopback and cron may arrive together.
        $lock_name = 'wpl_bg_install_worker_lock';
        $lock_time = (int) get_option( $lock_name, 0 );
        if ( $lock_time && ( time() - $lock_time ) < 10 * MINUTE_IN_SECONDS ) return;
        if ( $lock_time ) delete_option( $lock_name );
        if ( ! add_option( $lock_name, time(), '', 'no' ) ) return;
        register_shutdown_function( function() use ( $lock_name ) {
            delete_option( $lock_name );
        } );

        $job = get_option('wpl_bg_install_job', []);
        if ( empty($job) || ! in_array($job['status'], ['queued', 'running'], true) ) return;

        // امنع double-run: لو running من أقل من 60 ثانية، اعتبر فيه process تاني شغال
        if ( $job['status'] === 'running' && (time() - ($job['started_at'] ?? 0)) < 60 ) return;

        $job['status']     = 'running';
        $job['started_at'] = time();
        update_option('wpl_bg_install_job', $job, false);

        // Load WP libs
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        // إجبار filesystem مباشر — يمنع طلب FTP credentials
        if ( ! defined('FS_METHOD') ) define('FS_METHOD', 'direct');
        WP_Filesystem();

        $matched_products = $job['matched_products'] ?? [];
        $order_number     = $job['order_number']     ?? '';

        if ( empty($matched_products) ) {
            $job['status'] = 'complete';
            update_option('wpl_bg_install_job', $job, false);
            return;
        }

        // جيب ملفات المنتجات من السيرفر
        $wpl_ids  = implode(',', array_filter( array_map(fn($m) => $m['wpl_id'] ?? '', $matched_products) ));
        $url      = rtrim(WPL_SERVER_API_URL, '/') . '/products?wpl_ids=' . urlencode($wpl_ids);
        $response = wp_remote_get($url, [
            'headers'     => ['X-WPL-Key' => WPL_SERVER_API_KEY],
            'timeout'     => 30,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ]);

        if ( is_wp_error($response) ) {
            $job['status'] = 'error';
            $job['error']  = 'تعذّر الاتصال: ' . $response->get_error_message();
            update_option('wpl_bg_install_job', $job, false);
            return;
        }

        $body     = json_decode(wp_remote_retrieve_body($response), true);
        $products = $body['products'] ?? [];

        // ابنِ قائمة الملفات + حدّد المنتجات اللي مفيهاش ملفات
        $all_files     = [];
        $missing_prods = [];

        foreach ( $matched_products as $mp ) {
            $wpl_id   = (string) ($mp['wpl_id']   ?? '');
            $wpl_name = $mp['wpl_name'] ?? $wpl_id;
            $wc_id    = $mp['wc_item_id'] ?? '';
            $found    = null;
            foreach ( $products as $p ) {
                if ( (string)$p['id'] === $wpl_id ) { $found = $p; break; }
            }
            if ( ! $found || empty($found['files']) ) {
                $missing_prods[] = [
                    'woo_name' => $mp['wc_name']  ?? $wpl_name,
                    'wpl_name' => $wpl_name,
                    'wc_id'    => $wc_id,
                    'wpl_id'   => $wpl_id,
                ];
                continue;
            }
            $is_theme = ( ($found['category'] ?? '') === 'theme' );
            foreach ( $found['files'] as $file ) {
                $all_files[] = [
                    'filename'     => $file['filename'],
                    'label'        => $file['label']  ?? $file['filename'],
                    'product_name' => $wpl_name,
                    'product_id'   => $wpl_id,
                    'is_theme'     => $is_theme,
                    'status'       => 'pending',
                    'error'        => '',
                ];
            }
        }

        $job['files']         = $all_files;
        $job['total']         = count($all_files);
        $job['done']          = 0;
        // ادمج missing_prods مع unmatched_products + no_files_products
        // ✅ v2.7.3: no_files_products كمان بتتعرض للعميل كـ "هيتم تنصيبها يدوياً"
        $job['missing_prods'] = array_merge(
            $missing_prods,
            array_values( $job['unmatched_products'] ?? [] ),
            array_values( $job['no_files_products']  ?? [] )
        );
        update_option('wpl_bg_install_job', $job, false);

        if ( empty($all_files) ) {
            $job['status'] = 'complete';
            update_option('wpl_bg_install_job', $job, false);
            return;
        }

        // Never disable TLS verification globally. Individual legacy WPL requests
        // remain explicitly scoped; unrelated WordPress/vendor HTTP traffic keeps core defaults.

        $skin    = new WP_Ajax_Upgrader_Skin();
        $log_pfx = '[WPL-Install] order=' . $order_number . ' domain=' . parse_url( home_url(), PHP_URL_HOST );

        error_log( $log_pfx . ' | ▶ START — total_files=' . count($job['files']) );

        foreach ( $job['files'] as $idx => &$fi ) {
            $filename = $fi['filename'];
            $is_theme = (bool) $fi['is_theme'];
            $log_file = $log_pfx . ' | file[' . ($idx+1) . '/' . count($job['files']) . ']=' . $filename;

            $fi['status']       = 'installing';
            $job['files'][$idx] = $fi;
            update_option('wpl_bg_install_job', $job, false);

            // ── 1. بدء تحميل الملف ────────────────────────────
            $dl_endpoint = rtrim(WPL_SERVER_API_URL, '/') . '/download?file=' . urlencode($filename);
            error_log( $log_file . ' | [1] DOWNLOAD_START — fetching signed URL from: ' . $dl_endpoint );

            $dl = wp_remote_get( $dl_endpoint, [
                'headers'     => ['X-WPL-Key' => WPL_SERVER_API_KEY],
                'timeout'     => 15,
                'sslverify'   => false,
                'httpversion' => '1.1',
            ]);

            if ( is_wp_error($dl) ) {
                $err = $dl->get_error_message();
                error_log( $log_file . ' | [2] DOWNLOAD_FAIL — could not fetch signed URL: ' . $err );
                $fi['status'] = 'error'; $fi['error'] = 'تعذّر جلب الرابط: ' . $err;
                $job['files'][$idx] = $fi; update_option('wpl_bg_install_job', $job, false); continue;
            }

            $dl_body = json_decode(wp_remote_retrieve_body($dl), true);
            if ( empty($dl_body['success']) || empty($dl_body['download_url']) ) {
                $api_msg = $dl_body['message'] ?? wp_remote_retrieve_body($dl);
                error_log( $log_file . ' | [2] DOWNLOAD_FAIL — server returned no download_url. Response: ' . $api_msg );
                $fi['status'] = 'error'; $fi['error'] = 'الملف غير موجود على السيرفر: ' . $api_msg;
                $job['files'][$idx] = $fi; update_option('wpl_bg_install_job', $job, false); continue;
            }

            $signed_url = $dl_body['download_url'];
            error_log( $log_file . ' | [1] DOWNLOAD_START — signed URL obtained, downloading ZIP from: ' . $signed_url );

            // ── 2. تحميل الـ ZIP ──────────────────────────────
            $tmp = download_url( $signed_url, 60 );

            if ( is_wp_error($tmp) ) {
                $err = $tmp->get_error_message();
                error_log( $log_file . ' | [2] DOWNLOAD_FAIL — ' . $err );
                $fi['status'] = 'error'; $fi['error'] = 'فشل تحميل الملف: ' . $err;
                $job['files'][$idx] = $fi; update_option('wpl_bg_install_job', $job, false); continue;
            }

            $tmp_size = @filesize($tmp);
            error_log( $log_file . ' | [2] DOWNLOAD_OK — saved to: ' . $tmp . ' (' . number_format($tmp_size) . ' bytes)' );

            $actual_theme = $this->detect_file_type($tmp);
            $type_label   = $actual_theme ? 'theme' : 'plugin';
            error_log( $log_file . ' | [3] INSTALL_START — detected type: ' . $type_label );

            // ── 3 + 4. التثبيت ─────────────────────────────────
            if ( $actual_theme ) {
                if ( ! class_exists('Theme_Upgrader') )
                    require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
                $upgrader = new Theme_Upgrader($skin);
                $result   = $upgrader->install($tmp, ['overwrite_package' => true]);
                if ( ! $result || is_wp_error($result) ) {
                    $slug = $this->find_existing_theme_slug($filename);
                    if ( $slug ) {
                        error_log( $log_file . ' | [3] INSTALL_FALLBACK — trying upgrade() for existing theme slug: ' . $slug );
                        $u2     = new Theme_Upgrader($skin);
                        $result = $u2->upgrade($slug, ['source' => $tmp]);
                    }
                }
            } else {
                if ( ! class_exists('Plugin_Upgrader') )
                    require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
                $upgrader = new Plugin_Upgrader($skin);
                $result   = $upgrader->install($tmp, ['overwrite_package' => true]);
                if ( ! $result || is_wp_error($result) ) {
                    $pf = $this->find_existing_plugin_file($filename);
                    if ( $pf ) {
                        error_log( $log_file . ' | [3] INSTALL_FALLBACK — trying upgrade() for existing plugin: ' . $pf );
                        $u2     = new Plugin_Upgrader($skin);
                        $result = $u2->upgrade($pf, ['source' => $tmp]);
                    }
                }
            }

            @unlink($tmp);

            // ── 4. نتيجة التثبيت ─────────────────────────────
            if ( is_wp_error($result) ) {
                $err = $result->get_error_message();
                error_log( $log_file . ' | [4] INSTALL_FAIL — WP_Error: ' . $err );
                $fi['status'] = 'error'; $fi['error'] = $err;
            } elseif ( ! $result ) {
                // جمّع أخطاء الـ upgrader skin
                $skin_errors = implode(' | ', $skin->get_upgrade_messages() ?: ['no detail'] );
                error_log( $log_file . ' | [4] INSTALL_FAIL — result=false. Upgrader messages: ' . $skin_errors );
                $fi['status'] = 'error'; $fi['error'] = 'فشل التثبيت — ' . $skin_errors;
            } else {
                error_log( $log_file . ' | [4] INSTALL_OK — ' . $type_label . ' installed successfully' );
                $fi['status'] = 'done';
                $job['done']++;

                // ── 5 + 6. التفعيل ────────────────────────────
                if ( ! $actual_theme ) {
                    error_log( $log_file . ' | [5] ACTIVATE_START — attempting plugin activation' );
                    $activated = $this->try_activate_installed_plugin( $filename, $upgrader );
                    if ( $activated ) {
                        error_log( $log_file . ' | [6] ACTIVATE_OK — plugin activated: ' . $activated );
                        $fi['activated']   = true;
                        $fi['plugin_file'] = $activated;
                    } else {
                        error_log( $log_file . ' | [6] ACTIVATE_FAIL — could not auto-activate, manual activation needed' );
                        $fi['activated']        = false;
                        $fi['activation_error'] = 'لم يتم التفعيل التلقائي — فعّل من صفحة الإضافات يدوياً';
                    }
                } else {
                    error_log( $log_file . ' | [5] ACTIVATE_START — attempting theme switch' );
                    $activated_theme = $this->try_activate_installed_theme( $filename );
                    if ( $activated_theme ) {
                        error_log( $log_file . ' | [6] ACTIVATE_OK — theme switched to: ' . $activated_theme );
                        $fi['activated']   = true;
                        $fi['plugin_file'] = $activated_theme;
                    } else {
                        error_log( $log_file . ' | [6] ACTIVATE_FAIL — could not auto-switch theme, manual activation needed' );
                        $fi['activated']        = false;
                        $fi['activation_error'] = 'لم يتم التفعيل التلقائي — فعّل من صفحة المظهر يدوياً';
                    }
                }
            }

            $job['files'][$idx] = $fi;
            update_option('wpl_bg_install_job', $job, false);
        }
        unset($fi);

        $final_done   = count( array_filter($job['files'], fn($f) => $f['status'] === 'done'  ) );
        $final_errors = count( array_filter($job['files'], fn($f) => $f['status'] === 'error' ) );
        error_log( $log_pfx . ' | ■ END — done=' . $final_done . ' errors=' . $final_errors );

        // ✅ احسب الـ errors بعد انتهاء اللوب
        $error_count = count( array_filter( $job['files'], fn($f) => $f['status'] === 'error' ) );
        $done_count  = count( array_filter( $job['files'], fn($f) => $f['status'] === 'done'  ) );

        $job['status']      = 'complete';
        $job['finished_at'] = time();
        $job['done']        = $done_count;
        $job['errors']      = $error_count;
        update_option('wpl_bg_install_job', $job, false);

        // ✅ أبلّغ السيرفر دايماً — حتى لو في errors
        // بنبعت تقرير بالملفات الفاشلة عشان رسالة الواتساب تكون واضحة
        if ( $order_number && ( $done_count > 0 || $error_count > 0 ) ) {
            $domain = parse_url( home_url(), PHP_URL_HOST );

            // كلمات تدل على companion/helper plugins (مش المنتج الأساسي)
            $companion_keywords = [ 'installer', 'updater', 'otgs', 'companion', 'helper', 'addon' ];

            $is_companion = function( $f ) use ( $companion_keywords ) {
                $name = strtolower( $f['label'] ?? $f['filename'] ?? '' );
                foreach ( $companion_keywords as $kw ) {
                    if ( strpos( $name, $kw ) !== false ) return true;
                }
                return false;
            };

            $failed_all       = array_filter( $job['files'], fn( $f ) => $f['status'] === 'error' );
            $failed_main      = array_filter( $failed_all, fn( $f ) => ! $is_companion( $f ) );
            $failed_companion = array_filter( $failed_all, fn( $f ) =>   $is_companion( $f ) );

            $fmt = fn( $f ) => ( $f['label'] ?? $f['filename'] ?? '' ) . ( ! empty($f['error']) ? ' (' . $f['error'] . ')' : '' );

            $failed_files           = array_values( array_map( $fmt, $failed_main ) );
            $failed_companion_files = array_values( array_map( $fmt, $failed_companion ) );

            // done_count و error_count يحسبوا المنتجات الأساسية بس
            $main_error_count = count( $failed_main );
            $main_done_count  = $done_count - count( $failed_companion );

            wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/mark-installed', [
                'headers'     => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Content-Type' => 'application/json' ],
                'body'        => wp_json_encode([
                    'order_number'           => $order_number,
                    'domain'                 => $domain,
                    'done_count'             => $main_done_count,
                    'error_count'            => $main_error_count,
                    'failed_files'           => $failed_files,
                    'failed_companion_files' => $failed_companion_files,
                ]),
                'timeout'     => 10, 'sslverify' => false, 'httpversion' => '1.1', 'blocking' => true,
            ]);
            delete_option( 'wpl_has_pending_request' );
        }
        return;
    }

    /* ====== جيب طلبات التفعيل الخاصة بالدومين ====== */
    public function ajax_fetch_my_requests() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'غير مصرح', 403 );

        $domain = parse_url( home_url(), PHP_URL_HOST );
        $url    = rtrim( WPL_SERVER_API_URL, '/' ) . '/my-requests?domain=' . urlencode( $domain );

        $response = wp_remote_get( $url, [
            'headers'     => [ 'X-WPL-Key' => WPL_SERVER_API_KEY ],
            'timeout'     => 15,
            'sslverify'   => false,
            'httpversion' => '1.1',
        ]);

        if ( is_wp_error( $response ) ) wp_send_json_error( 'تعذّر الاتصال.' );

        $body     = json_decode( wp_remote_retrieve_body( $response ), true );
        $requests = $body['requests'] ?? [];

        // لو مفيش pending — امسح الـ flag المحلي
        $has_pending = false;
        foreach ( $requests as $r ) {
            if ( $r['status'] === 'pending' ) { $has_pending = true; break; }
        }
        if ( ! $has_pending ) delete_option( 'wpl_has_pending_request' );

        wp_send_json_success( $requests );
    }

    /* ====== أبلّغ السيرفر إن التنصيب اكتمل ====== */
    public function ajax_mark_installed() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'غير مصرح', 403 );

        $order_number = sanitize_text_field( $_POST['order_number'] ?? '' );
        $domain       = parse_url( home_url(), PHP_URL_HOST );

        $response = wp_remote_post( rtrim( WPL_SERVER_API_URL, '/' ) . '/mark-installed', [
            'headers'   => [ 'X-WPL-Key' => WPL_SERVER_API_KEY, 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode([ 'order_number' => $order_number, 'domain' => $domain ]),
            'timeout'   => 10, 'sslverify' => false, 'httpversion' => '1.1', 'blocking' => true,
        ]);

        delete_option( 'wpl_has_pending_request' );
        wp_send_json_success();
    }

    /* ====== حالة البلجن ====== */
    /* ====== اكتشاف نوع الملف: theme أم plugin؟ ====== */

    /**
     * حاول تفعيل بلجن بعدة طرق (4 استراتيجيات)
     * بيرجع الـ plugin_file لو نجح التفعيل، أو null لو فشل
     *
     * الاستراتيجيات بالترتيب:
     *   1. plugin_info() من الـ Upgrader
     *   2. البحث بالـ filename في قائمة الـ plugins
     *   3. البحث بالـ folder اللي اتعمله unzip
     *   4. البحث بكل الـ plugins اللي اتغيّر created_at فيهم مؤخراً
     */
    private function try_activate_installed_plugin( $filename, $upgrader ) {
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // امسح cache البلجنز عشان ناخد أحدث قائمة
        wp_cache_delete( 'plugins', 'plugins' );

        $candidates = [];

        // ===== الاستراتيجية 1: plugin_info() =====
        if ( method_exists( $upgrader, 'plugin_info' ) ) {
            $pf = $upgrader->plugin_info();
            if ( $pf ) $candidates[] = $pf;
        }

        // ===== الاستراتيجية 2: find_existing_plugin_file =====
        $found = $this->find_existing_plugin_file( $filename );
        if ( $found && ! in_array( $found, $candidates, true ) ) {
            $candidates[] = $found;
        }

        // ===== الاستراتيجية 3: البحث بالـ result folder =====
        if ( method_exists( $upgrader, 'result' ) || isset( $upgrader->result ) ) {
            $upgrader_result = isset( $upgrader->result ) ? $upgrader->result : null;
            if ( is_array( $upgrader_result ) && ! empty( $upgrader_result['destination_name'] ) ) {
                $dest_folder = $upgrader_result['destination_name'];

                // دوّر على أي بلجن في المجلد ده
                $all_plugins = get_plugins();
                foreach ( $all_plugins as $pf => $data ) {
                    $plugin_dir = explode( '/', $pf )[0];
                    if ( $plugin_dir === $dest_folder && ! in_array( $pf, $candidates, true ) ) {
                        $candidates[] = $pf;
                    }
                }
            }
        }

        // ===== الاستراتيجية 4: آخر بلجن اتثبّت (آخر ملف اتعدّل) =====
        // لو كل اللي فوق فشل، نحاول نلاقي أحدث بلجن اتثبّت في آخر 60 ثانية
        if ( empty( $candidates ) ) {
            $all_plugins      = get_plugins();
            $plugin_dir       = WP_PLUGIN_DIR;
            $recent_threshold = time() - 60;

            foreach ( $all_plugins as $pf => $data ) {
                $file_path = $plugin_dir . '/' . $pf;
                if ( file_exists( $file_path ) && filemtime( $file_path ) >= $recent_threshold ) {
                    $candidates[] = $pf;
                }
            }
        }

        // ===== جرّب التفعيل بكل candidate =====
        foreach ( $candidates as $plugin_file ) {
            // لو مفعّل بالفعل، اعتبره نجح
            if ( is_plugin_active( $plugin_file ) ) {
                return $plugin_file;
            }

            // حاول التفعيل — بدون silent mode عشان نشوف الأخطاء
            $result = activate_plugin( $plugin_file, '', false, false );

            if ( ! is_wp_error( $result ) ) {
                // تأكّد فعلياً إنه اتفعّل
                wp_cache_delete( 'alloptions', 'options' );
                if ( is_plugin_active( $plugin_file ) ) {
                    return $plugin_file;
                }
            }

            // لو فشل، جرّب بالـ silent mode كـ last resort
            $result = activate_plugin( $plugin_file, '', false, true );
            if ( ! is_wp_error( $result ) ) {
                wp_cache_delete( 'alloptions', 'options' );
                if ( is_plugin_active( $plugin_file ) ) {
                    return $plugin_file;
                }
            }
        }

        return null;
    }

    /**
     * ابحث عن بلجن موجود بناءً على اسم الـ zip — ارجع plugin_file أو null
     */
    private function find_existing_plugin_file( $filename ) {
        if ( ! function_exists( 'get_plugins' ) )
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $raw_slug   = strtolower( preg_replace( '/\.zip$/i', '', $filename ) );
        $clean_slug = preg_replace( '/-[\d]+[\d.\-]*$/', '', $raw_slug );
        $short_slug = preg_replace( '/-(plugin|addon|pro|premium|sites|theme)$/i', '', $clean_slug );

        foreach ( get_plugins() as $pf => $data ) {
            $dir  = strtolower( explode( '/', $pf )[0] );
            $name = strtolower( $data['Name'] ?? '' );
            if (
                $dir === $clean_slug || $dir === $short_slug ||
                strpos( $clean_slug, $dir ) !== false ||
                strpos( $dir, $short_slug ) !== false ||
                ( $name && (
                    strpos( $name, $short_slug ) !== false ||
                    strpos( $short_slug, $name ) !== false
                ))
            ) {
                return $pf;
            }
        }
        return null;
    }

    /**
     * ابحث عن قالب موجود بناءً على اسم الـ zip — ارجع stylesheet slug أو null
     */
    private function find_existing_theme_slug( $filename ) {
        $base_slug = strtolower( preg_replace( '/[-._\s]+/', '-',
            preg_replace( '/\.[^.]+$/', '', pathinfo( $filename, PATHINFO_FILENAME ) )
        ));
        $base_slug = preg_replace( '/-[\d]+[\d.\-]*$/', '', $base_slug );

        foreach ( wp_get_themes( [ 'errors' => false ] ) as $slug => $theme ) {
            $sl = strtolower( $slug );
            if ( $sl === $base_slug || strpos( $sl, $base_slug ) !== false || strpos( $base_slug, $sl ) !== false ) {
                return $slug;
            }
        }
        return null;
    }

    /**
     * ✅ v3.3.2: فعّل القالب بعد التثبيت (switch_theme)
     * @return string|null اسم الـ slug لو نجح، null لو فشل
     */
    private function try_activate_installed_theme( $filename ) {
        // جيب slug القالب المثبت
        $slug = $this->find_existing_theme_slug( $filename );
        if ( ! $slug ) return null;

        // تأكد إن القالب صالح قبل التفعيل
        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() || $theme->errors() ) return null;

        // switch_theme بيحتاج switch_themes capability
        if ( ! function_exists( 'switch_theme' ) ) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        // احفظ القالب القديم قبل الاستبدال (عشان لو حصل خلل)
        $previous = get_option( 'stylesheet' );
        if ( $previous && $previous !== $slug ) {
            update_option( 'wpl_previous_theme', $previous, false );
        }

        switch_theme( $slug );

        // تحقق إن فعلاً اتفعّل
        $current = get_option( 'stylesheet' );
        return ( $current === $slug ) ? $slug : null;
    }

    private function detect_file_type( $tmp_path ) {
        // القرار: theme أم plugin
        // القاعدة: لو فيه style.css بـ "Theme Name:" وملفه مش بيحتوي plugin header → theme
        //         لو فيه plugin header (Plugin Name: في .php) → plugin بغض النظر
        $is_theme  = false;
        $is_plugin = false;

        if ( ! class_exists( 'ZipArchive' ) ) return false;

        $zip = new ZipArchive();
        if ( $zip->open( $tmp_path ) !== true ) return false;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name  = $zip->getNameIndex( $i );
            $depth = substr_count( $name, '/' );

            // style.css في العمق الأول فقط (مش nested)
            if ( $depth === 1 && preg_match( '/\/style\.css$/i', $name ) ) {
                $css = $zip->getFromIndex( $i );
                if ( $css && stripos( $css, 'Theme Name:' ) !== false ) {
                    $is_theme = true;
                }
            }

            // plugin header في .php في العمق الأول
            if ( $depth === 1 && preg_match( '/\.php$/i', $name ) ) {
                $php = $zip->getFromIndex( $i );
                // اقرأ أول 8KB بس
                if ( $php && stripos( substr( $php, 0, 8192 ), 'Plugin Name:' ) !== false ) {
                    $is_plugin = true;
                    break; // لو فيه plugin header → plugin قطعي
                }
            }
        }

        $zip->close();

        // لو فيه plugin header صريح → plugin مهما كان
        if ( $is_plugin ) return false;
        return $is_theme;
    }

    /* ====== حالة القالب ====== */
    public function get_theme_status( $zip_filename ) {
        // نظّف slug من اسم الـ ZIP: اشيل الامتداد والأرقام والنقاط
        $raw  = preg_replace( '/\.zip$/i', '', $zip_filename );
        $slug = strtolower( preg_replace( '/[._\s]+/', '-', $raw ) );
        // اشيل أرقام الإصدار من الآخر: e.g. astra-4-0-12 → astra
        $slug_no_ver = preg_replace( '/-[\d]+(?:-[\d]+)*$/', '', $slug );

        $active_theme = get_option( 'stylesheet', '' );
        $all_themes   = wp_get_themes( [ 'errors' => false ] );

        foreach ( $all_themes as $theme_slug => $theme ) {
            $tsl  = strtolower( $theme_slug );
            $tnam = strtolower( $theme->get('Name') ?? '' );

            if ( $tsl === $slug || $tsl === $slug_no_ver ||
                 strpos( $tsl, $slug_no_ver ) !== false ||
                 strpos( $slug_no_ver, $tsl ) !== false ||
                 ( $tnam && strpos( $tnam, $slug_no_ver ) !== false ) ) {
                $is_active = ( strtolower( $active_theme ) === $tsl );
                return [
                    'status'      => $is_active ? 'active' : 'installed',
                    'plugin_file' => $theme_slug,
                ];
            }
        }

        return [ 'status' => 'not_installed', 'plugin_file' => '' ];
    }

    public function get_plugin_status( $zip_filename ) {
        if ( ! function_exists( 'get_plugins' ) )
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        $slug           = strtolower( preg_replace( '/\.zip$/i', '', $zip_filename ) );

        foreach ( $all_plugins as $plugin_file => $data ) {
            $dir  = strtolower( explode( '/', $plugin_file )[0] );
            $name = strtolower( $data['Name'] ?? '' );

            if ( strpos( $dir, $slug ) !== false || strpos( $slug, $dir ) !== false
              || ( $name && strpos( $name, $slug ) !== false ) ) {
                return [
                    'status'      => in_array( $plugin_file, $active_plugins ) ? 'active' : 'installed',
                    'plugin_file' => $plugin_file,
                ];
            }
        }

        return [ 'status' => 'not_installed', 'plugin_file' => '' ];
    }
}
