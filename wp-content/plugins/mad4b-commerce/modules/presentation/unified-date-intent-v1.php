<?php
/**
 * MAD4B Unified Date Intent V1 — read-only Presentation contract.
 * No checkout authority, inventory reservation, or price mutation.
 */
defined('ABSPATH') || exit;

final class MAD4B_Unified_Date_Intent_V1 {
    public const CONTRACT = 'mad4b.date-intent.v1';

    public static function settings(int $tourId=0): array {
        $raw = get_option('mad4b_unified_date_policy', []);
        $raw = is_array($raw) ? $raw : [];
        $policy = [
            'min_lead_days' => min(365, max(0, (int)($raw['min_lead_days'] ?? 5))),
            'min_flex_gap_days' => min(30, max(0, (int)($raw['min_flex_gap_days'] ?? 2))),
            'max_flex_days' => min(730, max(1, (int)($raw['max_flex_days'] ?? 90))),
            'max_advance_days' => min(1095, max(1, (int)($raw['max_advance_days'] ?? 730))),
        ];
        // Keep the original company option as the sole global numerical authority.
        // Per Tour context only adjusts min_lead_days via native Pricing Controls.
        if($tourId>0 && class_exists('MAD4B_Pricing_Booking_Date_Policy_V1',false)) {
            $effective=MAD4B_Pricing_Booking_Date_Policy_V1::resolve($tourId);
            $policy['min_lead_days']=(int)$effective['min_lead_days'];
        }
        return $policy;
    }

    public static function date(string $value): ?DateTimeImmutable {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $err = DateTimeImmutable::getLastErrors();
        return $date instanceof DateTimeImmutable
            && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))
            && $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function normalize(array $input, bool $enforce_policy = true, int $tourId = 0) {
        $mode = isset($input['mad4b_date_mode']) ? strtolower(trim((string)$input['mad4b_date_mode'])) : '';
        if (!$mode && isset($input['tour_date_flexibility'])) {
            $mode = (string)$input['tour_date_flexibility'] === 'true' ? 'flex' : 'fixed';
        }
        if ($mode === '') return null; // no date criterion: keep existing search
        if (!in_array($mode, ['fixed','flex'], true)) return new WP_Error('invalid_date_mode');

        $start = trim((string)($input['mad4b_date_from'] ?? $input['datetime'] ?? ''));
        $end = $mode === 'flex' ? trim((string)($input['mad4b_date_to'] ?? $input['datetime_2'] ?? '')) : $start;
        $fromDate = self::date($start);
        $toDate = self::date($end);
        if (!$fromDate || !$toDate) return new WP_Error('invalid_date_format');
        if ($toDate < $fromDate) return new WP_Error('invalid_date_window');

        $policy = self::settings($tourId);
        $today = new DateTimeImmutable('today', wp_timezone());
        $minLocalDay = $today->modify('+' . $policy['min_lead_days'] . ' days')->format('Y-m-d');
        $maxLocalDay = $today->modify('+' . $policy['max_advance_days'] . ' days')->format('Y-m-d');
        $span = (int)$fromDate->diff($toDate)->format('%a');
        if ($mode === 'flex' && $span < $policy['min_flex_gap_days']) return new WP_Error('date_window_too_short');
        if ($span > $policy['max_flex_days']) return new WP_Error('date_window_too_long');
        if ($enforce_policy) {
            if ($start < $minLocalDay) return new WP_Error('date_too_soon');
            if ($end > $maxLocalDay) return new WP_Error('date_too_far');
        }
        return [
            'contract' => self::CONTRACT, 'mode' => $mode,
            'date_from' => $start, 'date_to' => $mode === 'flex' ? $end : null,
            'window_from_ts' => $fromDate->getTimestamp(),
            'window_to_ts' => $toDate->getTimestamp() + DAY_IN_SECONDS - 1,
            'availability_confirmed' => false,
            'booking_authorized' => false,
        ];
    }

    public static function request(): array {
        $keys = ['mad4b_date_mode','mad4b_date_from','mad4b_date_to'];
        $input = [];
        foreach ($keys as $key) {
            if (isset($_GET[$key])) {
                if (!is_scalar($_GET[$key])) return ['status' => 'invalid'];
                $input[$key] = sanitize_text_field(wp_unslash((string)$_GET[$key]));
            }
        }
        if (!$input) return ['status' => 'absent'];
        $tourId=is_singular('tours-and-activities')?(int)get_queried_object_id():0;
        $result = self::normalize($input,true,$tourId);
        return is_wp_error($result)
            ? ['status' => 'invalid', 'reason' => $result->get_error_code()]
            : ($result === null ? ['status' => 'absent'] : ['status' => 'valid', 'intent' => $result]);
    }

    public static function matching_tour_ids(array $intent): array {
        if (($intent['contract'] ?? '') !== self::CONTRACT) return [];
        $rates = get_posts([
            'post_type' => 'tour-rates', 'post_status' => 'publish',
            'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'tour_rate_start_date', 'value' => (int)$intent['window_to_ts'], 'compare' => '<=', 'type' => 'NUMERIC'],
                ['key' => 'tour_rate_end_date', 'value' => (int)$intent['window_from_ts'], 'compare' => '>=', 'type' => 'NUMERIC'],
            ],
        ]);
        // Avoid one meta read query per Rate while preserving exhaustive matching.
        foreach (array_chunk($rates, 500) as $batch) {
            update_meta_cache('post', array_map('intval', $batch));
        }
        $tours = []; $rateGroups = [];
        foreach ($rates as $rateId) {
            $tourId = (int)get_post_meta((int)$rateId, 'related_tour_id', true);
            if ($tourId <= 0 || get_post_type($tourId) !== 'tours-and-activities'
                || get_post_status($tourId) !== 'publish') continue;
            $rateGroups[$tourId][] = (int)$rateId;
        }
        foreach ($rateGroups as $tourId => $candidateIds) {
            // Respect the current read-only eligibility resolver; don't create price authority.
            if (function_exists('shared_product_eligible_tour_rate_ids')) {
                $eligible = shared_product_eligible_tour_rate_ids((int)$tourId);
                if (!is_array($eligible) || !array_intersect($candidateIds, array_map('intval', $eligible))) continue;
            }
            $tours[] = (int)$tourId;
        }
        return $tours;
    }
}
