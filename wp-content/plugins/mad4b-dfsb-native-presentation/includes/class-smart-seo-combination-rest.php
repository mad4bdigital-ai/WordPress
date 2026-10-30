<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH')) { exit; }

/** Full administrator REST lifecycle for exact, evidence-qualified combinations. */
final class SmartSeoCombinationRest {
    const NS='mad4b/v1';
    public static function register(): void {
        $p=array(__CLASS__,'authorized');
        $routes=array(
            array('/seo-intelligence/combinations/facets','GET','facets'),
            array('/seo-intelligence/combinations/preview','POST','preview'),
            array('/seo-intelligence/combinations','GET','listing'),
            array('/seo-intelligence/combinations','POST','save'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})','GET','detail'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/localize','PUT','localize'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/verify','POST','verify'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/approve','POST','approve'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/publish','POST','publish'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/postpublish-qa','POST','postpublishQa'),
            array('/seo-intelligence/combinations/(?P<id>[a-f0-9]{64})/rollback','POST','rollback'),
        );
        foreach($routes as $r) {
            register_rest_route(self::NS,$r[0],array(
                'methods'=>$r[1],'permission_callback'=>$p,'callback'=>array(__CLASS__,$r[2]),
            ));
        }
    }
    public static function authorized(): bool { return current_user_can('manage_options'); }
    private static function source($args) {
        return SmartSeoCombinations::source((string)($args['provider']??''),
            (string)($args['query_id']??''));
    }
    private static function input(\WP_REST_Request $req): array {
        $data=$req->get_json_params();
        return is_array($data)?$data:array();
    }
    private static function revision(\WP_REST_Request $req,array $record) {
        $expected=(string)(self::input($req)['expected_revision']??'');
        if($expected===''||!hash_equals(SmartSeoCombinations::revision($record),$expected))
            return new \WP_Error('revision_conflict','Reload this combination before changing it',array('status'=>409));
        return SmartSeoCombinations::revision($record);
    }
    public static function facets(\WP_REST_Request $req) {
        $source=self::source($req->get_params());
        if(is_wp_error($source)) return $source;
        return rest_ensure_response(array(
            'source'=>$source,'facets'=>SmartSeoCombinations::facets($source),
            'max_facets_per_candidate'=>SmartSeoCombinations::MAX_FACETS,
            'max_preview_batch'=>SmartSeoCombinations::MAX_BATCH,
            'unlimited_discovery'=>true,
        ));
    }
    public static function preview(\WP_REST_Request $req) {
        $d=self::input($req);
        $source=self::source($d);
        if(is_wp_error($source))return $source;
        $items=$d['candidates']??null;
        if(!is_array($items) || count($items)<1 || count($items)>SmartSeoCombinations::MAX_BATCH)
            return new \WP_Error('batch_invalid','1 to 40 combinations per preview batch',array('status'=>400));
        $out=array();
        foreach($items as $item) {
            if(!is_array($item))return new \WP_Error('candidate_invalid','Malformed candidate',array('status'=>400));
            $x=SmartSeoCombinations::inspect($source,
                (array)($item['selections']??array()),(string)($item['language']??'en'));
            $out[]=is_wp_error($x)?array('error'=>$x->get_error_code(),'message'=>$x->get_error_message()):$x;
        }
        return rest_ensure_response(array('source'=>$source['key'],
            'examined'=>count($out),'results'=>$out,'wrote_native_rules'=>false));
    }
    public static function listing(\WP_REST_Request $req) {
        return rest_ensure_response(array('items'=>SmartSeoCombinations::list(
            (int)($req->get_param('limit')??50))));
    }
    public static function save(\WP_REST_Request $req) {
        $d=self::input($req);$source=self::source($d);
        if(is_wp_error($source))return $source;
        $inspected=SmartSeoCombinations::inspect($source,
            (array)($d['selections']??array()),(string)($d['language']??'en'));
        if(is_wp_error($inspected))return $inspected;
        $id=$inspected['id'];$existing=SmartSeoCombinations::get($id);
        if(!is_wp_error($existing))return new \WP_Error('combination_exists',
            'This exact combination is already saved',array('status'=>409));
        $record=array('id'=>$id,'contract'=>SmartSeoCombinations::CONTRACT,
            'status'=>'draft','source'=>$source['key'],'provider'=>$source['provider'],
            'query_id'=>$source['query_id'],'post_type'=>$source['post_type'],
            'language'=>$inspected['language'],'selections'=>$inspected['selections'],
            'url'=>$inspected['url'],'result_evidence'=>$inspected,
            'locale_reviews'=>array(),'seo_probe'=>null,'approval'=>null,
            'policy_revision'=>SmartSeoPolicyRegistry::revision(),
            'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'));
        $saved=SmartSeoCombinations::put($id,null,$record);
        return is_wp_error($saved)?$saved:new \WP_REST_Response($saved,201);
    }
    public static function detail(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        return is_wp_error($record)?$record:rest_ensure_response(array(
            'record'=>$record,'revision'=>SmartSeoCombinations::revision($record)));
    }
    public static function localize(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        if(is_wp_error($record))return $record;
        $rev=self::revision($req,$record);if(is_wp_error($rev))return $rev;
        if(($record['status']??'')==='published')
            return new \WP_Error('rollback_first',
                'Rollback published SEO before editing localized content',array('status'=>409));
        $d=self::input($req);$lang=(string)($d['language']??'');
        if($lang!==$record['language'])
            return new \WP_Error('language_mismatch','Use a distinct candidate for each language',array('status'=>422));
        $title=trim(wp_strip_all_tags((string)($d['title']??'')));
        $description=trim(wp_strip_all_tags((string)($d['description']??'')));
        if(mb_strlen($title)<10||mb_strlen($title)>85||
            mb_strlen($description)<40||mb_strlen($description)>180)
            return new \WP_Error('editorial_copy_invalid',
                'Title 10–85 chars; description 40–180 chars',array('status'=>422));
        $record['locale_reviews'][$lang]=array('title'=>$title,
            'description'=>$description,'reviewed_by'=>get_current_user_id(),
            'reviewed_at'=>gmdate('c'),'source_language'=>'en',
            'manual_review_complete'=>true);
        $record['status']='localized';$record['approval']=null;
        $record['updated_at']=gmdate('c');
        return self::saveRevision((string)$req['id'],$rev,$record);
    }
    private static function saveRevision(string $id,string $rev,array $record) {
        $saved=SmartSeoCombinations::put($id,$rev,$record);
        return is_wp_error($saved)?$saved:rest_ensure_response($saved);
    }
    private static function inspectSaved(array $record) {
        $source=SmartSeoCombinations::source($record['provider'],$record['query_id']);
        if(is_wp_error($source))return $source;
        $requested=array_map(static fn($x)=>array(
            'filter_id'=>(int)$x['filter_id'],'term_id'=>(int)$x['term_id']),
            (array)$record['selections']);
        $fresh=SmartSeoCombinations::inspect($source,$requested,$record['language']);
        if(is_wp_error($fresh))return $fresh;
        if($fresh['url']!==$record['url'] || $fresh['id']!==$record['id'])
            return new \WP_Error('combination_drift','The page identity changed',array('status'=>409));
        return $fresh;
    }
    public static function verify(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        if(is_wp_error($record))return $record;
        $rev=self::revision($req,$record);if(is_wp_error($rev))return $rev;
        if(($record['status']??'')==='published')
            return new \WP_Error('use_postpublish_qa',
                'Use postpublish-qa for an active exact URL',array('status'=>409));
        $fresh=self::inspectSaved($record);if(is_wp_error($fresh))return $fresh;
        $http=0;$canonical='';$robots='';$pageTitle='';
        $url=$fresh['url'];
        $response=wp_safe_remote_get($url,array('timeout'=>15,'redirection'=>0,
            'limit_response_size'=>2500000,'headers'=>array('Accept'=>'text/html')));
        if(!is_wp_error($response)) {
            $http=(int)wp_remote_retrieve_response_code($response);
            $html=(string)wp_remote_retrieve_body($response);
            if(preg_match('/<link[^>]+rel=["\x27]canonical["\x27][^>]+href=["\x27]([^"\x27]+)/i',$html,$m))
                $canonical=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
            if(preg_match('/<meta[^>]+name=["\x27]robots["\x27][^>]+content=["\x27]([^"\x27]+)/i',$html,$m))
                $robots=$m[1];
            if(preg_match('~<title[^>]*>(.*?)</title>~is',$html,$m))
                $pageTitle=html_entity_decode(wp_strip_all_tags($m[1]),ENT_QUOTES,'UTF-8');
        }
        $translation=array('required'=>$record['language']!=='en',
            'source_terms_resolved'=>true,'missing_term_translation_ids'=>array());
        if($record['language']!=='en') {
            foreach($record['selections'] as $x) {
                $translated=apply_filters('wpml_object_id',(int)$x['term_id'],
                    (string)$x['taxonomy'],false,$record['language']);
                if(!$translated)$translation['missing_term_translation_ids'][]=$x['term_id'];
            }
            $translation['source_terms_resolved']=empty($translation['missing_term_translation_ids']);
        }
        $validResult=$fresh['matched_published_count']>=$fresh['min_result_count'];
        $passed=$http===200 && $validResult && empty($fresh['blockers'])
            && $translation['source_terms_resolved']
            && $record['policy_revision']===SmartSeoPolicyRegistry::revision();
        $record['result_evidence']=$fresh;
        $record['seo_probe']=array('http_status'=>$http,'canonical_before_publish'=>$canonical,
            'robots_before_publish'=>$robots,'title_before_publish'=>$pageTitle,
            'wpml_terms'=>$translation,'result_count'=>$fresh['matched_published_count'],
            'checked_at'=>gmdate('c'),'pass_prepublication'=>$passed);
        $record['status']=$passed?'verified':'needs_review';
        $record['approval']=null;$record['updated_at']=gmdate('c');
        return self::saveRevision((string)$req['id'],$rev,$record);
    }
    public static function approve(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        if(is_wp_error($record))return $record;
        $rev=self::revision($req,$record);if(is_wp_error($rev))return $rev;
        $review=$record['locale_reviews'][$record['language']]??null;
        if(($record['status']??'')!=='verified' || empty($review['manual_review_complete'])
            || empty($record['seo_probe']['pass_prepublication']))
            return new \WP_Error('approval_gates_incomplete',
                'Content, WPML and live URL evidence plus editorial review required',array('status'=>409));
        if($record['policy_revision']!==SmartSeoPolicyRegistry::revision())
            return new \WP_Error('policy_changed','Policy changed since discovery',array('status'=>409));
        $record['approval']=array('approved_by'=>get_current_user_id(),
            'approved_at'=>gmdate('c'),'approved_revision'=>$rev);
        $record['status']='approved';$record['updated_at']=gmdate('c');
        return self::saveRevision((string)$req['id'],$rev,$record);
    }
    public static function publish(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        if(is_wp_error($record))return $record;
        $rev=self::revision($req,$record);if(is_wp_error($rev))return $rev;
        if(($record['status']??'')!=='approved' || !is_array($record['approval']??null))
            return new \WP_Error('not_approved','Explicit prior approval required',array('status'=>409));
        $fresh=self::inspectSaved($record);
        if(is_wp_error($fresh)||!empty($fresh['blockers'])
            || $fresh['matched_published_count']<$fresh['min_result_count'])
            return new \WP_Error('stale_result_evidence',
                'Filtered results changed; verify again',array('status'=>409));
        if($record['policy_revision']!==SmartSeoPolicyRegistry::revision())
            return new \WP_Error('policy_changed','Policy must be revalidated',array('status'=>409));
        if(jet_smart_filters()->settings->is_seo_enabled)
            return new \WP_Error('multiple_seo_publishers','Disable native JSF SEO before using exact Rank Math pages',array('status'=>409));
        if(!function_exists('rank_math'))return new \WP_Error('rankmath_missing','Rank Math unavailable',array('status'=>409));
        $record['status']='published';$record['published_at']=gmdate('c');
        $record['updated_at']=gmdate('c');
        $saved=SmartSeoCombinations::put((string)$req['id'],$rev,$record);
        if(is_wp_error($saved))return $saved;
        $indexKey=SmartSeoExactPublisher::indexKey($record['url']);
        if(!add_option($indexKey,$record['id'],'','no')) {
            // fail-closed if another exact URL is already owned; revert record via CAS
            $record['status']='approved';unset($record['published_at']);
            SmartSeoCombinations::put((string)$req['id'],$saved['revision'],$record);
            return new \WP_Error('published_url_conflict','Exact URL already registered',array('status'=>409));
        }
        return rest_ensure_response(array('status'=>'published','url'=>$record['url'],
            'id'=>$record['id'],'publisher'=>'rank_math_exact_url',
            'next'=>'Run post-publication SEO QA; rollback if tags are inconsistent'));
    }
    /** Confirms actual Rank Math output on an approved exact URL; fail closed. */
    public static function postpublishQa(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        if(is_wp_error($record))return $record;
        $rev=self::revision($req,$record);if(is_wp_error($rev))return $rev;
        if(($record['status']??'')!=='published')
            return new \WP_Error('not_published','Publish before final SEO QA',array('status'=>409));
        $response=wp_safe_remote_get($record['url'],array('timeout'=>15,
            'redirection'=>0,'limit_response_size'=>2500000,
            'headers'=>array('Accept'=>'text/html')));
        $http=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
        $html=is_wp_error($response)?'':(string)wp_remote_retrieve_body($response);
        $pageTitle='';$canonical='';$descriptions=array();$robots=array();
        if(preg_match('~<title[^>]*>(.*?)</title>~is',$html,$m))
            $pageTitle=html_entity_decode(wp_strip_all_tags($m[1]),ENT_QUOTES,'UTF-8');
        if(preg_match_all('/<link\\s+[^>]*>/i',$html,$links))
            foreach($links[0] as $tag)
                if(preg_match('/\\brel=["\x27]canonical["\x27]/i',$tag)
                    &&preg_match('/\\bhref=["\x27]([^"\x27]+)/i',$tag,$m))
                    $canonical=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
        if(preg_match_all('/<meta\\s+[^>]*>/i',$html,$metas))
            foreach($metas[0] as $tag) {
                if(preg_match('/\\bname=["\x27]description["\x27]/i',$tag)
                    &&preg_match('/\\bcontent=["\x27]([^"\x27]+)/i',$tag,$m))
                    $descriptions[]=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
                if(preg_match('/\\bname=["\x27]robots["\x27]/i',$tag)
                    &&preg_match('/\\bcontent=["\x27]([^"\x27]+)/i',$tag,$m))
                    $robots[]=strtolower($m[1]);
            }
        $review=$record['locale_reviews'][$record['language']]??array();
        $pass=$http===200 && $pageTitle===(string)($review['title']??'')
            && $canonical===$record['url']
            && count($descriptions)===1
            && $descriptions[0]===(string)($review['description']??'')
            && count($robots)<=1 && !preg_match('/noindex/i',implode(',', $robots));
        $record['postpublish_qa']=array('passed'=>$pass,'http'=>$http,
            'title'=>$pageTitle,'canonical'=>$canonical,
            'description_tags'=>count($descriptions),'robots'=>$robots,
            'checked_at'=>gmdate('c'));
        if(!$pass) {
            // Fail closed: remove exact mapping immediately, do not leave wrong SEO live.
            delete_option(SmartSeoExactPublisher::indexKey($record['url']));
            $record['status']='qa_failed_rolled_back';
            $record['approval']=null;
        } else {
            $record['status']='published';
        }
        $record['updated_at']=gmdate('c');
        return self::saveRevision((string)$req['id'],$rev,$record);
    }

    public static function rollback(\WP_REST_Request $req) {
        $record=SmartSeoCombinations::get((string)$req['id']);
        if(is_wp_error($record))return $record;
        $rev=self::revision($req,$record);if(is_wp_error($rev))return $rev;
        // Remove URL mapping first so no public output survives a record-write failure.
        delete_option(SmartSeoExactPublisher::indexKey($record['url']));
        $record['status']='rolled_back';$record['approval']=null;
        $record['updated_at']=gmdate('c');
        return self::saveRevision((string)$req['id'],$rev,$record);
    }
}
