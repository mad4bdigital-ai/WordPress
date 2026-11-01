<?php
/** QA31 isolated source-execution regression for catalog intent planning. No WordPress bootstrap or mutation. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['qa31_read_allowed'] = false;
$GLOBALS['qa31_registered'] = [];
final class MAD4B_SCP_Policy {
    public static function can_read(): bool {return $GLOBALS['qa31_read_allowed'];}
}
final class WP_Error {
    private $code;
    public function __construct($code, $message='', $data=[]) {$this->code=$code;}
    public function get_error_code(): string {return $this->code;}
}
function add_action($name,$callback,$priority=10): void {}
function wp_register_ability($id,$settings): void {$GLOBALS['qa31_registered'][$id]=$settings;}
function wp_has_ability($id): bool {return false;}
function qa31_assert($ok,$label): void {if (!$ok) throw new RuntimeException($label);}
require dirname(__DIR__) . '/includes/class-mad4b-scp-catalog-intent-policy.php';
MAD4B_SCP_Catalog_Intent_Policy::register();
$ability = $GLOBALS['qa31_registered']['mad4b/catalog-intent-plan'] ?? null;
qa31_assert(is_array($ability), 'ability registration missing');
qa31_assert(($ability['meta']['annotations']['readonly'] ?? false) === true, 'not read-only');
qa31_assert(($ability['meta']['public'] ?? true) === false, 'ability unexpectedly public');
qa31_assert(($ability['input_schema']['additionalProperties'] ?? true) === false, 'unknown input allowed');
$link = ['url'=>'https://example.org/tours/luxor?utm_source=test','title'=>'Luxor','entity_type'=>'individual_tour','discovery_context'=>'listing_card','intent_relation'=>'direct'];
$input = ['catalog_url'=>'https://example.org/tours','intent'=>'tour_catalog','links'=>[$link]];
$denied = MAD4B_SCP_Catalog_Intent_Policy::plan($input);
qa31_assert($denied instanceof WP_Error && $denied->get_error_code()==='mad4b_catalog_intent_permission_denied','missing read permission denied');
$GLOBALS['qa31_read_allowed']=true;
$ok = MAD4B_SCP_Catalog_Intent_Policy::plan($input);
qa31_assert(is_array($ok) && ($ok['counts']['primary'] ?? 0)===1, 'primary source card not planned');
foreach (['task_creation_performed','wordpress_mutation_performed','publication_authorized','third_party_content_copied'] as $key) {
    qa31_assert(($ok[$key]??null)===false, 'unexpected state '.$key);
}
qa31_assert(($ok['network_requests_performed']??null)===0,'network requests performed');
qa31_assert(MAD4B_SCP_Catalog_Intent_Policy::canonical_url('http://example.org/tour')==='', 'HTTP URL admitted');
qa31_assert(MAD4B_SCP_Catalog_Intent_Policy::canonical_url('https://127.0.0.1/private')==='', 'IP URL admitted');
qa31_assert(MAD4B_SCP_Catalog_Intent_Policy::canonical_url('https://user:pass@example.org/x')==='', 'credentials admitted');
$invalid=$input;$invalid['unknown']='not allowed';
qa31_assert(MAD4B_SCP_Catalog_Intent_Policy::plan($invalid) instanceof WP_Error,'unknown input admitted');
$invalid=$input;$invalid['links']=array_fill(0,201,$link);
qa31_assert(MAD4B_SCP_Catalog_Intent_Policy::plan($invalid) instanceof WP_Error,'over-limit links admitted');
$invalid=$input;$invalid['links'][0]['url']='http://example.org/private';
qa31_assert(MAD4B_SCP_Catalog_Intent_Policy::plan($invalid) instanceof WP_Error,'insecure link admitted');
echo "QA31_CATALOG_INTENT_RUNTIME: PASS\n";
