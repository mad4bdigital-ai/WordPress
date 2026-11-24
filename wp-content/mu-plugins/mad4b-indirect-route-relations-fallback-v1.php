<?php
/**
 * Plugin Name: MAD4B Indirect Route Relations Fallback
 * Description: Read-only, source-backed itinerary route endpoint suggestions from native WP/JetEngine relationships. No Google calls or cost writes.
 * Version: 1.0.2
 */

defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Indirect_Route_Relations_Fallback_V1', false)) {
final class MAD4B_Indirect_Route_Relations_Fallback_V1 {
    public const CONTRACT = 'mad4b.indirect-route-relations-fallback.v1';
    private const TAXONOMY = 'destinations';
    private const ITINERARY = 'itinerary_item';
    private const PROGRAM_ITINERARY = 'program_itinerary';
    private const FROM = 'mad4b_transport_from_term_v1';
    private const TO = 'mad4b_transport_to_term_v1';
    private const SOURCES = 'mad4b_transport_sources_v1';

    private static function destination($id): int {
        $id = absint($id);
        if (!$id || !term_exists($id, self::TAXONOMY)) return 0;
        $mapped = (int) apply_filters('wpml_object_id', $id, self::TAXONOMY, false, 'en');
        return $mapped && term_exists($mapped, self::TAXONOMY) ? $mapped : $id;
    }

