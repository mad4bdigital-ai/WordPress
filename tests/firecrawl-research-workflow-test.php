<?php
define( 'ABSPATH', '/tmp' );
$GLOBALS['allowed'] = false;
$GLOBALS['calls'] = 0;
$GLOBALS['abilities'] = array();
class WP_Error { private $code; public function __construct( $c ) { $this->code = $c; } public function get_error_code() { return $this->code; } }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Market_Growth_Policies {
    public static $rows = array();
    public static function current() { return array( 'competitors' => self::$rows ); }
}
class MAD4B_Research_Firecrawl_Bridge {
    public static function preview( $input ) {
        $GLOBALS['calls']++;
        return array(
            'preview' => "# Best Travel Deal\n## What is included?\nA sample offer costs USD 29.99 per adult.\n## Details\nBook a room for $120.00.",
            'truncated' => false,
            'metadata' => array( 'title' => 'Test Travel Offer', 'description' => 'Sample page description', 'language' => 'en' ),
        );
    }
}
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['allowed']; }
function add_action($event,$callback,$priority) { $GLOBALS['hook'] = $callback; }
function wp_has_ability($key) { return isset($GLOBALS['abilities'][$key]); }
function wp_register_ability($key,$args) { $GLOBALS['abilities'][$key] = $args; }
function is_wp_error($x) { return $x instanceof WP_Error; }
if (!function_exists('mb_substr')) { function mb_substr($v,$s,$len,$e) { return substr($v,$s,$len); } }
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-research-firecrawl-cycles.php';
call_user_func($GLOBALS['hook']);
assert(isset($GLOBALS['abilities']['mad4b/research-firecrawl-cycle-plan']));
assert(isset($GLOBALS['abilities']['mad4b/research-firecrawl-cycle-observe']));
$cls='MAD4B_Firecrawl_Research_Cycles';
assert(is_wp_error($cls::plan()));
assert(is_wp_error($cls::observe(array('url'=>'https://example.com','demo'=>true))));
assert($GLOBALS['calls']===0);
$GLOBALS['allowed']=true;
$plan=$cls::plan();
assert($plan['ready_for_competitor_collection']===false);
assert(count($plan['stages'])>=6);
assert(is_wp_error($cls::observe(array('url'=>'https://unapproved.example.com'))));
assert(is_wp_error($cls::observe(array('url'=>'http://example.com','demo'=>true))));
assert(is_wp_error($cls::observe(array('url'=>'https://localhost','demo'=>true))));
assert(is_wp_error($cls::observe(array('url'=>'https://example.com:8443','demo'=>true))));
assert(is_wp_error($cls::observe(array('url'=>'https://example.com','demo'=>true,'competitor_id'=>'other'))));
assert(is_wp_error($cls::observe(array('url'=>'https://example.com','competitor_id'=>'other'))));
assert($GLOBALS['calls']===0);
$demo=$cls::observe(array('url'=>'https://example.com','demo'=>true));
assert(!is_wp_error($demo));
assert($GLOBALS['calls']===1);
assert($demo['published']===false && $demo['retained_as_research_artifact']===false);
assert($demo['seo']['headings'][0]['text']==='Best Travel Deal');
assert($demo['seo']['title_candidate']==='Test Travel Offer');
assert($demo['seo']['meta_description_candidate']==='Sample page description');
assert($demo['seo']['search_ranking_verified']===false);
assert(count($demo['pricing']['price_mentions'])===2);
assert($demo['pricing']['price_mentions'][0]['comparison_ready']===false);
assert($demo['robots_policy_verdict']==='not_verified');
MAD4B_SCP_Market_Growth_Policies::$rows = array(
    'my-competitor'=>array('source_url'=>'https://example.com','disabled'=>false),
    'other'=>array('source_url'=>'https://elsewhere.com','disabled'=>false),
    'disabled'=>array('source_url'=>'https://example.com','disabled'=>true),
);
$plan=$cls::plan();
assert($plan['ready_for_competitor_collection']===true);
assert(count($plan['enrolled_competitors'])===2);
assert(is_wp_error($cls::observe(array('url'=>'https://example.com','competitor_id'=>'other'))));
assert(is_wp_error($cls::observe(array('url'=>'https://example.com','competitor_id'=>'disabled'))));
assert($GLOBALS['calls']===1);
$live=$cls::observe(array('url'=>'https://example.com/products','competitor_id'=>'my-competitor','lane'=>'pricing'));
assert(!is_wp_error($live));
assert($live['seo']===null && $live['content']===null);
assert(count($live['pricing']['price_mentions'])===2);
assert($live['used_to_change_website_price']===false);
assert($GLOBALS['calls']===2);
echo "mad4b-firecrawl-research-cycles: PASS\n";
