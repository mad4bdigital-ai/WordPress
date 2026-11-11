<?php
/**
 * Source-owned extra selection presentation. Read-only: no financial preview
 * endpoints, no cart mutation, no supplier booking. WPML controls active locale.
 */
if (!defined('ABSPATH')) return;

if (!function_exists('mad4b_shared_extra_ui_label_v1')) {
    function mad4b_shared_extra_ui_label_v1(string $kind): string {
        $english = ['select' => 'Select extra', 'remove' => 'Remove selected extra'];
        if (!isset($english[$kind])) return '';
        $key = $english[$kind];
        $translated = function_exists('icl_t')
            ? apply_filters('wpml_translate_single_string', $key, 'MAD4B Extra Selection UI', $key)
            : $key;
        if (is_string($translated) && $translated !== $key && $translated !== '') return $translated;
        $language = sanitize_key((string)apply_filters('wpml_current_language', null));
        $fallbacks = [
            'es' => ['select' => 'Seleccionar extra', 'remove' => 'Quitar extra seleccionado'],
            'fr' => ['select' => 'Sélectionner une option', 'remove' => 'Retirer l’option sélectionnée'],
            'de' => ['select' => 'Extra auswählen', 'remove' => 'Ausgewähltes Extra entfernen'],
            'it' => ['select' => 'Seleziona extra', 'remove' => 'Rimuovi extra selezionato'],
            'ar' => ['select' => 'اختر خدمة إضافية', 'remove' => 'إزالة الخدمة الإضافية'],
        ];
        return $fallbacks[$language][$kind] ?? $key;
    }
}

final class MAD4B_Extra_Selection_Presentation_V1 {
    private static bool $fallback = false;

    public static function init(): void {
        if (!class_exists('MAD4B_SCP_Site_Profile', false)) return;
        $profile = MAD4B_SCP_Site_Profile::status();
        if (empty($profile['configured']) || empty($profile['origin_match']) ||
            empty($profile['environment_match']) || empty($profile['deployment_binding_match'])) return;
        foreach (['select','remove'] as $kind) {
            $english = $kind === 'select' ? 'Select extra' : 'Remove selected extra';
            do_action('wpml_register_single_string', 'MAD4B Extra Selection UI', $english, $english);
        }
        // Canonical presentation always owns the shortcode; financial REST
        // providers remain independently gated by the Commerce Runtime.
        add_shortcode('extra_select_actions', [__CLASS__, 'render']);
        self::$fallback = true;
        add_action('wp_enqueue_scripts', [__CLASS__, 'scripts'], 70);
    }

    private static function canonicalTourId(int $id): int {
        $default = (string)apply_filters('wpml_default_language', '');
        if ($default === '') return $id;
        $mapped = apply_filters('wpml_object_id', $id, 'tours-and-activities', false, $default);
        return is_numeric($mapped) && (int)$mapped > 0 ? (int)$mapped : $id;
    }

