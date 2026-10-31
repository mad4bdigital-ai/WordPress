<?php
/**
 * Plugin Name: MAD4B Provider Qualification Bridge
 * Description: Read-only native WordPress Ability for inherited provider qualification; no authorization or mutation.
 * Version: 0.1.0
 * Author: MAD4B
 */
defined('ABSPATH') || exit;

require_once __DIR__.'/includes/class-mad4b-scp-provider-auto-qualification.php';

final class MAD4B_Provider_Qualification_Bridge {
    const ABILITY='mad4b/provider-auto-qualification-plan';
    const VERSION='0.1.0';

    public static function scope() {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type()==='staging'
            && function_exists('home_url')
            && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }

    public static function allowed($input = array()) {
        return self::scope() && current_user_can('manage_options')
            && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_read();
    }

    public static function plan($input = array()) {
        if (!self::allowed($input))
            return new WP_Error('mad4b_provider_qualification_read_denied','Provider qualification read access denied.');
        if (!class_exists('MAD4B_SCP_Provider_Compatibility_Certification'))
            return new WP_Error('mad4b_provider_qualification_owner_missing','Canonical provider compatibility owner missing.');
        $input=is_array($input)?$input:array();
        $ability=isset($input['ability'])?trim((string)$input['ability']):'';
        $provider=isset($input['provider_id'])?sanitize_key((string)$input['provider_id']):'';
        if ($ability==='' && $provider==='')
            return new WP_Error('mad4b_provider_qualification_target_required','Supply a provider_id or exact ability.');
        if ($ability!=='' && !preg_match('/^[A-Za-z0-9._\/-]{3,160}$/D',$ability))
            return new WP_Error('mad4b_provider_qualification_ability_invalid','Invalid native ability.');
        $inventory=MAD4B_SCP_Provider_Compatibility_Certification::inventory();
        $assessments=(array)($inventory['providers']??array());
        $matches=array();
        $build='unknown';
        // Only exact native provenance permits handoff to a behavioral probe.
        // No build status can grant execution authority here.
        foreach ($assessments as $key=>$assessment) {
            if (!is_array($assessment)) continue;
            if ($provider!=='' && !hash_equals($provider,(string)$key)) continue;
            if ($ability!=='') {
                $found=false;
                foreach ((array)($assessment['capabilities']??array()) as $capability) {
                    if (is_array($capability) && in_array($ability,(array)($capability['mounted_abilities']??array()),true)) {
                        $found=true; break;
                    }
                }
                if (!$found) continue;
            }
            $matches[$key]=$assessment;
        }
        if (count($matches)!==1)
            return new WP_Error('mad4b_provider_qualification_binding_not_unique',
                'Exact provider binding must resolve to one cataloged native adapter.',
                array('match_count'=>count($matches),'provider_id'=>$provider,'ability'=>$ability));
        $assessment=reset($matches);
        if (class_exists('MAD4B_SCP_Live_Acceptance_Observer')
            && method_exists('MAD4B_SCP_Live_Acceptance_Observer','build_provenance_status')) {
            $p=MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
            if (is_array($p) && !empty($p['manifest_present']) && !empty($p['manifest_valid'])
                && !empty($p['runtime_manifest_match']) && empty($p['stale'])
                && !empty($p['source_commit_sha']) && !empty($p['build_fingerprint']))
                $build='exact_current';
        }
        $plan=MAD4B_SCP_Provider_Auto_Qualification::plan(
            $assessment,$ability,array('build_provenance_state'=>$build)
        );
        $plan['site']=home_url('/');
        $plan['environment']=wp_get_environment_type();
        $plan['native_provenance_state']=$build;
        $plan['native_provider_owner']='MAD4B_SCP_Provider_Compatibility_Certification';
        $plan['dispatch_performed']=false;
        $plan['read_only']=true;
        return $plan;
    }

    public static function register() {
        if (!self::scope() || !function_exists('wp_register_ability')) return;
        if (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY)) return;
        wp_register_ability(self::ABILITY,array(
            'label'=>'Provider Auto Qualification Plan',
            'description'=>'Read native evidence and prepare the next qualification action for a demanded provider ability without executing a write or altering certification.',
            'category'=>'mad4b-read',
            'execute_callback'=>array(__CLASS__,'plan'),
            'permission_callback'=>array(__CLASS__,'allowed'),
            'input_schema'=>array(
                'type'=>'object',
                'properties'=>array(
                    'provider_id'=>array('type'=>'string','minLength'=>1,'maxLength'=>64,'pattern'=>'^[a-z0-9_-]+$'),
                    'ability'=>array('type'=>'string','minLength'=>3,'maxLength'=>160,'pattern'=>'^[A-Za-z0-9._/-]+$'),
                ),
                'additionalProperties'=>false,
            ),
            'output_schema'=>array('type'=>'object','additionalProperties'=>true),
            'meta'=>array(
                'public'=>false,'show_in_rest'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),
            ),
        ));
    }
}
add_action('wp_abilities_api_init',array('MAD4B_Provider_Qualification_Bridge','register'),99);
