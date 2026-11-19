<?php
/** Native JetEngine season Term Meta -> full-period Tour Rate matching. */
defined('ABSPATH')||exit;
final class MAD4B_JetEngine_Rate_Season_Term_Matcher_V1 {
    public const AUTO_META='_mad4b_rate_auto_season_term_v1';
    private static ?array $termDefinitions=null;
    public static function invalidate():void { self::$termDefinitions=null; }
    public static function boot():void {
        foreach(['edited_term','created_term','delete_term',
            'added_term_meta','updated_term_meta','deleted_term_meta'] as $hook)
            add_action($hook,[__CLASS__,'invalidate'],5,0);
    }
    public static function iso($raw):?string {
        if(!is_scalar($raw))return null;
        $v=trim((string)$raw);
        if(ctype_digit($v)&&(int)$v>0&&(int)$v<4102444800)
            return gmdate('Y-m-d',(int)$v);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d')===$v?$v:null;
    }
    private static function lang(int $id,string $type):?string {
        $lang=apply_filters('wpml_element_language_code',null,[
            'element_id'=>$id,'element_type'=>$type
        ]);
        return is_string($lang)&&$lang!==''?$lang:null;
    }
    public static function window(WP_Term $term,string $from,string $until):?array {
        // Use native Term Meta first. For WPML translations that do not
        // duplicate season calendars, resolve the default-language policy
        // term with a verified two-way WPML mapping. Do not rely on the
        // *admin's current language* when evaluating a Rate in another locale.
        $policyId=(int)$term->term_id;
        if(get_term_meta($policyId,'season_enabled',true)==='') {
            $default=apply_filters('wpml_default_language',null);
            $locale=apply_filters('wpml_element_language_code',null,[
                'element_id'=>(int)$term->term_taxonomy_id,
                'element_type'=>'tax_season'
            ]);
            if(is_string($default)&&is_string($locale)
                &&$default!==''&&$locale!==''&&$default!==$locale) {
                $mapped=(int)apply_filters('wpml_object_id',$policyId,'season',false,$default);
                $reverse=$mapped?(int)apply_filters('wpml_object_id',$mapped,'season',false,$locale):0;
                if($mapped>0&&$reverse===$policyId
                    &&get_term_meta($mapped,'season_enabled',true)==='1')
                    $policyId=$mapped;
            }
        }
        if(get_term_meta($policyId,'season_enabled',true)!=='1')return null;
        $mode=(string)get_term_meta($policyId,'season_mode',true);
        if($mode!==''&&$mode!=='travel')return null;
        $sm=(int)get_term_meta($policyId,'season_start_month',true);
        $sd=(int)get_term_meta($policyId,'season_start_day',true);
        $em=(int)get_term_meta($policyId,'season_end_month',true);
        $ed=(int)get_term_meta($policyId,'season_end_day',true);
        $cross=get_term_meta($policyId,'season_cross_year',true)==='1';
        for($year=(int)substr($from,0,4)-1;$year<=(int)substr($until,0,4);$year++) {
            if(!checkdate($sm,$sd,$year)
                ||!checkdate($em,$ed,$year+($cross?1:0)))continue;
            $a=sprintf('%04d-%02d-%02d',$year,$sm,$sd);
            $b=sprintf('%04d-%02d-%02d',$year+($cross?1:0),$em,$ed);
            if($a<=$from&&$until<=$b)
                return ['window_start'=>$a,'window_end'=>$b,'policy_term_id'=>$policyId];
        }
        return null;
    }
    public static function resolve(int $id):array {
        $fallback=['status'=>'unresolved','label'=>'Season not specified',
            'term_id'=>null,'source'=>'none','can_bind'=>false,
            'window_start'=>null,'window_end'=>null];
        if(get_post_type($id)!=='tour-rates')return $fallback;
        $terms=wp_get_post_terms($id,'season',['orderby'=>'name','order'=>'ASC']);
        if(is_wp_error($terms))return $fallback;
        $auto=get_post_meta($id,self::AUTO_META,true);
        $tracked=count($terms)===1&&is_array($auto)
            &&(int)($auto['term_id']??0)===(int)$terms[0]->term_id;
        if($terms&&!$tracked){
            $names=[];
            foreach(array_slice($terms,0,5) as $t)
                if($t instanceof WP_Term&&trim($t->name)!=='')
                    $names[]=sanitize_text_field($t->name);
            return [
                'status'=>'assigned','label'=>$names?implode(' / ',array_unique($names)):'Season not specified',
                'term_id'=>count($terms)===1?(int)$terms[0]->term_id:null,
                'source'=>'rate_taxonomy','can_bind'=>false,'window_start'=>null,'window_end'=>null
            ];
        }
        $a=self::iso(get_post_meta($id,'tour_rate_start_date',true));
        $b=self::iso(get_post_meta($id,'tour_rate_end_date',true));
        if(!$a||!$b||$a>$b){
            $fallback['status']='incomplete_rate_period';
            return $fallback;
        }
        if(self::$termDefinitions===null){
            $all=get_terms(['taxonomy'=>'season','hide_empty'=>false,
                'lang'=>'all','number'=>200,'orderby'=>'term_id','order'=>'ASC']);
            if(is_wp_error($all))return $fallback;
            self::$termDefinitions=$all;
        }
        $all=self::$termDefinitions;
        $candidates=[];
        foreach($all as $term){
            if(!$term instanceof WP_Term)continue;
            $window=self::window($term,$a,$b);
            if(!$window)continue;
            // WPML translations of one season represent one policy family.
            $default=apply_filters('wpml_default_language',null);
            $canonical=$default?apply_filters('wpml_object_id',
                $term->term_id,'season',false,$default):null;
            $key=(int)($canonical?:$window['policy_term_id']);
            $candidates[$key][]=['term'=>$term,'window'=>$window];
        }
        if(count($candidates)>1){
            $fallback['status']='ambiguous_seasons';
            $fallback['label']='Multiple matching seasons';
            $fallback['source']='term_meta_ambiguous';
            return $fallback;
        }
        if(!$candidates){
            $fallback['status']='outside_single_season';
            $fallback['label']='Multiple seasons / unmatched period';
            $fallback['source']='term_meta_no_full_match';
            return $fallback;
        }
        $family=reset($candidates);
        $rateLang=self::lang($id,'post_tour-rates');
        $selected=$family[0];
        foreach($family as $candidate){
            if($rateLang&&self::lang((int)$candidate['term']->term_taxonomy_id,'tax_season')===$rateLang){
                $selected=$candidate;break;
            }
        }
        $term=$selected['term'];$window=$selected['window'];
        $termLang=self::lang((int)$term->term_taxonomy_id,'tax_season');
        $sameLanguage=(!$rateLang&&!$termLang)
            ||($rateLang&&$termLang&&$rateLang===$termLang);
        return [
            'status'=>'inherited','label'=>sanitize_text_field($term->name),
            'term_id'=>(int)$term->term_id,
            'policy_term_id'=>(int)$window['policy_term_id'],
            'source'=>'season_term_meta_full_period_match',
            'can_bind'=>(bool)$sameLanguage,
            'rate_language'=>$rateLang,'term_language'=>$termLang,
            'window_start'=>$window['window_start'],
            'window_end'=>$window['window_end'],
            'rate_start'=>$a,'rate_end'=>$b,
        ];
    }
    /**
     * Reverse only the taxonomy relationship created by this module. If the
     * editor has changed it, fail closed rather than deleting manual choices.
     */
    public static function owned_rollback_status(int $id):array {
        $managed=get_post_meta($id,self::AUTO_META,true);
        if(!is_array($managed)||!isset($managed['term_id']))
            return ['status'=>'not_owned','clearable'=>false];
        $owned=(int)$managed['term_id'];
        $actual=wp_get_post_terms($id,'season',['fields'=>'ids']);
        if(is_wp_error($actual) || count($actual)!==1
            || (int)$actual[0]!==$owned)
            return ['status'=>'managed_term_changed','clearable'=>false];
        return ['status'=>'owned','clearable'=>true,'term_id'=>$owned];
    }
    public static function rollback_owned(int $id):array {
        $state=self::owned_rollback_status($id);
        if($state['status']==='not_owned')return ['status'=>'not_owned','changed'=>false];
        if(!$state['clearable'])return ['status'=>'conflict','changed'=>false];
        $type=get_post_type_object('tour-rates');
        if(!$type || !current_user_can('edit_post',$id)
           || (in_array(get_post_status($id),['publish','future','private'],true)
                && !current_user_can($type->cap->publish_posts)))
            return ['status'=>'not_authorized','changed'=>false];
        $removed=wp_set_object_terms($id,[],'season',false);
        if(is_wp_error($removed) || has_term('','season',$id))
            return ['status'=>'unlink_failed','changed'=>false];
        delete_post_meta($id,self::AUTO_META);
        return ['status'=>'auto_season_unlinked','changed'=>true];
    }
    public static function bind_if_safe(int $id,array $resolved):array {
        $type=get_post_type_object('tour-rates');
        if(!$type || !current_user_can('edit_post',$id)
            ||(in_array(get_post_status($id),['publish','future','private'],true)
                && !current_user_can($type->cap->publish_posts)))
            return ['status'=>'not_authorized','linked'=>false];
        $terms=wp_get_post_terms($id,'season',['fields'=>'ids']);
        if(is_wp_error($terms))return ['status'=>'taxonomy_read_error','linked'=>false];
        $managed=get_post_meta($id,self::AUTO_META,true);
        $tracked=is_array($managed)?(int)($managed['term_id']??0):0;
        $current=count($terms)===1?(int)$terms[0]:0;
        $owns=($tracked>0 && $current===$tracked);
        if($terms&&!$owns)return ['status'=>'manual_season_preserved','linked'=>false];
        if(($resolved['status']??'')!=='inherited' || empty($resolved['can_bind'])) {
            if(!$owns)return ['status'=>empty($resolved['can_bind'])
                ?'wpml_translation_missing':'not_matchable','linked'=>false];
            // Clear only the association previously created by this module;
            // do not leave a stale season on a Rate spanning two seasons.
            $clear=wp_set_object_terms($id,[],'season',false);
            if(is_wp_error($clear)||has_term('','season',$id))
                return ['status'=>'stale_season_clear_failed','linked'=>false];
            delete_post_meta($id,self::AUTO_META);
            return ['status'=>'stale_auto_season_cleared','linked'=>false];
        }
        $termId=(int)$resolved['term_id'];
        if(!$termId||!term_exists($termId,'season'))
            return ['status'=>'term_not_available','linked'=>false];
        if($owns && $current===$termId)
            return ['status'=>'already_linked','linked'=>false,'term_id'=>$termId];
        $result=wp_set_object_terms($id,[$termId],'season',false);
        if(is_wp_error($result)||!has_term($termId,'season',$id))
            return ['status'=>'season_bind_failed','linked'=>false];
        update_post_meta($id,self::AUTO_META,[
            'term_id'=>$termId,
            'policy_term_id'=>(int)$resolved['policy_term_id'],
            'source'=>'season_term_meta_full_period_match',
            'rate_start'=>$resolved['rate_start'],
            'rate_end'=>$resolved['rate_end'],
            'linked_at'=>gmdate('c'),
        ]);
        return ['status'=>$owns?'auto_season_relinked':'auto_season_linked',
            'linked'=>true,'term_id'=>$termId];
    }
}
