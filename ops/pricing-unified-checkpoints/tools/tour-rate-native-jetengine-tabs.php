<?php
/**
 * One-time, idempotent JetEngine-native Tour Rate tab layout migration.
 *
 * Usage (WP-CLI):
 *   wp eval-file ops/pricing-unified-checkpoints/tools/tour-rate-native-jetengine-tabs.php
 *   MAD4B_RATE_TABS_APPLY=1 wp eval-file ops/pricing-unified-checkpoints/tools/tour-rate-native-jetengine-tabs.php
 *
 * Changes ONLY the JetEngine CPT meta_fields schema (visual tab headers), not
 * any tour, rate, postmeta, term, relation, price, or booking records.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;

final class MAD4B_Tour_Rate_Native_Tabs_20261121 {
    private const SLUG='tour-rates';
    private const ANCHORS=[
        'related_tour_id'=>['mad4b_rate_tab_tour_schedule','Tour & Schedule',2026112101],
        'has_properties'=>['mad4b_rate_tab_setup','Property, Currency & Stock',2026112102],
        'shared_financial_tax_price_basis'=>['mad4b_rate_tab_financial','Financial Policies',2026112103],
        'accommodation_assignment_mode'=>['mad4b_rate_tab_accommodation','Accommodation',2026112104],
        'has_transportations'=>['mad4b_rate_tab_transport','Transportation',2026112105],
    ];
    private const ORIGINAL_ORDER_ANCHORS=[
        0=>'related_tour_id',10=>'has_properties',18=>'traveler_rates',
        35=>'shared_financial_tax_price_basis',
        42=>'accommodation_assignment_mode',45=>'has_transportations',
        52=>'transport_markup_override_pct',
    ];
    public static function transform(array $fields) {
        $names=array_column($fields,'name');
        $already=array_values(array_filter($names,static fn($n)=>
            is_string($n)&&strpos($n,'mad4b_rate_tab_')===0));
        if($already){
            $expected=array_column(self::ANCHORS,0);
            if(count($already)!==count($expected) ||
                array_diff($expected,$already)||array_diff($already,$expected))
                return new WP_Error('tour_rate_tabs_partial_schema');
            // Explicit idempotency validation below: only trusted tab IDs.
            foreach(self::ANCHORS as $anchor=>[$key,$title,$id]){
                $index=array_search($key,$names,true);
                if($index===false||($fields[$index]['object_type']??'')!=='tab' ||
                   ($fields[$index]['id']??null)!==$id)
                    return new WP_Error('tour_rate_tabs_partial_schema');
            }
            return $fields;
        }
        if(count($fields)!==53)
            return new WP_Error('tour_rate_tabs_unexpected_schema_count');
        foreach(self::ORIGINAL_ORDER_ANCHORS as $index=>$key)
            if(($fields[$index]['name']??null)!==$key)
                return new WP_Error('tour_rate_tabs_schema_anchor_mismatch');
        $existingTabs=array_values(array_filter($fields,
            static fn($f)=>($f['object_type']??'')==='tab'));
        if(count($existingTabs)!==1 ||
            ($existingTabs[0]['name']??null)!=='traveler_rates')
            return new WP_Error('tour_rate_tabs_foreign_tabs_present');
        $out=[];
        foreach($fields as $field){
            $name=(string)($field['name']??'');
            if(isset(self::ANCHORS[$name])){
                [$tab,$title,$id]=self::ANCHORS[$name];
                $out[]=[
                    'title'=>$title,'name'=>$tab,'object_type'=>'tab',
                    'type'=>'text','id'=>$id,'width'=>'100%',
                    'tab_layout'=>'horizontal','options'=>[],
                    'repeater-fields'=>[],
                ];
            }
            if($name==='traveler_rates')
                $field['title']='Traveler Prices & Availability';
            $out[]=$field;
        }
        if(count($out)!==58)
            return new WP_Error('tour_rate_tabs_output_count_mismatch');
        // Strictly preserve every native source field except the existing
        // display-only tab title. Never rename or clone a commercial field.
        $expected=$fields;
        foreach($expected as &$f)if(($f['name']??'')==='traveler_rates')
            $f['title']='Traveler Prices & Availability';
        unset($f);
        $actual=array_values(array_filter($out,
            static fn($f)=>strpos((string)($f['name']??''),'mad4b_rate_tab_')!==0));
        if($actual!==$expected)
            return new WP_Error('tour_rate_tabs_original_fields_changed');
        return $out;
    }
    public static function run():void {
        global $wpdb;
        $table=$wpdb->prefix.'jet_post_types';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,slug,meta_fields FROM `{$table}` WHERE slug=%s LIMIT 1",
            self::SLUG
        ));
        if(!$row || !is_string($row->meta_fields)){
            WP_CLI::error('Tour Rate JetEngine CPT row not found');
            return;
        }
        $fields=maybe_unserialize($row->meta_fields);
        if(!is_array($fields)){
            WP_CLI::error('Tour Rate JetEngine fields are not a valid array');
            return;
        }
        $new=self::transform($fields);
        if(is_wp_error($new)){
            WP_CLI::error($new->get_error_code());
            return;
        }
        $beforeHash=hash('sha256',$row->meta_fields);
        $newBlob=maybe_serialize($new);
        $afterHash=hash('sha256',$newBlob);
        $already=$beforeHash===$afterHash;
        $result=[
            'site'=>home_url(),'post_type'=>self::SLUG,
            'original_field_count'=>count($fields),
            'visual_tab_count'=>count(array_filter($new,
                static fn($f)=>($f['object_type']??'')==='tab')),
            'original_fields_preserved'=>true,
            'related_tour_id_authority'=>'existing_post_meta',
            'tour_queried_rates'=>'unchanged_derived_inventory',
            'jetengine_relation_created'=>false,
            'before_sha256'=>$beforeHash,'after_sha256'=>$afterHash,
            'already_applied'=>$already,'write_performed'=>false,
        ];
        if(getenv('MAD4B_RATE_TABS_APPLY')==='1' && !$already){
            $backup='/tmp/mad4b-rate-tabs-before-'.$row->id.'-'.substr($beforeHash,0,16).'.ser';
            if(file_put_contents($backup,$row->meta_fields,LOCK_EX)===false){
                WP_CLI::error('Pre-write native CPT backup failed');
                return;
            }
            chmod($backup,0600);
            // CAS against exact original bytes; no lost updates if JetEngine
            // editor or another worker saved the CPT schema concurrently.
            $written=$wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET meta_fields=%s WHERE id=%d AND meta_fields=%s",
                $newBlob,(int)$row->id,$row->meta_fields
            ));
            if($written!==1){
                WP_CLI::error('Native CPT schema conflict or update failed');
                return;
            }
            $verify=$wpdb->get_var($wpdb->prepare(
                "SELECT meta_fields FROM `{$table}` WHERE id=%d",(int)$row->id
            ));
            if(!is_string($verify)||!hash_equals($afterHash,hash('sha256',$verify))){
                WP_CLI::error('Native CPT schema write readback mismatch');
                return;
            }
            $result['write_performed']=true;
            $result['rollback_backup_path']=$backup;
            $result['reloaded_fields']=count((array)maybe_unserialize($verify));
        }
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
MAD4B_Tour_Rate_Native_Tabs_20261121::run();
