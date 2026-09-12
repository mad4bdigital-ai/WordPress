<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

final class MediaAssetValidator {
    const CONTRACT = 'etg.dfsb.media-asset-health.v1';

    public static function inspect(int $id): array {
        $id = function_exists('absint') ? absint($id) : abs($id);
        $result = array(
            'contract' => self::CONTRACT,
            'id' => $id,
            'valid' => false,
            'reason' => 'invalid_id',
            'url' => '',
            'width' => 0,
            'height' => 0,
            'aspect_ratio' => 0.0,
            'dimensions_source' => 'unknown',
            'local_file_checked' => false,
            'local_file_exists' => null,
            'authorizing' => false,
            'read_only' => true,
        );
        if (!$id) { return self::filtered($result); }

        if (function_exists('get_post_type') && 'attachment' !== get_post_type($id)) {
            $result['reason'] = 'not_attachment';
            return self::filtered($result);
        }
        if (function_exists('wp_attachment_is_image') && !wp_attachment_is_image($id)) {
            $result['reason'] = 'not_image';
            return self::filtered($result);
        }

        if (function_exists('wp_get_attachment_image_url')) {
            $url = wp_get_attachment_image_url($id, 'full');
            $result['url'] = is_string($url) ? trim($url) : '';
            if ('' === $result['url']) {
                $result['reason'] = 'missing_image_url';
                return self::filtered($result);
            }
        }

        if (function_exists('wp_get_attachment_metadata')) {
            $metadata = wp_get_attachment_metadata($id);
            if (is_array($metadata)) {
                $width = isset($metadata['width']) && is_numeric($metadata['width']) ? max(0, (int)$metadata['width']) : 0;
                $height = isset($metadata['height']) && is_numeric($metadata['height']) ? max(0, (int)$metadata['height']) : 0;
                if ($width > 0 && $height > 0) {
                    $result['width'] = $width;
                    $result['height'] = $height;
                    $result['aspect_ratio'] = round($width / $height, 4);
                    $result['dimensions_source'] = 'attachment_metadata';
                }
            }
        }

        if (function_exists('get_attached_file') && function_exists('file_exists')) {
            $file = get_attached_file($id);
            if (is_string($file) && '' !== trim($file)) {
                $result['local_file_checked'] = true;
                $result['local_file_exists'] = file_exists($file);
                if (!$result['local_file_exists']) {
                    $allowMissingLocal = false;
                    if (function_exists('apply_filters')) {
                        $allowMissingLocal = (bool) apply_filters(
                            'etg_dfsb_media_allow_missing_local_file',
                            false,
                            $id,
                            $file,
                            $result['url']
                        );
                    }
                    if (!$allowMissingLocal) {
                        $result['reason'] = 'missing_local_file';
                        return self::filtered($result);
                    }
                } elseif ((!$result['width'] || !$result['height']) && function_exists('getimagesize')) {
                    $dimensions = @getimagesize($file);
                    if (is_array($dimensions) && !empty($dimensions[0]) && !empty($dimensions[1])) {
                        $result['width'] = max(0, (int)$dimensions[0]);
                        $result['height'] = max(0, (int)$dimensions[1]);
                        $result['aspect_ratio'] = $result['height'] > 0 ? round($result['width'] / $result['height'], 4) : 0.0;
                        $result['dimensions_source'] = 'local_file';
                    }
                }
            }
        }

        $result['valid'] = true;
        $result['reason'] = 'ready';
        return self::filtered($result);
    }

    public static function isRenderableImage(int $id): bool {
        $result = self::inspect($id);
        return !empty($result['valid']);
    }

    private static function filtered(array $result): array {
        if (!function_exists('apply_filters')) { return $result; }
        $filtered = apply_filters('etg_dfsb_media_asset_health', $result, (int)($result['id'] ?? 0));
        if (!is_array($filtered)) { return $result; }
        $filtered['authorizing'] = false;
        $filtered['read_only'] = true;
        return $filtered;
    }
}
