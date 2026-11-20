<?php
if (!defined('ABSPATH') || !function_exists('wp_get_environment_type') || wp_get_environment_type()!=='local') return;
add_action('plugins_loaded',function() {
    $data = array('request'=>isset($_SERVER['REQUEST_METHOD'])?'http':'cli',
      'plugin_class'=>class_exists('MAD4B_SCP_Context_Authority',false),
      'ai_class'=>class_exists('MAD4B_SCP_AI_Only_Local_Activation',false),
      'active'=>in_array('mad4b-site-control-plane/mad4b-site-control-plane.php',(array)get_option('active_plugins',array()),true),
      'plugin_readable'=>is_readable(WP_PLUGIN_DIR.'/mad4b-site-control-plane/mad4b-site-control-plane.php'));
    @file_put_contents('/tmp/mad4b-ai-local-plugin-load-20261024.log',wp_json_encode($data)."\n",FILE_APPEND);
},PHP_INT_MAX);
