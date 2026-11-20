<?php
/** ETG isolated local laboratory only; disable by removing this file. */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') return;
add_action('init', static function () {
 if (!isset($_GET['etg_qa_local_login'])) return;
 nocache_headers();
 $remote = $_SERVER['REMOTE_ADDR'] ?? '';
 $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
 if ($remote !== '172.16.18.3' || !in_array($host,['127.0.0.1:8091','localhost:8091'],true)) { status_header(403);exit('QA access denied'); }
 $user=get_user_by('login','lab_jetengine_qa');
 if (!$user || !in_array('administrator',(array)$user->roles,true)) {status_header(403);exit('QA account unavailable');}
 wp_set_current_user($user->ID);wp_set_auth_cookie($user->ID,false,is_ssl());
 wp_safe_redirect(admin_url());exit;
},0);
