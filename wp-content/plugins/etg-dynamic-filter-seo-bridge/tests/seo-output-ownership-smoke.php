<?php
require_once dirname(__DIR__).'/includes/SEO/OutputOwnershipResolver.php';
$e=new \ETG\DynamicFilterSEOBridge\SEO\OutputOwnershipResolver();
function ck_owner($v,$label){if(!$v)throw new RuntimeException($label);}
$route=array('route_id'=>'archive','active'=>true,'in_scope'=>true,'index_eligible'=>true);
$ev=array('jsf_seo_rule'=>'unmatched','rank_math_frontend'=>'enabled',
    'sitemap_owner'=>'rank_math','emissions'=>array(
        'title'=>array('rank_math'),'description'=>array('rank_math'),
        'canonical'=>array('rank_math'),'robots'=>array('rank_math'),'sitemap'=>array('rank_math')),
    'robots_index_confirmed'=>true,'sitemap_inclusion_verified'=>true,
    'direct_ajax_parity_verified'=>true,'url_alias_parity_verified'=>true,'wpml_hreflang_verified'=>true);
$ok=$e->resolve($route,$ev);
ck_owner($ok['review_ready']&&!$ok['authorizing']&&!$ok['seo_published'],'review evidence must never authorize SEO');
$double=$ev;$double['emissions']['canonical'][]='jet_smart_filters';
ck_owner(!$e->resolve($route,$double)['review_ready'],'duplicate canonical blocked');
$unknown=$ev;unset($unknown['jsf_seo_rule']);
ck_owner(!$e->resolve($route,$unknown)['review_ready'],'unknown JSF rule blocked');
$jsf=$ev;$jsf['jsf_seo_rule']='matched';$jsf['rank_math_frontend']='disabled';
$jsf['emissions']['title']=array('jet_smart_filters');
$jsf['emissions']['description']=array('jet_smart_filters');
$jsf['emissions']['canonical']=array('jet_smart_filters');
ck_owner(!$e->resolve($route,$jsf)['review_ready'],'JSF cannot imply Rank Math robots');
$ajax=$route;$ajax['state_transport']='ajax';
ck_owner(!$e->resolve($ajax,$ev)['review_ready'],'AJAX no publication');
$noindex=$route;$noindex['index_eligible']=false;
ck_owner(!$e->resolve($noindex,$ev)['review_ready'],'noindex proof mandatory');
$missingHreflang=$ev;$missingHreflang['wpml_hreflang_verified']=false;
ck_owner(!$e->resolve($route,$missingHreflang)['review_ready'],'WPML parity mandatory');
echo "PASS SEO output ownership source evidence\n";
