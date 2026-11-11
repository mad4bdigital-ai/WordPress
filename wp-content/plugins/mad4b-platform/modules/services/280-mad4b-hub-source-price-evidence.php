<?php
/**
 * Plugin Name: MAD4B Tourism Source Price Evidence Registry (Staging)
 * Description: Versioned, source-provenanced private price/rate observations. Not a payable quote.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Source_Price_Evidence_V1 {
    const VERSION='0.1.0';
    const SCHEMA='mad4b_hub_source_price_evidence_schema';
    const RECEIPT='mad4b_hub_source_price_evidence_last_receipt';
    const SOURCE='tourism-almallahgroup';
    private static function t($name) {global $wpdb;return $wpdb->prefix.'mad4b_hub_price_'.$name;}
    private static function admin() {
        return class_exists('MAD4B_Hub_Canonical_Registry_V1') &&
          MAD4B_Hub_Canonical_Registry_V1::site() && current_user_can('manage_options');
    }
    private static function fail($code,$status=409) {
        return new WP_Error('source_rate_'.$code,'Source rate observation refused',['status'=>$status]);
    }
    private static function ready() {return get_option(self::SCHEMA)==self::VERSION;}
    public static function install() {
        if(!self::admin())return self::fail('permission',403);
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $a=self::t('observations');$b=self::t('versions');$collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $a (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_key char(64) NOT NULL,
            hub_post_id bigint(20) unsigned NOT NULL,
            canonical_id bigint(20) unsigned NOT NULL,
            source_company varchar(90) NOT NULL,
            source_type varchar(65) NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            source_hash char(64) NOT NULL,
            version bigint(20) unsigned NOT NULL,
            rates_count int(11) NOT NULL,
            observation_json longtext NOT NULL,
            captured_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY record_key (record_key),
            UNIQUE KEY hub_post_id (hub_post_id),
            KEY company_source (source_company,source_type,source_id)
        ) $collate;");
        dbDelta("CREATE TABLE $b (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            observation_id bigint(20) unsigned NOT NULL,
            version bigint(20) unsigned NOT NULL,
            source_hash char(64) NOT NULL,
            observation_json longtext NOT NULL,
            captured_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY observation_version (observation_id,version)
        ) $collate;");
        foreach([$a,$b] as $table)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return self::fail('tables_not_created');
        update_option(self::SCHEMA,self::VERSION,false);
        return ['schema'=>self::VERSION,'tables'=>2,'commercial_authorized'=>false];
    }
    private static function monetary($s): bool {
        return is_string($s) && preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?$/D',$s) &&
          (float)$s>0 && (float)$s<1000000000;
    }
    private static function validate_prices($obj,$names) {
        if(!is_array($obj)||array_diff(array_keys($obj),$names))return false;
        foreach($obj as $price)if(!self::monetary($price))return false;
        return true;
    }
    private static function validate_rate($r,$type): bool {
        if(!is_array($r)||($r['commercial_verified']??null)!==false||
            ($r['bookable']??null)!==false||
            !is_int($r['source_rate_id']??null) || $r['source_rate_id']<1)return false;
        if(!in_array($r['source_rate_type']??null,
            $type==='tours-and-activities'?['tour-rates']:
                ($type==='transportations'?['jet_cct_transport_rates']:[]),true))return false;
        $prices=$type==='tours-and-activities'?
           ['single_price','double_price','triple_price','egyptians_single_price',
            'egyptians_double_price','egyptians_triple_price','single_sale_price',
            'double_sale_price','triple_sale_price']:
           ['base_price','sale_price','base_price_egyptian','sale_price_egyptian'];
        if(!self::validate_prices($r['prices']??null,$prices))return false;
        foreach(['currency','egyptians_currency'] as $k)if(($r[$k]??null)!==null &&
            (!is_string($r[$k])||!preg_match('/^[A-Z]{3}$/D',$r[$k])))return false;
        foreach(['valid_from','valid_until'] as $k)if(($r[$k]??null)!==null &&
            (!is_int($r[$k])||$r[$k]<946684800||$r[$k]>4102444800))return false;
        // Reversed source date ranges are retained as unbookable source evidence,
        // never discarded or silently repaired. Quote preview marks them invalid.
        if(!is_bool($r['stop_sale']??null))return false;
        return true;
    }
    public static function import(array $manifest) {
        if(!self::admin() || !self::ready() || !defined('MAD4B_HUB_RATES_HOST_RUNNER') ||
           MAD4B_HUB_RATES_HOST_RUNNER!==true || !defined('WP_CLI')||!WP_CLI)
            return self::fail('operator_required',403);
        if(($manifest['contract']??null)!=='mad4b.hub.tourism-source-rates-snapshot.v1'||
          ($manifest['source_company']??null)!==self::SOURCE||
          ($manifest['canonical_host']??null)!=='tourism.almallahgroup-mg.com'||
          ($manifest['provider']??null)!=='tourism_wp'||
          ($manifest['dataset']??null)!=='tourism_main'||
          ($manifest['commercial_authorized']??null)!==false||
          ($manifest['bookable']??null)!==false ||
          !is_array($manifest['items']??null) ||
          !is_array($manifest['counts']??null))
            return self::fail('manifest_invalid');
        $items=$manifest['items'];
        if(count($items)!==array_sum($manifest['counts'])||count($items)>3000||
          $manifest['counts']!==[
           'tours-and-activities'=>217,'properties'=>597,'transportations'=>13])
            return self::fail('manifest_counts');
        $hash=hash('sha256',wp_json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if(!is_string($manifest['items_sha256']??null) ||
          !hash_equals($hash,$manifest['items_sha256']))return self::fail('manifest_digest');
        $active=get_option(MAD4B_Hub_Source_Import_V1::ACTIVE,[]);
        if(($active['source_company']??null)!==self::SOURCE ||
          !is_array($active['keys']??null) || count($active['keys'])!==827)
            return self::fail('active_snapshot_mismatch');
        $activeKeys=array_fill_keys($active['keys'],true);
        global $wpdb;
        $can=$wpdb->prefix.'mad4b_hub_canonical_entities';
        $entries=$wpdb->get_results($wpdb->prepare("SELECT source_hub_id,id,snapshot_json,company_id,source_type,state FROM $can WHERE company_id=%s",self::SOURCE),ARRAY_A);
        if(count($entries)!==827)return self::fail('canonical_coverage');
        $bySource=[];
        foreach($entries as $e){
            $j=json_decode($e['snapshot_json'],true);
            if(!is_array($j))return self::fail('canonical_snapshot_corrupt');
            $key=($e['source_type']??'').':'.($j['source_id']??'');
            if(isset($bySource[$key]))return self::fail('source_key_collision');
            $bySource[$key]=$e;
        }
        $validStart=[
          'tours-and-activities'=>['start_price','egyptians_start_price'],
          'properties'=>['prop_start_price'],
          'transportations'=>['trans_start_price','trans_sale_start_price']
        ];
        $validCurrency=[
          'tours-and-activities'=>['tour_base_currency','tour_egyptians_currency'],
          'properties'=>['prop_base_currency','base_currency','currency'],
          'transportations'=>['trans_base_currency']
        ];
        $prepared=[];$seen=[];$rates=0;$filled=0;$invalidDateRanges=0;
        foreach($items as $row){
            if(!is_array($row)||!in_array($row['source_type']??null,
               MAD4B_Hub_Canonical_Registry_V1::ALLOWED_TYPES,true) ||
               !is_int($row['source_id']??null)||$row['source_id']<1||
               !is_array($row['rates']??null)||count($row['rates'])>100||
               ($row['rates_commercial_verified']??null)!==false||
               ($row['inventory_verified']??null)!==false||
               ($row['checkout_authorized']??null)!==false) return self::fail('invalid_item');
            $type=$row['source_type'];$sid=$row['source_id'];$key=$type.':'.$sid;
            if(isset($seen[$key])||!isset($bySource[$key]))return self::fail('item_identity');
            $seen[$key]=true;
            $e=$bySource[$key];$j=json_decode($e['snapshot_json'],true);
            $activeKey=self::SOURCE.':'.$type.':'.($j['language']??'').':'.$sid;
            if(!isset($activeKeys[$activeKey]) ||
               ($j['provider']??null)!=='tourism_wp'||($j['dataset']??null)!=='tourism_main')
                return self::fail('provider_binding');
            if(!self::validate_prices($row['starting_prices']??null,$validStart[$type]))return self::fail('starting_amount_invalid');
            if(!is_array($row['currencies']??null) ||
               array_diff(array_keys($row['currencies']),$validCurrency[$type]))
                return self::fail('currencies_invalid');
            foreach($row['currencies'] as $code)if(!is_string($code)||!preg_match('/^[A-Z]{3}$/D',$code))
                return self::fail('currency_code');
            foreach($row['rates'] as $rate){
                if(!self::validate_rate($rate,$type))return self::fail('rate_fields_or_state');
                if(($rate['valid_from']??null)!==null && ($rate['valid_until']??null)!==null &&
                    $rate['valid_from']>$rate['valid_until'])$invalidDateRanges++;
            }
            $rates+=count($row['rates']);
            if(!empty($row['starting_prices']))$filled++;
            $prepared[]=['canonical_id'=>(int)$e['id'],'hub_post_id'=>(int)$e['source_hub_id'],
                'type'=>$type,'source_id'=>$sid,'payload'=>$row];
        }
        if(count($prepared)!==827||$rates!==(int)($manifest['rates_attached']??-1))
            return self::fail('snapshot_rate_counts');
        $a=self::t('observations');$b=self::t('versions');
        $created=0;$updated=0;$unchanged=0;$now=gmdate('Y-m-d H:i:s');
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)','mad4b_hub_prices_import'))!==1)
            return self::fail('busy');
        try{
            $wpdb->query('START TRANSACTION');
            foreach($prepared as $p){
                $json=wp_json_encode($p['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if(!$json)throw new RuntimeException('encoding');
                $sha=hash('sha256',$json);
                $key=hash('sha256',self::SOURCE.'|'.$p['type'].'|'.$p['source_id']);
                $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $a WHERE record_key=%s",$key),ARRAY_A);
                if($r && ((int)$r['hub_post_id']!==$p['hub_post_id'] ||
                  (int)$r['canonical_id']!==$p['canonical_id']))throw new RuntimeException('canonical_moved');
                if($r && hash_equals($sha,$r['source_hash'])){$unchanged++;continue;}
                $version=$r?(int)$r['version']+1:1;
                $data=[
                    'record_key'=>$key,'hub_post_id'=>$p['hub_post_id'],'canonical_id'=>$p['canonical_id'],
                    'source_company'=>self::SOURCE,'source_type'=>$p['type'],
                    'source_id'=>$p['source_id'],'source_hash'=>$sha,'version'=>$version,
                    'rates_count'=>count($p['payload']['rates']),'observation_json'=>$json,
                    'captured_at'=>gmdate('Y-m-d H:i:s',strtotime($manifest['captured_at'])),
                    'updated_at'=>$now
                ];
                if($r){if($wpdb->update($a,$data,['id'=>(int)$r['id']])===false)
                    throw new RuntimeException('row_update');$oid=(int)$r['id'];$updated++;}
                else {if(!$wpdb->insert($a,$data))throw new RuntimeException('row_insert');
                    $oid=(int)$wpdb->insert_id;$created++;}
                if(!$wpdb->insert($b,['observation_id'=>$oid,'version'=>$version,
                    'source_hash'=>$sha,'observation_json'=>$json,'captured_at'=>$now]))
                    throw new RuntimeException('version_insert');
            }
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');return self::fail('transaction_'.$e->getMessage(),503);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)','mad4b_hub_prices_import'));}
        $receipt=['contract'=>'mad4b.hub.price-import-receipt.v1',
            'source_company'=>self::SOURCE,'source_items'=>827,'rates_count'=>$rates,
            'starting_price_items'=>$filled,'invalid_date_range_rates'=>$invalidDateRanges,
            'created'=>$created,'updated'=>$updated,
            'unchanged'=>$unchanged,'source_items_sha256'=>$hash,
            'captured_at'=>$manifest['captured_at'],'commerce_ready'=>false,
            'checkout_authorized'=>false,'inventory_verified'=>false];
        update_option(self::RECEIPT,$receipt,false);
        return $receipt;
    }
    public static function status() {
        if(!self::admin())return self::fail('permission',403);
        if(!self::ready())return ['installed'=>false];
        global $wpdb;$a=self::t('observations');$b=self::t('versions');
        return ['installed'=>true,'source_records'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $a"),
           'rate_rows'=>(int)$wpdb->get_var("SELECT COALESCE(SUM(rates_count),0) FROM $a"),
           'versions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $b"),
           'last_receipt'=>get_option(self::RECEIPT,[]),
           'commerce_ready'=>false,'checkout_authorized'=>false];
    }
    public static function quote_preview($canonicalId,$onDate='') {
        if(!self::admin() || !self::ready())return self::fail('permission',403);
        if(!is_int($canonicalId)||$canonicalId<1)return self::fail('canonical_id');
        global $wpdb;$a=self::t('observations');
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $a WHERE canonical_id=%d",$canonicalId),ARRAY_A);
        if(!$r)return self::fail('observation_missing',404);
        $data=json_decode($r['observation_json'],true);
        if(!is_array($data)||!empty($data['checkout_authorized'])||!empty($data['inventory_verified']))
            return self::fail('unsafe_data');
        $date=null;
        if($onDate!==''){
            if(!is_string($onDate)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$onDate)||
                gmdate('Y-m-d',strtotime($onDate.' UTC'))!==$onDate)return self::fail('date');
            $date=strtotime($onDate.' 12:00:00 UTC');
        }
        $rows=[];
        foreach($data['rates'] as $rate){
            $invalidRange=$rate['valid_from']!==null && $rate['valid_until']!==null &&
                $rate['valid_from']>$rate['valid_until'];
            $within=$invalidRange?false:($date===null?null:
               (($rate['valid_from']===null||$rate['valid_from']<=$date) &&
                ($rate['valid_until']===null||$rate['valid_until']>=$date)));
            $rows[]=['source_rate_id'=>$rate['source_rate_id'],
                'source_rate_type'=>$rate['source_rate_type'],'currency'=>$rate['currency'],
                'price_fields'=>$rate['prices'],'valid_on_date'=>$within,
                'stop_sale'=>$rate['stop_sale'],'invalid_source_date_range'=>$invalidRange,
                'source_inventory_verified'=>false,
                'can_quote_payable'=>false];
        }
        return ['contract'=>'mad4b.hub.source-price-reference-preview.v1',
            'canonical_id'=>$canonicalId,'source_id'=>$data['source_id'],
            'starting_prices'=>$data['starting_prices'],'currencies'=>$data['currencies'],
            'source_rates'=>$rows,'requested_date'=>$onDate,
            'currency_conversion_applied'=>false,'company_pricing_applied'=>false,
            'availability_confirmed'=>false,'payable_quote'=>false,'checkout_authorized'=>false];
    }
    public static function rest(){
        if(!MAD4B_Hub_Canonical_Registry_V1::site())return;
        register_rest_route('mad4b-central/v1','/source-rates/status',[
           'methods'=>'GET','permission_callback'=>static function(){return self::admin();},
           'callback'=>static function(){return rest_ensure_response(self::status());}
        ]);
        register_rest_route('mad4b-central/v1','/source-rates/preview/(?P<id>[0-9]+)',[
           'methods'=>'GET','permission_callback'=>static function(){return self::admin();},
           'callback'=>static function($r){
               $x=self::quote_preview((int)$r['id'],(string)($r->get_param('date')??''));
               return is_wp_error($x)?$x:rest_ensure_response($x);
           }
        ]);
    }
    public static function boot(){add_action('rest_api_init',[__CLASS__,'rest']);}
}
MAD4B_Hub_Source_Price_Evidence_V1::boot();

