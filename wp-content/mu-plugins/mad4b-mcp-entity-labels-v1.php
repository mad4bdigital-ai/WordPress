<?php
/**
 * Plugin Name: MAD4B MCP Entity Reference Labels V1
 * Version: 1.0.0
 * Description: Governed read-only batch ID + human name + type resolver for MCP consumers.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_MCP_Entity_Labels_V1', false)) {
final class MAD4B_MCP_Entity_Labels_V1 {
    const ABILITY = 'mad4b/entity-reference-resolve';
    const CONTRACT = 'mad4b.entity-reference-labels.v1';
    const MAX = 30;

    private static function supported_posts(): array {
        return [
            'tours-and-activities', 'properties', 'units', 'tour-rates',
            'property-rates', 'transport-rates', 'transportations',
            'transport-com', 'guides', 'mad4b_rate_plan'
        ];
    }

    private static function supported_terms(): array {
        return [
            'programs', 'package_levels', 'destinations', 'property-types',
            'room-type', 'room_type', 'board-type', 'duration-options',
            'tour-types', 'transportation-types', 'amenities'
        ];
    }

    private static function infer_type(string $field): array {
        $known = [
            'tour_id' => ['post', 'tours-and-activities'],
            'related_tour_id' => ['post', 'tours-and-activities'],
            'property_id' => ['post', 'properties'],
            'related_property_id' => ['post', 'properties'],
            'unit_id' => ['post', 'units'],
            'related_unit_id' => ['post', 'units'],
            'program_id' => ['taxonomy', 'programs'],
            'program_term_id' => ['taxonomy', 'programs'],
            'related_program_term_id' => ['taxonomy', 'programs'],
            'related_program_terms' => ['taxonomy', 'programs'],
            'tier_id' => ['taxonomy', 'package_levels'],
            'package_level_id' => ['taxonomy', 'package_levels'],
            'destination_id' => ['taxonomy', 'destinations'],
            'rate_id' => ['post', 'auto_rate'],
            'transport_id' => ['post', 'transportations'],
        ];
        return $known[$field] ?? ['', ''];
    }

    public static function register(): void {
        if (!function_exists('wp_register_ability') ||
            (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY))) return;
        wp_register_ability(self::ABILITY, [
            'label' => 'Resolve MAD4B IDs to Names and Types',
            'description' => 'Batch resolve governed Tour, Property, Unit, Rate, Transport or Taxonomy IDs to ID+name+type+language+status. No IDs are edited. Supply entity_type or a known reference field; ambiguous IDs are not guessed.',
            'category' => 'mad4b-read',
            'execute_callback' => [self::class, 'execute'],
            'permission_callback' => [self::class, 'can_read'],
            'input_schema' => [
                'type' => 'object',
                'required' => ['references'],
                'properties' => [
                    'references' => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX,
                        'items' => [
                            'type' => 'object',
                            'required' => ['id'],
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 1],
                                'entity_type' => ['type' => 'string', 'maxLength' => 64],
                                'entity_kind' => ['type' => 'string', 'enum' => ['post', 'taxonomy']],
                                'field' => ['type' => 'string', 'maxLength' => 80],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'language' => ['type' => 'string', 'pattern' => '^[a-z]{2}(?:-[A-Za-z]{2})?$', 'maxLength' => 5],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'meta' => [
                'public' => false,
                'show_in_rest' => false,
                'mcp' => ['public' => false, 'type' => 'tool', 'surface' => 'read'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function can_read($input = null): bool {
        if (!class_exists('MAD4B_SCP_Policy', false) || !MAD4B_SCP_Policy::can_read()) return false;
        $input = is_array($input) ? $input : [];
        return isset($input['references']) &&
            is_array($input['references']) &&
            count($input['references']) >= 1 &&
            count($input['references']) <= self::MAX;
    }

    private static function in_language(string $language, callable $read) {
        if ($language === '') return $read();
        $before = (string)apply_filters('wpml_current_language', '');
        if ($before === $language || $before === '') return $read();
        do_action('wpml_switch_language', $language);
        try { return $read(); }
        finally { do_action('wpml_switch_language', $before); }
    }

    private static function lang_of_post(WP_Post $post): ?string {
        $lang = apply_filters('wpml_element_language_code', null, [
            'element_id' => $post->ID, 'element_type' => 'post_' . $post->post_type,
        ]);
        return is_string($lang) && $lang !== '' ? $lang : null;
    }

    private static function lang_of_term(WP_Term $term): ?string {
        $lang = apply_filters('wpml_element_language_code', null, [
            'element_id' => $term->term_taxonomy_id, 'element_type' => 'tax_' . $term->taxonomy,
        ]);
        return is_string($lang) && $lang !== '' ? $lang : null;
    }

    private static function unavailable(int $id, string $kind, string $type, string $reason): array {
        return [
            'id' => $id,
            'resolved_id' => null,
            'entity_kind' => $kind,
            'type' => $type,
            'name' => null,
            'display_label' => null,
            'language' => null,
            'status' => 'unavailable',
            'resolution_status' => $reason,
        ];
    }

    private static function target_language(array $input): string {
        $v = isset($input['language']) ? strtolower(trim((string)$input['language'])) : '';
        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D', $v) ? $v : '';
    }

    private static function resolve(array $ref, string $language): array {
        $id = absint($ref['id'] ?? 0);
        $field = sanitize_key((string)($ref['field'] ?? ''));
        $type = sanitize_key((string)($ref['entity_type'] ?? ''));
        $kind = (string)($ref['entity_kind'] ?? '');
        if ($id < 1) return self::unavailable($id, $kind, $type, 'invalid_id');

        if ($type === '') {
            [$inferred_kind, $inferred_type] = self::infer_type($field);
            $type = $inferred_type;
            if ($kind === '') $kind = $inferred_kind;
        }
        if ($kind === '') {
            if (in_array($type, self::supported_posts(), true) || $type === 'auto_rate') $kind = 'post';
            elseif (in_array($type, self::supported_terms(), true)) $kind = 'taxonomy';
        }
        if ($kind === '') return self::unavailable($id, '', $type, 'type_required_for_ambiguous_id');
        if ($kind !== 'post' && $kind !== 'taxonomy')
            return self::unavailable($id, $kind, $type, 'entity_kind_not_supported');
        if ($kind === 'post') {
            if ($type !== 'auto_rate' && $type !== '' && !in_array($type, self::supported_posts(), true))
                return self::unavailable($id, $kind, $type, 'post_type_not_allowlisted');
            $post = get_post($id);
            if (!$post instanceof WP_Post || !in_array($post->post_type, self::supported_posts(), true))
                return self::unavailable($id, $kind, $type, 'not_found_or_not_allowlisted');
            if ($type !== '' && $type !== 'auto_rate' && $type !== $post->post_type)
                return self::unavailable($id, $kind, $type, 'post_type_mismatch');
            if ($type === 'auto_rate' && !in_array($post->post_type, ['tour-rates', 'property-rates', 'transport-rates'], true))
                return self::unavailable($id, $kind, $type, 'rate_type_mismatch');
            if (!current_user_can('read_post', $post->ID))
                return self::unavailable($id, $kind, $type, 'unavailable_or_denied');
            $result = $post;
            $fallback = false;
            if ($language !== '') {
                $mapped = (int)apply_filters('wpml_object_id', $id, $post->post_type, false, $language);
                if ($mapped > 0) {
                    $translated = self::in_language($language, static fn() => get_post($mapped));
                    if ($translated instanceof WP_Post && $translated->post_type === $post->post_type &&
                        current_user_can('read_post', $translated->ID)) {
                        $result = $translated;
                    } else $fallback = true;
                } else $fallback = true;
            }
            $name = trim(html_entity_decode(wp_strip_all_tags((string)$result->post_title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '') {
                $name = null;
            }
            $type_label = get_post_type_object($post->post_type);
            $friendly = $type_label ? (string)$type_label->labels->singular_name : $post->post_type;
            return [
                'id' => $id,
                'resolved_id' => (int)$result->ID,
                'entity_kind' => 'post',
                'type' => $post->post_type,
                'type_label' => $friendly,
                'name' => $name,
                'display_label' => $name === null ? null : $id . ' — ' . $name,
                'language' => self::lang_of_post($result),
                'status' => (string)$result->post_status,
                'resolution_status' => $name !== null ? 'resolved' : 'name_missing',
                'translation_fallback' => $fallback,
            ];
        }
        if (!in_array($type, self::supported_terms(), true) || !taxonomy_exists($type))
            return self::unavailable($id, 'taxonomy', $type, 'taxonomy_not_allowlisted');
        $tax = get_taxonomy($type);
        if (!$tax->public && !current_user_can($tax->cap->manage_terms))
            return self::unavailable($id, 'taxonomy', $type, 'unavailable_or_denied');
        $term = get_term($id, $type);
        if (!$term instanceof WP_Term)
            return self::unavailable($id, 'taxonomy', $type, 'term_not_found');
        $result = $term;
        $fallback = false;
        if ($language !== '') {
            $mapped = (int)apply_filters('wpml_object_id', $id, $type, false, $language);
            if ($mapped > 0) {
                $translated = self::in_language($language, static fn() => get_term($mapped, $type));
                if ($translated instanceof WP_Term) $result = $translated;
                else $fallback = true;
            } else $fallback = true;
        }
        $name = trim(html_entity_decode(wp_strip_all_tags((string)$result->name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = $name !== '' ? $name : null;
        return [
            'id' => $id,
            'resolved_id' => (int)$result->term_id,
            'entity_kind' => 'taxonomy',
            'type' => $type,
            'type_label' => (string)$tax->labels->singular_name,
            'name' => $name,
            'display_label' => $name === null ? null : $id . ' — ' . $name,
            'language' => self::lang_of_term($result),
            'status' => null,
            'resolution_status' => $name !== null ? 'resolved' : 'name_missing',
            'translation_fallback' => $fallback,
        ];
    }

    public static function execute($input = []): array {
        $input = is_array($input) ? $input : [];
        $refs = $input['references'] ?? [];
        if (!is_array($refs) || count($refs) < 1 || count($refs) > self::MAX)
            return ['contract' => self::CONTRACT, 'error' => 'reference_batch_out_of_bounds', 'items' => []];
        $language = self::target_language($input);
        $out = [];
        foreach ($refs as $ref) {
            $out[] = self::resolve(is_array($ref) ? $ref : [], $language);
        }
        return [
            'contract' => self::CONTRACT,
            'site' => home_url(),
            'requested_language' => $language !== '' ? $language : null,
            'count' => count($out),
            'resolved_count' => count(array_filter($out, static fn($x) => $x['resolution_status'] === 'resolved')),
            'items' => $out,
            'read_only' => true,
            'mutation_performed' => false,
            'labels_do_not_replace_ids' => true,
        ];
    }
}
add_action('wp_abilities_api_init', ['MAD4B_MCP_Entity_Labels_V1', 'register'], 26);
}

