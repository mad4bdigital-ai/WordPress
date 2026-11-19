<?php
/**
 * Read-only WP-CLI integration probe for the in-page AJAX selector.
 * Run on each company instance:
 *   wp --path=/var/www/html eval-file tests/stay-pricing-panel-runtime-cli.php --allow-root
 * Does not mutate database or approve rates.
 */
if (!class_exists('MAD4B_Stay_Pricing_Controls_Panel_V1',false))
    throw new RuntimeException('Native stay pricing panel not loaded');
$users=get_users(['role'=>'master_admin','fields'=>'ID','number'=>1]);
if(!$users)$users=get_users(['role'=>'administrator','fields'=>'ID','number'=>1]);
if(!$users)throw new RuntimeException('No pricing admin for read-only probe');
wp_set_current_user((int)$users[0]);
$panel='MAD4B_Stay_Pricing_Controls_Panel_V1';
$checks=[];
$test=static function($name,$yes) use(&$checks) {
    if(!$yes)throw new RuntimeException('Failed: '.$name);
    $checks[]=$name;
};
$test('authorized pricing role',$panel::permitted());
$facets=$panel::facets();
$tax=array_column($facets['properties'],'taxonomy');
$test('JetEngine Property taxonomy dimensions',in_array('property-types',$tax,true)
    &&in_array('destinations',$tax,true) &&in_array('stars',$tax,true));
$test('meal plan terms available',count($facets['boards'])>=3);
$list=$panel::properties(['paged'=>1,'filters'=>[]]);
$test('paged published Properties',!is_wp_error($list)
    &&count($list['items'])>=1&&count($list['items'])<=12
    &&$list['found']>=1 && $list['page']===1);
$test('all result IDs published',array_reduce($list['items'],
    static function($ok,$row){return $ok&&get_post_type($row['id'])==='properties'
       &&get_post_status($row['id'])==='publish';},true));
$test('Property edit links are native',isset($list['items'][0]['edit_url'])
    &&strpos($list['items'][0]['edit_url'],'post.php')!==false);
$hotel=get_term_by('slug','hotel','property-types');
$hurghada=get_term_by('slug','hurghada','destinations');
if($hotel instanceof WP_Term && $hurghada instanceof WP_Term){
    $filtered=$panel::properties(['filters'=>[
        'property-types'=>(string)$hotel->term_id,
        'destinations'=>(string)$hurghada->term_id]]);
    $test('AND Property + destination filters',!is_wp_error($filtered)
        &&$filtered['found']>=1);
    foreach($filtered['items'] as $item)
        $test('matched Property belongs to all selected taxonomies',
          has_term((int)$hotel->term_id,'property-types',$item['id'])
          &&has_term((int)$hurghada->term_id,'destinations',$item['id']));
    $found=$panel::term_search(['taxonomy'=>'destinations','search'=>'hurg']);
    $test('AJAX taxonomy remote term search',!is_wp_error($found)
        &&count($found['options'])>=1);
}
$rejected=$panel::properties(['filters'=>['post_status'=>'publish']]);
$test('unlisted filter rejected',is_wp_error($rejected)
    &&$rejected->get_error_code()==='stay_panel_filter_invalid');
$units=$panel::units(['property_id'=>36260]);
$test('JetEngine Property-Unit relation',!is_wp_error($units)
    &&$units['property_id']===36260&&$units['found']>=1);
$test('only linked published Units shown',array_reduce($units['items'],
    static function($ok,$row){return $ok&&get_post_status($row['id'])==='publish';},true));
$badUnit=$panel::units(['property_id'=>99999999]);
$test('non-Property units request rejected',is_wp_error($badUnit));
$rates=$panel::rates(['property_id'=>36362]);
$test('native Property Rates readback',!is_wp_error($rates)
    &&isset($rates['counts'])&&$rates['read_only']===true);
$preview=$panel::preview(['property_id'=>36260,'unit_id'=>37553,
    'checkin'=>'2027-01-01','nights'=>2,'adults'=>2]);
$test('pricing registry resolver preview',!is_wp_error($preview)
    &&$preview['quote']['provider_id']==='self'
    &&$preview['quote']['checkout_ready']===false
    &&$preview['quote']['mutation_performed']===false);
$cruise=$panel::preview(['property_id'=>36048,'unit_id'=>0,'checkin'=>'2027-01-01']);
$test('cruise meal default retained',!is_wp_error($cruise)
    &&$cruise['quote']['meal_base']==='full-board');
$_GET['page']='dynamic-pricing-controls';
$panel::assets();
$test('main page inlines native selector assets',
    wp_script_is('mad4b-stay-pricing-inline','enqueued')
    &&wp_style_is('mad4b-stay-pricing-inline','enqueued'));
$registered=wp_scripts()->registered['mad4b-stay-pricing-inline']??null;
$before=implode("\n",array_filter((array)($registered->extra['before']??[]),'is_string'));
$test('AJAX nonce + in-page template available',strpos($before,'MAD4BStayPricingUI')!==false
    &&strpos($before,'Accommodation &')!==false);
wp_set_current_user(0);
$denied=$panel::properties(['filters'=>[]]);
$test('unauthenticated property catalog denied',is_wp_error($denied)
    &&$denied->get_error_code()==='stay_panel_forbidden');
$test('unauthenticated page not authorized',!$panel::permitted());
echo wp_json_encode(['site'=>home_url(),'assertions'=>count($checks),
    'passed'=>true,'published_properties'=>$list['found'],
    'published_units'=>$units['found'],'native_page'=>'dynamic-pricing-controls',
    'no_mutations'=>true],JSON_PRETTY_PRINT);
