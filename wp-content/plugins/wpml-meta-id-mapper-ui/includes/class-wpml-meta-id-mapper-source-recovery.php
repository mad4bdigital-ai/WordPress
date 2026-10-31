<?php
/**
 * Source-backed WPML ID reference reconciliation.
 *
 * Site-neutral: no hard-coded IDs, slugs, company domain, languages,
 * pricing schema, or write privileges. Rules come from this site's
 * WPML Meta ID Mapper, source post meta, WPML translations and optional
 * site-specific validation policy.
 */
defined('ABSPATH') || exit;

final class WPML_Meta_ID_Mapper_Source_Recovery {
    public const SOURCE_POINTER = '_wpml_mapper_source_post_id';
    public const POLICY_OPTION = 'wpml_meta_id_mapper_recovery_policy_v1';

    private static function error(string $code): WP_Error {
        return new WP_Error('wpml_mapper_recovery_' . $code);
    }

    private static function configured_rule($post_type, $meta_key) {
        foreach ((array) get_option('wpml_meta_id_mapper_rules', []) as $rule) {
            if (!is_array($rule)) continue;
            if (($rule['post_type'] ?? null) === $post_type &&
                ($rule['meta_key'] ?? null) === $meta_key &&
                ($rule['type'] ?? '') === 'post' &&
                !empty($rule['object_type'])) {
                return $rule;
            }
        }
        return null;
    }

    private static function policy($type, $key): array {
        $policies = get_option(self::POLICY_OPTION, []);
        $specific = (array) ($policies[$type][$key] ?? []);
        return [
            'match_fields' => array_values(array_filter(array_map('sanitize_key',
                (array) ($specific['match_fields'] ?? [])))),
            'mirror_meta_key' => sanitize_key((string) ($specific['mirror_meta_key'] ?? '')),
            'expiry_meta_key' => sanitize_key((string) ($specific['expiry_meta_key'] ?? '')),
            'stop_sale_meta_key' => sanitize_key((string) ($specific['stop_sale_meta_key'] ?? '')),
        ];
    }

    private static function wpml_group_source(int $target, string $post_type): int {
        $trid = apply_filters('wpml_element_trid', null, $target, 'post_' . $post_type);
        if (!$trid) return 0;
        $members = apply_filters('wpml_get_element_translations', [], $trid, 'post_' . $post_type);
        if (!is_array($members)) return 0;
        foreach ($members as $member) {
            if (!is_object($member) || !empty($member->source_language_code)) continue;
            $id = absint($member->element_id ?? 0);
            if ($id && $id !== $target && get_post_type($id) === $post_type) return $id;
        }
        return 0;
    }

