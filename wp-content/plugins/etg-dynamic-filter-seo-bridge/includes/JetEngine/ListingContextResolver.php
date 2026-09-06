<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

require_once dirname(__DIR__) . '/Identifiers/FieldKey.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;

final class ListingContextResolver {
    private $normalizer;

    public function __construct(ValueNormalizer $normalizer) { $this->normalizer = $normalizer; }

    public function currentObject() {
        if (function_exists('jet_engine')) {
            try {
                $engine = jet_engine();
                if (is_object($engine) && isset($engine->listings) && isset($engine->listings->data) && method_exists($engine->listings->data, 'get_current_object')) {
                    $object = $engine->listings->data->get_current_object();
                    if ($object) { return $object; }
                }
            } catch (\Throwable $e) {}
        }
        global $post;
        return $post ?: null;
    }

    public function property(string $key, $object=null) {
        $key = trim($key);
        if ('' === $key || !preg_match('/^[A-Za-z0-9_.-]+$/', $key)) { return ''; }
        $object = null === $object ? $this->currentObject() : $object;
        if (!$object) { return ''; }
        if (false !== strpos($key, '.') || false !== strpos($key, '/')) { return $this->normalizer->path($object, $key); }
        if (function_exists('jet_engine')) {
            try {
                $engine = jet_engine();
                if (isset($engine->listings->data) && method_exists($engine->listings->data, 'get_prop')) {
                    $value = $engine->listings->data->get_prop($key, $object);
                    if (null !== $value && false !== $value && '' !== $value) { return $value; }
                }
            } catch (\Throwable $e) {}
        }
        if (is_array($object) && array_key_exists($key, $object)) { return $object[$key]; }
        if (is_object($object) && isset($object->{$key})) { return $object->{$key}; }
        if (is_object($object) && 'WP_Post' === get_class($object)) {
            $map = array('id'=>'ID','title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content','slug'=>'post_name','date'=>'post_date');
            if (isset($map[$key]) && isset($object->{$map[$key]})) { return $object->{$map[$key]}; }
            if ('url' === $key && function_exists('get_permalink')) { return get_permalink($object); }
            if ('featured_image_id' === $key && function_exists('get_post_thumbnail_id')) { return (int)get_post_thumbnail_id($object); }
        }
        return '';
    }

    public function meta(string $key, $object=null) {
        $key = FieldKey::normalize($key);
        if ('' === $key) { return ''; }
        $object = null === $object ? $this->currentObject() : $object;
        if (!$object) { return ''; }
        if (function_exists('jet_engine')) {
            try {
                $engine = jet_engine();
                if (isset($engine->listings->data) && method_exists($engine->listings->data, 'get_meta')) {
                    $value = $engine->listings->data->get_meta($key, $object);
                    if (null !== $value && false !== $value && '' !== $value) { return $value; }
                }
            } catch (\Throwable $e) {}
        }
        if (is_array($object) && array_key_exists($key, $object)) { return $object[$key]; }
        if (is_object($object) && isset($object->{$key})) { return $object->{$key}; }
        if (is_object($object) && isset($object->ID) && function_exists('get_post_meta')) { return get_post_meta((int)$object->ID, $key, true); }
        if (is_object($object) && isset($object->term_id) && function_exists('get_term_meta')) { return get_term_meta((int)$object->term_id, $key, true); }
        if (is_object($object) && isset($object->user_login) && isset($object->ID) && function_exists('get_user_meta')) { return get_user_meta((int)$object->ID, $key, true); }
        return '';
    }

    public function repeaterRows(string $metaKey, $object=null): array {
        return $this->normalizer->rows($this->meta($metaKey, $object));
    }

    public function describe($object=null): array {
        $object = null === $object ? $this->currentObject() : $object;
        if (!$object) { return array('available'=>false,'type'=>'none','id'=>0); }
        $type = is_object($object) ? get_class($object) : (is_array($object) ? 'array' : gettype($object));
        $id = 0;
        foreach (array('ID','id','_ID','term_id') as $key) {
            if (is_object($object) && isset($object->{$key}) && is_numeric($object->{$key})) { $id=(int)$object->{$key}; break; }
            if (is_array($object) && isset($object[$key]) && is_numeric($object[$key])) { $id=(int)$object[$key]; break; }
        }
        return array('available'=>true,'type'=>$type,'id'=>$id,'authorizing'=>false);
    }
}
