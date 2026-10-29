<?php
/**
 * Presentation Compatibility Adapter Registry v1.
 * Read-only request-scoped descriptors of native UI bindings.
 * This class neither registers UI elements nor changes pricing or orders.
 */
defined('ABSPATH') || exit;
// Conditional declaration: a top-level return is not enough to prevent
// PHP's compile-time registration of an unconditional class declaration.
if (function_exists('wp_get_environment_type')
    && wp_get_environment_type() === 'local'
    && defined('SHARED_PRODUCT_LAB_ONLY')
    && SHARED_PRODUCT_LAB_ONLY
) {
final class Shared_Product_Presentation_Adapter_Registry_v1 {
    private const VERSION = '1.0.0';
    private const ALLOWED_ADAPTERS = ['jetengine_listing'];
    private const FIELDS = ['id','adapter','slot','mode','priority','enabled','binding'];
    private const BINDING_FIELDS = ['slot','mode','template_id','listing_id','query_id','provider_id'];

    private array $registered = [];
    private bool $sealed = false;

    private static function result(bool $ok, string $code, array $extra=[]): array {
        return ['ok'=>$ok,'code'=>$code] + $extra;
    }

    public function register(array $descriptor): array {
        if ($this->sealed) return self::result(false,'REGISTRY_SEALED');
        if (array_diff(self::FIELDS,array_keys($descriptor))) return self::result(false,'MISSING_DESCRIPTOR_FIELD');
        if (array_diff(array_keys($descriptor),self::FIELDS)) return self::result(false,'UNKNOWN_DESCRIPTOR_FIELD');
        $id=$descriptor['id'];
        if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$id))
            return self::result(false,'INVALID_ADAPTER_ID');
        if (!is_string($descriptor['adapter']) || !in_array($descriptor['adapter'],self::ALLOWED_ADAPTERS,true))
            return self::result(false,'UNSUPPORTED_ADAPTER');
        if (!is_int($descriptor['priority']) || $descriptor['priority']<0 || $descriptor['priority']>100 ||
            !is_bool($descriptor['enabled'])) return self::result(false,'INVALID_DESCRIPTOR_TYPE');
        if (!is_array($descriptor['binding']) ||
            array_diff(self::BINDING_FIELDS,array_keys($descriptor['binding'])) ||
            array_diff(array_keys($descriptor['binding']),self::BINDING_FIELDS))
            return self::result(false,'INVALID_BINDING_SHAPE');
        if (!is_string($descriptor['slot']) || !is_string($descriptor['mode']) ||
            $descriptor['slot']!==$descriptor['binding']['slot'] ||
            $descriptor['mode']!==$descriptor['binding']['mode'])
            return self::result(false,'CONTEXT_BINDING_MISMATCH');
        if (isset($this->registered[$id])) {
            if ($this->registered[$id] === $descriptor) return self::result(true,'ALREADY_REGISTERED');
            return self::result(false,'REGISTRATION_ID_CONFLICT');
        }
        if (!class_exists('Shared_Product_Presentation_Compatibility_v1'))
            return self::result(false,'COMPATIBILITY_FACADE_UNAVAILABLE');
        $validation=Shared_Product_Presentation_Compatibility_v1::validate_binding($descriptor['binding']);
        if (empty($validation['ok'])) return self::result(false,(string)($validation['code']??'BINDING_REJECTED'));
        $this->registered[$id]=$descriptor;
        return self::result(true,'REGISTERED',['id'=>$id]);
    }

    public function seal(): array {
        if ($this->sealed) return self::result(true,'ALREADY_SEALED');
        $this->sealed=true;
        return self::result(true,'SEALED',['count'=>count($this->registered)]);
    }

    public function resolve(string $slot,string $mode): array {
        if (!$this->sealed) return self::result(false,'REGISTRY_NOT_SEALED');
        if ($slot!=='tour.rate_list') return self::result(false,'UNKNOWN_SLOT');
        if (!in_array($mode,['grid','accordion'],true)) return self::result(false,'INVALID_DISPLAY_MODE');
        $eligible=[];
        foreach ($this->registered as $descriptor) {
            if ($descriptor['enabled'] && $descriptor['slot']===$slot && $descriptor['mode']===$mode)
                $eligible[]=$descriptor;
        }
        if (!$eligible) return self::result(false,'NO_ELIGIBLE_ADAPTER');
        usort($eligible,static fn($a,$b)=>($b['priority']<=>$a['priority']) ?: strcmp($a['id'],$b['id']));
        if (count($eligible)>1 && $eligible[0]['priority']===$eligible[1]['priority'])
            return self::result(false,'AMBIGUOUS_PRIORITY',
                ['conflicting_ids'=>[$eligible[0]['id'],$eligible[1]['id']]]);
        return self::result(true,'RESOLVED',['winner'=>$eligible[0]]);
    }

    /**
     * Deterministic native transport Slot resolver. No mutation or autonomous
     * adapter registration. Supports v1 rate arbitration unchanged.
     */
    public function resolve_component(string $slot): array {
        if (!$this->sealed) return self::result(false,'REGISTRY_NOT_SEALED');
        if (!class_exists('Shared_Product_Presentation_Transport_Context_v1'))
            return self::result(false,'TRANSPORT_CONTEXT_UNAVAILABLE');
        foreach (Shared_Product_Presentation_Transport_Context_v1::categories() as $key=>$schema) {
            if ($schema['slot']!==$slot) continue;
            $binding=Shared_Product_Presentation_Transport_Context_v1::binding($key);
            if (empty($binding['ok']))return $binding;
            return self::result(true,'RESOLVED_NATIVE_COMPONENT',
              ['slot'=>$slot,'adapter'=>'jetengine_listing','binding'=>$binding,
               'read_only'=>true,'booking_authorized'=>false]);
        }
        return self::result(false,'UNKNOWN_SLOT');
    }

    public function snapshot(): array {
        $ids=array_keys($this->registered);
        sort($ids,SORT_STRING);
        return ['schema_version'=>self::VERSION,'read_only'=>true,
                'sealed'=>$this->sealed,'size'=>count($ids),'ids'=>$ids];
    }
}
} // Strict Local Lab-only conditional declaration.
