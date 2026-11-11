<?php
/**
 * Native JetEngine ownership of provider price source configuration.
 * Two existing-format JetEngine repeater fields (one provider register, one
 * scoped rules) are the only source of configuration truth.
 * This bridge validates native form BEFORE JetEngine saves any option.
 */
defined('ABSPATH') || exit;

final class MAD4B_Provider_JetEngine_Bridge_V1 {
    public const SLUG='etg-dynamic-pricing-controls';
    public const PROVIDERS='mad4b_provider_catalog_rows';
    public const RULES='mad4b_provider_source_policy_rows';
    public const AUDIT='mad4b_price_source_native_audit_v1';

    public static function boot():void {
        if(!is_admin())return;
        add_action('admin_init',[__CLASS__,'maybe_register_schema'],12);
        add_action('admin_init',[__CLASS__,'validate_before_native_save'],35);
        add_action('jet-engine/options-pages/after-save/'.self::SLUG,
            [__CLASS__,'record_audit'],20,1);
    }
    private static function selector(array $options):array {
        return array_map(static fn($k,$v)=>[
            'key'=>(string)$k,'value'=>(string)$v
        ],array_keys($options),array_values($options));
    }
    private static function child(string $name,string $title,string $type='text',array $options=[]):array {
        $value=['name'=>$name,'title'=>$title,'type'=>$type];
        if($type==='select'){
            $value['options_source']='manual';
            $value['options']=self::selector($options);
        }
        return $value;
    }
    public static function definitions():array {
        $provider=[
            self::child('provider_id','Provider ID (lowercase code)'),
            self::child('provider_name','Provider name'),
            self::child('linked_rate_ids','Owned published Tour Rate IDs (comma-separated)')
        ];
        $taxes=[];
        foreach(['good-for','tour-types','tour_categories','tour-styles','destinations',
                 'season','package_levels','group-size-tiers'] as $tax){
            $object=get_taxonomy($tax);
            $taxes[$tax]=$object ? $object->labels->name : $tax;
        }
        $rule=[
            self::child('rule_id','Unique rule identifier'),
            self::child('provider_id','Provider ID (self or registered provider)'),
            self::child('scope','Apply at level','select',[
                'company'=>'Company','provider'=>'Provider','group'=>'Tour taxonomy group',
                'tour'=>'Specific tours','rate'=>'Specific Rates'
            ]),
            self::child('priority','Priority (0–1000)','number'),
            self::child('source','Source order','select',[
                'prefer_rate'=>'Prefer Rate, then Direct',
                'prefer_direct'=>'Prefer Direct, then Rate',
                'rate_only'=>'Only Tour Rate','direct_only'=>'Only Direct Tour Price'
            ]),
            self::child('fallback','Unavailable-source fallback','select',[
                'next_valid'=>'Next valid source','strict'=>'Strict — no fallback'
            ]),
            self::child('taxonomy','Group taxonomy','select',$taxes),
            self::child('term_ids','Group term IDs (comma-separated)'),
            self::child('tour_ids','Published Tour IDs (comma-separated)'),
            self::child('rate_ids','Published Rate IDs (comma-separated)'),
            self::child('preview_enabled','Use in Draft preview','switcher')
        ];
        return [
            [
                'title'=>'Provider Catalog · Rate Ownership',
                'name'=>self::PROVIDERS,
                'object_type'=>'field',
                'type'=>'repeater',
                'width'=>'100%',
                'show_in_rest'=>false,
                'description'=>'JetEngine-native provider identity and published Rate ownership. Unassigned Rates inherit the company Self Provider.',
                'mad4b_ui_mode'=>'source-cards',
                'repeater-fields'=>$provider
            ],
            [
                'title'=>'Provider Source Policies · Scope & Priority',
                'name'=>self::RULES,
                'object_type'=>'field',
                'type'=>'repeater',
                'width'=>'100%',
                'show_in_rest'=>false,
                'description'=>'Rules target Rate > Tour > taxonomy group > Provider > Company. JetEngine Save changes persists inactive Drafts only.',
                'mad4b_ui_mode'=>'source-cards',
                'repeater-fields'=>$rule
            ]
        ];
    }
    public static function ensure_schema():array|WP_Error {
        global $wpdb;
        $table=$wpdb->prefix.'jet_post_types';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)
            return new WP_Error('provider_jetengine_registry_missing');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,slug,status,meta_fields FROM {$table} WHERE slug=%s LIMIT 1",self::SLUG),ARRAY_A);
        if(!$row || $row['status']!=='page')
            return new WP_Error('provider_jetengine_page_missing');
        $fields=maybe_unserialize($row['meta_fields']);
        if(!is_array($fields))return new WP_Error('provider_jetengine_schema_invalid');
        $known=[];
        foreach($fields as $field)if(is_array($field)&&isset($field['name']))
            $known[(string)$field['name']]=$field;
        foreach([self::PROVIDERS,self::RULES] as $key)if(isset($known[$key])
            &&($known[$key]['type']??'')!=='repeater')
            return new WP_Error('provider_jetengine_field_type_conflict',$key);
        $added=[];
        foreach(self::definitions() as $definition){
            if(isset($known[$definition['name']]))continue;
            $added[]=$definition['name'];$fields[]=$definition;
        }
        if(!$added)return ['status'=>'exists','fields'=>[self::PROVIDERS,self::RULES]];
        // Optimistic row CAS: do not overwrite changes made in JetEngine
        // UI by another administrator between read and write.
        $updated=$wpdb->update($table,
            ['meta_fields'=>maybe_serialize($fields)],
            ['id'=>(int)$row['id'],'meta_fields'=>$row['meta_fields']],
            ['%s'],['%d','%s']);
        if($updated!==1)
            return new WP_Error('provider_jetengine_schema_write_conflict');
        wp_cache_delete('jet-engine-options-pages','options');
        return ['status'=>'added','fields'=>$added];
    }
    public static function maybe_register_schema():void {
        $slug=sanitize_key((string)($_GET['page']??''));
        if(!in_array($slug,['dynamic-pricing-controls',self::SLUG],true)
          ||!current_user_can('manage_options'))return;
        self::ensure_schema();
    }
    private static function raw(string $field):array {
        $value=get_option(self::SLUG.'_'.$field,[]);
        return is_array($value)?array_values($value):[];
    }
    public static function schema_is_native():bool {
        if(!class_exists('MAD4B_JetEngine_Field_Modes_V1'))return false;
        $page=MAD4B_JetEngine_Field_Modes_V1::pages()[self::SLUG]['fields']??[];
        return isset($page[self::PROVIDERS],$page[self::RULES])
          &&($page[self::PROVIDERS]['type']??'')==='repeater'
          &&($page[self::RULES]['type']??'')==='repeater';
    }
    private static function csv_ids($value,string $key):array|WP_Error {
        if($value===null || $value==='')return [];
        if(is_array($value)){
            $parts=$value;
        }elseif(is_string($value)){
            $trim=trim($value);
            $parts=$trim===''?[]:preg_split('/[\s,;]+/u',$trim,-1,PREG_SPLIT_NO_EMPTY);
        }else return new WP_Error('provider_native_ids_type','Expected comma-separated IDs: '.$key);
        if(count($parts)>120)return new WP_Error('provider_native_ids_size','Too many IDs for '.$key);
        $ids=[];
        foreach($parts as $id){
            if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/D',(string)$id))
                return new WP_Error('provider_native_ids_invalid','Use numeric IDs for '.$key);
            $value=(int)$id;
            if($value<1 || $value>2147483647)return new WP_Error('provider_native_id_bounds');
            $ids[$value]=$value;
        }
        return array_values($ids);
    }
    private static function native_rows($value):array|WP_Error {
        if($value===null||$value===''||$value===false)return [];
        if(!is_array($value)||count($value)>100)return new WP_Error('provider_native_repeater_invalid');
        $out=[];
        foreach($value as $row) {
            if(!is_array($row))return new WP_Error('provider_native_row_invalid');
            // JetEngine can create a blank row from the Add Item control.
            // A row is omitted only when ALL of its fields are blank.
            $isEmpty=!array_filter($row,static fn($v)=>
                !(is_null($v)||$v===''||$v===false||$v==='false'||$v===[]));
            if($isEmpty)continue;
            $out[]=$row;
        }
        return $out;
    }
    public static function normalize($providerRows,$ruleRows):array|WP_Error {
        $providers=self::native_rows($providerRows);
        if(is_wp_error($providers))return $providers;
        $rules=self::native_rows($ruleRows);
        if(is_wp_error($rules))return $rules;
        $out=['providers'=>[],'rules'=>[]];
        foreach($providers as $entry) {
            $rateIds=self::csv_ids($entry['linked_rate_ids']??'','provider linked Rates');
            if(is_wp_error($rateIds))return $rateIds;
            $out['providers'][]=[
                'id'=>sanitize_key((string)($entry['provider_id']??'')),
                'name'=>sanitize_text_field((string)($entry['provider_name']??'')),
                'linked_rate_ids'=>$rateIds
            ];
        }
        foreach($rules as $entry) {
            $mapped=[
                'id'=>sanitize_key((string)($entry['rule_id']??'')),
                'provider_id'=>sanitize_key((string)($entry['provider_id']??'')),
                'scope'=>sanitize_key((string)($entry['scope']??'')),
                'source'=>sanitize_key((string)($entry['source']??'')),
                'fallback'=>sanitize_key((string)($entry['fallback']??'next_valid')),
                'priority'=>$entry['priority']??null,
                'taxonomy'=>sanitize_key((string)($entry['taxonomy']??'')),
                'preview_enabled'=>filter_var(
                    $entry['preview_enabled']??false,FILTER_VALIDATE_BOOLEAN)
            ];
            foreach(['term_ids','tour_ids','rate_ids'] as $key){
                $ids=self::csv_ids($entry[$key]??'',$key);
                if(is_wp_error($ids))return $ids;
                $mapped[$key]=$ids;
            }
            $out['rules'][]=$mapped;
        }
        return $out;
    }
    public static function current():array|WP_Error {
        $value=self::normalize(self::raw(self::PROVIDERS),self::raw(self::RULES));
        if(is_wp_error($value))return $value;
        $checked=MAD4B_Provider_Price_Sources_V1::validate($value);
        return is_wp_error($checked)?$checked:$checked;
    }
    public static function validate_before_native_save():void {
        $slug=sanitize_key((string)($_REQUEST['page']??($_GET['page']??'')));
        if(($_REQUEST['action']??'')!=='jet-engine-op-save-settings'
            ||!in_array($slug,['dynamic-pricing-controls',self::SLUG],true))return;
        if(!current_user_can('manage_options'))return;
        $rawProviders=isset($_REQUEST[self::PROVIDERS])
            ?wp_unslash($_REQUEST[self::PROVIDERS]):[];
        $rawRules=isset($_REQUEST[self::RULES])
            ?wp_unslash($_REQUEST[self::RULES]):[];
        $parsed=self::normalize($rawProviders,$rawRules);
        if(!is_wp_error($parsed))
            $parsed=MAD4B_Provider_Price_Sources_V1::validate($parsed);
        if(is_wp_error($parsed)) {
            wp_die(
                esc_html('Provider Price Sources were NOT saved: '.$parsed->get_error_code()
                 .' — '.$parsed->get_error_message()).
                '<p>'.esc_html('Return to Pricing Controls, correct provider IDs/Rate ownership or rule scope, then Save changes again.').'</p>',
                'Provider Pricing Validation', ['response'=>422]
            );
        }
    }
    public static function record_audit($page):void {
        if(!self::schema_is_native())return;
        $native=self::current();
        if(is_wp_error($native))return;
        $hash=hash('sha256',wp_json_encode($native));
        $state=get_option(self::AUDIT,[]);
        if(!is_array($state))$state=[];
        $last=end($state);
        if(is_array($last)&&($last['fingerprint']??'')===$hash)return;
        $state[]=[
            'revision'=>count($state)?max(array_column($state,'revision'))+1:1,
            'timestamp'=>gmdate('c'),
            'actor'=>get_current_user_id(),
            'provider_count'=>count($native['providers']),
            'rule_count'=>count($native['rules']),
            'fingerprint'=>$hash,
            'commercial_active'=>false
        ];
        update_option(self::AUDIT,array_slice($state,-30),false);
    }
}