    private static function allowedExtraIds(int $parent): array {
        $raw = get_post_meta($parent, 'tour_optional_extras', true);
        if (is_string($raw)) $raw = maybe_unserialize($raw);
        if (is_string($raw)) $raw = explode(',', $raw);
        if (!is_array($raw)) return [];
        $ids = [];
        foreach ($raw as $item) {
            if ((is_int($item) || is_string($item)) && ctype_digit((string)$item) && (int)$item > 0) {
                $ids[] = (int)$item;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function render(): string {
        global $wp_the_query;
        if (!$wp_the_query instanceof WP_Query ||
            !$wp_the_query->is_singular('tours-and-activities')) return '';
        $parent = (int)$wp_the_query->get_queried_object_id();
        $extra = (int)get_the_ID();
        if (!$parent || !$extra || $parent === $extra ||
            get_post_type($parent) !== 'tours-and-activities' ||
            get_post_type($extra) !== 'tours-and-activities' ||
            get_post_status($parent) !== 'publish' ||
            get_post_status($extra) !== 'publish' ||
            !in_array((string)get_post_meta($extra, 'tour_mode', true),
                ['activity','extra_trip'], true)) return '';

        // The displayed Extra must belong to the current WPML language. An
        // untranslated source listing item is never surfaced as a translated UI.
        $currentLanguage = (string)apply_filters('wpml_current_language', '');
        $languageDetails = apply_filters('wpml_post_language_details', null, $extra);
        if ($currentLanguage !== '' && is_array($languageDetails) &&
            !empty($languageDetails['language_code']) &&
            (string)$languageDetails['language_code'] !== $currentLanguage) return '';

        $canonicalParent = self::canonicalTourId($parent);
        $canonicalExtra = self::canonicalTourId($extra);

        // Target-language meta wins when explicitly configured. Only an absent
        // translated field may inherit its verified canonical parent field.
        $statusOwner = metadata_exists('post', $parent, 'tour_extras_status')
            ? $parent : $canonicalParent;
        if ((string)get_post_meta($statusOwner, 'tour_extras_status', true) !== '1') return '';

        $optionsOwner = metadata_exists('post', $parent, 'tour_optional_extras')
            ? $parent : $canonicalParent;
        $ids = self::allowedExtraIds($optionsOwner);
        if (!$ids) return '';
        $allowed = false;
        foreach ($ids as $id) {
            if ($id === $extra || $id === $canonicalExtra ||
                self::canonicalTourId($id) === $canonicalExtra) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) return '';

        return '<div class="shared-extra-actions" data-extra-actions="'.esc_attr((string)$extra).
            '" data-parent-tour="'.esc_attr((string)$parent).
            '" data-selected="false">'.
            '<button type="button" data-extra-select="'.esc_attr((string)$extra).
            '" aria-pressed="false">'.esc_html(mad4b_shared_extra_ui_label_v1('select')).'</button>'.
            '<button type="button" data-extra-remove="'.esc_attr((string)$extra).
            '" aria-pressed="false" hidden>'.esc_html(mad4b_shared_extra_ui_label_v1('remove')).'</button>'.
            '<output aria-live="polite"></output></div>';
    }

    public static function scripts(): void {
        if (!self::$fallback || !is_singular('tours-and-activities')) return;
        $file = dirname(__DIR__,2).'/assets/extra-selection-ui.js';
        if (!is_readable($file)) return;
        // Keep this short event-handler script at its durable Commerce URL.
        // LiteSpeed can regenerate a minified hash while cached HTML still
        // refers to the retired filename (intermittent 404 / HTML MIME error).
        add_filter('litespeed_optimize_js_excludes', [__CLASS__, 'exclude_litespeed_js'], 200);
        add_filter('script_loader_tag', [__CLASS__, 'stable_script_tag'], 500, 3);
        wp_enqueue_script('mad4b-extra-selection-ui',plugins_url('assets/extra-selection-ui.js',dirname(__DIR__,2).'/mad4b-commerce.php'),[],(string)filemtime($file),true);
        // Original inline Lab handler is deprecated; prevent duplicate events
        // while retaining its commercial preview and REST providers.
        wp_add_inline_script('mad4b-extra-selection-ui', 'window.sharedExtraSelectionReady=true;', 'before');
        // The public From price mirrors the selected, already-rendered Rate card.
        // No booking calculations or authority changes. Durable JS source avoids
        // regenerating LiteSpeed hashes in cached tour HTML.
        $price_bridge = dirname(__DIR__,2).'/assets/selected-rate-price-bridge.js';
        if (is_readable($price_bridge)) {
            wp_enqueue_script('mad4b-selected-rate-price-bridge',
                plugins_url('assets/selected-rate-price-bridge.js',dirname(__DIR__,2).'/mad4b-commerce.php'),
                ['mad4b-extra-selection-ui'],(string)filemtime($price_bridge),true);
        }
    }

    public static function exclude_litespeed_js($excludes) {
        if (!is_array($excludes)) $excludes = [];
        $excludes[] = 'mad4b-commerce/assets/extra-selection-ui.js';
        $excludes[] = 'extra-selection-ui.js';
        $excludes[] = 'mad4b-commerce/assets/selected-rate-price-bridge.js';
        $excludes[] = 'selected-rate-price-bridge.js';
        return array_values(array_unique($excludes));
    }

    public static function stable_script_tag(string $tag, string $handle, string $src): string {
        $stable_assets = [
            'mad4b-extra-selection-ui' => '/mad4b-commerce/assets/extra-selection-ui.js',
            'mad4b-selected-rate-price-bridge' => '/mad4b-commerce/assets/selected-rate-price-bridge.js',
        ];
        if (!isset($stable_assets[$handle]) || strpos($src, $stable_assets[$handle]) === false) return $tag;
        if (strpos($tag, 'data-no-optimize=') === false) {
            $tag = preg_replace('/<script\b/i', '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false"', $tag, 1);
        }
        return $tag;
    }
}
add_action('init',['MAD4B_Extra_Selection_Presentation_V1','init'],60);

/**
 * Native, non-mutating Company Extras discovery.
 * Explicit Source/Target execution: wp company-extras reconcile --format=json
 * No user impersonation, finance writes, WPML language switching or fixtures.
 */
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI', false)) {
    WP_CLI::add_command('company-extras reconcile', static function($args, $assoc_args): void {
        if (!empty($assoc_args['apply']) || !empty($assoc_args['write'])) {
            WP_CLI::error('COMPANY_EXTRAS_RECONCILE_PLAN_ONLY');
        }
        $profile = class_exists('MAD4B_SCP_Site_Profile', false)
            ? MAD4B_SCP_Site_Profile::status() : [];
        $ready = !empty($profile['configured']) && !empty($profile['origin_match'])
            && !empty($profile['environment_match']) && !empty($profile['deployment_binding_match']);
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        $active = is_array($languages) ? array_values(array_map('strval', array_keys($languages)))
            : [(string)apply_filters('wpml_default_language', get_locale())];
        // WPML can scope get_posts even with suppress_filters. A bounded,
        // read-only native SQL inventory covers every site-owned language.
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
             WHERE p.post_type=%s AND p.post_status IN ('publish','draft','pending','private')
               AND m.meta_key=%s AND m.meta_value=%s
             ORDER BY p.ID ASC LIMIT 501",
            'tours-and-activities','tour_extras_status','1'
        )));
        if (count($ids) > 500) WP_CLI::error('EXTRAS_DISCOVERY_LIMIT_EXCEEDED_USE_PAGINATED_NATIVE_PROVIDER');
        $rows = [];
        $eligible = 0;
        $draft = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $post = get_post($id);
            if (!$post) continue;
            $lang = apply_filters('wpml_post_language_details', null, $id);
            $language = is_array($lang) ? (string)($lang['language_code'] ?? '') : '';
            $raw = get_post_meta($id, 'tour_optional_extras', true);
            if (is_string($raw)) $raw = maybe_unserialize($raw);
            if (is_string($raw)) $raw = explode(',', $raw);
            $references = [];
            foreach (is_array($raw) ? $raw : [] as $entry) {
                if (!is_scalar($entry) || !ctype_digit((string)$entry)) continue;
                $child = (int)$entry;
                if ($child > 0) {
                    $childDetails = apply_filters('wpml_post_language_details', null, $child);
                    $references[] = [
                        'post_id' => $child, 'published' => get_post_status($child) === 'publish',
                        'language' => is_array($childDetails) ? ($childDetails['language_code'] ?? null) : null,
                        'mode' => (string)get_post_meta($child, 'tour_mode', true),
                    ];
                }
            }
            if ($post->post_status !== 'publish') $draft++;
            $valid = $post->post_status === 'publish' &&
                count($references) > 0 &&
                count(array_filter($references, static fn($v) =>
                    $v['published'] && in_array($v['mode'],['activity','extra_trip'],true))) > 0;
            if ($valid) $eligible++;
            $rows[] = [
                'post_id' => $id, 'slug' => $post->post_name,
                'status' => $post->post_status, 'language' => $language,
                'linked_extra_count' => count($references),
                'eligible_listing_candidate' => $valid,
                'linked_extras' => array_slice($references,0,15),
            ];
        }
        $js = dirname(__DIR__, 2).'/assets/extra-selection-ui.js';
        $registered = shortcode_exists('extra_select_actions');
        $result = [
            'contract' => 'mad4b.company-extras-native-reconcile-plan.v1',
            'mode' => 'plan', 'canonical_origin' => untrailingslashit(home_url('/')),
            'site_uuid' => $profile['site_uuid'] ?? null,
            'environment' => wp_get_environment_type(),
            'profile_ready' => $ready, 'active_languages' => $active,
            'shortcode_registered' => $registered,
            'presentation_script_present' => is_readable($js),
            'script_sha256' => is_readable($js) ? hash_file('sha256', $js) : null,
            'commerce_runtime_enabled' => defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
                && MAD4B_COMMERCE_RUNTIME_ENABLED === true,
            'quote_provider_functions' => [
                'extra_source_quote' => function_exists('shared_product_extra_source_quote'),
                'optional_tour_allowlist' => function_exists('shared_product_optional_tour_ids_for_tour'),
                'booking_preview' => function_exists('shared_product_booking_v2_preview'),
                'source_pricing_resolver' => function_exists('shared_product_resolve_price_sources'),
            ],
            'readonly_quote_runtime' => class_exists('MAD4B_Extra_Readonly_Quote_Runtime_V1', false)
                ? MAD4B_Extra_Readonly_Quote_Runtime_V1::status() : [
                  'status' => 'SOURCE_PRESENTATION_PROVIDER_NOT_LOADED',
                  'read_only_preview_dispatch_available' => false,
                  'commercial_pricing_approved' => false,
                ],
            'enabled_tours_total' => count($ids),
            'enabled_tours_not_published' => $draft,
            'eligible_listing_candidates' => $eligible,
            'rows' => $rows,
            'data_writes' => 0, 'financial_writes' => 0,
            'is_publication_receipt' => false,
            'status' => !$ready || !$registered || !is_readable($js)
                ? 'RUNTIME_RECONCILE_REQUIRED'
                : ($eligible === 0 ? 'NO_PUBLISHED_EXTRAS_CONFIGURED' : 'ELIGIBILITY_AND_BROWSER_TEST_REQUIRED'),
        ];
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }, ['shortdesc' => 'Read-only multilingual Extras runtime and content inventory']);
}
