<?php
namespace WP\MCP\Infrastructure\ErrorHandling\Contracts {
 interface McpErrorHandlerInterface { public function log(string $message,array $context=array(),string $type='error'):void; }
}
namespace {
 define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);
 define('AUTH_KEY',str_repeat('K',64));define('AUTH_SALT',str_repeat('S',64));
 $options=array();$db_throws=false;
 function get_option($key,$default=false){global $options;return $options[$key]??$default;}
 function update_option($key,$value,$autoload=false){global $options,$db_throws;if($db_throws){MAD4B_Native_Incident_MU::record_throwable(new \RuntimeException('nested-secret'),'execution');throw new \RuntimeException('private-db-error');}$options[$key]=$value;return true;}
 function is_admin(){return false;}
 class WP_Error {private $code,$message,$data;function __construct($c,$m='',$d=null){$this->code=$c;$this->message=$m;$this->data=$d;}function get_error_code(){return $this->code;}function get_error_data(){return $this->data;}function get_error_message(){return $this->message;}}
 function check_incident($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 require dirname(__DIR__).'/bootstrap/mad4b-native-incident-mu.php';
 require dirname(__DIR__).'/includes/class-mad4b-scp-native-runtime-diagnostics.php';
 require dirname(__DIR__).'/includes/class-mad4b-scp-connector-resilience.php';
 check_incident(false===MAD4B_Native_Incident_MU::record_throwable(new \RuntimeException('secret'),'execution'),'Non-enrolled incident persisted');
 check_incident(strpos(MAD4B_SCP_Native_Runtime_Diagnostics::adapter_error_handler(),'ErrorLogMcpErrorHandler')!==false,'Inactive MU replaced normal Adapter logger');
 MAD4B_Native_Incident_MU::boot(true);
 check_incident('MAD4B_SCP_Native_MCP_Error_Handler'===MAD4B_SCP_Native_Runtime_Diagnostics::adapter_error_handler(),'Native Adapter sink not mounted');
 $log=tempnam(sys_get_temp_dir(),'mu-caught-');ini_set('error_log',$log);
 $calls=0;
 $args=array('meta'=>array('annotations'=>array('readonly'=>true)), 'execute_callback'=>static function()use(&$calls){++$calls;throw new \TypeError('private-provider-secret');});
 $wrapped=MAD4B_SCP_Connector_Resilience::wrap_read_registration($args,'mad4b/site-info');
 $result=$wrapped['execute_callback'](array('token'=>'private-input-secret'));
 check_incident($result instanceof WP_Error&&$calls===1,'Capture retried or upgraded failed callback');
 $events=MAD4B_Native_Incident_MU::status()['events'];$event=end($events);
 check_incident($event['event_kind']==='caught_exception'&&$event['stage']==='execution'&&$event['ability_name']==='mad4b/site-info','Caught fault metadata missing');
 check_incident($event['correlation_id']===$result->get_error_data()['correlation_id'],'MCP error correlation not joined to native incident');
 check_incident($event['first_party_line']>0&&$event['first_party_file']==='tests/native-caught-incident-runtime.php','Public MAD4B source hint unavailable');
 $handler=new MAD4B_SCP_Native_MCP_Error_Handler();
 $handler->log('private-adapter-message',array('tool'=>'mad4b-site-info','exception'=>'private-adapter-exception','authorization'=>'Bearer private-token'));
 $events=MAD4B_Native_Incident_MU::status()['events'];$event=end($events);
 check_incident($event['event_kind']==='adapter_error'&&$event['stage']==='adapter'&&$event['first_party_file']==='','Adapter catch not captured or invented location');
 $serialized=json_encode($options).file_get_contents($log);
 check_incident(strpos($serialized,'private-')===false&&strpos($serialized,'Bearer')===false&&strpos($serialized,__DIR__)===false,'Native persistence leaked request/message/path');
 $db_throws=true;
 $handler->log('different-secret-error',array('exception'=>'different-secret-detail'));
 $db_throws=false;
 $before=$options;
 check_incident(false===MAD4B_Native_Incident_MU::record_throwable(new \RuntimeException('fourth-secret'),'execution'),'Per-request capture bound bypassed');
 check_incident($before===$options,'Bounded recorder performed another write');
 $options[MAD4B_Native_Incident_MU::OPTION]['events'][0]['ability_name']='<script>bad</script>';
 $options[MAD4B_Native_Incident_MU::OPTION]['events'][0]['stage']='private-stage';
 $options[MAD4B_Native_Incident_MU::OPTION]['events'][0]['correlation_id']='private-id';
 $sanitized=MAD4B_Native_Incident_MU::status()['events'][0];
 check_incident($sanitized['ability_name']===''&&$sanitized['stage']===''&&$sanitized['correlation_id']==='','Untrusted stored metadata reflected');
 unlink($log);
 echo "NATIVE_CAUGHT_INCIDENT_RUNTIME_PASS\n";
}
