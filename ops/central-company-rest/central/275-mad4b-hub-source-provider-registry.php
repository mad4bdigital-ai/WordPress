<?php
/**
 * Plugin Name: MAD4B Content Hub Company Source Provider Registry
 * Description: Governed read-only provider identity and transport registry for the central Content Hub.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Source_Providers_V1 {
    const CONTRACT='mad4b.hub.source-providers.v1';
    const LEDGER='mad4b_hub_import_execution_ledger_v1';
    private static $providers=[];
    private static function enabled(): bool {
        return wp_get_environment_type()==='staging' &&
            wp_parse_url(home_url('/'),PHP_URL_HOST)==='tours.dev.mad4b.com' &&
            class_exists('MAD4B_Central_Company_REST_V1');
    }
    /**
     * A custom integration can register a provider/dataset at init.
     * Only previously enrolled Company Controls hosts are accepted; no arbitrary host URLs.
     */
    public static function register($key,array $definition): bool {
        if(!self::enabled() || !is_string($key) ||
            !preg_match('/^[a-z][a-z0-9_]{2,50}$/D',$key) || isset(self::$providers[$key]))
            return false;
        $company=$definition['company_id']??null;
        $dataset=$definition['dataset_id']??null;
        $transport=$definition['transports']??null;
        $types=$definition['source_types']??null;
        $host=$definition['canonical_host']??null;
        if(!is_string($company) ||
            !isset(MAD4B_Central_Company_REST_V1::COMPANIES[$company]) ||
            $host!==MAD4B_Central_Company_REST_V1::COMPANIES[$company]['host'] ||
            !is_string($dataset) || !preg_match('/^[a-z][a-z0-9_]{2,50}$/D',$dataset) ||
            !is_array($transport) || !$transport || count($transport)>3 ||
            array_values($transport)!==$transport ||
            !is_array($types)||!$types||count($types)>20 ||
            array_values($types)!==$types)
            return false;
        foreach($transport as $method)
            if(!in_array($method,['wp_cli_cross_ssh','wp_rest_application_password'],true))
                return false;
        foreach($types as $type)
            if(!is_string($type)||!preg_match('/^[a-z0-9_-]{2,55}$/D',$type))return false;
        if(count(array_unique($transport))!==count($transport) ||
            count(array_unique($types))!==count($types))
            return false;
        self::$providers[$key]=[
            'provider_id'=>$key,'dataset_id'=>$dataset,
            'company_id'=>$company,'canonical_host'=>$host,
            'transports'=>$transport,'source_types'=>$types,
            'publish_allowed'=>false,'checkout_allowed'=>false,
            'auto_fusion_allowed'=>false,
        ];
        ksort(self::$providers,SORT_STRING);
        return true;
    }
    public static function defaults(): void {
        self::register('tourism_wp',[
            'company_id'=>'tourism-almallahgroup','dataset_id'=>'tourism_main',
            'canonical_host'=>'tourism.almallahgroup-mg.com',
            'transports'=>['wp_cli_cross_ssh','wp_rest_application_password'],
            'source_types'=>['tours-and-activities','properties','transportations'],
        ]);
    }
    public static function authorize($company,$provider,$dataset,$transport,array $types,$host) {
        if(!self::enabled())return new WP_Error('hub_provider_control_unavailable','Company controls not available');
        $p=self::$providers[$provider]??null;
        if(!$p || $p['company_id']!==$company || $p['dataset_id']!==$dataset ||
           $p['canonical_host']!==$host || !in_array($transport,$p['transports'],true) ||
           !$types || count(array_unique($types))!==count($types))
            return new WP_Error('hub_provider_unregistered','Unregistered provider or dataset');
        foreach($types as $type)
            if(!in_array($type,$p['source_types'],true))
                return new WP_Error('hub_provider_type_denied','Source type not allowed');
        return true;
    }
    public static function inspect(): array {
        if(!self::enabled())return ['contract'=>self::CONTRACT,'status'=>'unavailable'];
        return ['contract'=>self::CONTRACT,'providers'=>array_values(self::$providers),
            'ledger_tail'=>array_slice((array)get_option(self::LEDGER,[]),-12),
            'writes_enabled'=>false,'content_merge_enabled'=>false];
    }
    /** Must be called inside the source importer transaction, before COMMIT. */
    public static function record_import(array $receipt,$transport): bool {
        if(!self::enabled() || !is_string($transport) ||
           !in_array($transport,['wp_cli_cross_ssh','wp_rest_application_password'],true))
            return false;
        $rows=get_option(self::LEDGER,[]);
        if(!is_array($rows))return false;
        $entry=[
            'company_id'=>'tourism-almallahgroup','provider_id'=>'tourism_wp',
            'dataset_id'=>'tourism_main','transport'=>$transport,
            'snapshot_sha256'=>(string)($receipt['snapshot_sha256']??''),
            'imported'=>(int)($receipt['imported']??-1),
            'created'=>(int)($receipt['created']??-1),
            'updated'=>(int)($receipt['updated']??-1),
            'status'=>'committed','at'=>gmdate('c'),
        ];
        if(!preg_match('/^[a-f0-9]{64}$/D',$entry['snapshot_sha256'])||
           $entry['imported']<0)return false;
        $rows[]=$entry;$rows=array_slice($rows,-40);
        $result=update_option(self::LEDGER,$rows,false);
        return $result!==false || get_option(self::LEDGER,[])===$rows;
    }
    public static function rest(): void {
        register_rest_route('mad4b-central/v1','/hub-source-providers',[
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>static function(){return current_user_can('manage_options');},
            'callback'=>static function(){return new WP_REST_Response(self::inspect(),200);}
        ]);
    }
    public static function boot(): void {
        if(!self::enabled())return;
        add_action('init',[__CLASS__,'defaults'],14);
        add_action('rest_api_init',[__CLASS__,'rest']);
    }
}
MAD4B_Hub_Source_Providers_V1::boot();
