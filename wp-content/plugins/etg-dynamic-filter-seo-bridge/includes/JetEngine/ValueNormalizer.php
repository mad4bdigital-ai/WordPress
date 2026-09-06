<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

final class ValueNormalizer {
    const MAX_DEPTH = 8;
    const MAX_ITEMS = 200;

    public function decode($value) {
        if (!is_string($value)) { return $value; }
        $trim = trim($value);
        if ('' === $trim) { return ''; }
        if (function_exists('is_serialized') && is_serialized($trim)) {
            $decoded = @unserialize($trim, array('allowed_classes'=>false));
            if (false !== $decoded || 'b:0;' === $trim) { return $decoded; }
        }
        $json = json_decode($trim, true);
        if (JSON_ERROR_NONE === json_last_error() && (is_array($json) || is_scalar($json))) { return $json; }
        return $value;
    }

    public function path($value, string $path) {
        $value = $this->decode($value);
        $path = trim($path);
        if ('' === $path) { return $value; }
        $parts = preg_split('/[.\/]+/', $path);
        if (!is_array($parts) || count($parts) > self::MAX_DEPTH) { return ''; }
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ('' === $part || !preg_match('/^[A-Za-z0-9_-]+$/', $part)) { return ''; }
            if (is_array($value) && array_key_exists($part, $value)) { $value = $value[$part]; continue; }
            if (is_object($value) && isset($value->{$part})) { $value = $value->{$part}; continue; }
            return '';
        }
        return $this->decode($value);
    }

    public function rows($value): array {
        $value = $this->decode($value);
        if (is_object($value)) { $value = (array)$value; }
        if (!is_array($value)) { return array(); }
        $rows = array();
        foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $key=>$row) {
            $row = $this->decode($row);
            if (is_object($row)) { $row = (array)$row; }
            if (!is_array($row)) { continue; }
            $row['_etg_row_key'] = (string)$key;
            $rows[] = $row;
        }
        return $rows;
    }

    public function scalar($value): string {
        $value = $this->decode($value);
        if (is_bool($value)) { return $value ? '1' : '0'; }
        if (is_scalar($value)) { return trim((string)$value); }
        if (is_object($value) && method_exists($value, '__toString')) { return trim((string)$value); }
        return '';
    }

    public function attachmentIds($value): array {
        $ids = array();
        $this->collectAttachmentIds($this->decode($value), $ids, 0);
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        return array_slice($ids, 0, self::MAX_ITEMS);
    }

    private function collectAttachmentIds($value, array &$ids, int $depth): void {
        if ($depth > self::MAX_DEPTH || count($ids) >= self::MAX_ITEMS) { return; }
        $value = $this->decode($value);
        if (is_numeric($value)) { $ids[] = (int)$value; return; }
        if (is_object($value)) {
            foreach (array('ID','id','attachment_id') as $key) { if (isset($value->{$key}) && is_numeric($value->{$key})) { $ids[]=(int)$value->{$key}; return; } }
            $value = (array)$value;
        }
        if (is_array($value)) {
            foreach (array('ID','id','attachment_id') as $key) { if (isset($value[$key]) && is_numeric($value[$key])) { $ids[]=(int)$value[$key]; return; } }
            foreach (array('url','src') as $key) {
                if (!empty($value[$key]) && is_string($value[$key]) && function_exists('attachment_url_to_postid')) {
                    $id = attachment_url_to_postid($value[$key]); if ($id) { $ids[]=(int)$id; return; }
                }
            }
            foreach ($value as $item) { $this->collectAttachmentIds($item, $ids, $depth+1); }
            return;
        }
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && function_exists('attachment_url_to_postid')) {
            $id = attachment_url_to_postid($value); if ($id) { $ids[]=(int)$id; }
        }
    }
}