    public static function assess(int $target, string $meta_key, int $explicit_source = 0) {
        $target_post = get_post($target);
        if (!$target_post || !$target_post instanceof WP_Post || !defined('ICL_SITEPRESS_VERSION')) {
            return self::error('target_or_wpml_unavailable');
        }
        $rule = self::configured_rule($target_post->post_type, $meta_key);
        if (!$rule) return self::error('configured_mapper_rule_required');
        $language = apply_filters('wpml_post_language_details', null, $target);
        $lang = is_array($language) ? sanitize_key((string) ($language['language_code'] ?? '')) : '';
        if (!$lang) return self::error('language_missing');
        $from_group = self::wpml_group_source($target, $target_post->post_type);
        $from_pointer = absint(get_post_meta($target, self::SOURCE_POINTER, true));
        $source = $from_group ?: $from_pointer ?: $explicit_source;
        $provenance = $from_group ? 'wpml_group' : ($from_pointer ? 'source_meta' :
            ($explicit_source ? 'explicit_authorized_source' : 'none'));
        if (!$source) return self::error('source_lineage_missing');
        if ($explicit_source && $source !== $explicit_source) return self::error('source_conflicts_with_lineage');
        if (get_post_type($source) !== $target_post->post_type || $source === $target) {
            return self::error('wrong_source_type');
        }
        $raw = get_post_meta($source, $meta_key, true);
        if (!is_scalar($raw) || !ctype_digit((string) $raw) || absint($raw) < 1) {
            return self::error('source_field_empty');
        }
        if (get_post_type(absint($raw)) !== $rule['object_type']) {
            return self::error('source_reference_wrong_type');
        }
        $policy = self::policy($target_post->post_type, $meta_key);
        // An explicitly supplied source from an independent WPML translation
        // group must match site-configured commercial/source evidence.
        if (!$from_group && !$from_pointer) {
            $default_lang = apply_filters('wpml_default_language', null);
            $source_lang = apply_filters('wpml_post_language_details', null, $source);
            if (!$default_lang || !is_array($source_lang) ||
                ($source_lang['language_code'] ?? '') !== $default_lang) {
                return self::error('explicit_source_must_be_default_language');
            }
            if (count($policy['match_fields']) < 2) return self::error('insufficient_source_evidence_policy');
            foreach ($policy['match_fields'] as $field) {
                $a = get_post_meta($source, $field, true);
                $b = get_post_meta($target, $field, true);
                if (!is_scalar($a) || (string) $a === '' || (string) $a !== (string) $b) {
                    return self::error('source_evidence_mismatch');
                }
            }
        }
        $mapped = apply_filters('wpml_sync_custom_field_copied_value',
            $raw, $target, $source, $meta_key);
        if (!is_scalar($mapped) || !ctype_digit((string) $mapped)) {
            return self::error('mapper_returned_invalid_reference');
        }
        $mapped = absint($mapped);
        if (!$mapped || get_post_type($mapped) !== $rule['object_type']) {
            return self::error('translated_reference_missing');
        }
        $mapped_language = apply_filters('wpml_post_language_details', null, $mapped);
        if (!is_array($mapped_language) || ($mapped_language['language_code'] ?? '') !== $lang) {
            return self::error('translated_reference_language_mismatch');
        }
        $current = get_post_meta($target, $meta_key, true);
        if (is_scalar($current) && (string) $current !== '' && (int) $current !== $mapped) {
            return self::error('existing_nonmatching_reference');
        }
        $end = $policy['expiry_meta_key'] ? get_post_meta($target, $policy['expiry_meta_key'], true) : null;
        $until = is_numeric($end) ? (int)$end : (is_string($end) && $end ? strtotime($end) : 0);
        return [
            'contract' => 'wpml.mapper.source-recovery.v1',
            'target_id' => $target, 'source_id' => $source,
            'post_type' => $target_post->post_type, 'meta_key' => $meta_key,
            'owner_object_type' => $rule['object_type'], 'target_language' => $lang,
            'provenance' => $provenance, 'mapped_id' => $mapped,
            'current_value' => (string) $current,
            'mirror_meta_key' => $policy['mirror_meta_key'],
            'expiry_meta_key' => $policy['expiry_meta_key'],
            'stop_sale_meta_key' => $policy['stop_sale_meta_key'],
            'expired' => $until > 0 && $until < current_time('timestamp', true),
            'expiry_proved' => $until > 0,
            'source_match_fields' => $policy['match_fields'],
            'writes_allowed_by_preview' => false,
            'site_origin' => home_url('/'),
        ];
    }

