<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

require_once dirname(__DIR__) . '/Identifiers/FieldKey.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;

final class MediaDiscoveryRegistry {
    const OPTION_NAME = 'etg_dfsb_media_discovery';
    const MAX_KEYS_PER_KIND = 50;
    const MAX_TAXONOMIES = 50;

    public function all(): array {
        $stored = function_exists('get_option') ? get_option(self::OPTION_NAME, array()) : array();
        return $this->sanitize(is_array($stored) ? $stored : array());
    }

    public function defaults(): array {
        return array(
            'image' => array('thumbnail_id','image','hero_image'),
            'gallery' => array('gallery','hero_gallery','term_gallery'),
            'taxonomies' => array(),
            'authorizing' => false,
        );
    }

    public function keysForTaxonomy(string $taxonomy): array {
        $taxonomy = sanitize_key($taxonomy);
        $all = $this->all();
        $specific = isset($all['taxonomies'][$taxonomy]) && is_array($all['taxonomies'][$taxonomy]) ? $all['taxonomies'][$taxonomy] : array();
        return array(
            'image' => $this->uniqueKeys(array_merge((array)($specific['image']??array()), (array)$all['image'])),
            'gallery' => $this->uniqueKeys(array_merge((array)($specific['gallery']??array()), (array)$all['gallery'])),
        );
    }

    public function save(array $input): array {
        $clean = $this->sanitize($input);
        $current = $this->all();
        if ($clean === $current) { return array('saved'=>true,'unchanged'=>true,'settings'=>$clean,'authorizing'=>false); }
        $saved = function_exists('update_option') ? update_option(self::OPTION_NAME, $clean, false) : false;
        return array('saved'=>(bool)$saved, 'unchanged'=>false, 'settings'=>$clean, 'authorizing'=>false);
    }

    public function sanitize(array $input): array {
        $defaults = $this->defaults();
        $out = array(
            'image' => $this->keyList($input['image'] ?? $defaults['image']),
            'gallery' => $this->keyList($input['gallery'] ?? $defaults['gallery']),
            'taxonomies' => array(),
            'authorizing' => false,
        );
        if (!$out['image']) { $out['image'] = $defaults['image']; }
        if (!$out['gallery']) { $out['gallery'] = $defaults['gallery']; }
        foreach (array_slice((array)($input['taxonomies']??array()),0,self::MAX_TAXONOMIES,true) as $taxonomy=>$row) {
            $taxonomy = sanitize_key((string)$taxonomy);
            if ('' === $taxonomy || !is_array($row)) { continue; }
            $out['taxonomies'][$taxonomy] = array(
                'image' => $this->keyList($row['image']??array()),
                'gallery' => $this->keyList($row['gallery']??array()),
            );
        }
        ksort($out['taxonomies'], SORT_STRING);
        return $out;
    }

    public function export(): array {
        $all = $this->all();
        return array(
            'contract'=>'etg.dfsb.media-discovery-registry.v1',
            'authorizing'=>false,
            'read_only'=>true,
            'global'=>array('image'=>$all['image'],'gallery'=>$all['gallery']),
            'taxonomies'=>$all['taxonomies'],
        );
    }

    private function keyList($value): array {
        if (is_string($value)) { $value = preg_split('/[\r\n,]+/', $value); }
        $out = array();
        foreach (array_slice((array)$value,0,self::MAX_KEYS_PER_KIND) as $key) {
            $key = FieldKey::normalize($key);
            if ('' !== $key) { $out[] = $key; }
        }
        return $this->uniqueKeys($out);
    }

    private function uniqueKeys(array $keys): array {
        $out = array(); $seen = array();
        foreach ($keys as $key) {
            $key = FieldKey::normalize($key);
            if ('' === $key || isset($seen[$key])) { continue; }
            $seen[$key] = true; $out[] = $key;
        }
        return $out;
    }
}
