<?php
/**
 * Plugin Name: MAD4B Company Options Registry & Customization
 * Description: Tenant-only JetEngine Options Page registry and pending-input UI.
 * Version: 0.1.0
 *
 * Important: this plugin never copies source business values or writes into
 * JetEngine Options Pages. JetEngine remains the tenant's authoritative editor.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Company_Options_Registry_UI', false)) {
final class MAD4B_Company_Options_Registry_UI {
    const OPTION = 'mad4b_company_options_field_registry_v1';

    public static function init() {
        add_action('admin_menu', array(__CLASS__,'register_admin'));
        add_action('rest_api_init',array(__CLASS__,'register_rest'));
    }
    private static function registry() {
        $r=get_option(self::OPTION,array());
        if(!is_array($r) ||
           ($r['contract']??'')!=='mad4b.company-options-field-registry.v1'||
           !is_array($r['pages']??null) || empty($r['fingerprint']))return null;
        return $r;
    }
    private static function summary() {
        $registry=self::registry();
        $company=get_option('mad4b_company_site_identity_v1',array());
        $out=array(
            'contract'=>'mad4b.company-options-customization-status.v1',
            'company'=>is_array($company)?sanitize_key((string)($company['company_code']??'')):'',
            'site_uuid'=>is_array($company)?(string)($company['site_uuid']??''):'',
            'definition_authority'=>'MAD4B Control Plane',
            'value_authority'=>'own JetEngine Options Page',
            'registry_fingerprint'=>$registry['fingerprint']??null,
            'status'=>$registry?'READY_FOR_COMPANY_INPUT':'REGISTRY_NOT_SYNCED',
            'pages'=>array(),
        );
        if(!$registry)return $out;
        foreach($registry['pages'] as $slug=>$page) {
            if(!preg_match('/^[a-z0-9-]+$/D',(string)$slug))continue;
            $saved=get_option($slug,null);
            $entries=array();
            $filled=0;
            $pending=0;
            $form_fields=0;
            foreach($page['fields'] as $f){
                if(!isset($f['key'],$f['type'])||!is_string($f['key']))continue;
                $key=(string)$f['key'];$type=(string)$f['type'];
                $structural=($type==='html' ||
                    (bool)preg_match('/(?:_tab|_section_intro|_section)$/D',$key));
                $present=is_array($saved)&&array_key_exists($key,$saved);
                $has=$present && $saved[$key]!==null &&
                   $saved[$key]!=='' && $saved[$key]!==array();
                if(!$structural){
                    $form_fields++;
                    if($has)$filled++;else $pending++;
                }
                $entries[]=array(
                    'key'=>$key,
                    'type'=>$type,
                    'label'=>(string)($f['label']??$key),
                    'is_input'=>!$structural,
                    'state'=>$structural?'STRUCTURAL':($has?'CONFIGURED':'EMPTY_FOR_CUSTOMIZATION'),
                );
            }
            $out['pages'][$slug]=array(
                'label'=>(string)($page['label']??$slug),
                'scope'=>(string)($page['scope']??'company_configuration'),
                'editor'=>admin_url('admin.php?page='.$slug),
                'definition_field_count'=>count($entries),
                'editable_field_count'=>$form_fields,
                'configured_count'=>$filled,
                'pending_count'=>$pending,
                'values_inherited_from_source'=>false,
                'fields'=>$entries
            );
        }
        return $out;
    }
    public static function register_rest() {
        register_rest_route('mad4b/v1','/company-configuration/fields',array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>static function($request) {
                $res=new WP_REST_Response(self::summary(),200);
                $res->header('Cache-Control','private, no-store');
                return $res;
            },
            'permission_callback'=>static function(){
                return current_user_can('manage_options');
            }
        ));
    }
    public static function register_admin() {
        add_menu_page('Company Configuration','Company Configuration',
            'manage_options','mad4b-company-configuration',
            array(__CLASS__,'render'),'dashicons-admin-generic',65);
    }
    public static function render() {
        if(!current_user_can('manage_options'))wp_die('Not permitted');
        $state=self::summary();
        echo '<div class="wrap"><h1>Company Configuration</h1>';
        echo '<p>Field definitions are governed by the MAD4B Control Plane. Company values stay in this site’s JetEngine Options Pages. Unconfigured values remain empty; no source brand values are imported.</p>';
        if($state['status']!=='READY_FOR_COMPANY_INPUT'){
            echo '<div class="notice notice-error"><p>Company Options Registry not synchronized.</p></div></div>';return;
        }
        echo '<table class="widefat striped"><thead><tr><th>JetEngine Page</th><th>Editable fields</th><th>Configured</th><th>Pending</th><th>Action</th></tr></thead><tbody>';
        foreach($state['pages'] as $slug=>$p){
            echo '<tr><td><strong>'.esc_html($p['label']).'</strong><br><code>'.esc_html($slug).'</code></td>';
            echo '<td>'.esc_html((string)$p['editable_field_count']).'</td>';
            echo '<td>'.esc_html((string)$p['configured_count']).'</td>';
            echo '<td>'.esc_html((string)$p['pending_count']).'</td>';
            echo '<td><a class="button" href="'.esc_url($p['editor']).'">Edit in JetEngine</a></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Fields awaiting company input</h2>';
        foreach($state['pages'] as $slug=>$page){
            $names=array();
            foreach($page['fields'] as $f)if($f['state']==='EMPTY_FOR_CUSTOMIZATION')
                $names[]=esc_html($f['label']).' <code>'.esc_html($f['key']).'</code>';
            if(!$names)continue;
            echo '<details style="margin-bottom:10px"><summary><strong>'.esc_html($page['label']).'</strong> — '.
                esc_html((string)$page['pending_count']).' pending</summary>';
            echo '<p>'.implode(' · ',$names).'</p></details>';
        }
        echo '</div>';
    }
}
MAD4B_Company_Options_Registry_UI::init();
}

