<?php
/**
 * Plugin Name: MAD4B Property Meal Base Policy
 * Description: Site-neutral meal base resolver: BB globally, AI for Hurghada/Sharm hotels, FB for cruises, contract rates authoritative.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Hotel_Meal_Base_Policy_V1', false)) {
final class MAD4B_Hotel_Meal_Base_Policy_V1 {
    const OPTION = 'mad4b_hotel_meal_base_policy_v1';
    const OVERRIDE = 'prop_meal_base_override';
    const ALLOWED = ['room-only','bed-breakfast','half-board','full-board','all-inclusive'];

    public static function defaults(): array {
        return [
            'schema' => 1,
            'global_board' => 'bed-breakfast',
            'cruise_board' => 'full-board',
            'destinations' => ['hurghada' => 'all-inclusive', 'sharm-el-sheikh' => 'all-inclusive'],
        ];
    }

    public static function config() {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) return new WP_Error('meal_policy_config_invalid');
        $cfg = array_replace_recursive(self::defaults(), $stored);
        if ($cfg['schema'] !== 1 || !in_array($cfg['global_board'], self::ALLOWED, true)
            || !in_array($cfg['cruise_board'], self::ALLOWED, true)
            || !is_array($cfg['destinations'])) return new WP_Error('meal_policy_config_invalid');
        foreach ($cfg['destinations'] as $slug => $board) {
            if (!is_string($slug) || !preg_match('/^[a-z0-9-]{1,80}$/', $slug)
                || !in_array($board, self::ALLOWED, true)) return new WP_Error('meal_policy_config_invalid');
        }
        return $cfg;
    }

    private static function term_board(string $slug) {
        if (!in_array($slug, self::ALLOWED, true)) return new WP_Error('meal_policy_board_invalid');
        $term = get_term_by('slug', $slug, 'board-type');
        return $term instanceof WP_Term ? $term : new WP_Error('meal_policy_board_term_missing', $slug);
    }

    private static function property_destinations(int $id): array {
        $ids = wp_get_post_terms($id, 'destinations', ['fields' => 'ids']);
        if (is_wp_error($ids)) return [];
        $slugs = [];
        $default_language = apply_filters('wpml_default_language', null);
        foreach ($ids as $tid) {
            $candidates = [(int)$tid];
            if (is_string($default_language) && $default_language !== '') {
                $canonical = apply_filters('wpml_object_id', (int)$tid, 'destinations', true, $default_language);
                if (is_numeric($canonical) && (int)$canonical) $candidates[] = (int)$canonical;
            }
            foreach (array_unique($candidates) as $candidate) {
                $ancestors = array_merge([$candidate], get_ancestors($candidate, 'destinations', 'taxonomy'));
                foreach ($ancestors as $ancestor) {
                    $term = get_term((int)$ancestor, 'destinations');
                    if ($term instanceof WP_Term) $slugs[$term->slug] = true;
                }
            }
        }
        return array_keys($slugs);
    }

    public static function resolve(int $property_id, int $rate_id = 0) {
        if ($property_id < 1 || get_post_type($property_id) !== 'properties')
            return new WP_Error('meal_policy_property_invalid');
        // Property type takes precedence over destination; cruises use FB even within resort cities.
        $types = wp_get_post_terms($property_id, 'property-types', ['fields' => 'ids']);
        if (is_wp_error($types)) return $types;
        $hotel = false; $cruise = false;
        foreach ($types as $tid) {
            $lineage = array_merge([(int)$tid], get_ancestors((int)$tid, 'property-types', 'taxonomy'));
            foreach ($lineage as $member) {
                $term = get_term((int)$member, 'property-types');
                if (!($term instanceof WP_Term)) continue;
                if ($term->slug === 'hotel') $hotel = true;
                if ($term->slug === 'cruise') $cruise = true;
            }
        }
        if (!$cruise && !$hotel) return new WP_Error('meal_policy_property_type_unclassified');
        $cfg = self::config();
        if (is_wp_error($cfg)) return $cfg;
        $base = $cruise ? $cfg['cruise_board'] : $cfg['global_board'];
        $source = $cruise ? 'property_type_policy' : 'global_default';
        $source_ref = $cruise ? 'cruise' : null;
        // Resort destination policy applies to hotels, never to cruises.
        if (!$cruise) {
            $destinations = self::property_destinations($property_id);
            foreach ($cfg['destinations'] as $slug => $board) {
                if (in_array($slug, $destinations, true)) {
                    $base = $board; $source = 'destination_policy'; $source_ref = $slug;
                    break;
                }
            }
        }

        $property_override = get_post_meta($property_id, self::OVERRIDE, true);
        if ($property_override !== '' && $property_override !== false) {
            if (!is_string($property_override) || is_wp_error(self::term_board($property_override)))
                return new WP_Error('meal_policy_property_override_invalid');
            $base = $property_override; $source = 'property_override'; $source_ref = $property_id;
        }
        $recommended = $base;
        $recommended_source = $source;
        $rate_declared = false;
        if ($rate_id > 0) {
            if (get_post_type($rate_id) !== 'property-rates'
                || (int)get_post_meta($rate_id, 'prop_related_property_id', true) !== $property_id)
                return new WP_Error('meal_policy_rate_relation_invalid');
            $declared = get_post_meta($rate_id, 'prop_board_type', true);
            if ($declared !== '' && $declared !== false) {
                if (!ctype_digit((string)$declared) || (int)$declared < 1)
                    return new WP_Error('meal_policy_rate_board_invalid');
                $term = get_term((int)$declared, 'board-type');
                if (!($term instanceof WP_Term) || is_wp_error(self::term_board($term->slug)))
                    return new WP_Error('meal_policy_rate_board_invalid');
                $base = $term->slug; $source = 'rate_contract_declared'; $source_ref = $rate_id;
                $rate_declared = true;
            }
        }
        $term = self::term_board($base);
        if (is_wp_error($term)) return $term;
        return [
            'property_id' => $property_id, 'rate_id' => $rate_id ?: null,
            'base_board' => $base, 'board_term_id' => (int)$term->term_id,
            'source' => $source, 'source_ref' => $source_ref,
            'inherited_base' => $recommended, 'inherited_source' => $recommended_source,
            'rate_contract_declared' => $rate_declared,
            'rate_differs_from_inherited_policy' => $rate_declared && $base !== $recommended,
            'requires_supplier_board_confirmation' => !$rate_declared,
            'price_mutation_performed' => false, 'supplier_price_verified' => false,
            'checkout_authorized' => false, 'policy_schema' => 1,
        ];
    }

    public static function metabox(WP_Post $post): void {
        $result = self::resolve((int)$post->ID);
        $override = (string)get_post_meta($post->ID, self::OVERRIDE, true);
        wp_nonce_field('mad4b_meal_base_edit_' . $post->ID, 'mad4b_meal_base_nonce');
        echo '<p><label for="mad4b_meal_base_override">Meal base override (contract exception):</label></p>';
        echo '<select name="mad4b_meal_base_override" id="mad4b_meal_base_override" style="width:100%">';
        echo '<option value="">Inherit type / destination / global policy</option>';
        foreach (self::ALLOWED as $slug) {
            $term = self::term_board($slug);
            if (is_wp_error($term)) continue;
            echo '<option value="' . esc_attr($slug) . '"' . selected($override, $slug, false) . '>' . esc_html($term->name) . '</option>';
        }
        echo '</select>';
        if (is_wp_error($result)) {
            echo '<p><strong>Policy requires review:</strong> ' . esc_html($result->get_error_code()) . '</p>';
        } else {
            $term = self::term_board($result['base_board']);
            echo '<p><strong>Effective base:</strong> ' . esc_html(is_wp_error($term) ? $result['base_board'] : $term->name)
                . '<br><small>Source: ' . esc_html($result['source']) . '</small></p>';
        }
        echo '<p><small>This is a base-plan selection policy. It does not change an existing supplier rate or certify a meal cost.</small></p>';
    }

    public static function save(int $post_id, WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        $type_check = self::resolve($post_id);
        if (is_wp_error($type_check) && in_array($type_check->get_error_code(), [
            'meal_policy_property_type_unclassified'
        ], true)) return;
        if (!isset($_POST['mad4b_meal_base_nonce'], $_POST['mad4b_meal_base_override'])
            || !is_scalar($_POST['mad4b_meal_base_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_meal_base_nonce'])), 'mad4b_meal_base_edit_' . $post_id)
            || !current_user_can('edit_post', $post_id)) return;
        $value = sanitize_key(wp_unslash((string)$_POST['mad4b_meal_base_override']));
        if ($value === '') delete_post_meta($post_id, self::OVERRIDE);
        elseif (!is_wp_error(self::term_board($value))) update_post_meta($post_id, self::OVERRIDE, $value);
    }

    public static function boot(): void {
        add_action('add_meta_boxes_properties', static function ($post) {
            if (!($post instanceof WP_Post)) return;
            $type_check = self::resolve((int)$post->ID);
            if (is_wp_error($type_check) && in_array($type_check->get_error_code(), [
                'meal_policy_property_type_unclassified'
            ], true)) return;
            add_meta_box('mad4b_hotel_meal_base', 'Property Meal Plan Base', [self::class, 'metabox'], 'properties', 'side', 'default');
        });
        add_action('save_post_properties', [self::class, 'save'], 10, 2);
        add_filter('abk_accommodation_rules_registry', static function ($registry) {
            if (is_array($registry)) $registry['hotel_meal_base_policy'] = [
                'state' => 'configured_recommendation_only', 'phase' => 'base',
                'cost_verified' => false, 'checkout_authorized' => false
            ];
            return $registry;
        });
        add_action('rest_api_init', static function () {
            register_rest_route('mad4b-pricing/v1', '/hotel-meal-base', [
                'methods' => 'GET',
                'permission_callback' => static function ($r) {
                    $id = absint($r->get_param('property_id'));
                    return $id > 0 && current_user_can('edit_post', $id);
                },
                'callback' => static function ($r) {
                    $result = self::resolve(absint($r->get_param('property_id')), absint($r->get_param('rate_id')));
                    return is_wp_error($result) ? $result : rest_ensure_response($result);
                },
            ]);
        });
    }
}
MAD4B_Hotel_Meal_Base_Policy_V1::boot();
}
