<?php
/**
 * Read-only WP-CLI contract for the exact 3 opt-in Settings API screens.
 * wp eval-file wp-content/plugins/mad4b-jetengine/modules/functional-modes/qa/wp-native-settings-adapter-contract.php --skip-themes --user=1
 * Does not invoke global admin_init, Settings API update or form submit.
 */
if(!class_exists('MAD4B_Native_WP_Settings_Adapter_V1')){
 echo wp_json_encode(['pass'=>false,'error'=>'not registered']).PHP_EOL;return;
}
wp_set_current_user(1);
$cases=[
 'mad4b-visitor-experience'=>['MAD4B_Visitor_Experience','render'],
 'mad4b-presentation-experience'=>['MAD4B_Presentation_Experience','render'],
 'mad4b-site-shell'=>['MAD4B_Persistent_Site_Shell','admin_page'],
];
$rows=[];
foreach($cases as $slug=>$fn){
 $_GET['page']=$slug;set_current_screen('settings_page_'.$slug);
 global $wp_styles;$wp_styles=new WP_Styles();
 MAD4B_Native_WP_Settings_Adapter_V1::enqueue();
 $class=MAD4B_Native_WP_Settings_Adapter_V1::body_class('wp-admin');
 ob_start();call_user_func($fn);$html=ob_get_clean();
 $dom=new DOMDocument();libxml_use_internal_errors(true);
 $dom->loadHTML('<html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();$x=new DOMXPath($dom);
 $forms=$x->query('//form[@action="options.php" and translate(@method,"POST","post")="post"]');
 $inputs=$x->query('//form//input[@type="checkbox"]')->length;
 $hidden=$x->query('//form//input[@type="hidden"]')->length;
 $submit=$x->query('//form//input[@type="submit"]|//form//button[@type="submit"]')->length;
 $ok=count($forms)===1&&$inputs>0&&$hidden>=3&&$submit===1
   &&str_contains($class,'mad4b-native-wp-settings-optin')
   &&wp_style_is('mad4b-native-wp-settings-adapter','enqueued')
   &&$wp_styles->all_deps(['mad4b-native-wp-settings-adapter'],false,false);
 $rows[$slug]=['pass'=>$ok,'forms'=>count($forms),'checkboxes'=>$inputs,
    'hidden_fields'=>$hidden,'submit'=>$submit,'css_queued'=>wp_style_is('mad4b-native-wp-settings-adapter','enqueued')];
}
foreach(['mad4b-ai-routing','mad4b-brand-asset-store','mad4b-jetengine-recipe-registry'] as $slug){
 $_GET['page']=$slug;set_current_screen('admin_page_'.$slug);
 global $wp_styles;$wp_styles=new WP_Styles();
 MAD4B_Native_WP_Settings_Adapter_V1::enqueue();
 $class=MAD4B_Native_WP_Settings_Adapter_V1::body_class('wp-admin');
 $rows[$slug]=['pass'=>!wp_style_is('mad4b-native-wp-settings-adapter','enqueued')
   && !str_contains($class,'mad4b-native-wp-settings-optin'),'excluded'=>true];
}
echo wp_json_encode(['pass'=>!in_array(false,array_column($rows,'pass'),true),'cases'=>$rows],JSON_PRETTY_PRINT).PHP_EOL;
