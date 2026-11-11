<?php
/**
 * Exact Tours.dev read-only browser-adjacent WP acceptance probe.
 * Accepts no request input and has no WordPress or filesystem mutations.
 * Execution: docker exec -i -u www-data PHP_CONTAINER php < this_file
 */
chdir('/var/www/html');
$_GET=['mad4b_date_mode'=>'fixed','mad4b_date_from'=>'2026-12-15'];
require 'wp-load.php';
$site=rtrim((string)home_url(),'/');
if($site!=='https://tours.dev.mad4b.com'){
    echo json_encode(['state'=>'BLOCKED_SITE_IDENTITY','site'=>$site])."\n";
    exit(3);
}
if(get_option('mad4b_unified_date_enabled_v1','off')!=='on'){
    echo json_encode(['state'=>'BLOCKED_DATE_MODULE_DISABLED'])."\n";
    exit(3);
}
$query=(object)['query_id'=>'tours_query_archive'];
$base=['post_type'=>'tours-and-activities','post_status'=>'publish'];
$read=static function(array $overrides)use($base,$query):array{
    $args=apply_filters('jet-engine/query-builder/types/posts-query/args',
        array_merge($base,$overrides),$query);
    return array_values(array_map('intval',(array)($args['post__in']??[])));
};
$normal=$read([]);
$none=$read(['post__in'=>[0]]);
$mixed=$read(['post__in'=>[0,60629]]);
$nonmatch=$read(['post__in'=>[999999999]]);
$expected=['no_prior_selection_has_matching_tours'=>count($normal)>0,
    'prior_deny_all_remains_deny_all'=>$none===[0],
    'mixed_deny_all_remains_deny_all'=>$mixed===[0],
    'nonmatching_positive_prior_selection_denied'=>$nonmatch===[0]];
$passed=!in_array(false,$expected,true);
echo json_encode([
    'contract'=>'mad4b.date-v035.live-readonly-post-in-acceptance.v1',
    'state'=>$passed?'PASS':'FAIL',
    'source_site'=>$site,
    'date_filter_enabled'=>true,
    'input_date'=>'2026-12-15',
    'test_results'=>$expected,
    'normal_matched_count'=>count($normal),
    'prior_zero'=>$none,
    'prior_mixed_zero'=>$mixed,
    'not_in_prior_positive'=>$nonmatch,
    'code_mutation_performed'=>false,
    'database_mutation_intended'=>false,
    'publication_authorized_by_this_probe'=>false
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
exit($passed?0:2);
