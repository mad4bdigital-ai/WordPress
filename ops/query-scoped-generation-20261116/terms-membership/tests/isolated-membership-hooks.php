<?php
add_action('transition_post_status',static function($new,$old,$post){
    if($new===$old || ($new!=='publish' && $old!=='publish')
       || !is_object($post) || empty($post->ID))return;
    $scoped=mad4b_render_query_scope_bump_membership_post((int)$post->ID);
    if($scoped)mad4b_render_query_invalidate([
        'kind'=>'public_membership_status','post_type'=>(string)get_post_type($post->ID)
    ],true);
},40,3);
add_action('before_delete_post',static function($post_id){
    if(get_post_status($post_id)!=='publish')return;
    $scoped=mad4b_render_query_scope_bump_membership_post((int)$post_id);
    if($scoped)mad4b_render_query_invalidate([
        'kind'=>'published_membership_delete','post_type'=>(string)get_post_type($post_id)
    ],true);
},41,1);
add_action( 'set_object_terms', static function ( $id, $terms, $tt_ids, $taxonomy, $append=false, $old_tt_ids=[] ) {
    $tax = get_taxonomy( $taxonomy );
    if ( !$tax || empty( $tax->public ) || get_post_status($id)!=='publish' )return;
    // WordPress can fire set_object_terms even for a no-op replay; do not
    // rotate the certificate without a changed relation membership set.
    $before=array_values(array_unique(array_map('absint',(array)$old_tt_ids)));
    $after=array_values(array_unique(array_map('absint',(array)$tt_ids)));
    // When append=true WordPress provides only the supplied new term IDs,
    // not necessarily the whole existing set; merge before comparing.
    if($append)$after=array_values(array_unique(array_merge($before,$after)));
    sort($before,SORT_NUMERIC);sort($after,SORT_NUMERIC);
    if($before===$after)return;
    $scoped=mad4b_render_query_scope_bump_membership_post((int)$id,(string)$taxonomy);
    if($scoped)mad4b_render_query_invalidate([
        'kind'=>'public_term_membership','taxonomy'=>(string)$taxonomy,
        'post_type'=>(string)get_post_type($id)
    ],true);
}, 40, 6 );
