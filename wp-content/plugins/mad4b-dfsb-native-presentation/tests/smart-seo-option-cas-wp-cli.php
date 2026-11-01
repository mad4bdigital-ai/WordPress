<?php
/** Isolated QA-option CAS regression; does not write native JSF settings. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
if (!class_exists('MAD4B\\DFSB\\NativePresentation\\SmartSeoNativeStore'))
    require_once dirname(__DIR__).'/includes/class-smart-seo-native-store.php';
use MAD4B\DFSB\NativePresentation\SmartSeoNativeStore as Store;
global $wpdb;
$option='mad4b_smart_seo_cas_qa_'.wp_generate_password(12,false,false);
$initial=array('rules'=>array(array('id'=>'existing','title'=>'Original rule')),'enabled'=>false);
$second=$initial;$second['rules'][]=array('id'=>'new','title'=>'Additional rule');
$third=$second;$third['rules'][]=array('id'=>'concurrent','title'=>'Concurrent change');
$tests=array();
try {
    $tests['qa_option_created']=add_option($option,$initial,'','no');
    $before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$option));
    $tests['cas_append_succeeds']=Store::compareAndSwapOption($option,$before,maybe_serialize($second));
    $tests['existing_rule_preserved']=(get_option($option)['rules'][0]??null)===$initial['rules'][0];
    $tests['appended_rule_present']=count(get_option($option)['rules'])===2;
    $tests['concurrent_stale_update_rejected']=Store::compareAndSwapOption($option,$before,maybe_serialize($third))===false;
    $tests['rejected_update_did_not_overwrite']=get_option($option)===$second;
} finally {
    delete_option($option);
}
$tests['qa_option_cleaned']=get_option($option,null)===null;
foreach($tests as $n=>$ok)echo ($ok?'PASS ':'FAIL ').$n.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($tests)).'/'.count($tests).PHP_EOL;
if(in_array(false,$tests,true))exit(1);
