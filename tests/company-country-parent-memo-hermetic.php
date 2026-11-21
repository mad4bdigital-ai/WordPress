<?php
/**
 * Hermetic, no-network, no-database test of the country operating-scope
 * request-local taxonomy parent memo. Never grants sale/publish authority.
 */
declare(strict_types=1);
define('ABSPATH','/');
define('ARRAY_A','ARRAY_A');
$_GET=[];$_POST=[];$_COOKIE=[];
$_SERVER=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>'company.invalid','HTTPS'=>'on','REQUEST_URI'=>'/tours-and-activities/sample-tour/'];
$GLOBALS['qa_admin']=false;
$GLOBALS['qa_map']=[100=>665,665=>0,400=>401,401=>400,900=>0];
$GLOBALS['qa_en']=[200=>100,669=>665];
$GLOBALS['qa_types']=[665=>'country',900=>'country'];
function is_admin():bool{return (bool)$GLOBALS['qa_admin'];}
function is_user_logged_in():bool{return (bool)($GLOBALS['qa_logged_in']??false);}
function taxonomy_exists($tax):bool{return $tax==='destinations';}
function add_filter(...$args):void{}
function add_action(...$args):void{}
function apply_filters($hook,$id,...$args) {
    if($hook==='wpml_object_id')return $GLOBALS['qa_en'][(int)$id]??(int)$id;
    return $id;
}
function get_term_meta($id,$key,$single) {
    if($key==='destination_type')return $GLOBALS['qa_types'][(int)$id]??'city';
    return '';
}
class FakeCountryTermsDB {
    public $term_taxonomy='wp_term_taxonomy';
    public $queries=0;
    public $seen_ids=[];
    public function prepare($sql,...$params) {
        $escaped=str_replace('%s',"'%s'",$sql);
        return vsprintf($escaped,$params);
    }
    public function get_row($sql,$format) {
        if(!preg_match('/\bterm_id\s*=\s*(\d+)/i',$sql,$m))throw new RuntimeException('unbounded country taxonomy SQL');
        if(!preg_match("/taxonomy='destinations'/",$sql))throw new RuntimeException('taxonomy not constrained');
        $id=(int)$m[1];
        $this->queries++;$this->seen_ids[$id]=($this->seen_ids[$id]??0)+1;
        if(!array_key_exists($id,$GLOBALS['qa_map']))return null;
        return ['term_id'=>$id,'parent'=>$GLOBALS['qa_map'][$id]];
    }
}
$GLOBALS['wpdb']=new FakeCountryTermsDB();
require dirname(__DIR__).'/wp-content/mu-plugins/020-mad4b-company-country-operating-scope-v1.php';
function qa_assert($condition,$name) {
    if(!$condition){fwrite(STDERR,"FAIL: ".$name."\n");exit(1);}
}
$cls='MAD4B_Company_Country_Operating_Scope_V1';
for($i=0;$i<100;$i++)qa_assert($cls::canonical_country(100)===665,'repeat 100 resolves exact canonical EN country');
qa_assert($GLOBALS['wpdb']->queries===2,'100 repeats only read two distinct taxonomy rows');
qa_assert($cls::canonical_country(200)===665,'localized WPML term still resolves EN 100');
qa_assert($cls::canonical_country(669)===665,'localized country maps canonical EN 665');
qa_assert($GLOBALS['wpdb']->queries===2,'WPML translation checks parent cache only');
// A locale hint created by WPML *after* HTTP parsing is not a visitor cookie.
$_COOKIE=['wp-wpml_current_language'=>'en'];
$before=$GLOBALS['wpdb']->queries;
for($i=0;$i<30;$i++)qa_assert($cls::canonical_country(100)===665,'synthetic WPML language does not bypass row memo');
qa_assert($GLOBALS['wpdb']->queries===$before,'synthetic WPML-only cookie retains request memo');
$_COOKIE=['wp-wpml_current_language'=>'invalid value'];
$GLOBALS['qa_map'][100]=900;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===900,'malformed synthetic locale sees fresh country parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'malformed locale bypasses memo');
$_COOKIE=['unexpected_internal_cookie'=>'1'];
$GLOBALS['qa_map'][100]=665;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===665,'unknown synthetic cookie sees fresh parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'unknown synthetic cookie bypasses memo');
$_COOKIE=['wp-wpml_current_language'=>'es'];
$_SERVER['HTTP_COOKIE']='wp-wpml_current_language=es';
$GLOBALS['qa_map'][100]=900;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===900,'real inbound WPML cookie sees fresh parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'real Cookie header disables memo');
unset($_SERVER['HTTP_COOKIE']);
$_COOKIE=[];
$GLOBALS['qa_map'][100]=665;
$public_memo_base_queries=$GLOBALS['wpdb']->queries;
$GLOBALS['qa_types'][665]='city';
qa_assert($cls::canonical_country(100)===0,'live country term meta denial is not memoized');
$GLOBALS['qa_types'][665]='country';
qa_assert($cls::canonical_country(100)===665,'restored country meta is evaluated live');
qa_assert($GLOBALS['wpdb']->queries===$public_memo_base_queries,'meta publication changes do not force stale parent checks');
qa_assert($cls::canonical_country(300)===0 && $cls::canonical_country(300)===0,'missing row fails closed');
qa_assert($GLOBALS['wpdb']->queries===$public_memo_base_queries+1,'missing row is cached only for anonymous GET');
qa_assert($cls::canonical_country(400)===0 && $cls::canonical_country(400)===0,'cyclic ancestry fails closed');
qa_assert($GLOBALS['wpdb']->queries===$public_memo_base_queries+3,'cycle queries each distinct term only once');
$GLOBALS['qa_en'][200]=300;
qa_assert($cls::canonical_country(200)===0,'WPML canonical source translation not stale');
$GLOBALS['qa_en'][200]=100;
$GLOBALS['qa_admin']=true;
$GLOBALS['qa_map'][100]=900;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===900,'wp-admin sees changed country parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'wp-admin bypasses anonymous cache');
$GLOBALS['qa_admin']=false;
$_SERVER['REQUEST_METHOD']='POST';
$GLOBALS['qa_map'][100]=665;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===665,'POST sees latest country parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'POST never consumes anonymous cache');
$_SERVER['REQUEST_METHOD']='GET';
$_COOKIE=['woocommerce_cart_hash'=>'private'];
$GLOBALS['qa_map'][100]=900;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===900,'cookie-bearing visitor reads latest parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'cookie session always bypasses cache');
$_COOKIE=[];
$_SERVER['HTTP_AUTHORIZATION']='Bearer fake';
$GLOBALS['qa_map'][100]=665;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===665,'authorization-bearing request reads latest parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'authenticated request bypasses cache');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_GET=['preview'=>'1'];
$GLOBALS['qa_map'][100]=900;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===900,'query-bearing GET sees fresh country parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'GET with query bypasses cache');
$_GET=[];
$GLOBALS['qa_logged_in']=true;
$GLOBALS['qa_map'][100]=665;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===665,'logged-in actor sees fresh country parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'authenticated user bypasses cache');
$GLOBALS['qa_logged_in']=false;
$_SERVER['HTTP_X_WP_NONCE']='qa-test-nonce';
$GLOBALS['qa_map'][100]=900;
$before=$GLOBALS['wpdb']->queries;
qa_assert($cls::canonical_country(100)===900,'nonce-bearing request sees fresh country parent');
qa_assert($GLOBALS['wpdb']->queries>$before,'nonce requests bypass cache');
unset($_SERVER['HTTP_X_WP_NONCE']);
$GLOBALS['qa_map'][100]=665;
qa_assert($cls::canonical_set([100,200,665,0,'nope'])===[665],'canonical set preserves exact country deduplication');
qa_assert($cls::canonical_country(0)===0,'bad term fails closed');
echo "COUNTRY_PARENT_REQUEST_MEMO: PASS 34 checks\n";
