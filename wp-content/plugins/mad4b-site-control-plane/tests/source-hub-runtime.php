<?php
/** Read-only Source Hub regression; no authenticated Google or WordPress network required. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(3);
$c='MAD4B_SCP_Source_Hub';$fail=0;
$check=static function($name,$ok)use(&$fail){echo $name.'='.($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;};
$check('CONTRACT',$c::status()['contract']==='mad4b.source-hub.v1');
$check('ADAPTERS',count($c::adapters())===5);
$rss='<?xml version="1.0"?><rss version="2.0"><channel><item><guid>n-1</guid><title>Travel sector update</title><link>https://example.org/news</link><description>News report</description></item></channel></rss>';
$a=$c::parse_rss($rss);$check('RSS2',is_array($a)&&count($a)===1&&$a[0]['article_url']==='https://example.org/news');
$atom='<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><id>a-1</id><title>Travel policy</title><link href="https://example.org/atom"/><summary>Policy update</summary></entry></feed>';
$a=$c::parse_rss($atom);$check('ATOM',is_array($a)&&count($a)===1&&$a[0]['article_url']==='https://example.org/atom');
$a=$c::parse_rss('<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss>&xxe;</rss>');$check('XML_ENTITY_REJECTED',is_wp_error($a));
$provider=wp_json_encode(['contract'=>'mad4b.source-provider.v1','items'=>[['id'=>'s-4','title'=>'Local supplier','summary'=>'Internal rate review required']]]);
$a=$c::normalize(['adapter'=>'provider_json'],$provider);$check('PROVIDER_JSON',is_array($a)&&count($a)===1&&$a[0]['category']==='local_provider_update');
$a=$c::normalize(['adapter'=>'provider_json'],wp_json_encode(['items'=>[['id'=>'x','title'=>'bad']]]));$check('PROVIDER_SCHEMA_REJECTED',is_wp_error($a));
$wpml=MAD4B_SCP_Market_Profiles::language_visibility();$check('HIDDEN_LANGUAGES_KNOWN',in_array('fr',$wpml['hidden'],true));
$a=$c::ingest_snapshot('source-never-approved');$check('NO_UNAPPROVED_INGEST',is_wp_error($a));
$check('NO_AUTOMATIC_PUBLICATION',empty($c::status()['auto_publish']));
$check('NO_AUTOMATIC_APPROVAL',empty($c::status()['automatic_approval']));
$check('SOURCE_RECIPES_STAY_DRAFT',$c::status()['sources']===3&&$c::status()['approved_sources']===0);
$check('REVIEW_INBOX_EMPTY',$c::status()['pending_items']===0);
exit($fail?1:0);
