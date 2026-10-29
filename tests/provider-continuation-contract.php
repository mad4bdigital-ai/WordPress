<?php
define('ABSPATH','/tmp');
$GLOBALS['admin'] = false; $GLOBALS['registry'] = array(); $GLOBALS['calls'] = 0;
$GLOBALS['fire_ready'] = false; $GLOBALS['serp_ready'] = false;
class WP_Error { private $code; function __construct($c){$this->code=$c;} function get_error_code(){return $this->code;} }
class MAD4B_SCP_Policy { static function can_read(){return true;} }
class MAD4B_Research_Firecrawl_Bridge { static function permitted(){return $GLOBALS['admin'];} }
class MAD4B_Firecrawl_Provider_Connection {
    static function route($args) { $serp=$args['intent']==='google_serp';
        return array('execution_ready'=>$serp?$GLOBALS['serp_ready']:$GLOBALS['fire_ready'],'provider_state'=>$serp?'not_configured':'unavailable'); }
}
class MAD4B_Firecrawl_Research_Cycles {
    static function observe($args) { $GLOBALS['calls']++;
        if(empty($args['demo']))return new WP_Error('competitor_source_not_enrolled');
        return array('source_url'=>'https://example.com','extracted_sha256'=>str_repeat('a',64),'evidence_state'=>'preliminary_unreviewed');
    }
}
class MAD4B_SCP_Connector_Resilience {
    static function safe_read($name,$callback,$options) {
        assert($options['max_attempts']===2);
        $r=$callback();return is_array($r)?array('ok'=>true,'data'=>$r,'attempts'=>1):array('ok'=>false,'error_code'=>$r->get_error_code(),'attempts'=>1);
    }
}
function add_action($name,$cb,$priority){$GLOBALS['hook']=$cb;}
function wp_register_ability($name,$args){$GLOBALS['registry'][$name]=$args;}
function wp_has_ability($name){return isset($GLOBALS['registry'][$name]);}
function is_wp_error($r){return $r instanceof WP_Error;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-research-provider-continuation.php';
call_user_func($GLOBALS['hook']);
$c='MAD4B_Research_Provider_Continuation';
assert(isset($GLOBALS['registry'][$c::ABILITY]));
assert(is_wp_error($c::evaluate(array('task'=>'seo_audit'))));
$GLOBALS['admin']=true;
assert(is_wp_error($c::evaluate(array('task'=>'remove_users'))));
assert(is_wp_error($c::evaluate(array('task'=>'content_brief','collect_preview'=>true))));
$p=$c::evaluate(array('task'=>'seo_audit'));
assert($p['state']==='partial' && $p['continuation_allowed']===true);
assert(in_array('certified_serp_evidence',$p['blocking_evidence_for_finalization'],true));
assert(!$p['business_publication_authorized'] && !$p['wordpress_mutation_performed']);
assert($GLOBALS['calls']===0);
$v=$c::evaluate(array('task'=>'content_brief','collect_preview'=>true,'url'=>'https://example.com','demo'=>true));
assert(!$v['source_observation_collected'] && $GLOBALS['calls']===0 && $v['observation_attempts']===0);
$GLOBALS['fire_ready']=true;
$v=$c::evaluate(array('task'=>'content_brief','collect_preview'=>true,'url'=>'https://example.com','demo'=>true));
assert($v['source_observation_collected'] && $GLOBALS['calls']===1);
assert(!$v['serp_rank_verified'] && !$v['business_publication_authorized']);
assert($v['state']==='evidence_collected_pending_governance');
$v=$c::evaluate(array('task'=>'price_intelligence','collect_preview'=>true,'url'=>'https://notenrolled.example.com'));
assert($v['state']==='partial' && !$v['price_verified']);
assert(in_array('fresh_commercial_price_validation',$v['blocking_evidence_for_finalization'],true));
$GLOBALS['serp_ready']=true;
$v=$c::evaluate(array('task'=>'seo_audit'));
assert($v['state']==='partial' && !$v['serp_rank_verified']);
$v=$c::evaluate(array('task'=>'publication_readiness','collect_preview'=>true,'url'=>'https://example.com','demo'=>true));
assert(!$v['business_publication_authorized']);
assert(in_array('independent_editorial_and_write_approval',$v['blocking_evidence_for_finalization'],true));
echo "provider-continuation-contract: PASS\n";
