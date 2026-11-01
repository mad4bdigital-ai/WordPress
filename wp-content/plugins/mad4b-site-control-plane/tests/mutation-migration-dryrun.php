<?php
if(wp_get_environment_type()!=='staging'||parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')throw new Exception('wrong_environment');
global $wpdb;
$original=MAD4B_SCP_Schema::tables()['mutations'];
$test=$wpdb->prefix.'mad4b_scp_mutation_principal_qa20261030';
if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$test))===$test)throw new Exception('qa_table_exists');
try{
 $queries=[
  "CREATE TABLE `$test` LIKE `$original`",
  "INSERT INTO `$test` SELECT * FROM `$original`",
  "ALTER TABLE `$test` ADD COLUMN principal_type varchar(32) NOT NULL DEFAULT 'independent_nhi' AFTER parent_mutation_id, MODIFY COLUMN agent_id bigint(20) unsigned NULL",
  "ALTER TABLE `$test` ADD INDEX principal_created (principal_type,wp_user_id,created_at)"
 ];
 foreach($queries as $i=>$query){
   $result=$wpdb->query($query);
   if(false===$result)throw new Exception('dryrun_step_'.$i.'_failed:'.$wpdb->last_error);
 }
 $source=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$original`");
 $copied=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$test`");
 $nhi=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$test` WHERE principal_type='independent_nhi'");
 $cols=$wpdb->get_results("SHOW COLUMNS FROM `$test`",ARRAY_A);
 $by=[];foreach($cols as $row)$by[$row['Field']]=$row;
 if($source!==$copied||$source!==$nhi||$by['agent_id']['Null']!=='YES')throw new Exception('dryrun_readback_failed');
 $wpdb->insert($test,[
  'mutation_id'=>wp_generate_uuid4(),'request_id'=>'qa20261030',
  'parent_mutation_id'=>null,'principal_type'=>'wordpress_user','agent_id'=>null,
  'subject_type'=>'wordpress_user','subject_fingerprint'=>str_repeat('1',64),
  'wp_user_id'=>2,'server_id'=>'mad4b-content','ability_name'=>'mad4b/content-create-post',
  'provider'=>'qa','target_type'=>'qa','target_id'=>'test','impact'=>'low','status'=>'planned',
  'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')
 ]);
 if($wpdb->insert_id<1)throw new Exception('null_agent_insert_failed:'.$wpdb->last_error);
 echo "MUTATION_SCHEMA_DRYRUN_PASS sources=$source copied=$copied original_nhi=$nhi null_agent=yes\n";
}finally{
 $wpdb->query("DROP TABLE IF EXISTS `$test`");
 echo "QA_TABLE_CLEANED=yes\n";
}