    private static function ids($input): array {
        if (is_string($input)) {
            $json = json_decode($input, true);
            $input = is_array($json) ? $json : preg_split('/[,|]/', $input);
        }
        if (!is_array($input)) $input = [$input];
        $ids = [];
        foreach ($input as $id) {
            if (!is_scalar($id) || !ctype_digit((string) $id) || !$id) continue;
            $id = (int) $id;
            if (get_post_type($id) === 'transportations') $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private static function sourcePair(int $transport): array {
        if (get_post_type($transport) !== 'transportations') return [];
        $from = self::destination(get_post_meta($transport, '_mad4b_pdf_route_origin_term_id_v1', true));
        $to = self::destination(get_post_meta($transport, '_mad4b_pdf_route_destination_term_id_v1', true));
        if ($from && $to && $from !== $to) {
            return ['from' => $from, 'to' => $to, 'source_post_id' => $transport,
                'evidence' => 'selected_transport_explicit_pdf_route_terms'];
        }
        // Legacy source has allowed-place lists, not necessarily a booked route.
        // Use those ONLY when each side contains exactly one native destination.
        $a = self::destinationList(get_post_meta($transport, 'trans_pickup_destinations_allowed', true));
        $b = self::destinationList(get_post_meta($transport, 'trans_dropoff_destinations_allowed', true));
        if (count($a) === 1 && count($b) === 1 && $a[0] !== $b[0]) {
            return ['from' => $a[0], 'to' => $b[0], 'source_post_id' => $transport,
                'evidence' => 'selected_transport_unique_allowed_endpoints'];
        }
        return [];
    }

    private static function destinationList($raw): array {
        if (is_string($raw)) {
            $json = json_decode($raw, true);
            $raw = is_array($json) ? $json : preg_split('/[,|]/', $raw);
        }
        if (!is_array($raw)) $raw = [$raw];
        $out = [];
        foreach ($raw as $id) {
            if (!is_scalar($id)) continue;
            $term = self::destination($id);
            if ($term) $out[$term] = $term;
        }
        return array_values($out);
    }

    /** Two-hop context: Tour/Program -> linked Transportation CPT -> route terms.
     * None of these broader links identifies an actual itinerary-day booking.
     */
    private static function broaderTransportPairs(int $tour, int $program): array {
        $ids = [];
        if (class_exists('MAD4B_Itinerary_Transport_Shared_Query_V1', false)) {
            $context = $program > 0
                ? MAD4B_Itinerary_Transport_Shared_Query_V1::contextForProgram($program)
                : MAD4B_Itinerary_Transport_Shared_Query_V1::contextForTour($tour);
            foreach (self::ids($context['selected_transport_ids'] ?? []) as $id) {
                $ids[$id] = ['id' => $id, 'path' => 'tour_or_program_itinerary_selected_sources'];
            }
        }
        // Native taxonomy edge: Tour -> destination term -> Transportation.
        // This is an INDIRECT suggestion, not a route/booking assignment.
        $scope = self::scopeDestinations($tour);
        if ($scope) {
            $related = get_posts(['post_type' => 'transportations',
                'post_status' => 'publish', 'posts_per_page' => -1,
                'fields' => 'ids', 'suppress_filters' => true,
                'no_found_rows' => true,
                'tax_query' => [[ 'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id', 'terms' => $scope ]]]);
            foreach ((array) $related as $id) {
                $id = (int) $id;
                if ($id > 0 && get_post_type($id) === 'transportations' && !isset($ids[$id])) {
                    $ids[$id] = ['id' => $id,
                        'path' => 'tour_destination_term > linked_transportation_taxonomy'];
                }
            }
        }
        if (function_exists('jet_engine') && is_object(jet_engine()->relations ?? null)) {
            foreach (jet_engine()->relations->get_active_relations() as $relation) {
                if (!method_exists($relation, 'get_args') ||
                    $relation->get_args('parent_object') !== 'posts::tours-and-activities' ||
                    $relation->get_args('child_object') !== 'posts::transportations') continue;
                foreach ((array) $relation->get_children($tour, 'ids') as $id) {
                    $id = (int) $id;
                    if ($id && get_post_type($id) === 'transportations') {
                        $ids[$id] = ['id' => $id, 'path' => 'jetengine_tour_to_transport_relation'];
                    }
                }
            }
        }
        $out = [];
        foreach ($ids as $entry) {
            $pair = self::sourcePair($entry['id']);
            if (!$pair) continue;
            $out[] = ['from_term_id' => $pair['from'],
                'to_term_id' => $pair['to'], 'source_post_id' => $entry['id'],
                'relation_path' => $entry['path'].' > original_transport_route_terms',
                'depth' => 2, 'not_a_day_assignment' => true];
        }
        return $out;
    }

    private static function scopeDestinations(int $tour): array {
        $terms = wp_get_post_terms($tour, self::TAXONOMY, ['fields' => 'ids']);
        if (is_wp_error($terms) || !is_array($terms)) return [];
        $result = [];
        foreach ($terms as $term) {
            $id = self::destination($term);
            if (!$id) continue;
            // Broad country/continent terms must never become inferred stops.
            if (count((array) get_ancestors($id, self::TAXONOMY, 'taxonomy')) < 2) continue;
            $result[$id] = $id;
        }
        return array_values($result);
    }

    private static function sourceRows(int $tour, int $program): array {
        // Follow the existing first-party itinerary source; do not invent new
        // owner/Program assignments or copy stale translated itineraries.
        if (class_exists('MAD4B_Guide_Service_Composition_V1', false)) {
            $source = MAD4B_Guide_Service_Composition_V1::source($tour, $program);
            if (!is_wp_error($source)) {
                $sourceId = (int) ($source['source_id'] ?? 0);
                $isTerm = ($source['source_kind'] ?? '') === 'term';
                $rows = $isTerm
                    ? get_term_meta($sourceId, self::PROGRAM_ITINERARY, true)
                    : get_post_meta($sourceId, self::ITINERARY, true);
                return ['rows' => is_array($rows) ? $rows : [],
                    'source_id' => $sourceId,
                    'source_kind' => $isTerm ? 'program_term' : 'tour_post',
                    'source_hash' => (string) ($source['source_hash'] ?? '')];
            }
        }
        // Safe fallback only to the explicitly requested Tour itself.
        $rows = get_post_meta($tour, self::ITINERARY, true);
        return ['rows' => is_array($rows) ? $rows : [], 'source_id' => $tour,
            'source_kind' => 'tour_post', 'source_hash' => ''];
    }

    /** Returns concrete endpoint evidence; never infers a road distance. */
    public static function point(int $termId): array {
        $termId = self::destination($termId);
        if (!$termId) return ['status' => 'destination_missing'];
        $raw = get_term_meta($termId, 'google_location', true);
        if (is_string($raw) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/D', $raw, $m)) {
            $lat = (float) $m[1]; $lng = (float) $m[2];
            if (is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180) {
                return ['status' => 'exact_term_coordinate', 'term_id' => $termId,
                    'latitude' => $lat, 'longitude' => $lng, 'point_accuracy' => 'term_location'];
            }
        }
        $place = trim((string) get_term_meta($termId, 'google_place_id', true));
        if (preg_match('/^[A-Za-z0-9_-]{12,160}$/D', $place)) {
            return ['status' => 'exact_term_place_id', 'term_id' => $termId,
                'place_id' => $place, 'point_accuracy' => 'original_google_place_id'];
        }
        // One-hop geographical parent lookup only; NEVER a sibling or a
        // generic country/continent centroid. This is REVIEW context, not
        // a door-to-door waypoint nor an approved Google Routes input.
        $term = get_term($termId, self::TAXONOMY);
        $parent = ($term instanceof WP_Term) ? (int) $term->parent : 0;
        if ($parent && count((array) get_ancestors($parent, self::TAXONOMY, 'taxonomy')) >= 2) {
            $parentPoint = self::pointExact($parent);
            if ($parentPoint) return ['status' => 'related_parent_reference_only',
                'term_id' => $termId, 'related_term_id' => $parent,
                'related_point' => $parentPoint,
                'point_accuracy' => 'approximate_parent_not_valid_for_pricing'];
        }
        return ['status' => 'point_not_available', 'term_id' => $termId];
    }

    private static function pointExact(int $termId): ?array {
        $raw = get_term_meta($termId, 'google_location', true);
        if (!is_string($raw) || !preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/D', $raw, $m)) return null;
        $lat = (float) $m[1]; $lng = (float) $m[2];
        return is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180
            ? ['term_id' => $termId, 'latitude' => $lat, 'longitude' => $lng] : null;
    }

    public static function inspect(int $tour, int $program = 0): array {
        $result = ['contract' => self::CONTRACT, 'tour_id' => $tour,
            'program_id' => $program, 'price_authorized' => false,
            'route_optimized' => false, 'google_api_called' => false,
            'distance_inferred' => false, 'auto_assignment_allowed' => false,
            'rows' => []];
        if ($tour < 1 || get_post_type($tour) !== 'tours-and-activities')
            return $result + ['status' => 'tour_not_found'];
        $source = self::sourceRows($tour, $program);
        $rows = $source['rows'];
        $broader = self::broaderTransportPairs($tour, $program);
        $result['related_transport_route_suggestions'] = $broader;
        $result['tour_destination_scope_only'] = self::scopeDestinations($tour);
        $explicit = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row)) continue;
            $from = self::destination($row[self::FROM] ?? 0);
            $to = self::destination($row[self::TO] ?? 0);
            $explicit[(string) $key] = ['from' => $from, 'to' => $to];
        }
        $dayIndex = 0;
        foreach ($rows as $key => $row) {
            if (!is_array($row)) continue;
            ++$dayIndex;
            $from = self::destination($row[self::FROM] ?? 0);
            $to = self::destination($row[self::TO] ?? 0);
            $direct = $from > 0 && $to > 0 && $from !== $to;
            $transport = self::ids($row[self::SOURCES] ?? []);
            $pairs = [];
            foreach ($transport as $id) {
                $candidate = self::sourcePair($id);
                if ($candidate) {
                    $index = $candidate['from'].':'.$candidate['to'];
                    $pairs[$index] = $candidate;
                }
            }
            $candidate = null;
            if (!$direct && count($pairs) === 1) {
                $pair = reset($pairs);
                if ((!$from || $from === $pair['from']) && (!$to || $to === $pair['to'])) {
                    $candidate = $pair;
                }
            }
            // Broad linked destinations are suggestions only. Their membership
            // never supplies both endpoints or a sequence of actual stops.
            $status = $direct ? 'direct_route_present' : ($candidate ? 'indirect_candidate_needs_review' :
                ($pairs ? 'ambiguous_or_conflicting_linked_transport' : 'no_unique_route_relation'));
            $result['rows'][] = [
                'day_key' => (string) $key, 'day_number' => $dayIndex,
                'day_title' => sanitize_text_field(wp_strip_all_tags((string) ($row['title'] ?? ''))),
                'status' => $status,
                'original_from_term_id' => $from,
                'original_to_term_id' => $to,
                'candidate_from_term_id' => $candidate['from'] ?? 0,
                'candidate_to_term_id' => $candidate['to'] ?? 0,
                'candidate_source_post_id' => $candidate['source_post_id'] ?? 0,
                'source_evidence' => $candidate['evidence'] ?? null,
                'selected_transport_post_ids' => $transport,
                'matched_candidate_pairs' => count($pairs),
                // Broader (two-hop) relationships are shown as evidence only:
                // never use these to auto-pick a day or infer a transfer price.
                'indirect_tour_relation_suggestions' => $broader,
                'requires_operator_acceptance' => !$direct,
                'do_not_auto_quote' => true,
            ];
        }
        return array_merge($result, ['status' => 'ok',
            'source_kind' => $source['source_kind'],
            'source_id' => $source['source_id'],
            'source_hash' => $source['source_hash']]);
    }

