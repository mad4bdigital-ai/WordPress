<?php
/**
 * Hybrid Pricing Policy Storage: central Lab draft registry + term/post
 * reference metadata + read-only inheritance, without checkout writes.
 */
defined('ABSPATH') || exit;
final class MAD4B_Hybrid_Pricing_Policies_V1 {
    public const TERM_META='mad4b_pricing_policy_ids';
    public const POST_META='mad4b_pricing_policy_override_ids';
    public const GLOBAL_OPTION='mad4b_pricing_global_policy_ids_v1';
    public const RESOLUTION_OPTION='mad4b_hybrid_pricing_resolution_v1';
    public const RESOLUTION_LEVELS=[
        'rate_override','tour_override','taxonomy_term_policy','global_policy'
    ];
    public const LAB_OPTION='mad4b_jetengine_pricing_lab_presets_v1';
    public const AUDIT_OPTION='mad4b_pricing_policy_binding_audit_v1';
    public const REV_META='_mad4b_pricing_policy_binding_revision_v1';
    public const AJAX='mad4b_hybrid_policy_bindings_v1';
    public const MAX_REFS=12;
    private const TOUR='tours-and-activities';
    private const RATE='tour-rates';

    /** Versioned, company-local resolver policy; does not alter live pricing. */
    public static function resolution_config():array {
        $raw=get_option(self::RESOLUTION_OPTION,[]);
        $tax=self::taxonomies();
        $order=self::RESOLUTION_LEVELS;
        $taxOrder=$tax;
        $mode='strict';
        $rev=0;
        if(is_array($raw)){
            $incoming=$raw['order']??null;
            if(is_array($incoming)&&count($incoming)===4
                &&count(array_filter($incoming,'is_string'))===4
                &&count(array_unique($incoming))===4
                &&!array_diff($incoming,self::RESOLUTION_LEVELS)
                &&!array_diff(self::RESOLUTION_LEVELS,$incoming))
                $order=array_values($incoming);
            $incoming=$raw['taxonomy_order']??null;
            if(is_array($incoming) && count($incoming)===count($tax)
                &&count(array_filter($incoming,'is_string'))===count($tax)
                &&count(array_unique($incoming))===count($tax)
                &&!array_diff($incoming,$tax)&&!array_diff($tax,$incoming))
                $taxOrder=array_values($incoming);
            if(in_array($raw['taxonomy_collision']??'',['strict','ranked'],true))
                $mode=$raw['taxonomy_collision'];
            $rev=max(0,(int)($raw['revision']??0));
        }
        return ['order'=>$order,'taxonomy_order'=>$taxOrder,
            'taxonomy_collision'=>$mode,'revision'=>$rev,
            'saved'=>$rev>0,'scope'=>'preview_only_policy_resolution'];
    }
    public static function save_resolution_config(array $input,int $expected=0){
        if(!self::permitted())return new WP_Error('hybrid_forbidden');
        $old=self::resolution_config();
        if($expected!==$old['revision'])return new WP_Error('hybrid_config_stale');
        $order=$input['order']??null;
        $tax=$input['taxonomy_order']??null;
        $all=self::taxonomies();
        if(!is_array($order)||count($order)!==4
            ||count(array_filter($order,'is_string'))!==4
            ||count(array_unique($order))!==4
            ||array_diff($order,self::RESOLUTION_LEVELS)
            ||array_diff(self::RESOLUTION_LEVELS,$order))
            return new WP_Error('hybrid_invalid_precedence');
        if(!is_array($tax)||count($tax)!==count($all)
            ||count(array_filter($tax,'is_string'))!==count($all)
            ||count(array_unique($tax))!==count($all)
            ||array_diff($tax,$all)||array_diff($all,$tax))
            return new WP_Error('hybrid_invalid_taxonomy_priority');
        $collision=$input['taxonomy_collision']??null;
        if(!in_array($collision,['strict','ranked'],true))
            return new WP_Error('hybrid_invalid_collision_mode');
        $value=['order'=>array_values($order),
            'taxonomy_order'=>array_values($tax),
            'taxonomy_collision'=>$collision,
            'revision'=>$expected+1,'actor'=>get_current_user_id(),
            'updated_at'=>gmdate('c')];
        update_option(self::RESOLUTION_OPTION,$value,false);
        $read=self::resolution_config();
        if($read['revision']!==$value['revision']
           ||$read['order']!==$value['order']
           ||$read['taxonomy_order']!==$value['taxonomy_order']
           ||$read['taxonomy_collision']!==$collision)
           return new WP_Error('hybrid_config_write_failed');
        return $read+['status'=>'saved','live_prices_changed'=>false];
    }
    public static function permitted():bool {
        return current_user_can('commerce_manage_pricing')
            && (current_user_can('manage_woocommerce') || (current_user_can('mad4b_manage_company_operations') && current_user_can('mad4b_work_company_tasks')));
    }
    public static function taxonomies():array {
        $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
        $catalog=$bridge::catalog(['en'],false);
        $tax=[];
        foreach($catalog['dimensions'] as $d){
            if(($d['source']??'')!=='taxonomy')continue;
            $name=(string)($d['key']??'');
            if($name && taxonomy_exists($name) && is_object_in_taxonomy(self::TOUR,$name))
                $tax[$name]=$name;
        }
        if(taxonomy_exists('season'))$tax['season']='season';
        return array_values($tax);
    }
    public static function boot():void {
        add_action('init',[__CLASS__,'register_meta'],25);
    }
    public static function register_meta():void {
        foreach([self::TOUR,self::RATE] as $type)
            register_post_meta($type,self::POST_META,[
                'type'=>'array','single'=>true,'default'=>[],
                'show_in_rest'=>false,'auth_callback'=>[__CLASS__,'meta_authorized'],
                'sanitize_callback'=>[__CLASS__,'sanitize_meta']
            ]);
        foreach(self::taxonomies() as $tax)
            register_term_meta($tax,self::TERM_META,[
                'type'=>'array','single'=>true,'default'=>[],
                'show_in_rest'=>false,'auth_callback'=>[__CLASS__,'meta_authorized'],
                'sanitize_callback'=>[__CLASS__,'sanitize_meta']
            ]);
    }
    public static function meta_authorized():bool {return self::permitted();}
    public static function sanitize_meta($value):array {
        if(!is_array($value))return [];
        $ids=[];
        foreach(array_slice($value,0,self::MAX_REFS) as $id)
            if(is_string($id) && preg_match('/^pl-[a-f0-9]{32}$/D',$id))
                $ids[$id]=$id;
        return array_values($ids);
    }
    public static function presets():array {
        $rows=get_option(self::LAB_OPTION,[]);
        $map=[];
        foreach(is_array($rows)?$rows:[] as $preset)
            if(is_array($preset)&&preg_match('/^pl-[a-f0-9]{32}$/D',(string)($preset['id']??'')))
                $map[$preset['id']]=$preset;
        return $map;
    }
    public static function read(string $scope,int $id=0):array {
        if($scope==='global'){
            $v=get_option(self::GLOBAL_OPTION,[]);
            $ids=is_array($v)?($v['ids']??[]):[];
            $rev=is_array($v)?(int)($v['revision']??0):0;
        } elseif($scope==='term'){
            $ids=get_term_meta($id,self::TERM_META,true);
            $rev=(int)get_term_meta($id,self::REV_META,true);
        } else {
            $ids=get_post_meta($id,self::POST_META,true);
            $rev=(int)get_post_meta($id,self::REV_META,true);
        }
        return ['scope'=>$scope,'id'=>$id,
            'ids'=>self::sanitize_meta(is_array($ids)?$ids:[]),
            'revision'=>$rev];
    }
    public static function validate_target(string $scope,int $id) {
        if($scope==='global' && $id===0)return true;
        if($id<=0)return new WP_Error('hybrid_target_missing','Select a real term, Tour or Rate');
        if($scope==='term'){
            $term=get_term($id);
            if(!$term || is_wp_error($term)
                ||!in_array($term->taxonomy,self::taxonomies(),true))
                return new WP_Error('hybrid_term_scope_invalid','Term is not a supported Tour taxonomy');
            return true;
        }
        $type=$scope==='tour'?self::TOUR:($scope==='rate'?self::RATE:null);
        if(!$type||get_post_type($id)!==$type)
            return new WP_Error('hybrid_post_scope_invalid','Target is not the selected post type');
        return true;
    }
    public static function bind(string $scope,int $id,array $refs,int $expected=0) {
        if(!self::permitted())return new WP_Error('hybrid_forbidden');
        $target=self::validate_target($scope,$id);
        if(is_wp_error($target))return $target;
        if(count($refs)>self::MAX_REFS)return new WP_Error('hybrid_too_many_refs');
        if(count(array_filter($refs,'is_string'))!==count($refs))
            return new WP_Error('hybrid_invalid_ref');
        $clean=self::sanitize_meta($refs);
        if(count($clean)!==count(array_unique($refs)))
            return new WP_Error('hybrid_invalid_or_duplicate_refs');
        $presets=self::presets();
        foreach($clean as $pid){
            if(!isset($presets[$pid]))return new WP_Error('hybrid_unknown_policy',
                'Policies must exist in the central Pricing Lab registry');
            if(!in_array(($presets[$pid]['status']??''),['draft','staged'],true))
                return new WP_Error('hybrid_invalid_policy_status');
        }
        $old=self::read($scope,$id);
        if($old['revision']!==$expected)return new WP_Error('hybrid_stale_revision');
        if($old['ids']===$clean)return $old+['status'=>'unchanged'];
        $revision=$expected+1;
        if($scope==='global'){
            $done=update_option(self::GLOBAL_OPTION,
                ['ids'=>$clean,'revision'=>$revision,'updated_at'=>gmdate('c')],false);
        }elseif($scope==='term'){
            $done=empty($clean)?delete_term_meta($id,self::TERM_META)
                :update_term_meta($id,self::TERM_META,$clean);
            update_term_meta($id,self::REV_META,$revision);
        }else{
            $done=empty($clean)?delete_post_meta($id,self::POST_META)
                :update_post_meta($id,self::POST_META,$clean);
            update_post_meta($id,self::REV_META,$revision);
        }
        // A delete of an absent option is a no-op; compare readback for
        // actual correctness rather than relying on WP's boolean return.
        $new=self::read($scope,$id);
        if($new['ids']!==$clean || $new['revision']!==$revision)
            return new WP_Error('hybrid_binding_failed','Reference write did not persist');
        $audit=get_option(self::AUDIT_OPTION,[]);
        if(!is_array($audit))$audit=[];
        $audit[]=['scope'=>$scope,'id'=>$id,'old'=>$old['ids'],
            'new'=>$clean,'revision'=>$revision,'actor'=>get_current_user_id(),
            'time'=>gmdate('c')];
        update_option(self::AUDIT_OPTION,array_slice($audit,-100),false);
        return $new+['status'=>'saved','live_prices_changed'=>false];
    }
    private static function language(int $id,string $type):?string {
        $v=apply_filters('wpml_element_language_code',null,[
            'element_id'=>$id,'element_type'=>$type]);
        return is_string($v)&&$v!==''?$v:null;
    }
    /**
     * Read-only precedence. All links are references to central Lab drafts;
     * ambiguous policies at any one precedence tier fail closed. This does
     * not authorize a checkout price or apply a marketing campaign.
     */
    public static function resolve(int $postId):array {
        $type=get_post_type($postId);
        if(!in_array($type,[self::RATE,self::TOUR],true))
            return ['status'=>'invalid_target','effective'=>null,'levels'=>[],'conflicts'=>[]];
        $presets=self::presets();
        $levels=[];$conflicts=[];
        $tourId=$type===self::TOUR?$postId:absint(get_post_meta($postId,'related_tour_id',true));
        if($type===self::RATE){
            $levels[]=['source'=>'rate_override','source_id'=>$postId,
                'ids'=>self::read('rate',$postId)['ids']];
        }
        $validTour=$tourId>0 && get_post_type($tourId)===self::TOUR;
        if($validTour && $type===self::RATE){
            $rateLang=self::language($postId,'post_'.self::RATE);
            $tourLang=self::language($tourId,'post_'.self::TOUR);
            if($rateLang&&$tourLang&&$rateLang!==$tourLang){
                $conflicts[]=['type'=>'wpml_parent_language_mismatch',
                    'rate'=>$postId,'tour'=>$tourId];
                $validTour=false;
            }
        }
        if($validTour){
            $levels[]=['source'=>'tour_override','source_id'=>$tourId,
                'ids'=>self::read('tour',$tourId)['ids']];
        }
        $termRefs=[];$taxonomyList=self::taxonomies();
        // Inherit from native Rate season and all native Tour taxonomy terms.
        $scan=$type===self::RATE?[[$postId,'rate']]:[];
        if($validTour)$scan[]=[$tourId,'tour'];
        foreach($scan as [$owner,$kind]){
            $ownerLanguage=self::language($owner,'post_'.get_post_type($owner));
            $terms=wp_get_object_terms($owner,$taxonomyList);
            if(is_wp_error($terms))continue;
            foreach($terms as $term){
                // Only terms carrying policy references participate in
                // policy inheritance. A language mismatch on an unbound
                // taxonomy is irrelevant and must not create false
                // commercial conflict warnings in Rate Generator previews.
                $refs=self::read('term',(int)$term->term_id)['ids'];
                if(!$refs)continue;
                $termLanguage=self::language((int)$term->term_taxonomy_id,'tax_'.$term->taxonomy);
                if($ownerLanguage&&$termLanguage&&$ownerLanguage!==$termLanguage){
                    $conflicts[]=['type'=>'wpml_term_language_mismatch',
                        'term_id'=>$term->term_id,'owner_id'=>$owner];
                    continue;
                }
                $termRefs[]=['term_id'=>(int)$term->term_id,
                    'taxonomy'=>$term->taxonomy,'from'=>$kind,'ids'=>$refs];
            }
        }
        $config=self::resolution_config();
        $merged=[];
        $rankedTaxonomy=null;
        if($config['taxonomy_collision']==='ranked' && $termRefs){
            $ranks=array_flip($config['taxonomy_order']);
            usort($termRefs,static function($a,$b)use($ranks){
                $ar=$ranks[$a['taxonomy']]??PHP_INT_MAX;
                $br=$ranks[$b['taxonomy']]??PHP_INT_MAX;
                return ($ar<=>$br) ?: (($a['term_id']??0)<=>($b['term_id']??0));
            });
            // A taxonomy can contain several matching terms. Never silently
            // choose between different policies of identical taxonomy rank.
            $top=$termRefs[0]['taxonomy'];
            $rankedTaxonomy=$top;
            foreach($termRefs as $row){
                if($row['taxonomy']!==$top)break;
                foreach($row['ids'] as $id)$merged[$id]=$id;
            }
        }else{
            foreach($termRefs as $row)
                foreach($row['ids'] as $id)$merged[$id]=$id;
        }
        $levels[]=['source'=>'taxonomy_term_policy','terms'=>$termRefs,
            'ids'=>array_values($merged),'selected_taxonomy'=>$rankedTaxonomy];
        $levels[]=['source'=>'global_policy','ids'=>self::read('global')['ids']];
        $positions=array_flip($config['order']);
        usort($levels,static fn($a,$b)=>($positions[$a['source']]??99)
            <=>($positions[$b['source']]??99));
        $effective=null;$status='existing_pricing_default';
        foreach($levels as &$level){
            $valid=[];
            foreach($level['ids'] as $id){
                if(isset($presets[$id]) &&
                    in_array(($presets[$id]['status']??''),['draft','staged'],true))
                    $valid[$id]=['id'=>$id,'title'=>$presets[$id]['title']??$id,
                        'revision'=>(int)($presets[$id]['revision']??0),
                        'status'=>$presets[$id]['status']];
                else $conflicts[]=['type'=>'unresolved_policy_ref','id'=>$id,
                    'source'=>$level['source']];
            }
            $level['policies']=array_values($valid);
            if($effective!==null||$status==='ambiguous_policy_scope'||!$valid)continue;
            if(count($valid)>1){
                $status='ambiguous_policy_scope';
                $conflicts[]=['type'=>'multiple_policies_same_precedence',
                    'source'=>$level['source'],'ids'=>array_keys($valid)];
            }else{
                $effective=reset($valid);
                $effective['source']=$level['source'];
                $status='preview_only_policy_reference';
            }
        }
        unset($level);
        return ['status'=>$status,'target_id'=>$postId,
            'tour_id'=>$validTour?$tourId:null,'effective'=>$effective,
            'levels'=>$levels,'conflicts'=>$conflicts,
            'mode'=>'read_only_draft_inheritance',
            'resolution_config'=>$config,'commercial_activation'=>false];
    }
}
