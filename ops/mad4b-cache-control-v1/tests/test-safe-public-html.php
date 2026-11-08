<?php
namespace LiteSpeed {
final class Purge {
  public static int $page_only=0;
  public static int $global_all=0;
  public static function purge_all_lscache($reason=false) : void {
    self::$page_only++;
    \trigger_mock_action('litespeed_purged_all_lscache');
  }
  public static function purge_all($reason=false):void {self::$global_all++;}
}
}
namespace {
define('ABSPATH','/');
define('WP_CONTENT_DIR',sys_get_temp_dir().'/mad4b-safe-html-'.getmypid());
mkdir(WP_CONTENT_DIR.'/.mad4b-purge',0700,true);
file_put_contents(WP_CONTENT_DIR.'/.mad4b-purge/global-html-invalidate.request',"MAD4B_SAFE_PUBLIC_HTML_ALL_V1\n");
$actions=[];
function add_action($name,$cb,$priority=10,$accepted=1){$GLOBALS['actions'][$name][]=$cb;return true;}
function trigger_mock_action($name){foreach($GLOBALS['actions'][$name]??[] as $cb){$cb();}}
require $argv[1];
$cls='MAD4B_Safe_Public_HTML_Purge_V1';
if($cls::signal_file()!==WP_CONTENT_DIR.'/.mad4b-purge/global-html-invalidate.request')
  throw new \RuntimeException('FIXED_SIGNAL_PATH_MISMATCH');
$result=$cls::purge_public_html('qa_fixtures');
if(($result['status']??'')!=='public_html_purge_queued'
  ||\LiteSpeed\Purge::$page_only!==1 ||\LiteSpeed\Purge::$global_all!==0)
   throw new \RuntimeException('VENDOR_FULL_PURGE_EXECUTED');
$signal=file_get_contents($cls::signal_file());
if(!preg_match('/^MAD4B_SAFE_PUBLIC_HTML_ALL_V2\n[a-f0-9]{24}\n$/D',$signal))
 throw new \RuntimeException('SIGNAL_INCORRECT');
if(($result['request_id']??'')!==trim(explode("\n",$signal)[1]))
 throw new \RuntimeException('REQUEST_ID_NOT_CORRELATED');
$again=$cls::purge_public_html('idempotent');
if(($again['request_id']??'')!==$result['request_id'] ||
 $again['status']!=='already_queued_in_request' || \LiteSpeed\Purge::$page_only!==1)
 throw new \RuntimeException('DUPLICATE_REQUEST_ID_NOT_STABLE');
unlink($cls::signal_file());
symlink('/etc/passwd',$cls::signal_file());
$before=\LiteSpeed\Purge::$page_only;
$reject=$cls::purge_public_html('unsafe_signal');
if($reject['status']!=='host_global_epoch_signal_unavailable' ||
 \LiteSpeed\Purge::$page_only!==$before)
 throw new \RuntimeException('UNSAFE_SIGNAL_NOT_FAIL_CLOSED');
unlink($cls::signal_file());
rmdir(WP_CONTENT_DIR.'/.mad4b-purge');
rmdir(WP_CONTENT_DIR);
echo "SAFE_PUBLIC_HTML_PURE_TEST_PASS page_only=1 global_object_flush=0 symlink_denied=1\n";
}

