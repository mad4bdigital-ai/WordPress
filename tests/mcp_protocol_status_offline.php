<?php
namespace WP\MCP\Core {
 final class McpVersionNegotiator {
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25'];
    public const LEGACY_PROTOCOL_VERSIONS = ['2024-11-05'];
 }
}
namespace {
 define('ABSPATH','/test/');
 define('WP_PLUGIN_DIR','/test/wp-content/plugins');
 class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=[]){$this->code=$code;}
    public function get_error_code(){return $this->code;}
 }
 function is_wp_error($value){return $value instanceof WP_Error;}
 function wp_normalize_path($s){return str_replace('\\','/',$s);}
 function trailingslashit($s){return rtrim($s,'/').'/';}
 function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
 class MAD4B_SCP_MCP_Adapter_Dynamic_Integrity {
     public static function inspect($file,$version,$verify=false){
         return ['ready'=>$version==='6.10.1000' &&
             $file==='mcp-adapter/mcp-adapter.php',
             'layout_checked'=>$version==='6.10.1000',
             'official_package_verified'=>false];
     }
 }
 class MAD4B_SCP_Provider_Contracts {
     public static $installed='0.7.1';
     public static function installed_version($slug){return self::$installed;}
     public static function get($slug){return ['plugin_file'=>'mcp-adapter/mcp-adapter.php'];}
     public static function get_for_version($slug,$version){
         $t=['legacy_session_revisions'=>['2024-11-05','2025-06-18','2025-11-25'],
             'modern_per_request_revisions'=>['2026-07-28']];
         return in_array($version,['0.6.1','0.7.1'],true)
             ? ['transport_compatibility'=>$t] : [];
     }
 }
 function check($value,$label){
  if(!$value){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(2);}
 }
 require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-protocol-profile.php';
 $cl=MAD4B_SCP_MCP_Protocol_Profile::class;
 $result=$cl::status();
 if (empty($result['ready'])) fwrite(STDERR,'STATUS '.json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL);
 check($result['ready']===true,'known transport and runtime supported protocol ready');
 check($result['effective_protocol_versions']===['2025-11-25','2024-11-05'],
       'effective runtime protocol subset');
 check($result['authorizing']===false,'not authorizing');
 check($result['canonical_mcp_runtime_evaluated']===false,'non canonical scope');
 check($result['transport_compatible']===true,'transport compatible');
 $unsupported=$cl::profile('2026-07-28');
 check(is_wp_error($unsupported) &&
       $unsupported->get_error_code()==='mad4b_mcp_protocol_revision_unavailable_in_loaded_runtime',
       'unsupported runtime revision must fail closed');
 $ok=$cl::profile('2025-11-25');
 check(!is_wp_error($ok) && ($ok['authorizing']??true)===false,'supported profile read');
 MAD4B_SCP_Provider_Contracts::$installed='0.6.1';
 $compatible=$cl::status();
 check($compatible['ready']===true && !$compatible['adapter_version_match'],
       'compatible version without fixed whitelist');
 check($compatible['certified_adapter_version']==='0.7.1',
       'certified reference remains distinct');
 MAD4B_SCP_Provider_Contracts::$installed='6.10.1000';
 $future=$cl::status();
 check($future['ready']===true && !$future['adapter_version_match'],
       'future known local runtime accepted by dynamic layout and protocol');
 check($future['version_certification_warning']===true
       && $future['authorizing']===false
       && $future['certification_warning_code']==='future_version_layout_verified_protocol_runtime_limited',
       'future version is compatible, not certified or authorizing');
 MAD4B_SCP_Provider_Contracts::$installed='9.9.9';
 $unknown=$cl::status();
 check($unknown['ready']===false && $unknown['authorizing']===false,
       'unreviewed installed layout not granted');
 echo json_encode(['state'=>'PASS','cases'=>10,'loaded_protocol_subset'=>2,
 'fixed_adapter_version_whitelist'=>false,'authority_issued'=>false,
 'live_runtime_test'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
}
