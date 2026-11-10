<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_Provider_Contract {
	const CONTRACT='mad4b.dynamic-provider-manifest.v1';
	const MAX_PROVIDERS=64;

	public static function manifests(){
		$raw=apply_filters('mad4b_scp_dynamic_provider_manifests',array());
		$out=array();
		foreach(array_slice(is_array($raw)?$raw:array(),0,self::MAX_PROVIDERS) as $manifest){
			$n=self::normalize($manifest);
			if(!is_wp_error($n)) $out[$n['provider_id']]=$n;
		}
		ksort($out,SORT_STRING);
		return array_values($out);
	}

	public static function normalize($manifest){
		if(!is_array($manifest)) return new WP_Error('mad4b_dynamic_provider_manifest_invalid','Provider manifest must be an array.');
		$id=isset($manifest['provider_id'])?sanitize_key((string)$manifest['provider_id']):'';
		$registry=isset($manifest['existing_registry_identity'])?sanitize_key((string)$manifest['existing_registry_identity']):'';
		if($id===''||$registry==='') return new WP_Error('mad4b_dynamic_provider_identity_missing','Provider manifest requires provider_id and existing_registry_identity.');
		$effects=array();
		foreach(isset($manifest['side_effects'])&&is_array($manifest['side_effects'])?array_slice($manifest['side_effects'],0,100):array() as $effect){
			if(!is_array($effect)) continue;
			$effects[]=array(
				'effect_scope'=>isset($effect['effect_scope'])?sanitize_key((string)$effect['effect_scope']):'unknown',
				'ownership'=>self::enum($effect,'ownership',array('core','provider','external','shared'),'external'),
				'reversibility'=>self::enum($effect,'reversibility',array('reversible','irreversible','unknown'),'unknown'),
				'compensation_support'=>self::enum($effect,'compensation_support',array('none','best_effort','verified'),'none'),
				'externality'=>self::enum($effect,'externality',array('local','remote','external_system'),'external_system'),
			);
		}
		return array(
			'contract'=>self::CONTRACT,
			'provider_id'=>$id,
			'existing_registry_identity'=>$registry,
			'implementation_version'=>isset($manifest['implementation_version'])?substr(sanitize_text_field((string)$manifest['implementation_version']),0,64):'',
			'supports'=>isset($manifest['supports'])&&is_array($manifest['supports'])?array_values(array_unique(array_map('sanitize_key',array_slice($manifest['supports'],0,100)))):array(),
			'side_effects'=>$effects,
			'soft_elapsed_budget_ms'=>isset($manifest['soft_elapsed_budget_ms'])?max(1,min(30000,absint($manifest['soft_elapsed_budget_ms']))):30000,
			'enforceable_deadline_supported'=>!empty($manifest['enforceable_deadline_supported']),
			'manifest_presence_authorizes'=>false,
		);
	}
	private static function enum(array $a,$key,array $allowed,$fallback){ $v=isset($a[$key])?sanitize_key((string)$a[$key]):''; return in_array($v,$allowed,true)?$v:$fallback; }
}


/**
 * Existing Elementor owner -> existing MAD4B provider manifests and Content
 * Pipeline. This is a passive site-local readback, NOT another executor.
 * A target's certified original provider owns regeneration after import.
 */
final class MAD4B_SCP_Elementor_CSS_Existing_Provider_Bridge {
    private static function available(): bool {
        return class_exists('Elementor\\Plugin', false)
            && class_exists('Elementor\\Core\\Files\\CSS\\Post');
    }
    public static function manifest($items) {
        if (!is_array($items) || !self::available()) return $items;
        $items[] = array(
            'provider_id' => 'elementor_native_css',
            'existing_registry_identity' => 'elementor',
            'implementation_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            'supports' => array('generated_css_readback','generated_css_native_rebuild'),
            'side_effects' => array(array(
                'effect_scope' => 'generated_css',
                'ownership' => 'provider',
                'reversibility' => 'reversible',
                'compensation_support' => 'verified',
                'externality' => 'local'
            )),
            'soft_elapsed_budget_ms' => 30000,
            'enforceable_deadline_supported' => false,
        );
        return $items;
    }
    private static function finding(int $id, string $code, array $detail=array()): array {
        return array_merge(array(
            'code'=>$code, 'post_id'=>$id, 'provider_id'=>'elementor_native_css',
            'repairable'=>false, 'requires_native_provider_readback'=>true,
            'write_authorized'=>false
        ), $detail);
    }
    public static function validate($findings, $context, $policy, $stage) {
        if (!is_array($findings) || !is_array($context)) return $findings;
        $id = absint($context['post_id'] ?? 0);
        if ($id < 1 || !get_post($id)) return $findings;
        $kit = (int) get_option('elementor_active_kit');
        $source = get_post_meta($id, '_elementor_data', true);
        if ($id !== $kit && (!is_string($source) || strlen($source)<2)) return $findings;
        if (!self::available())
            return array_merge($findings, array(self::finding($id,'native_css_provider_unavailable')));
        if (is_string($source) && strlen($source)>2097152)
            return array_merge($findings, array(self::finding($id,'native_css_source_oversize')));
        try {
            // Preview and stored readback are separate. Never call update().
            $expected = (new \Elementor\Core\Files\CSS\Post($id))->get_content();
        } catch (\Throwable $error) {
            return array_merge($findings, array(self::finding($id,'native_css_preview_unavailable')));
        }
        if (!is_string($expected) || strlen($expected)>2097152)
            return array_merge($findings, array(self::finding($id,'native_css_preview_invalid')));
        $stored = get_post_meta($id,'_elementor_css',true);
        if (!is_array($stored) || ($stored['status']??'')!=='inline')
            return array_merge($findings, array(self::finding($id,'native_css_requires_file_mode_readback')));
        $actual = (string)($stored['css']??'');
        if (!hash_equals(hash('sha256',$expected),hash('sha256',$actual)))
            $findings[] = self::finding($id,'native_css_generated_drift',array(
                'expected_css_sha256'=>hash('sha256',$expected),
                'stored_css_sha256'=>hash('sha256',$actual)
            ));
        return $findings;
    }
}
// Only register WordPress hooks after its hook API is available. Standalone
// pure-policy tests may load this file before the WordPress runtime exists;
// the real WordPress plugin boot still registers both original filters.
if (function_exists('add_filter')) {
    add_filter('mad4b_scp_dynamic_provider_manifests',
        array('MAD4B_SCP_Elementor_CSS_Existing_Provider_Bridge','manifest'),20,1);
    add_filter('mad4b_scp_dynamic_content_policy_findings',
        array('MAD4B_SCP_Elementor_CSS_Existing_Provider_Bridge','validate'),20,4);
}