    public static function repair(int $target, string $meta_key, int $source = 0) {
        if (!current_user_can('manage_options')) return self::error('administrator_permission_required');
        $plan = self::assess($target, $meta_key, $source);
        if (is_wp_error($plan)) return $plan;
        $existing = (string) $plan['current_value'];
        if ($existing === (string) $plan['mapped_id'] &&
            (int)get_post_meta($target, $plan['mirror_meta_key'] ?: $meta_key, true) === $plan['mapped_id'] &&
            absint(get_post_meta($target, self::SOURCE_POINTER, true)) === $plan['source_id']) {
            return $plan + ['state' => 'already_reconciled'];
        }
        $before = [
            'source_pointer' => get_post_meta($target, self::SOURCE_POINTER, true),
            'mapped' => get_post_meta($target, $meta_key, true),
            'mirror' => $plan['mirror_meta_key'] ? get_post_meta($target, $plan['mirror_meta_key'], true) : null,
            'stop_sale' => $plan['stop_sale_meta_key'] ? get_post_meta($target, $plan['stop_sale_meta_key'], true) : null
        ];
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            update_post_meta($target, $meta_key, $plan['mapped_id']);
            if ($plan['mirror_meta_key']) update_post_meta($target, $plan['mirror_meta_key'], $plan['mapped_id']);
            update_post_meta($target, self::SOURCE_POINTER, $plan['source_id']);
            // Expiry is read from this site's configured date field. Never
            // extend the rate or silently approve checkout.
            if ($plan['expired'] && $plan['stop_sale_meta_key']) {
                update_post_meta($target, $plan['stop_sale_meta_key'], '1');
            }
            if ((int)get_post_meta($target, $meta_key, true) !== $plan['mapped_id'] ||
                absint(get_post_meta($target, self::SOURCE_POINTER, true)) !== $plan['source_id'] ||
                ($plan['mirror_meta_key'] && (int)get_post_meta($target, $plan['mirror_meta_key'], true) !== $plan['mapped_id']) ||
                ($plan['expired'] && $plan['stop_sale_meta_key'] &&
                    (string)get_post_meta($target, $plan['stop_sale_meta_key'], true) !== '1')) {
                throw new RuntimeException('write_readback_failed');
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            wp_cache_delete($target, 'post_meta');
            return self::error('write_readback_failed');
        }
        $audit = (array) get_option('wpml_meta_id_mapper_recovery_audit_v1', []);
        $audit[] = ['target' => $target, 'key' => $meta_key, 'source' => $plan['source_id'],
            'mapped' => $plan['mapped_id'], 'before' => $before, 'at' => gmdate('c'),
            'actor' => get_current_user_id(), 'expired' => $plan['expired']];
        update_option('wpml_meta_id_mapper_recovery_audit_v1', array_slice($audit, -100), false);
        return $plan + ['state' => 'repaired', 'receipt' => hash('sha256',
            wp_json_encode([$target, $meta_key, $plan['source_id'], $plan['mapped_id']]))];
    }

    /** Enforce an expired record's configured stop-sale field, no ID mapping. */
    public static function protect_expired(int $post_id, string $meta_key) {
        if (!current_user_can('manage_options')) return self::error('administrator_permission_required');
        $post = get_post($post_id);
        if (!$post || !self::configured_rule($post->post_type, $meta_key))
            return self::error('configured_mapper_rule_required');
        $policy = self::policy($post->post_type, $meta_key);
        $date_key = $policy['expiry_meta_key'];
        $sale_key = $policy['stop_sale_meta_key'];
        if (!$date_key || !$sale_key) return self::error('expiry_policy_not_configured');
        $raw = get_post_meta($post_id, $date_key, true);
        $until = is_numeric($raw) ? (int)$raw : (is_string($raw)&&$raw ? strtotime($raw) : 0);
        if (!$until) return self::error('expiry_source_field_invalid');
        if ($until >= current_time('timestamp',true))
            return ['state'=>'still_valid','post_id'=>$post_id,'sale_unchanged'=>true];
        $old = get_post_meta($post_id,$sale_key,true);
        if ((string)$old==='1')
            return ['state'=>'already_stopped','post_id'=>$post_id,'sale_unchanged'=>true];
        update_post_meta($post_id,$sale_key,'1');
        if ((string)get_post_meta($post_id,$sale_key,true)!=='1')
            return self::error('stop_sale_readback_failed');
        $audit=(array)get_option('wpml_meta_id_mapper_recovery_audit_v1',[]);
        $audit[]=['target'=>$post_id,'operation'=>'stop_expired','date_key'=>$date_key,
            'stop_key'=>$sale_key,'before'=>$old,'after'=>'1',
            'at'=>gmdate('c'),'actor'=>get_current_user_id()];
        update_option('wpml_meta_id_mapper_recovery_audit_v1',array_slice($audit,-100),false);
        return ['state'=>'expired_stop_sale_enabled','post_id'=>$post_id,
            'date_field'=>$date_key,'stop_sale_field'=>$sale_key,'expired'=>true];
    }
}
