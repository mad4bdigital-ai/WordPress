<?php
/**
 * MAD4B Provider Field Intelligence v1
 *
 * Read-only capability, storage, translation and use-case discovery.
 * Discover actual provider fields rather than assuming configured integrations
 * from plugin names. No values, secrets, permissions or writes are inferred.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Provider_Field_Intelligence {
    const CONTRACT='mad4b.provider-field-intelligence.v1';
    const LIMIT=500;
    const OBSERVED_LIMIT=350;

    private static function valid($key):bool {
        return is_string($key) && strlen($key)<=120
            && preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D',$key)===1
            && !preg_match('/(password|secret|token|credentials?|private_key|auth_nonce|session_key)/i',$key);
    }
    private static function row($key):array {
        return [
            'key'=>$key,'provider'=>'wordpress_observed',
            'sources'=>[], 'declared'=>false,
            'observed_records'=>0,'storage'=>'post_meta',
            'field_type'=>'unknown','value_format'=>'unknown',
            'rest_exposed'=>false,'wpml_translation'=>'unconfigured',
            'wpml_translation_source'=>'not_declared',
            'translation_is_approved'=>false,
            'is_media_field'=>false,'is_seo_field'=>false,
            'media_visual_review_required'=>false,
            'authorizes_edit'=>false,'authorizes_translation_write'=>false,
        ];
    }
    private static function classify(array &$r):void {
        $key=$r['key'];
        if(str_starts_with($key,'rank_math_') && $r['provider']==='wordpress_observed')
            $r['provider']='rank_math_observed';
        if(str_starts_with($key,'_elementor_') && $r['provider']==='wordpress_observed')
            $r['provider']='elementor_observed';
        $r['is_seo_field']=str_starts_with($key,'rank_math_') ||
            in_array($key,['_yoast_wpseo_title','_yoast_wpseo_metadesc'],true);
        $r['is_media_field']=in_array($r['field_type'],['media','gallery','image'],true)
            ||(bool)preg_match('/(?:^|_)(?:image|gallery|thumbnail|hero|photo|logo|icon|cover)(?:_|$)/i',$key)
            ||$key==='_thumbnail_id' ||$key==='_wp_attachment_image_alt';
        $r['media_visual_review_required']=$r['is_media_field']
            && !in_array($key,['_wp_attachment_image_alt'],true);
        $r['suggested_handling']=$r['is_media_field']
            ?($key==='_wp_attachment_image_alt'?'localized_alt_text_review':
                'verify_asset_identity_and_image_subject_per_locale')
            :($r['is_seo_field']?'localized_seo_copy_review':'according_to_provider_contract');
    }
    private static function wpml():array {
        $mode_map=[];
        $sitepress=$GLOBALS['sitepress']??null;
        if(is_object($sitepress) && method_exists($sitepress,'get_custom_fields_translation_settings')){
            foreach([0=>'ignore',1=>'copy',2=>'translate',3=>'copy_once'] as $flag=>$mode){
                $entries=$sitepress->get_custom_fields_translation_settings($flag);
                if(!is_array($entries))continue;
                foreach(array_slice($entries,0,1500) as $k){
                    if(self::valid($k))$mode_map[$k]=[
                        'mode'=>$mode,'source'=>'wpml_effective_configuration'];
                }
            }
        }
        $settings=(array)get_option('icl_sitepress_settings',[]);
        $attachment_setting=(int)($settings['custom_posts_sync_option']['attachment']??0);
        $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        $languages=is_array($langs)?array_keys($langs):[];
        $settings_mode=(array)($settings['custom_fields_translation']??[]);
        return [
            'available'=>is_object($sitepress),
            'attachment_translatable_configured'=>$attachment_setting===1,
            'attachment_translatable_value'=>$attachment_setting,
            'field_preferences'=>$mode_map,
            'declared_preferences_count'=>count($mode_map),
            'languages'=>array_slice($languages,0,20),
            'raw_field_settings_count'=>count($settings_mode),
        ];
    }
    private static function core(array &$fields,$meta_type,$subtype):void {
        $registered=get_registered_meta_keys($meta_type,$subtype);
        if(!is_array($registered))return;
        foreach(array_slice($registered,0,self::LIMIT,true) as $name=>$data){
            if(!self::valid($name)||!is_array($data))continue;
            if(!isset($fields[$name]))$fields[$name]=self::row($name);
            $r=&$fields[$name];
            $r['storage']=$meta_type==='term'?'term_meta':'post_meta';
            $r['declared']=true;
            $r['field_type']=is_string($data['type']??null)?$data['type']:'unknown';
            $r['rest_exposed']=!empty($data['show_in_rest']);
            $r['sources']['wordpress_meta_registry']=true;
            if($r['provider']==='wordpress_observed')$r['provider']='wordpress_registered';
            unset($r);
        }
    }
    private static function jetengine(array &$fields,$subtype):array {
        $info=['available'=>false,'context_found'=>false,'field_count'=>0];
        $engine=function_exists('jet_engine')?jet_engine():null;
        $boxes=is_object($engine)?($engine->meta_boxes??null):null;
        if(!is_object($boxes)||!method_exists($boxes,'get_registered_fields'))return $info;
        $info['available']=true;
        $registered=$boxes->get_registered_fields();
        if(!is_array($registered))return $info;
        $group=$registered[$subtype]??null;
        if(!is_array($group))return $info;
        $info['context_found']=true;
        foreach(array_slice($group,0,self::LIMIT) as $def){
            if(!is_array($def)||!self::valid($def['name']??null))continue;
            $key=(string)$def['name'];
            if(!isset($fields[$key]))$fields[$key]=self::row($key);
            $r=&$fields[$key];
            $r['provider']='jetengine';
            $r['declared']=true;
            $r['field_type']=sanitize_key((string)($def['type']??'unknown'));
            $r['value_format']=sanitize_key((string)($def['value_format']??'unknown'));
            $r['rest_exposed']=!empty($def['show_in_rest'])||$r['rest_exposed'];
            $r['sources']['jetengine_registered_fields']=true;
            $r['jetengine_field_id']=absint($def['id']??0);
            $r['jetengine_object_type']=sanitize_key((string)($def['object_type']??'field'));
            $info['field_count']++;
            // Repeater children are not independent WordPress meta keys.
            $nested=(array)($def['repeater-fields']??[]);
            $r['nested_field_names']=[];
            foreach(array_slice($nested,0,60) as $child)
                if(is_array($child)&&self::valid($child['name']??null))
                    $r['nested_field_names'][]=$child['name'];
            unset($r);
        }
        return $info;
    }
    private static function observed(array &$fields,$object,$subtype):array {
        global $wpdb;
        $stats=['complete'=>false,'observed_keys'=>0,'limit'=>self::OBSERVED_LIMIT];
        if($object==='term'){
            // WordPress taxonomy relationship is only available through terms.
            $sql=$wpdb->prepare(
                "SELECT m.meta_key, COUNT(*) AS c FROM {$wpdb->termmeta} m
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=m.term_id
                 WHERE tt.taxonomy=%s GROUP BY m.meta_key
                 ORDER BY m.meta_key LIMIT %d",$subtype,self::OBSERVED_LIMIT+1);
        }else{
            $sql=$wpdb->prepare(
                "SELECT m.meta_key, COUNT(*) AS c FROM {$wpdb->postmeta} m
                 JOIN {$wpdb->posts} p ON p.ID=m.post_id
                 WHERE p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft')
                 GROUP BY m.meta_key ORDER BY m.meta_key LIMIT %d",
                 $subtype,self::OBSERVED_LIMIT+1);
        }
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($rows)||!empty($wpdb->last_error))return $stats;
        $stats['complete']=count($rows)<=self::OBSERVED_LIMIT;
        foreach(array_slice($rows,0,self::OBSERVED_LIMIT) as $one){
            $name=$one['meta_key']??null;
            if(!self::valid($name))continue;
            if(!isset($fields[$name]))$fields[$name]=self::row($name);
            $fields[$name]['observed_records']=min(10000000,(int)$one['c']);
            $fields[$name]['sources']['database_key_inventory']=true;
            $stats['observed_keys']++;
        }
        return $stats;
    }
    private static function extensions(array &$fields,$context):array {
        $extra=apply_filters('mad4b/provider_field_intelligence/adapters',[],$context);
        $count=0;
        if(!is_array($extra))return ['accepted'=>0,'status'=>'invalid_adapter_collection'];
        foreach(array_slice($extra,0,8,true) as $provider=>$definitions){
            if(!is_string($provider)||
                !preg_match('/^[a-z][a-z0-9_-]{1,60}$/D',$provider)
                ||!is_array($definitions))continue;
            foreach(array_slice($definitions,0,120,true) as $key=>$info){
                if(!self::valid($key)||!is_array($info))continue;
                if(!isset($fields[$key]))$fields[$key]=self::row($key);
                $r=&$fields[$key];
                if($r['provider']==='wordpress_observed')$r['provider']=$provider;
                $r['sources']['provider_extension_'.$provider]=true;
                // Extension metadata provides a typed observation, not a
                // higher privilege than canonical WP/JetEngine declarations.
                if(!$r['declared']&&in_array($info['field_type']??'',
                    ['text','textarea','select','number','date','media','gallery','image','repeater'],true)){
                    $r['field_type']=$info['field_type'];
                }
                $count++;
                unset($r);
            }
        }
        return ['accepted'=>$count,'status'=>'discovery_only'];
    }
    public static function discover($input=[]){
        if(!is_array($input)||array_diff(array_keys($input),['object','subtype','only_media','limit']))
            return new WP_Error('mad4b_provider_fields_invalid_input','Only bounded discovery parameters allowed.');
        $object=(string)($input['object']??'post');
        $subtype=(string)($input['subtype']??'tours-and-activities');
        if(!in_array($object,['post','term'],true)
            ||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{1,65}$/D',$subtype)
            ||($object==='post'&&!post_type_exists($subtype))
            ||($object==='term'&&!taxonomy_exists($subtype)))
            return new WP_Error('mad4b_provider_fields_unknown_object','Exact registered object is required.');
        $limit=max(1,min(self::LIMIT,(int)($input['limit']??self::LIMIT)));
        $site=class_exists('MAD4B_SCP_Site_Profile',false)
            ?MAD4B_SCP_Site_Profile::status():[];
        if(empty($site['site_uuid'])||empty($site['origin_match'])
            ||empty($site['environment_match'])||empty($site['deployment_binding_match']))
            return new WP_Error('mad4b_provider_fields_site_unverified','Exact enrolled Site Profile required.');
        $fields=[];
        self::core($fields,$object,$subtype);
        $jet=self::jetengine($fields,$subtype);
        $observed=self::observed($fields,$object,$subtype);
        $extensions=self::extensions($fields,['object'=>$object,'subtype'=>$subtype,
            'site_uuid'=>$site['site_uuid']]);
        $wpml=self::wpml();
        foreach($fields as $key=>&$row){
            $pref=$wpml['field_preferences'][$key]??null;
            if($pref){
                $row['wpml_translation']=$pref['mode'];
                $row['wpml_translation_source']=$pref['source'];
            }
            self::classify($row);
            $row['sources']=array_keys($row['sources']);
        }
        unset($row);
        $all_count=count($fields);
        ksort($fields,SORT_STRING);
        if(!empty($input['only_media']))$fields=array_filter($fields,
            static fn($r)=>!empty($r['is_media_field']));
        $filtered_count=count($fields);
        $fields=array_slice(array_values($fields),0,$limit);
        $counts=['total'=>$all_count,'filtered'=>$filtered_count,
            'returned'=>count($fields),'fields_with_explicit_translation_mode'=>0,
            'media_fields'=>0,'seo_fields'=>0,'jetengine_fields'=>0];
        foreach($fields as $field){
            if($field['wpml_translation']!=='unconfigured')$counts['fields_with_explicit_translation_mode']++;
            if($field['is_media_field'])$counts['media_fields']++;
            if($field['is_seo_field'])$counts['seo_fields']++;
            if($field['provider']==='jetengine')$counts['jetengine_fields']++;
        }
        unset($wpml['field_preferences']);
        return [
            'contract'=>self::CONTRACT,'site_uuid'=>$site['site_uuid'],
            'environment'=>$site['environment']??'',
            'object'=>$object,'subtype'=>$subtype,
            'providers'=>[
                'wordpress_meta_registry'=>true,
                'jetengine'=>$jet,
                'wpml'=>$wpml,
                'rank_math_present'=>defined('RANK_MATH_VERSION')
                    ||class_exists('RankMath',false),
                'elementor_present'=>defined('ELEMENTOR_VERSION'),
                'provider_extensions'=>$extensions,
            ],
            'observed_inventory'=>$observed,
            'fields'=>$fields,'counts'=>$counts,
            'enumeration_complete'=>$observed['complete'] && count($fields)===$filtered_count,
            'discovery_read_only'=>true,'mutations_performed'=>false,
            'translation_changes_authorized'=>false,
            'seo_changes_authorized'=>false,
            'physical_media_copied'=>false,
        ];
    }
    /**
     * Exact WPML family SEO/media gap assessment. Never manufacture alt text
     * or duplicate media binaries; a localized alt must describe the image,
     * not merely repeat the tour keyword.
     */
    public static function locale_media_audit($post_id):array {
        $post_id=absint($post_id);
        $post=$post_id?get_post($post_id):null;
        if(!$post ||!post_type_exists($post->post_type))
            return ['contract'=>'mad4b.locale-media-audit.v1',
                'complete'=>false,'error'=>'post_not_found'];
        $site=class_exists('MAD4B_SCP_Site_Profile',false)
            ?MAD4B_SCP_Site_Profile::status():[];
        if(empty($site['site_uuid'])||empty($site['origin_match']))
            return ['contract'=>'mad4b.locale-media-audit.v1',
                'complete'=>false,'error'=>'site_identity_unverified'];
        $trid=apply_filters('wpml_element_trid',null,$post_id,
            'post_'.$post->post_type);
        $set=$trid?apply_filters('wpml_get_element_translations',[],$trid,
            'post_'.$post->post_type):[];
        if(!is_array($set)||!$set)
            $set=['und'=> (object)['element_id'=>$post_id,'language_code'=>'und']];
        $prefs=self::wpml();
        $rows=[];
        $missing=0;$seen=0;$group_ids=[];
        foreach(array_slice($set,0,15) as $translation){
            if(!is_object($translation))continue;
            $id=(int)($translation->element_id??0);
            $lang=sanitize_key((string)($translation->language_code??'und'));
            if($id<1||!get_post($id))continue;
            $aid=get_post_thumbnail_id($id);
            $alt=$aid?trim((string)get_post_meta($aid,'_wp_attachment_image_alt',true)):'';
            $alt_missing=$aid>0 && $alt==='';
            if($alt_missing)$missing++;
            $seen++;
            $asset_file=$aid?get_attached_file($aid):'';
            $asset_trid=$aid?apply_filters('wpml_element_trid',null,$aid,'post_attachment'):null;
            if($asset_trid)$group_ids[(int)$asset_trid]=true;
            $seo_title=(string)get_post_meta($id,'rank_math_title',true);
            $seo_description=(string)get_post_meta($id,'rank_math_description',true);
            $rows[]=[
                'language'=>$lang,'post_id'=>$id,'featured_attachment_id'=>$aid,
                'featured_asset_exists'=>$aid>0&&is_file((string)$asset_file),
                'attachment_wpml_translation_group'=>$asset_trid?(int)$asset_trid:null,
                'featured_alt_present'=>!$alt_missing&&$alt!=='',
                'featured_alt_length'=>mb_strlen($alt),
                'rank_math_title_present'=>trim($seo_title)!=='',
                'rank_math_description_present'=>trim($seo_description)!=='',
                'gallery_meta_present'=>(string)get_post_meta($id,'gallery',true)!=='',
            ];
        }
        return [
            'contract'=>'mad4b.locale-media-audit.v1','complete'=>true,
            'site_uuid'=>$site['site_uuid'],
            'source_post_id'=>$post_id,'post_type'=>$post->post_type,
            'languages_with_posts'=>$seen,'featured_alt_missing'=>$missing,
            'featured_attachment_translation_groups'=>count($group_ids),
            'wpml_attachment_translatable'=>$prefs['attachment_translatable_configured'],
            'wpml_alt_translation_mode'=>$prefs['field_preferences']['_wp_attachment_image_alt']['mode']??'unconfigured',
            'rank_math_title_translation_mode'=>$prefs['field_preferences']['rank_math_title']['mode']??'unconfigured',
            'rank_math_description_translation_mode'=>$prefs['field_preferences']['rank_math_description']['mode']??'unconfigured',
            'locales'=>$rows,
            'seo_action'=>'describe_verified_original_photo_in_each_language_and_use_localized_rank_math_metadata',
            'photo_subject_verified'=>false,'alt_text_generated'=>false,
            'translation_data_written'=>false,'media_binary_duplicated'=>false,
        ];
    }
    public static function media_field_names($subtype):array {
        $r=self::discover(['object'=>'post','subtype'=>$subtype,
            'only_media'=>true,'limit'=>self::LIMIT]);
        if(is_wp_error($r))return [];
        $wanted=[];
        foreach($r['fields'] as $field){
            if(!$field['is_media_field']||$field['key']==='_thumbnail_id')continue;
            // Only recorded author-managed fields; hidden analytics/media internals
            // are not permitted to enter the gallery mutation pipeline.
            if(!empty($field['declared']) && $field['provider']==='jetengine'
                && in_array($field['field_type'],['media','gallery','image'],true))
                $wanted[]=$field['key'];
        }
        return array_slice(array_values(array_unique($wanted)),0,40);
    }
    public static function cli($args,$assoc){
        if($args)WP_CLI::error('Use --post-type=... or --taxonomy=... only.');
        $only_media=!empty($assoc['media-only']);
        $argkeys=array_keys($assoc);
        if(array_diff($argkeys,['post-type','taxonomy','media-only','limit']))
            WP_CLI::error('Unsupported argument.');
        $object=isset($assoc['taxonomy'])?'term':'post';
        $subtype=(string)($assoc['taxonomy']??$assoc['post-type']??'tours-and-activities');
        $r=self::discover(['object'=>$object,'subtype'=>$subtype,
            'only_media'=>$only_media,'limit'=>(int)($assoc['limit']??self::LIMIT)]);
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function rest_route():void {
        register_rest_route('mad4b/v1','/provider-fields',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function($request){
                $r=self::discover([
                    'object'=>(string)($request['object']??'post'),
                    'subtype'=>(string)($request['subtype']??'tours-and-activities'),
                    'only_media'=>filter_var($request['only_media']??false,FILTER_VALIDATE_BOOLEAN),
                    'limit'=>(int)($request['limit']??self::LIMIT),
                ]);
                return is_wp_error($r)?$r:rest_ensure_response($r);
            },
        ]);
    }
}
add_action('rest_api_init',[MAD4B_SCP_Provider_Field_Intelligence::class,'rest_route']);
if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b fields',[MAD4B_SCP_Provider_Field_Intelligence::class,'cli']);

