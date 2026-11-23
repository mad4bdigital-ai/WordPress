<?php
declare(strict_types=1);
// Isolated fail-closed simulation. Never connects to WordPress or edits site content.
define('ABSPATH', '/test/');
$GLOBALS['options']=['home'=>'https://tours.dev.mad4b.com'];
$GLOBALS['reenter']=true;
$GLOBALS['locked']=false;
$GLOBALS['force_lock_fail']=false;
class MAD4BCSSFakeWpdb {
    public string $prefix='wp_';
    public string $options='wp_options';
    public function prepare(string $sql,...$values): string {return $sql;}
    public function get_var(string $sql): ?string {
        if (str_contains($sql,'GET_LOCK')) {
            // MySQL GET_LOCK permits same-connection reentry; the PHP guard must reject it.
            if ($GLOBALS['force_lock_fail']) return '0';
            $GLOBALS['locked']=true; return '1';
        }
        if (str_contains($sql,'RELEASE_LOCK')) {
            $GLOBALS['locked']=false; return '1';
        }
        return null;
    }
    public function query(string $sql): int {
        if (!str_contains($sql,'ON DUPLICATE KEY UPDATE'))
            throw new RuntimeException('unexpected-query');
        $key='mad4b_ccss_global_generation_v1';
        $GLOBALS['options'][$key]=(int)($GLOBALS['options'][$key]??0)+1;
        $GLOBALS['fallbacks']=($GLOBALS['fallbacks']??0)+1;
        return 1;
    }
}
$GLOBALS['wpdb']=new MAD4BCSSFakeWpdb();
function wp_parse_url($url,$part=null){return parse_url($url,$part);}
function get_option($key,$fallback=null){
    $before=$GLOBALS['options'][$key]??$fallback;
    if ($key==='mad4b_ccss_public_dirty_v1' && $GLOBALS['reenter']) {
        $GLOBALS['reenter']=false;
        $GLOBALS['method']->invoke(null,'post:properties',222,false);
    }
    return $before;
}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function update_post_meta($id,$key,$value){$GLOBALS['postmeta'][$id]=$value;return true;}
function wp_cache_delete($key,$group='options'){return true;}
function add_action(...$args){}
require dirname(__DIR__,3).'/wp-content/mu-plugins/421-mad4b-public-critical-css-coverage.php';
$GLOBALS['method']=new ReflectionMethod('MAD4B_Public_Critical_CSS_Coverage_V1','mark');
$mark=$GLOBALS['method'];
$mark->invoke(null,'post:properties',111,false);
$dirty=$GLOBALS['options']['mad4b_ccss_public_dirty_v1'];
$journal=$GLOBALS['options']['mad4b_ccss_public_event_journal_v1'];
if ($dirty['generation']!==1 || count($journal['events'])!==1 ||
    ($GLOBALS['options']['mad4b_ccss_global_generation_v1']??0)!==1 ||
    ($GLOBALS['fallbacks']??0)!==1)
    throw new RuntimeException('lost-contended-event-was-not-fail-closed');
echo "PASS race triggers global invalidation instead of unsafe prior CSS\n";
$mark->invoke(null,'post:properties',333,false);
if ($GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']!==2 ||
    count($GLOBALS['options']['mad4b_ccss_public_event_journal_v1']['events'])!==2)
    throw new RuntimeException('serial-journal-generation-did-not-resume');
echo "PASS admitted edits preserve consecutive journal generations\n";
$GLOBALS['force_lock_fail']=true;
$mark->invoke(null,'post:properties',444,false);
if (($GLOBALS['options']['mad4b_ccss_global_generation_v1']??0)!==2 ||
    $GLOBALS['options']['mad4b_ccss_public_dirty_v1']['generation']!==2)
    throw new RuntimeException('lock-timeout-must-fail-closed');
echo "PASS lock timeout invalidates prior certificates without forged journal\n";
