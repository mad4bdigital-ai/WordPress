<?php
/**
 * Plugin Name: MAD4B Company Template Identity Discovery
 * Description: Tenant-bound read-only discovery of unverified hardcoded brand/contact identity in imported and localized Elementor content.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Brand_Identity_Discovery_V1 {
    const CONTRACT='mad4b.company-template-brand-discovery.v1';
    const HEALTH='mad4b_brand_identity_discovery_health_v1';
    const MAX_OBJECTS=1200;
    const TYPES=['elementor_library','page','tours-and-activities','product'];
    const FIELDS=['_elementor_data','_elementor_page_settings'];
    private static $outstanding=false;

    private static function policy(){
        $identity=get_option('mad4b_scp_brand_context_profile_v1',[]);
        $policy=get_option('mad4b_brand_surface_policy_v1',[]);
        if(!is_array($identity)||!is_array($policy)||empty($identity['site_uuid'])
            ||empty($identity['brand_name'])||!hash_equals((string)$identity['site_uuid'],(string)($policy['site_uuid']??''))
            ||!hash_equals((string)$identity['brand_name'],(string)($policy['brand_name']??''))
            ||rtrim(home_url(),'/')!==(string)($policy['origin']??'')
            ||!in_array(wp_get_environment_type(),['staging','production'],true))return null;
        $contacts=(array)get_option('contact-and-help-info',[]);
        $domains=[strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST))];
        foreach((array)($policy['approved_company_domains']??[]) as $d)
            if(is_string($d)&&preg_match('/^(?:[a-z0-9-]+\\.)+[a-z]{2,}$/i',$d))$domains[]=strtolower($d);
        return [
            'brand'=>(string)$identity['brand_name'],
            'domains'=>array_unique($domains),
            'emails'=>array_filter(array_merge((array)($policy['approved_emails']??[]),
                [$contacts['site_email']??'']),'is_string'),
            'phones'=>array_filter(array_merge((array)($policy['approved_phones']??[]),
                [$contacts['site_whatsapp_num']??'']),'is_string'),
            'socials'=>array_filter(array_merge((array)($policy['approved_social_profiles']??[]),
                [$contacts['site_facebook_page_link']??'',$contacts['site_instagram_page_link']??'']),'is_string'),
            'addresses'=>(array)($policy['approved_addresses']??[]),
            'blocked'=>(array)($policy['blocked_source_tokens']??[]),
            'verified'=>!empty($identity['last_verified_at']),
            'uuid'=>(string)$identity['site_uuid'],
        ];
    }
    private static function domain($input){
        $url=html_entity_decode(trim((string)$input),ENT_QUOTES,'UTF-8');
        if(str_starts_with(strtolower($url),'mailto:'))$url=substr($url,7);
        if(filter_var($url,FILTER_VALIDATE_EMAIL))return strtolower(substr(strrchr($url,'@'),1));
        if(!preg_match('~^https?://~i',$url))return '';
        return strtolower((string)wp_parse_url($url,PHP_URL_HOST));
    }
    private static function phone($value){
        $s=preg_replace('/[^0-9+]/','',(string)$value);
        $digits=preg_replace('/[^0-9]/','',$s);
        return strlen($digits)>=9 && strlen($digits)<=15?$digits:'';
    }
    private static function approved_phone($number,$policy){
        if(!$number)return false;
        foreach($policy['phones'] as $x)if(self::phone($x)===$number)return true;
        return false;
    }
    private static function social($url){
        $host=self::domain($url);
        if(!$host)return false;
        foreach(['facebook.com','instagram.com','tiktok.com','linkedin.com','youtube.com','x.com','twitter.com','pinterest.com','threads.net','t.me'] as $service){
            if($host===$service||str_ends_with($host,'.'.$service))return true;
        }
        return false;
    }
    private static function contact_path($path){
        return (bool)preg_match('/(?:contact|footer|header|business|company|brand|icon_list|social|whatsapp|email|phone|tel|address|location|copyright|logo|support|customer_service|profile|map|find_us)/i',$path);
    }
    private static function clean_path($path){
        return substr(preg_replace('/[^a-zA-Z0-9_.:\\/-]/','_',$path),0,180);
    }
    private static function add(&$hits,$kind,$confidence,$path,$widget,$value){
        $key=$kind.'|'.$path.'|'.hash('sha256',(string)$value);
        if(isset($hits[$key]))return;
        $hits[$key]=['kind'=>$kind,'confidence'=>$confidence,'widget_id'=>substr((string)$widget,0,24),
            'field_path'=>self::clean_path($path),'evidence_sha256'=>hash('sha256',(string)$value)];
    }
    private static function leaf($input,$path,$widget,$policy,&$hits){
        if(!is_scalar($input)||is_bool($input)||strlen((string)$input)>20000)return;
        $val=html_entity_decode((string)$input,ENT_QUOTES,'UTF-8');
        if(!trim($val)||str_contains($val,'{{')||preg_match('/^\\[(?:jet|elementor|wpml|dynamic|acf)[-_]/i',$val))return;
        if(str_contains($path, "phone_number_format") || str_contains($path, "input_mask") || str_contains($path, "phone_mask"))return;
        $lower=strtolower($val);$in_contact=self::contact_path($path);
        foreach($policy['blocked'] as $token){
            if(is_string($token)&&strlen($token)>=6&&stripos($val,$token)!==false){
                self::add($hits,'known_foreign_identity','confirmed',$path,$widget,$token);
                break;
            }
        }
        if(!$in_contact)return;
        preg_match_all('/(?<![\\w.+-])([A-Z0-9._%+\\-]+@[A-Z0-9.-]+\\.[A-Z]{2,})(?![\\w.-])/i',$val,$emails);
        foreach(array_unique($emails[1]) as $email){
            $email=strtolower($email);$domain=self::domain($email);
            if(in_array($email,array_map('strtolower',$policy['emails']),true)||in_array($domain,$policy['domains'],true))continue;
            self::add($hits,'unverified_business_email','review',$path,$widget,$email);
        }
        preg_match_all('~https?://[^\\s\\\"<>]+~iu',$val,$links);
        foreach($links[0] as $link){
            $link=rtrim(html_entity_decode($link,ENT_QUOTES,'UTF-8'),',);');
            $host=self::domain($link);
            if(!$host)continue;
            $query=(string)wp_parse_url($link,PHP_URL_QUERY);
            $is_phone=(bool)preg_match('~(?:wa\\.me|api\\.whatsapp\\.com|web\\.whatsapp\\.com|whatsapp\\.com)$~i',$host)
                || preg_match('/[?&]phone=/i',$query);
            if($is_phone){
                $number='';
                if(preg_match('/(?:phone=|wa\\.me\\/|send\\/)([+0-9]{9,17})/i',$link,$m))$number=self::phone($m[1]);
                if(!$number&&preg_match('/[?&]phone=([+0-9]{9,17})/i',$link,$m))$number=self::phone($m[1]);
                if($number&&!self::approved_phone($number,$policy))
                    self::add($hits,'unverified_whatsapp_number','review',$path,$widget,$number);
            }elseif(self::social($link)){
                $socials=array_map(static fn($x)=>rtrim(strtolower((string)$x),'/'),$policy['socials']);
                if(!in_array(rtrim(strtolower($link),'/'),$socials,true))
                    self::add($hits,'unverified_social_account','review',$path,$widget,$link);
            }elseif(preg_match('/(?:google\\.[a-z.]+\\/maps|maps\\.google\\.[a-z.]+|goo\\.gl\\/maps|maps\\.app\\.goo\\.gl)/i',$host.$link)){
                if(!in_array($link,$policy['addresses'],true))
                    self::add($hits,'unverified_business_map','review',$path,$widget,$link);
            }elseif((bool)preg_match('/(?:website|company_url|business_url|logo_link|brand_url|home_link)/i',$path)
                &&!in_array($host,$policy['domains'],true)){
                self::add($hits,'unverified_brand_website','review',$path,$widget,$link);
            }
        }
        if(preg_match_all('/(?:tel:|phone:|whatsapp:|wa\\.me\\/)([+0-9\\s()\\-]{9,25})/i',$val,$phones)){
            foreach($phones[1] as $x){$n=self::phone($x);
                if($n&&!self::approved_phone($n,$policy))self::add($hits,'unverified_business_phone','review',$path,$widget,$n);}
        }elseif(preg_match('/(?:phone|tel|whatsapp|mobile)/i',$path) && !str_contains($val,'http')){
            if(preg_match('/(?:\\+?\\d[\\d\\s().\\-]{8,20})/',$val,$m)){
                $n=self::phone($m[0]);
                if($n&&!self::approved_phone($n,$policy))self::add($hits,'unverified_business_phone','review',$path,$widget,$n);
            }
        }
        if(preg_match('/(?:copyright|powered_by|brand_name|company_name|business_name|org_name)/i',$path)
            &&mb_strlen(trim(wp_strip_all_tags($val)))>=5
            &&!str_contains(mb_strtolower($val),mb_strtolower($policy['brand']))
            &&!str_contains($val,'http')&&!str_contains($val,'@')){
            self::add($hits,'unverified_brand_claim','review',$path,$widget,$val);
        }
    }
    private static function walk($obj,$path,$widget,$policy,&$hits,$depth=0){
        if($depth>18||count($hits)>150)return;
        if(is_array($obj)){
            if(isset($obj['id'])&&isset($obj['elType']))$widget=(string)$obj['id'];
            foreach($obj as $key=>$val){
                $k=is_int($key)?(string)$key:(string)$key;
                self::walk($val,$path===''?$k:$path.'.'.$k,$widget,$policy,$hits,$depth+1);
            }
        }else self::leaf($obj,$path,$widget,$policy,$hits);
    }
    public static function inspect_data($data,$policy=null){
        $policy=$policy?:self::policy();
        if(!$policy)return ['complete'=>false,'reason'=>'company_identity_unbound','signals'=>[]];
        $data=is_string($data)?json_decode($data,true):$data;
        if(!is_array($data))return ['complete'=>false,'reason'=>'invalid_elementor_json','signals'=>[]];
        $hits=[];self::walk($data,'','',$policy,$hits);
        $signals=array_values($hits);
        return ['complete'=>count($signals)<=150,'signals'=>$signals];
    }
    private static function language($id){
        $a=apply_filters('wpml_post_language_details',null,(int)$id);
        return is_array($a)?(string)($a['language_code']??''):'';
    }
    private static function default_lang(){
        return (string)apply_filters('wpml_default_language',null);
    }
    private static function all_posts(){
        $ids=[];
        foreach(self::TYPES as $type){
            $result=get_posts(['post_type'=>$type,'post_status'=>['publish','draft','private'],
                'numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'DESC','suppress_filters'=>true]);
            foreach($result as $id)$ids[]=(int)$id;
        }
        return $ids;
    }
    public static function scan(){
        $policy=self::policy();
        if(!$policy)return ['contract'=>self::CONTRACT,'complete'=>false,'reason'=>'company_identity_unbound',
            'issues'=>[],'observations'=>[]];
        $ids=self::all_posts();
        if(count($ids)>self::MAX_OBJECTS)return ['contract'=>self::CONTRACT,'complete'=>false,
            'reason'=>'scan_capacity_exceeded','scanned'=>0,'issues'=>[],'observations'=>[]];
        $groups=[];$observations=[];$checked=0;$default=self::default_lang();$signals_total=0;
        foreach($ids as $id){
            $post=get_post($id);if(!$post)continue;
            $signals=[];
            foreach(self::FIELDS as $field){
                $raw=get_post_meta($id,$field,true);if(!$raw)continue;
                $r=self::inspect_data($raw,$policy);
                if(empty($r['complete']))return ['contract'=>self::CONTRACT,'complete'=>false,
                    'reason'=>'scan_source_invalid_or_oversize','object_id'=>$id,'issues'=>[],'observations'=>[]];
                foreach($r['signals'] as $signal)$signals[]=$signal;
            }
            $checked++;
            if(!$signals)continue;
            $signals_total+=count($signals);
            $lang=self::language($id)?:$default;
            $ob=['object_id'=>$id,'post_type'=>$post->post_type,'post_status'=>$post->post_status,
                'language'=>$lang,'signal_count'=>count($signals),'signals'=>array_slice($signals,0,12)];
            $observations[]=$ob;
            if($post->post_status!=='publish'||!in_array($lang,['en','ar','fr','de','es','it'],true))continue;
            $source=apply_filters('wpml_object_id',$id,$post->post_type,false,$default);
            $source=(int)($source?:$id);
            if(!get_post($source)||get_post_status($source)!=='publish')continue;
            $key=$post->post_type.':'.$source.':'.$lang;
            if(!isset($groups[$key]))$groups[$key]=['type'=>$post->post_type,'source'=>$source,
                'lang'=>$lang,'confirmed'=>0,'review'=>0,'kinds'=>[],'widgets'=>[]];
            foreach($signals as $signal){
                $groups[$key][$signal['confidence']==='confirmed'?'confirmed':'review']++;
                $groups[$key]['kinds'][$signal['kind']]=true;
                $groups[$key]['widgets'][$signal['widget_id']]=true;
            }
        }
        $issues=[];
        foreach($groups as $group){
            $code='loc_brand_'.$group['lang'];$critical=$group['confirmed']>0;
            $id=hash('sha256',get_current_blog_id().'|'.$group['type'].'|'.$group['source'].'|'.$code);
            $issues[]=['id'=>$id,'post_type'=>$group['type'],'object_id'=>$group['source'],
                'code'=>$code,'severity'=>$critical?'critical':'high',
                'source'=>'mad4b-elementor-localization',
                'label'=>mb_substr('['.$group['lang'].'] '.get_the_title($group['source']).': '.
                    ($critical?'confirmed foreign-brand':'unverified company identity').
                    ' in Elementor ('.$group['confirmed'].' confirmed; '.$group['review'].' review; '.
                    count($group['widgets']).' widgets)',0,239)];
        }
        return ['contract'=>self::CONTRACT,'complete'=>true,'scanned'=>$checked,
            'published_issue_groups'=>count($issues),'flagged_objects'=>count($observations),
            'signals_total'=>$signals_total,'issues'=>$issues,'observations'=>array_slice($observations,0,120),
            'policy_is_company_bound'=>true,'unverified_is_not_confirmed'=>true];
    }
    public static function external($issues,$config){
        if(!is_array($issues))return $issues;
        $scan=self::scan();if(empty($scan['complete']))return $issues;
        $existing=array_fill_keys(array_column($issues,'id'),true);
        foreach($scan['issues'] as $issue){
            if(!isset($existing[$issue['id']]))$issues[]=$issue;
        }
        return $issues;
    }
    public static function note_change($meta_id,$id,$key,$value){
        if(!in_array($key,self::FIELDS,true)||!in_array(get_post_type($id),self::TYPES,true))return;
        self::schedule();
    }
    public static function save_post($post_id,$post,$update){
        if(wp_is_post_revision($post_id)||!in_array($post->post_type,self::TYPES,true))return;
        self::schedule();
    }
    private static function schedule(){
        if(self::$outstanding)return;
        self::$outstanding=true;
        if(!wp_next_scheduled('mad4b_brand_identity_discovery_refresh'))
            wp_schedule_single_event(time()+35,'mad4b_brand_identity_discovery_refresh');
    }
    public static function scheduled(){
        $scan=self::scan();
        update_option(self::HEALTH,['at'=>gmdate('c'),'complete'=>!empty($scan['complete']),
            'reason'=>$scan['reason']??null,'scanned'=>$scan['scanned']??0,
            'issue_groups'=>$scan['published_issue_groups']??0,
            'flagged_objects'=>$scan['flagged_objects']??0,
            'signals_total'=>$scan['signals_total']??0],false);
        if(empty($scan['complete']))error_log('[MAD4B Brand Discovery] '.($scan['reason']??'unknown'));
        do_action('mad4b/brand_identity/scan_finished',['complete'=>!empty($scan['complete']),
            'groups'=>$scan['published_issue_groups']??0]);
    }
    public static function cli($args,$opts){
        if($args||$opts)WP_CLI::error('No arguments allowed');
        $result=self::scan();
        WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if(empty($result['complete']))WP_CLI::error($result['reason']??'scan_incomplete');
    }
    public static function boot(){
        if(!self::policy())return;
        add_filter('mad4b/content_readiness/external_issues',[__CLASS__,'external'],55,2);
        add_action('updated_post_meta',[__CLASS__,'note_change'],40,4);
        add_action('added_post_meta',[__CLASS__,'note_change'],40,4);
        add_action('deleted_post_meta',[__CLASS__,'note_change'],40,4);
        add_action('save_post',[__CLASS__,'save_post'],30,3);
        add_action('mad4b_brand_identity_discovery_scan',[__CLASS__,'scheduled']);
        add_action('mad4b_brand_identity_discovery_refresh',[__CLASS__,'scheduled']);
        add_action('init',static function(){
            if(!wp_next_scheduled('mad4b_brand_identity_discovery_scan'))
                wp_schedule_event(time()+180,'hourly','mad4b_brand_identity_discovery_scan');
        },35);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b brand discover',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_Brand_Identity_Discovery_V1','boot'],50);
