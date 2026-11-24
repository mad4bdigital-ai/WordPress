<?php
/**
 * Plugin Name: MAD4B Company Package Levels — Operational Assignment & Global Fallback
 * Version: 1.0.0
 * Description: Company-local default Package Level strategy in Policy Registry v2, with explicit Tour taxonomy overriding defaults.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Package_Levels_V1',false)) {
final class MAD4B_Company_Package_Levels_V1 {
    public const PROVIDER='company-package-level-strategy';
    public const POLICY='pricing.package.levels';
    public const SLUGS=[
        1=>'silver-package',
        2=>'gold-package',
        4=>'diamond-package',
        8=>'platinum-package'
    ];
    public const LABELS=[
        1=>'Silver',2=>'Gold',4=>'Diamond',8=>'Platinum'
    ];
    public static function presets():array {
        $out=[];
        for($i=1;$i<16;$i++) {
            $names=[];
            foreach(self::LABELS as $mask=>$name)if($i&$mask)$names[]=$name;
            $out['mask_'.str_pad((string)$i,2,'0',STR_PAD_LEFT)]=implode(' + ',$names);
        }
        return $out;
    }
    public static function register():void {
        if(!class_exists('MAD4B_Company_Policy_Registry_V2',false))return;
        MAD4B_Company_Policy_Registry_V2::register_provider(self::PROVIDER,[
            'label'=>'Company Package Levels — Operational Default (only unassigned Tours)',
            'policy_id'=>self::POLICY,
            'fields'=>[
                'tier_mask'=>[
                    'type'=>'enum',
                    'options'=>self::presets(),
                ],
            ],
            'allowed_scopes'=>['company'],
        ]);
    }
    public static function canonical(int $post):int {
        if($post<1||get_post_type($post)!=='tours-and-activities')return 0;
        $en=(int)apply_filters('wpml_object_id',$post,'tours-and-activities',false,'en');
        return $en>0 && get_post_type($en)==='tours-and-activities' ? $en : $post;
    }
    private static function sourceLanguage(callable $callback) {
        $from=(string)apply_filters('wpml_current_language','');
        $default=(string)apply_filters('wpml_default_language','');
        if(!$from||!$default||$from===$default)return $callback();
        do_action('wpml_switch_language',$default);
        try{return $callback();}
        finally{do_action('wpml_switch_language',$from);}
    }
    public static function catalog():array {
        return self::sourceLanguage(static function(){
            $out=[];
            foreach(self::SLUGS as $mask=>$slug) {
                $term=get_term_by('slug',$slug,'package_levels');
                if(!($term instanceof WP_Term))continue;
                $out[$mask]=['id'=>(int)$term->term_id,'slug'=>$slug,
                  'label'=>self::LABELS[$mask],'mask'=>$mask];
            }
            return $out;
        });
    }
    public static function idsFromMask(string $value):array {
        if(!preg_match('/^mask_(0[1-9]|1[0-5])$/D',$value))return [];
        $mask=(int)substr($value,5);
        $out=[];
        foreach(self::catalog() as $bit=>$term)
            if($mask & $bit)$out[]=$term['id'];
        return $out;
    }
    public static function maskFromIds(array $ids):string {
        $set=array_flip(array_map('absint',$ids));$mask=0;
        foreach(self::catalog() as $bit=>$term)
            if(isset($set[$term['id']]))$mask|=$bit;
        return 'mask_'.str_pad((string)$mask,2,'0',STR_PAD_LEFT);
    }
    public static function operationalAssignments():array {
        return self::sourceLanguage(static function(){
            $ids=get_posts([
                'post_type'=>'tours-and-activities','post_status'=>'publish',
                'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,
                'tax_query'=>[[
                    'taxonomy'=>'tour-types','field'=>'slug',
                    'terms'=>['packages'],'operator'=>'IN'
                ]]
            ]);
            $c=self::catalog();$allowedIds=array_column($c,'id');
            $rows=[];$counts=array_fill_keys($allowedIds,0);
            $assigned=0;$missing=[];
            foreach($ids as $tour) {
                $source=self::canonical((int)$tour);
                if($source!==(int)$tour)continue;
                $terms=wp_get_object_terms($source,'package_levels',['fields'=>'ids']);
                if(is_wp_error($terms))continue;
                $values=array_values(array_unique(array_intersect(
                    $allowedIds,array_map('intval',$terms))));
                sort($values,SORT_NUMERIC);
                $rows[$source]=$values;
                if(!$values){$missing[]=$source;continue;}
                $assigned++;
                foreach($values as $id)$counts[$id]++;
            }
            ksort($rows,SORT_NUMERIC);
            $default=[];
            foreach($counts as $id=>$count)
                if($assigned>0 && $count*2>$assigned)$default[]=(int)$id;
            return [
                'contract'=>'mad4b.operational-tour-package-levels-audit.v1',
                'company_site'=>home_url(),
                'published_package_tours'=>count($rows),
                'explicitly_assigned_package_tours'=>$assigned,
                'unassigned_tour_ids'=>$missing,
                'counts_per_tier'=>$counts,
                'majority_tier_ids'=>$default,
                'majority_mask'=>self::maskFromIds($default),
                'source_assignments_sha256'=>hash('sha256',
                    wp_json_encode($rows,JSON_UNESCAPED_SLASHES))
            ];
        });
    }
    public static function resolve(int $tour):array {
        $tour=self::canonical($tour);
        $out=[
            'contract'=>'mad4b.company-package-level-effective.v1',
            'company_site'=>home_url(),'tour_id'=>$tour,
            'source'=>'unavailable','effective_tier_ids'=>[],
            'explicit_tier_ids'=>[],'company_default_tier_ids'=>[],
            'company_rule_id'=>null,'company_registry_revision'=>0,
            'operator_override_preserved'=>true,
            'checkout_authorized'=>false,
        ];
        if(!$tour)return $out;
        return self::sourceLanguage(static function() use ($tour,$out){
            $types=wp_get_object_terms($tour,'tour-types',['fields'=>'slugs']);
            if(is_wp_error($types)||!in_array('packages',$types,true)){
                $out['source']='not_package';return $out;
            }
            $catalog=self::catalog();
            $all=array_column($catalog,'id');
            $terms=wp_get_object_terms($tour,'package_levels',['fields'=>'ids']);
            if(is_wp_error($terms)){
                $out['source']='taxonomy_unavailable';return $out;
            }
            $rawAssigned=array_values(array_unique(array_map('intval',$terms)));
            $explicit=array_values(array_unique(array_intersect($all,$rawAssigned)));
            $unknown=array_values(array_diff($rawAssigned,$all));
            sort($explicit,SORT_NUMERIC);
            $out['explicit_tier_ids']=$explicit;
            $out['unsupported_operator_tier_ids']=$unknown;
            $policy=class_exists('MAD4B_Company_Policy_Registry_V2',false)
                ?MAD4B_Company_Policy_Registry_V2::resolve(self::POLICY,
                    ['tour_id'=>$tour],[])
                :[];
            $mask=(string)($policy['values']['tier_mask']??'');
            $receipt=$policy['receipt']??[];
            $default=$receipt['rule_id']??null;
            $fallback=$default?self::idsFromMask($mask):[];
            $out['company_default_tier_ids']=$fallback;
            $out['company_rule_id']=$default;
            $out['company_registry_revision']=(int)($receipt['registry_revision']??0);
            $out['company_policy_mask']=$default?$mask:null;
            if($rawAssigned) {
                // Any operator assignment, even one unknown to this engine,
                // overrides the company fallback. Unknown levels remain
                // blocked rather than being silently replaced by defaults.
                $out['source']=$explicit?'explicit_operational_tour_terms':
                    'explicit_operator_levels_require_provider_support';
                $out['effective_tier_ids']=$explicit;
            } elseif($default && $fallback) {
                $out['source']='company_default_inherited';
                $out['effective_tier_ids']=$fallback;
            } else $out['source']='unassigned_company_policy_missing';
            return $out;
        });
    }
    public static function permitted(int $tour,int $tier):bool {
        $tier=(int)$tier;
        if(class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false))
            $tier=MAD4B_Package_Stage_Rate_Guard_V1::canonicalTier($tier);
        $resolved=self::resolve($tour);
        return $tier>0&&in_array($tier,$resolved['effective_tier_ids'],true);
    }
}
add_action('muplugins_loaded',[MAD4B_Company_Package_Levels_V1::class,'register'],30);
}

