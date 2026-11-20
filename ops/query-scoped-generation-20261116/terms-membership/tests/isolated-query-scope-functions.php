<?php
function mad4b_render_query_scope_option(string $kind, string $name): string {
    if (!in_array($kind,['post_type','taxonomy','membership','membership_taxonomy'],true))return '';
    // Each published taxonomic membership is bound to *both* taxonomy and
    // public post type. A package_levels assignment to a tour cannot rotate
    // the destinations listing's source version merely because both
    // taxonomies happen to be registered to tours-and-activities.
    $pattern=$kind==='membership_taxonomy'
        ? '/^[A-Za-z0-9_-]{1,100}::[A-Za-z0-9_-]{1,100}$/D'
        : '/^[A-Za-z0-9_-]{1,100}$/D';
    if(!preg_match($pattern,$name))return '';
    $prefix=match($kind){
       'post_type'=>'p_','taxonomy'=>'t_','membership'=>'m_','membership_taxonomy'=>'mt_'
    };
    return 'mad4b_rq_source_v1_' . $prefix
        . substr(hash('sha256',$name),0,24);
}
function mad4b_render_query_scope_bump(string $kind,string $name): bool {
    $option=mad4b_render_query_scope_option($kind,$name);
    if($option==='')return false;
    // Nonrepeating opaque versions: concurrent writers can supersede each
    // other, but never resurrect the version of a previously cached result.
    $revision=substr(hash('sha256',wp_generate_uuid4().'|'.microtime(true)),0,32);
    update_option($option,$revision,false);
    return hash_equals($revision,(string)get_option($option,''));
}
function mad4b_render_query_scope_bump_post(int $post_id): bool {
    if(!$post_id || wp_is_post_revision($post_id))return false;
    $type=(string)get_post_type($post_id);
    $obj=$type!==''?get_post_type_object($type):null;
    if(!$obj || empty($obj->public)
        || preg_match('/(?:order|booking|checkout|payment|customer|reservation|invoice)/i',$type))return false;
    return mad4b_render_query_scope_bump('post_type',$type);
}
/**
 * Only relation and published-membership events should rotate this version.
 * Ordinary post meta edits continue to bump their own post-content version
 * for queries returning posts, but not a native terms query.
 */
