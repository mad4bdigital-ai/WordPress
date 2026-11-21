<?php
/** No WordPress/DB/network: tests original date helper through stable fake native APIs. */
declare(strict_types=1);
define('ABSPATH','/fake/wp/');
class WP_Term {
 public int $term_id;
 public function __construct(int $id){$this->term_id=$id;}
}
$GLOBALS['rate_meta']=[];
$GLOBALS['rate_terms']=[];
$GLOBALS['season_ranges']=[];
function absint($v):int {return abs((int)$v);}
function get_post_type($id):string {return (int)$id===1?'tours-and-activities':'tour-rates';}
function get_post_meta($id,$key,$single=true) {
 return $GLOBALS['rate_meta'][(int)$id][$key]??'';
}
function wp_get_post_terms($id,$taxonomy,$args=[]) {
 if($taxonomy!=='season')throw new Exception('Unexpected taxonomy');
 return $GLOBALS['rate_terms'][(int)$id]??[];
}
function is_wp_error($value):bool {return false;}
final class MAD4B_JetEngine_Rate_Season_Term_Matcher_V1 {
 public static function window(WP_Term $term,string $from,string $until):?array {
  foreach(($GLOBALS['season_ranges'][$term->term_id]??[]) as [$a,$b]) {
   if($a<=$from && $until<=$b)
    return ['window_start'=>$a,'window_end'=>$b,'policy_term_id'=>$term->term_id];
  }
  return null;
 }
}
require __DIR__.'/../wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/rate-date-eligibility-readonly-v1.php';
$tests=0;
function assert_rate(bool $value,bool $expected,string $message):void {
 global $tests;
 $tests++;
 if($value!==$expected)throw new RuntimeException('FAIL: '.$message.' expected='.($expected?'true':'false').' got='.($value?'true':'false'));
}
function dates($id,$from,$to):void {
 $GLOBALS['rate_meta'][$id]=['tour_rate_start_date'=>$from,'tour_rate_end_date'=>$to];
}
function assign($id,array $terms):void {
 $GLOBALS['rate_terms'][$id]=array_map(static fn($v)=>new WP_Term($v),$terms);
}
function range_pass(int $id,string $from,string $to):bool {
 return shared_product_booking_v2_rate_covers_window($id,$from,$to);
}
// Explicit period remains authoritative even when a conflicting Season exists.
dates(101,strtotime('2026-11-01 00:00:00 UTC'),strtotime('2026-11-30 00:00:00 UTC'));
assign(101,[22]);
assert_rate(range_pass(101,'2026-11-01','2026-11-30'),true,'explicit inclusive boundaries');
assert_rate(range_pass(101,'2026-10-31','2026-11-30'),false,'explicit before');
assert_rate(range_pass(101,'2026-11-02','2026-12-01'),false,'explicit after');
assert_rate(shared_product_booking_v2_rate_covers_date(101,'2026-11-01'),true,'single explicit day');
assert_rate(range_pass(101,'2026-11-30','2026-11-01'),false,'reverse interval');
assert_rate(range_pass(101,'2026-11-05','2026-13-01'),false,'invalid end date not accepted by string ordering');
// A partial or invalid explicit override must never silently inherit a Season.
dates(102,strtotime('2026-12-01 00:00:00 UTC'),'');
assign(102,[22]);
assert_rate(range_pass(102,'2026-12-15','2026-12-20'),false,'partial explicit period fails');
dates(103,'2026-12-20','2026-12-01');
assign(103,[22]);
assert_rate(range_pass(103,'2026-12-15','2026-12-20'),false,'reversed explicit period fails');
// Native Season contract may be inherited only with exactly one assigned term.
dates(104,'','');
assign(104,[22]);
$GLOBALS['season_ranges'][22]=[
 ['2026-12-01','2027-02-28'],
 ['2027-12-01','2028-02-29']
];
assert_rate(range_pass(104,'2026-12-01','2027-02-28'),true,'winter cross-year single season');
assert_rate(range_pass(104,'2026-11-30','2026-12-03'),false,'outside start');
assert_rate(range_pass(104,'2027-02-27','2027-03-01'),false,'outside end');
assert_rate(range_pass(104,'2026-12-15','2028-01-02'),false,'two separate winter seasons with summer gap');
assert_rate(shared_product_booking_v2_rate_covers_date(104,'2028-02-29'),true,'leap-day within native term');
assert_rate(range_pass(104,'2027-12-23','2028-02-29'),true,'later single winter instance');
assert_rate(range_pass(104,'2027-12-23','2028-03-01'),false,'later winter cannot extend after window');
assign(105,[22,33]);dates(105,'','');
assert_rate(range_pass(105,'2026-12-15','2026-12-20'),false,'ambiguous two assigned Seasons');
assign(106,[]);dates(106,'','');
assert_rate(range_pass(106,'2026-12-15','2026-12-20'),false,'missing season assignment');
assign(107,[33]);dates(107,'','');
assert_rate(range_pass(107,'2026-12-15','2026-12-20'),false,'disabled or unrelated season');
assert_rate(range_pass(1,'2026-12-15','2026-12-20'),false,'not a Tour Rate');
assert_rate(range_pass(104,'2027-02-29','2027-02-29'),false,'invalid leap date');
assert_rate(range_pass(104,'2026-12-15','2026-xx-20'),false,'invalid target date');
assert_rate(range_pass(104,'2026-12-15','2027-01-15'),true,'inherited date coverage includes all interval days');
echo "PASS $tests/$tests hermetic Rate Season inheritance and full-window fail-closed checks".PHP_EOL;
$planner=file_get_contents(__DIR__.'/../wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/rate-planner.php');
if(substr_count($planner,'shared_product_booking_v2_rate_covers_window(')!==2)
 throw new RuntimeException('Original Rate Planner must use same bounded window helper in its two readback flows');
echo 'PASS Rate Planner uses original centralized full-window helper in both source paths'.PHP_EOL;
