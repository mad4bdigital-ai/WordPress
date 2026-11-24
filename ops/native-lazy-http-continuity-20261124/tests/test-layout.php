<?php
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('WP_CONTENT_DIR',sys_get_temp_dir().'/mad4b-layout-test-'.bin2hex(random_bytes(5)));
mkdir(WP_CONTENT_DIR.'/uploads/elementor/css',0700,true);
$GLOBALS['opts']=['mad4b_css_ai_epoch_v1'=>154,'elementor_active_kit'=>10,'astra-settings'=>['test'=>true]];
$GLOBALS['mock_docs']=array_fill_keys(range(10,16),json_encode([['id'=>'abc','elements'=>[],'settings'=>['text'=>str_repeat('layout',70)]]]));
$GLOBALS['host']='tours.dev.mad4b.com';
function add_action(...$args){}
function wp_parse_url($u,$c){return parse_url($u,$c);}
function home_url($p){return 'https://'.$GLOBALS['host'].$p;}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
define('DAY_IN_SECONDS',86400);
function apply_filters($key,$value){return $key==='wpml_current_language'?'en':$value;}
function mad4b_render_query_generation_for_id($id){return $GLOBALS['test_generation']??'A';}
function mad4b_secondary_grid_lazy_ajax_parity_proven($q,$l,$d){return $GLOBALS['test_native_gate']??false;}
function get_stylesheet(){return 'astra-child';}
function get_template(){return 'astra';}
function get_post_meta($id,$key,$single){return $key==='_elementor_page_settings'?['viewport_mobile'=>767]:($GLOBALS['mock_docs'][$id]??'');}
function maybe_serialize($v){return serialize($v);}
function wp_get_custom_css(){return 'body {}';}
function get_theme_mods(){return ['test'=>1];}
function absint($v){return abs((int)$v);}
require getenv('MAD4B_LAYOUT_SOURCE') ?: dirname(__DIR__,3).'/wp-content/mu-plugins/436-mad4b-scoped-layout-epoch-renewal-v1.php';
class_alias('MAD4B_Native_Layout_Epoch_Continuity_V1','Layout');
function chk($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
try{
 $docs=[];foreach(range(10,16) as $id)$docs[$id]=hash('sha256',$GLOBALS['mock_docs'][$id]);
 $files=[];foreach(range(10,23) as $id){$path='uploads/elementor/css/post-'.$id.'.css';file_put_contents(WP_CONTENT_DIR.'/'.$path,str_repeat('.x{height:4px}',20));$files[$path]=hash_file('sha256',WP_CONTENT_DIR.'/'.$path);}
 $entry=['listing_id'=>11,'locale'=>'en','device'=>'mobile','status'=>'pass','listing_source_sha256'=>$docs[11],'listing_css_sha256'=>$files['uploads/elementor/css/post-11.css'],'height_px'=>464,'sampled_widths'=>[320,390,767],'ajax_parity_by_market'=>['de-usd'=>['document_id'=>12,'document_sha256'=>$docs[12],'query_generation'=>'A','audited_at'=>100]]];
 $r=['contract'=>'mad4b.native-listing.layout-proof.v1','site_host'=>$GLOBALS['host'],'css_epoch'=>140,'mobile_breakpoint'=>767,'entries'=>['11|en|mobile'=>$entry]];
 $v1=['contract'=>Layout::CONTRACT,'site_host'=>$GLOBALS['host'],'enrolled_css_epoch'=>140,'registry_sha256'=>Layout::proof_digest($r),'mobile_breakpoint'=>767,'kit_id'=>10,'kit_settings_sha256'=>hash('sha256',serialize(['viewport_mobile'=>767])),'theme_stylesheet'=>'astra-child','theme_template'=>'astra','theme_mods_sha256'=>hash('sha256',serialize(get_theme_mods())),'astra_options_sha256'=>hash('sha256',serialize(get_option('astra-settings'))),'wp_custom_css_sha256'=>hash('sha256',wp_get_custom_css()),'elementor_docs'=>$docs,'css_dependencies'=>$files];
 chk(Layout::matches($r,$v1,154),'legacy exact dependencies survive unrelated epoch');
 $v2=Layout::derive_geometry_baseline($r,$v1,154);
 chk(($v2['contract']??'')===Layout::CONTRACT_V2,'derive V2 only from validated V1');
 chk(Layout::derive_geometry_baseline($r,[],154)===[],'missing historical baseline cannot invent geometry');
 $q=$r;$q['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['query_generation']='B';
 $q['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['audited_at']=200;
 chk(!Layout::matches($q,$v1,154),'old whole-registry identity rejects query-only change');
 chk(Layout::matches($q,$v2,154),'V2 geometry survives query-only change');
 chk(Layout::proof_digest($q)!==Layout::proof_digest($r),'original query evidence remains independent');
 $x=$q;$x['css_epoch']=154;$x['last_epoch_transition']=['old'=>140];
 chk(Layout::matches($x,$v2,155),'unrelated epoch and transition metadata do not require browser');
 $x=$q;$x['entries']['11|en|mobile']['height_px']=465;chk(!Layout::matches($x,$v2,154),'changed layout height rejected');
 $x=$q;$x['entries']['11|en|mobile']['status']='hold';chk(!Layout::matches($x,$v2,154),'held proof never promoted');
 $x=$q;$x['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['document_id']=999;chk(!Layout::matches($x,$v2,154),'unknown parent rejected');
 $x=$q;$x['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['document_sha256']=str_repeat('0',64);chk(!Layout::matches($x,$v2,154),'changed parent proof rejected');
 $x=$q;$x['mobile_breakpoint']=768;chk(!Layout::matches($x,$v2,154),'breakpoint change rejected');
 $GLOBALS['mock_docs'][12]='modified';chk(!Layout::matches($q,$v2,154),'actual Elementor dependency change rejected');$GLOBALS['mock_docs'][12]=json_encode([['id'=>'abc','elements'=>[],'settings'=>['text'=>str_repeat('layout',70)]]]);
 $path=WP_CONTENT_DIR.'/uploads/elementor/css/post-11.css';$old=file_get_contents($path);file_put_contents($path,$old.'.changed{}');chk(!Layout::matches($q,$v2,154),'actual stylesheet change rejected');file_put_contents($path,$old);
 $GLOBALS['host']='other.example';chk(!Layout::matches($q,$v2,154),'cross-tenant rejection');$GLOBALS['host']='tours.dev.mad4b.com';
 chk(!Layout::matches($q,$v2,139),'epoch regression rejected');
 echo "PASS 16/16 geometry identity invariants\n";
 mkdir(WP_CONTENT_DIR.'/themes/astra-child',0700,true);
 mkdir(WP_CONTENT_DIR.'/themes/astra',0700,true);
 mkdir(WP_CONTENT_DIR.'/mu-plugins',0700,true);
 foreach(['themes/astra-child/style.css','themes/astra/style.css','mu-plugins/mad4b-secondary-listing-lazy.php'] as $f)file_put_contents(WP_CONTENT_DIR.'/'.$f,str_repeat('source',30));
 $co=['listing_id'=>11,'document_id'=>12,'page_id'=>13,'locale'=>'en','market'=>'de-usd','query_id'=>291,'query_generation'=>'A'];
 $r['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['query_id']=291;
 $r['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['status']='pass';
 $r['entries']['11|en|mobile']['ajax_parity_by_market']['de-usd']['browser_evidence_sha256']=str_repeat('a',64);
 $r['entries']['11|en|wide']=$r['entries']['11|en|mobile'];$r['entries']['11|en|wide']['device']='wide';
 $r['entries']['11|en|wide']['sampled_widths']=[768,1024,1440,1920];
 $r['entries']['11|en|wide']['height_px']=500;
 $r['entries']['14|es|mobile']=$r['entries']['11|en|mobile'];
 $r['entries']['14|es|mobile']['listing_id']=14;$r['entries']['14|es|mobile']['locale']='es';
 $v1['registry_sha256']=Layout::proof_digest($r);
 $snapshot=Layout::family_source($co);
 chk(!empty($snapshot)&&count($snapshot['documents'])===3,'family source traverses scoped documents');
 chk(!Layout::record_native_admission($co,[],$r),'missing pre-admission source rejected');
 chk(Layout::record_native_admission($co,$snapshot,$r),'original native evidence recorded per family');
 $reordered=$snapshot;krsort($reordered);krsort($reordered['documents']);
 chk(Layout::record_native_admission($co,$reordered,$r),'JSON associative key order does not block original receipt');
 $changed=$reordered;$changed['kit_id']=999;
 chk(!Layout::record_native_admission($co,$changed,$r),'changed source value still rejected');
 chk(Layout::assess_family($co)['source_same'],'lightweight family source matches');
 $httpkey='mad4b_layout_http_observation_v1_'.substr(hash('sha256',$GLOBALS['host'].'|en|11|12|291|13'),0,40);
 $receipt=['contract'=>'mad4b.native-listing.functional-observation.v1','authority'=>'functional_only_no_native_qualification',
 'site_host'=>$GLOBALS['host'],'source'=>$snapshot,'source_sha256'=>hash('sha256',serialize(Layout::canonical_source($snapshot))),
 'query_generation'=>'A','global_data_revision'=>'initial','observed_at'=>time(),
 'http'=>['signed_request_accepted'=>true,'ordered_semantics_match'=>true,'ssr_count'=>3,'ajax_count'=>3,
 'ssr_sha256'=>str_repeat('a',64),'ajax_sha256'=>str_repeat('a',64)]];
 $GLOBALS['opts'][$httpkey]=$receipt;
 $status=Layout::http_requalification_status($co);
 chk($status['functional_current']&&!$status['visual_identity_current']&&!$status['native_gate']&&$status['decision']==='functional_current_visual_authority_pending','functional proof cannot substitute visual authority');
 $GLOBALS['test_native_gate']=true;
 chk(Layout::http_requalification_status($co)['decision']==='native_admitted_functional_current','current native gate recognized independently');
 $GLOBALS['test_native_gate']=false;
 $GLOBALS['test_generation']='B';
 chk(!Layout::http_requalification_status($co)['functional_current'],'changed generation rejects stale HTTP receipt');
 $GLOBALS['test_generation']='A';
 $GLOBALS['opts'][$httpkey]['observed_at']=time()-90000;
 chk(!Layout::http_requalification_status($co)['functional_current'],'expired receipt rejected');
 $GLOBALS['opts'][$httpkey]=$receipt;
 $GLOBALS['opts'][$httpkey]['http']['ajax_count']=2;
 chk(!Layout::http_requalification_status($co)['functional_current'],'incomplete AJAX result rejected');
 $GLOBALS['opts'][$httpkey]=$receipt;
 chk(!Layout::http_requalification_status(array_merge($co,['locale'=>'es']))['functional_current'],'cross-language receipt rejected');

 chk(!Layout::can_retain_family($r,'11|en|mobile',154),'partial source receipt cannot authorize epoch');
 chk(Layout::enroll_verified_family($co,$r,$v1),'verified baseline derives scoped family');
 chk(Layout::can_retain_family($r,'11|en|mobile',154),'verified family survives epoch');
 $other=$r;$other['entries']['14|es|mobile']['height_px']=999;
 chk(Layout::can_retain_family($other,'11|en|mobile',154),'unrelated family geometry change does not invalidate this family');
 $select=Layout::selective_registry($other,154);
 chk(($select['css_epoch']??0)===154 && $select['entries']['11|en|mobile']['status']==='pass' &&
     $select['entries']['14|es|mobile']['status']==='hold_css_epoch_rollover','only independently verified families survive rollover');
 chk($select['entries']['11|en|mobile']['ajax_parity_by_market']===$other['entries']['11|en|mobile']['ajax_parity_by_market'],'retained query evidence byte-identical');
 $other['entries']['11|en|wide']['height_px']=501;
 chk(!Layout::can_retain_family($other,'11|en|mobile',154),'own geometry drift rejects this family');
 $GLOBALS['mock_docs'][13]='modified';
 chk(!Layout::assess_family($co)['source_same'],'source change detected without Chromium');
 chk(!Layout::can_retain_family($r,'11|en|mobile',154),'changed family source cannot reuse geometry');
 foreach(['themes/astra-child/style.css','themes/astra/style.css','mu-plugins/mad4b-secondary-listing-lazy.php'] as $f)unlink(WP_CONTENT_DIR.'/'.$f);
 rmdir(WP_CONTENT_DIR.'/themes/astra-child');rmdir(WP_CONTENT_DIR.'/themes/astra');rmdir(WP_CONTENT_DIR.'/themes');rmdir(WP_CONTENT_DIR.'/mu-plugins');
 echo "PASS 21/21 family qualification and HTTP integration invariants\n";
}finally{foreach(glob(WP_CONTENT_DIR.'/uploads/elementor/css/*') as $p)unlink($p);rmdir(WP_CONTENT_DIR.'/uploads/elementor/css');rmdir(WP_CONTENT_DIR.'/uploads/elementor');rmdir(WP_CONTENT_DIR.'/uploads');rmdir(WP_CONTENT_DIR);}
