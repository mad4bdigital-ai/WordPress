<?php
/** Hermetic, no WordPress bootstrap. Run: php tests/destination-term-alerts-runtime.php [scanner.php] */
declare(strict_types=1);
define('ABSPATH', '/fixture/');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['fixture_html']='<html><body>'.str_repeat('x',400).'<a href="https://example.test/destination/cairo/">Cairo</a></body></html>';
$GLOBALS['fixture_http_code']=200;
$GLOBALS['fixture_remote_requests']=0;
$GLOBALS['fixture_base']=sys_get_temp_dir().'/mad4b-term-alert-test-'.getmypid();
@mkdir($GLOBALS['fixture_base'].'/2026/01', 0700, true);
file_put_contents($GLOBALS['fixture_base'].'/2026/01/existing.jpg', 'fixture');
class MAD4B_SCP_Site_Profile { public static function site_uuid(){return 'fixture-site-uuid';} }
class WPDB_Destination_Fixture {
    public $termmeta='wp_termmeta';
    public $term_taxonomy='wp_term_taxonomy';
    public $last_error='';
    public $rows=array();
    public function prepare($sql,...$args) {
        foreach($args as $v) {
            $quoted=is_int($v)?(string)$v:"'".str_replace("'","''",(string)$v)."'";
            $sql=preg_replace('/%[sd]/',$quoted,$sql,1);
        }
        return $sql;
    }
    public function get_results($query,$kind){return $this->rows;}
    public function get_col($query) {
        if(!preg_match("/term_id=([0-9]+) AND meta_key='([^']+)'/",$query,$match))return null;
        $vals=array();
        foreach($this->rows as $r)if((int)$r['term_id']===(int)$match[1]&&$r['meta_key']===$match[2])$vals[]=$r['meta_value'];
        return $vals;
    }
    public function get_var($query){return 1;}
}
$GLOBALS['wpdb']=new WPDB_Destination_Fixture();
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function home_url($path=''){return 'https://example.test'.$path;}
function taxonomy_exists($tax){return $tax==='destinations';}
function apply_filters($hook,$value,...$rest){return $value;}
function get_term($id,$taxonomy){return (object)['term_id'=>$id,'taxonomy'=>$taxonomy,'name'=>'Fixture'];}
function is_wp_error($value){return false;}
function get_current_blog_id(){return 1;}
function wp_get_upload_dir(){return ['basedir'=>$GLOBALS['fixture_base']];}
function wp_json_encode($value,$options=0){return json_encode($value,$options);}
function get_term_link($id,$taxonomy){return 'https://example.test/destination/'.$id.'/';}
function wp_safe_remote_get($url,$args) {
    $GLOBALS['fixture_remote_requests']++;
    return ['code'=>$GLOBALS['fixture_http_code'],'body'=>$GLOBALS['fixture_html']];
}
function wp_remote_retrieve_response_code($res){return $res['code'];}
function wp_remote_retrieve_body($res){return $res['body'];}
function add_filter($hook,$cb,$priority=10,$accepted=1){return true;}
function check($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}}
$plugin=$argv[1]??dirname(__DIR__).'/wp-content/mu-plugins/mad4b-destination-term-alerts.php';
check(is_file($plugin),'scanner file exists');
require $plugin;
$w=$GLOBALS['wpdb'];
$external='https://staging.egypttourgates.com/wp-content/uploads/2026/01/existing.jpg';
$missing='https://staging.egypttourgates.com/wp-content/uploads/2026/01/missing.jpg';
$w->rows=[
 ['meta_id'=>1,'term_id'=>11,'meta_key'=>'internal_links','meta_value'=>'<a href="https://another-company.test/tours/">Explore</a>'],
 ['meta_id'=>2,'term_id'=>12,'meta_key'=>'image_gellery','meta_value'=>$external.','.$missing],
 ['meta_id'=>3,'term_id'=>13,'meta_key'=>'video_url','meta_value'=>'https://www.youtube.com/watch?v=123'],
 ['meta_id'=>4,'term_id'=>14,'meta_key'=>'internal_links','meta_value'=>'<a href="/destination/egypt/cairo/">Cairo</a>'],
 ['meta_id'=>5,'term_id'=>15,'meta_key'=>'source_reference','meta_value'=>'https://reference.example.com/egypt'],
];
$r=MAD4B_Destination_Term_Alerts_V1::scan();
check($r['complete']===true,'complete bounded taxonomy scan');
check($r['coverage']['scanned_rows']===5,'counts every raw term meta row');
$codes=[];foreach($r['issues'] as $issue)$codes[$issue['object_id'].'|'.$issue['code']]=$issue;
check(count($codes)===3,'one external link, one foreign gallery and one missing image alert');
check(($codes['11|term_external_link_attribution']['severity']??'')==='high','neutral-label offsite internal link is high severity');
check(($codes['12|term_foreign_media_origin']['source_evidence']['reference_count']??0)===2,'CSV gallery splits into two real URL occurrences');
check(($codes['12|term_media_local_missing']['source_evidence']['reference_count']??0)===1,'only genuinely missing local file alerts');
check(!isset($codes['13|term_external_link_attribution']),'editorial YouTube reference exempt');
check(!isset($codes['14|term_external_link_attribution']),'root relative first-party anchor accepted');
$old=$codes['11|term_external_link_attribution'];
check(MAD4B_Destination_Term_Alerts_V1::can_resolve($old)===false,'present issue must not close');
$w->rows[0]['meta_value']='<a href="/destination/egypt/cairo/">Cairo</a>';
MAD4B_Destination_Term_Alerts_V1::scan();
check(MAD4B_Destination_Term_Alerts_V1::can_resolve($old)===true,'raw corrected and public HTML readback resolves');
$GLOBALS['fixture_html']='<html><body>'.str_repeat('x',400).'<a href="https://another-company.test/tours/">Explore</a></body></html>';
check(MAD4B_Destination_Term_Alerts_V1::can_resolve($old)===false,'stale public HTML prevents close');
$GLOBALS['fixture_html']='<html><body>'.str_repeat('x',500);
check(MAD4B_Destination_Term_Alerts_V1::can_resolve($old)===false,'truncated HTML cannot certify missing link');
$GLOBALS['fixture_http_code']=503;
check(MAD4B_Destination_Term_Alerts_V1::can_resolve($old)===false,'HTTP failure prevents close');
$w->last_error='incomplete';
$r=MAD4B_Destination_Term_Alerts_V1::scan();
check($r['complete']===false,'SQL error fails closed');
check(MAD4B_Destination_Term_Alerts_V1::can_resolve($old)===false,'incomplete scan cannot resolve');
@unlink($GLOBALS['fixture_base'].'/2026/01/existing.jpg');
@rmdir($GLOBALS['fixture_base'].'/2026/01');
@rmdir($GLOBALS['fixture_base'].'/2026');
@rmdir($GLOBALS['fixture_base']);
echo "PASS: DESTINATION_TERM_ALERTS\n";