    public static function box($post): void {
        if (!($post instanceof WP_Post) || !current_user_can('edit_post', $post->ID)) return;
        $tour = (int) get_post_meta($post->ID, 'related_tour_id', true);
        if (!$tour) return;
        $review = self::inspect($tour);
        if (($review['status'] ?? '') !== 'ok') return;
        echo '<p>Read-only suggestions from the original day and linked Transportation CPT. No API request, automatic assignment or approved cost.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Day</th><th>Source</th><th>Route status</th></tr></thead><tbody>';
        foreach ($review['rows'] as $line) {
            echo '<tr><td>'.esc_html($line['day_number'].' — '.$line['day_title']).'</td>';
            echo '<td>'.esc_html($line['source_evidence'] ?? 'Original Itinerary / no unique linked route').'</td>';
            echo '<td>'.esc_html($line['status']).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function metaBox(): void {
        if (!current_user_can('edit_posts')) return;
        add_meta_box('mad4b-indirect-route-fallback', 'Indirect Route Relation Review',
            [self::class, 'box'], 'tour-rates', 'normal', 'default');
    }

    public static function boot(): void {
        add_action('add_meta_boxes_tour-rates', [self::class, 'metaBox']);
        // Other source modules can reuse the exact return contract without
        // requiring this module to modify their original rate/cost authority.
    }
}
MAD4B_Indirect_Route_Relations_Fallback_V1::boot();
}