function mad4b_render_query_scope_bump_membership_post(
    int $post_id, ?string $changed_taxonomy=null
): bool {
    if(!$post_id || wp_is_post_revision($post_id))return false;
    $type=(string)get_post_type($post_id);
    $obj=$type!==''?get_post_type_object($type):null;
    if(!$obj || empty($obj->public)
        || preg_match('/(?:order|booking|checkout|payment|customer|reservation|invoice)/i',$type))return false;

    $all=(array)get_object_taxonomies($type);
    $public=[];
    foreach($all as $taxonomy){
        if(!is_string($taxonomy))continue;
        $tax=get_taxonomy($taxonomy);
        if($tax && !empty($tax->public)
            && in_array($type,(array)($tax->object_type??[]),true))
            $public[$taxonomy]=true;
    }
    if($changed_taxonomy!==null){
        // set_object_terms affects exactly one taxonomy, never the complete
        // list of taxonomies registered to the same post type.
        if(!isset($public[$changed_taxonomy]))return false;
        return mad4b_render_query_scope_bump(
            'membership_taxonomy',$changed_taxonomy.'::'.$type
        );
    }
    if(!$public)return false;
    // A publish/unpublish/delete can affect any public taxonomy bound to
    // this post type. Rotate all *registered* taxonomy/type pairs (not
    // unrelated post types or taxonomies). This intentionally avoids using
    // WPML-filtered term lookups that could miss assignments in other
    // languages during a status change.
    $any=false;
    foreach(array_keys($public) as $taxonomy){
        $any=mad4b_render_query_scope_bump(
            'membership_taxonomy',$taxonomy.'::'.$type
        )||$any;
    }
    return $any;
}
function mad4b_render_query_scope_bump_term(string $taxonomy): bool {
    $obj=get_taxonomy($taxonomy);
    if(!$obj || empty($obj->public))return false;
    return mad4b_render_query_scope_bump('taxonomy',$taxonomy);
}
function mad4b_render_query_scope_spec($query): array {
    if(!is_object($query) || !in_array((string)($query->query_type??''),['posts','terms'],true))return [];
    $raw=$query->query??null;
    $final=$query->final_query??null;
    if(!is_array($raw))return [];
    $type=(string)$query->query_type;
    $scopes=[];
    if($type==='posts'){
        $types=$raw['post_type']??($final['post_type']??[]);
        if(is_string($types))$types=[$types];
        if(!is_array($types) || !$types || count($types)>18)return [];
        foreach($types as $pt){
            if(!is_string($pt) || $pt==='any' || !get_post_type_object($pt)
                || empty(get_post_type_object($pt)->public))return [];
            $scopes[]=['post_type',$pt];
        }
        // Term-meta based filters may change without a post edit. Include
        // the public taxonomies explicitly selected by JetEngine Query Builder.
        $walk=static function($clauses)use(&$walk,&$scopes):bool{
            if(!is_array($clauses))return false;
            foreach($clauses as $k=>$clause){
                if(!is_array($clause))continue;
                if(isset($clause['taxonomy'])){
                    $tax=(string)$clause['taxonomy'];
                    $obj=get_taxonomy($tax);
                    if($tax==='' || !$obj || empty($obj->public))return false;
                    $scopes[]=['taxonomy',$tax];
                }elseif(is_int($k) && !$walk($clause))return false;
            }
            return true;
        };
        if(isset($raw['tax_query']) && !$walk($raw['tax_query']))return [];
    }else{
        $taxes=$raw['taxonomy']??($final['taxonomy']??[]);
        if(is_string($taxes))$taxes=[$taxes];
        if(!is_array($taxes) || !$taxes || count($taxes)>16)return [];
        foreach($taxes as $tax){
            if(!is_string($tax) || $tax==='')return [];
            $obj=get_taxonomy($tax);
            if(!$obj || empty($obj->public))return [];
            $scopes[]=['taxonomy',$tax];
            // hide_empty and object counts depend on source posts. A post
            // save cannot silently keep an old public terms-query result.
            foreach((array)($obj->object_type??[]) as $pt){
                if(is_string($pt) && get_post_type_object($pt)
                    && !empty(get_post_type_object($pt)->public)
                    && !preg_match('/(?:order|booking|checkout|payment|customer|reservation|invoice)/i',$pt))
                    $scopes[]=['membership_taxonomy',$tax.'::'.$pt];
            }
        }
    }
    $out=[];foreach($scopes as [$kind,$name]){
        $option=mad4b_render_query_scope_option($kind,$name);
        if($option==='')return [];
        $out[$option]=true;
    }
    if(!$out || count($out)>45)return [];
    ksort($out,SORT_STRING);
    return array_keys($out);
}
function mad4b_render_query_effective_generation($query): string {
    $original=(string)get_option('mad4b_render_query_data_generation','initial');
    if((string)get_option('mad4b_render_query_scoped_generation_v1','off')!=='on')return $original;
    $spec=mad4b_render_query_scope_spec($query);
    if(!$spec)return $original; // fail closed; unknown still globally invalidated
    $versions=[];
    foreach($spec as $option)$versions[$option]=(string)get_option($option,'baseline');
    $safety=(string)get_option('mad4b_render_query_global_safety_generation_v1','initial');
    $source=is_array($query->query??null)?$query->query:[];
    return 'scoped:v1:' . hash('sha256',wp_json_encode([
        'query_id'=>(int)($query->id??0),
        'type'=>(string)($query->query_type??''),
        'source_sha256'=>hash('sha256',wp_json_encode($source)),
        'scope_versions'=>$versions,
        'safety'=>$safety
    ]));
}
