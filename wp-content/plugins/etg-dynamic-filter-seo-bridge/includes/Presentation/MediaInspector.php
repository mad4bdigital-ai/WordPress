<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

require_once __DIR__ . '/MediaAssetValidator.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;
use WP_Term;

final class MediaInspector {
    const MAX_TERMS = 20;
    const MAX_FIELDS = 120;
    const MAX_SAMPLE_IDS = 12;
    const MAX_SAMPLE_VALUES = 5;

    private $registry;

    public function __construct(MediaDiscoveryRegistry $registry) { $this->registry = $registry; }

    /** Backwards-compatible media-only scan used by the original Media Lab surface. */
    public function scanTaxonomy(string $taxonomy, int $termLimit = 10): array {
        $scan = $this->scanTaxonomyMetadata($taxonomy, $termLimit, false);
        $fields = array();
        foreach ((array) ($scan['fields'] ?? array()) as $key => $row) {
            if (!empty($row['media_ids'])) { $fields[$key] = $row; }
        }
        $scan['contract'] = 'etg.dfsb.media-inspector.v1';
        $scan['fields'] = $fields;
        $scan['reason'] = $fields ? 'ready' : 'no_media_meta_detected';
        return $scan;
    }

    /**
     * Fetch-safe taxonomy Meta catalog.
     *
     * Includes safe scalar/complex/repeater Meta so admin pickers can discover
     * exact keys instead of asking users to type identifiers. Media candidates
     * are verified as renderable WordPress image attachments. Stale attachment
     * references remain visible as non-authorizing health evidence.
     */
    public function scanTaxonomyMetadata(string $taxonomy, int $termLimit = 10, bool $mediaOnly = false): array {
        $taxonomy = sanitize_key($taxonomy);
        $termLimit = max(1, min(self::MAX_TERMS, $termLimit));
        $base = array(
            'contract' => 'etg.dfsb.taxonomy-metadata-catalog.v1',
            'authorizing' => false,
            'read_only' => true,
            'taxonomy' => $taxonomy,
            'term_count' => 0,
            'configured' => $this->registry->keysForTaxonomy($taxonomy),
            'fields' => array(),
            'terms' => array(),
        );
        if ('' === $taxonomy || !function_exists('get_terms')) { $base['reason'] = 'taxonomy_unavailable'; return $base; }
        if (function_exists('taxonomy_exists') && !taxonomy_exists($taxonomy)) { $base['reason'] = 'taxonomy_unavailable'; return $base; }

        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => $termLimit));
        if (function_exists('is_wp_error') && is_wp_error($terms)) { $base['reason'] = 'term_scan_unavailable'; return $base; }
        if (!is_array($terms)) { $base['reason'] = 'term_scan_unavailable'; return $base; }

        $fieldStats = array();
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) { continue; }
            $termRow = array('term_id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'meta_fields' => array());
            $meta = function_exists('get_term_meta') ? get_term_meta($term->term_id) : array();
            foreach (array_slice((array) $meta, 0, self::MAX_FIELDS, true) as $rawKey => $rawValues) {
                $key = FieldKey::normalize($rawKey);
                if ('' === $key || $this->sensitive($key)) { continue; }
                $report = $this->attachmentReport($rawValues);
                $ids = $report['ids'];
                $rejected = $report['rejected'];
                if ($mediaOnly && !$ids) { continue; }
                $kind = $this->guessKind($key, $rawValues, $ids);
                if (!isset($fieldStats[$key])) {
                    $fieldStats[$key] = array(
                        'key' => $key,
                        'label' => $this->label($key),
                        'kind' => $kind,
                        'confidence' => $this->confidence($kind, $ids),
                        'term_hits' => 0,
                        'media_ids' => array(),
                        'rejected_media' => array(),
                        'sample_terms' => array(),
                        'sample_values' => array(),
                        'configured_as' => array(),
                        'authorizing' => false,
                        'read_only' => true,
                    );
                } else {
                    $fieldStats[$key]['kind'] = $this->strongerKind((string) $fieldStats[$key]['kind'], $kind);
                    $fieldStats[$key]['confidence'] = $this->confidence((string) $fieldStats[$key]['kind'], array_merge((array) $fieldStats[$key]['media_ids'], $ids));
                }
                $fieldStats[$key]['term_hits']++;
                $fieldStats[$key]['media_ids'] = array_values(array_unique(array_merge((array) $fieldStats[$key]['media_ids'], $ids)));
                $fieldStats[$key]['rejected_media'] = $this->mergeRejected((array) $fieldStats[$key]['rejected_media'], $rejected);
                if (count($fieldStats[$key]['sample_terms']) < self::MAX_SAMPLE_VALUES) { $fieldStats[$key]['sample_terms'][] = (string) $term->name; }
                $sample = $this->sampleValue($rawValues);
                if ('' !== $sample && count($fieldStats[$key]['sample_values']) < self::MAX_SAMPLE_VALUES && !in_array($sample, $fieldStats[$key]['sample_values'], true)) {
                    $fieldStats[$key]['sample_values'][] = $sample;
                }
                $termRow['meta_fields'][$key] = array(
                    'kind' => $kind,
                    'ids' => array_slice($ids, 0, self::MAX_SAMPLE_IDS),
                    'rejected_media' => array_slice($rejected, 0, self::MAX_SAMPLE_IDS),
                    'sample' => $sample,
                );
            }
            $base['terms'][] = $termRow;
        }

        $configured = $base['configured'];
        foreach ($fieldStats as $key => &$row) {
            if (in_array($key, (array) $configured['image'], true)) { $row['configured_as'][] = 'image'; }
            if (in_array($key, (array) $configured['gallery'], true)) { $row['configured_as'][] = 'gallery'; }
            $row['media_ids'] = array_slice(array_values(array_unique(array_map('absint', (array) $row['media_ids']))), 0, self::MAX_SAMPLE_IDS);
            $row['rejected_media'] = array_slice((array) $row['rejected_media'], 0, self::MAX_SAMPLE_IDS);
        }
        unset($row);
        uasort($fieldStats, static function($a, $b) {
            $media = (!empty($b['media_ids']) ? 1 : 0) <=> (!empty($a['media_ids']) ? 1 : 0);
            if (0 !== $media) { return $media; }
            $hit = ((int) $b['term_hits']) <=> ((int) $a['term_hits']);
            return 0 !== $hit ? $hit : strcmp((string) $a['key'], (string) $b['key']);
        });
        $base['fields'] = array_slice($fieldStats, 0, self::MAX_FIELDS, true);
        $base['term_count'] = count($base['terms']);
        $base['reason'] = $base['fields'] ? 'ready' : 'no_safe_meta_detected';
        return $base;
    }

    public function taxonomyOptions(): array {
        if (!function_exists('get_taxonomies')) { return array(); }
        $objects = get_taxonomies(array('public' => true), 'objects');
        $out = array();
        foreach ((array) $objects as $taxonomy => $object) {
            $taxonomy = sanitize_key((string) $taxonomy); if ('' === $taxonomy) { continue; }
            $label = is_object($object) && isset($object->labels->singular_name) ? (string) $object->labels->singular_name : $taxonomy;
            $out[$taxonomy] = $label . ' [' . $taxonomy . ']';
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    private function guessKind(string $key, $value, array $ids): string {
        $needle = strtolower($key);
        if ($ids) {
            if (preg_match('/(?:gallery|slides|slider|images|photos)/', $needle) || count($ids) > 1) { return 'gallery'; }
            return 'media';
        }
        $decoded = $this->decoded($value);
        if (is_array($decoded)) {
            foreach ($decoded as $item) { if (is_array($item) || is_object($item)) { return 'repeater'; } }
            return 'complex';
        }
        if (is_object($decoded)) { return 'complex'; }
        return 'scalar';
    }

    private function strongerKind(string $a, string $b): string {
        $rank = array('scalar' => 1, 'complex' => 2, 'repeater' => 3, 'media' => 4, 'gallery' => 5);
        return (($rank[$b] ?? 0) > ($rank[$a] ?? 0)) ? $b : $a;
    }

    private function confidence(string $kind, array $ids): string {
        if ($ids || in_array($kind, array('media', 'gallery'), true)) { return 'high'; }
        if (in_array($kind, array('repeater', 'complex'), true)) { return 'medium'; }
        return 'observed';
    }

    private function label(string $key): string { return ucwords(str_replace(array('_', '-'), ' ', $key)); }

    private function sampleValue($value): string {
        $decoded = $this->decoded($value);
        if (is_array($decoded)) { return '[array ' . count($decoded) . ']'; }
        if (is_object($decoded)) { return '[object]'; }
        if (!is_scalar($decoded)) { return ''; }
        $sample = trim((string) $decoded);
        if (function_exists('wp_strip_all_tags')) { $sample = wp_strip_all_tags($sample); } else { $sample = strip_tags($sample); }
        if (strlen($sample) > 96) { $sample = substr($sample, 0, 93) . '...'; }
        return $sample;
    }

    private function decoded($value) {
        if (is_array($value) && 1 === count($value)) { $value = reset($value); }
        if (!is_string($value)) { return $value; }
        $trim = trim($value); if ('' === $trim) { return ''; }
        if (function_exists('maybe_unserialize')) {
            $unserialized = maybe_unserialize($trim);
            if ($unserialized !== $trim) { return $unserialized; }
        }
        $json = json_decode($trim, true);
        if (JSON_ERROR_NONE === json_last_error()) { return $json; }
        return $trim;
    }

    private function attachmentReport($value): array {
        $candidates = array();
        $this->collectCandidates($value, $candidates);
        $ids = array();
        $rejected = array();
        foreach (array_values(array_unique(array_filter(array_map('absint', $candidates)))) as $id) {
            $health = MediaAssetValidator::inspect((int) $id);
            if (!empty($health['valid'])) { $ids[] = (int) $id; continue; }
            $rejected[] = array('id'=>(int)$id, 'reason'=>(string)($health['reason'] ?? 'unrenderable'));
        }
        return array('ids'=>array_values(array_unique($ids)), 'rejected'=>$rejected);
    }

    private function collectCandidates($value, array &$ids): void {
        if (is_numeric($value)) { $ids[] = (int) $value; return; }
        if (is_object($value)) {
            foreach (array('ID', 'id', 'attachment_id') as $key) { if (isset($value->{$key}) && is_numeric($value->{$key})) { $ids[] = (int) $value->{$key}; return; } }
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach (array('ID', 'id', 'attachment_id') as $key) { if (isset($value[$key]) && is_numeric($value[$key])) { $ids[] = (int) $value[$key]; return; } }
            foreach ($value as $item) { $this->collectCandidates($item, $ids); }
            return;
        }
        if (!is_string($value)) { return; }
        $value = trim($value); if ('' === $value) { return; }
        if (function_exists('maybe_unserialize')) { $unserialized = maybe_unserialize($value); if ($unserialized !== $value) { $this->collectCandidates($unserialized, $ids); return; } }
        $decoded = json_decode($value, true); if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) { $this->collectCandidates($decoded, $ids); return; }
        if (false !== strpos($value, ',')) { foreach (explode(',', $value) as $part) { $this->collectCandidates(trim($part), $ids); } return; }
        if (filter_var($value, FILTER_VALIDATE_URL) && function_exists('attachment_url_to_postid')) { $id = attachment_url_to_postid($value); if ($id) { $ids[] = (int) $id; } }
    }

    private function mergeRejected(array $left, array $right): array {
        $out = array(); $seen = array();
        foreach (array_merge($left, $right) as $row) {
            if (!is_array($row)) { continue; }
            $id = absint($row['id'] ?? 0); $reason = sanitize_key((string)($row['reason'] ?? 'unrenderable'));
            if (!$id) { continue; }
            $key = $id . ':' . $reason;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = array('id'=>$id, 'reason'=>$reason);
            if (count($out) >= self::MAX_SAMPLE_IDS) { break; }
        }
        return $out;
    }

    private function sensitive(string $key): bool { return (bool) preg_match('/(?:password|passwd|secret|token|api[_-]?key|credential|auth[_-]?key|nonce|session)/i', $key); }
}
