<?php
/**
 * Hermetic source-only checks for site-local station IDs, editorial room drafts,
 * and the fail-closed no-cost / no-booking contract.
 */
declare(strict_types=1);
define('ABSPATH','/hermetic/source-review/');
define('OBJECT','OBJECT');
define('ARRAY_A','ARRAY_A');
class WP_Post {
    public int $ID;
    public string $post_type='stations';
    public string $post_status='publish';
    public string $post_title='';
    public function __construct(int $id,string $title) {
        $this->ID=$id;$this->post_title=$title;
    }
}
$GLOBALS['mad4b_test_station_map']=[
    'ramses-station'=>new WP_Post(91234,'Ramses Station'),
    'upper-egypt-railway-station-bashteel'=>new WP_Post(98888,'Upper Egypt Railway Station (Bashteel)')
];
$GLOBALS['mad4b_test_station_terms']=[91234=>['cairo'],98888=>['giza-city']];
function get_page_by_path($slug,$output,$type){
    if($type!=='stations' || $output!=='OBJECT')return null;
    return $GLOBALS['mad4b_test_station_map'][$slug]??null;
}
function wp_get_object_terms($id,$taxonomy,$args){
    if($taxonomy!=='destinations')return [];
    return $GLOBALS['mad4b_test_station_terms'][(int)$id]??[];
}
function is_wp_error($value):bool{return false;}
class MAD4B_Fixture_WPDB {
    public string $prefix='wp_';
    public string $posts='wp_posts';
    public array $rows=[];
    public function prepare($sql,...$args){return vsprintf(str_replace(['%d','%s'],['%d','%s'],$sql),$args);}
    public function get_results($query,$mode){return $this->rows;}
}
$GLOBALS['wpdb']=new MAD4B_Fixture_WPDB();
require __DIR__.'/../../../wp-content/mu-plugins/mad4b-package-stage-sourcing-v1.php';
$cls='MAD4B_Package_Stage_Sourcing_V1';
function run_private(string $class,string $method,array $args=[]){
    $reflection=new ReflectionMethod($class,$method);
    return $reflection->invokeArgs(null,$args);
}
$checks=0;
$assert=static function(bool $ok,string $msg) use (&$checks):void {
    $checks++;
    if(!$ok){fwrite(STDERR,'FAILED: '.$msg.PHP_EOL);exit(1);}
};
$assert(run_private($cls,'ramsesStation')===91234,'Ramses resolved from native slug/title, not fixed post ID');
$assert(run_private($cls,'bashtilStation')===98888,'Bashtil resolved from native slug/title and destination');
$assert(in_array('cairo',run_private($cls,'stationCities',[98888]),true),
    'Bashtil verified exceptional Greater Cairo terminal');
$GLOBALS['mad4b_test_station_terms'][98888]=['giza'];
$assert(run_private($cls,'bashtilStation')===0,
    'unverified Giza-city term cannot silently authorize Bashtil');
$GLOBALS['mad4b_test_station_terms'][98888]=['giza-city'];
$GLOBALS['mad4b_test_station_map']['ramses-station']->post_title='Other station';
$assert(run_private($cls,'ramsesStation')===0,'wrong title rejected even with matching slug');
$GLOBALS['mad4b_test_station_map']['ramses-station']->post_title='Ramses Station';
$GLOBALS['wpdb']->rows=[
    ['post_status'=>'draft','count_units'=>3],
    ['post_status'=>'publish','count_units'=>2],
    ['post_status'=>'private','count_units'=>1]
];
$unitStats=run_private($cls,'relationStatusCounts',[36479]);
$assert($unitStats===['publish'=>2,'draft'=>3,'other'=>1],
    'draft units are not incorrectly counted as published inventory');
$code=file_get_contents(__DIR__.'/../../../wp-content/mu-plugins/mad4b-package-stage-sourcing-v1.php');
$assert(is_string($code) && !str_contains($code,'54231'),
    'no cross-company hard-coded native station ID');
$assert(str_contains($code,'supplier_cabin_rate_verified') &&
    str_contains($code,"'schedule_verified'=>false") &&
    str_contains($code,"'cost_total'=>null") &&
    str_contains($code,"'writes'=>false"),
    'sleeper discovery cannot issue tariff, verified timetable or booking approval');
$js=file_get_contents(__DIR__.'/../../../wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js');
$assert(is_string($js) && str_contains($js,'draft_native_units_discovered') &&
    str_contains($js,'native_draft_unit_count') &&
    str_contains($js,'native_terminal_needs_schedule_revalidation'),
    'admin UI shows draft-only Units and ticket terminal warning');
$assert(!str_contains($js,'the saved train stop data uses Ramses'),
    'warning must not claim unchecked station identity');
echo 'PASS: PACKAGE_SOURCE_SITE_BOUND_STATION_AND_DRAFT_UNITS '.$checks.' checks; no writes'.PHP_EOL;
