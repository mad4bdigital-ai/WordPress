<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string)$code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string)$value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-canonicalization.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL canonicalization-policy-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$p=MAD4B_SCP_Canonicalization::policy();
$check('mad4b.canonicalization-policy.v1'===$p['contract']&&false===$p['authorizing'],'canonicalization policy contract missing',$p);

$check('mad4b/content-update-post'===MAD4B_SCP_Canonicalization::ability_name('mad4b/content-update-post'),'canonical ability rejected');
$check(is_wp_error(MAD4B_SCP_Canonicalization::ability_name("m\\u{0430}d4b/content-update-post")),'Cyrillic confusable ability name accepted');
$check(is_wp_error(MAD4B_SCP_Canonicalization::ability_name('MAD4B/content-update-post')),'non-canonical uppercase ability alias accepted');
$check('content.publish'===MAD4B_SCP_Canonicalization::semantic_operation_id('content.publish'),'canonical semantic operation rejected');
$check(is_wp_error(MAD4B_SCP_Canonicalization::semantic_operation_id("content.\\u{FF50}ublish")),'full-width semantic operation confusable accepted');

$check('Provider:Obj_42'===MAD4B_SCP_Canonicalization::resource_identifier('Provider:Obj_42'),'canonical resource identifier rejected');
$check(is_wp_error(MAD4B_SCP_Canonicalization::resource_identifier("Provider:\\u{041E}bj")),'Unicode resource confusable accepted');

$check('uploads/2026/image.webp'===MAD4B_SCP_Canonicalization::relative_path('uploads/2026/image.webp'),'canonical relative path rejected');
foreach(array('../secret','uploads/%2e%2e/secret','uploads\\secret',"/absolute","uploads/\\u{200B}secret") as $bad){
	$check(is_wp_error(MAD4B_SCP_Canonicalization::relative_path($bad)),'path alias/confusable accepted',$bad);
}

$check('x-mad4b-request-id'===MAD4B_SCP_Canonicalization::header_name('X-MAD4B-Request-ID'),'header canonicalization failed');
$check(is_wp_error(MAD4B_SCP_Canonicalization::header_name("X-Test\r\nInjected")),'CRLF header alias accepted');

$check('https://example.com/a?x=1'===MAD4B_SCP_Canonicalization::url('HTTPS://Example.COM:443/a?x=1'),'URL canonicalization failed');
$check('http://example.com/'===MAD4B_SCP_Canonicalization::url('http://EXAMPLE.com:80/'),'default HTTP port canonicalization failed');
foreach(array('https://user:pass@example.com/a','https://example.com/a#frag','https://example.com/%2e%2e/admin',"https://ex\\u{0430}mple.com/") as $bad){
	$check(is_wp_error(MAD4B_SCP_Canonicalization::url($bad)),'unsafe/confusable URL accepted',$bad);
}

$a=MAD4B_SCP_Canonicalization::canonical_json(array('z'=>1,'a'=>array('text'=>'مرحبا','b'=>2,'a'=>1)));
$b=MAD4B_SCP_Canonicalization::canonical_json(array('a'=>array('a'=>1,'b'=>2,'text'=>'مرحبا'),'z'=>1));
$check(is_string($a)&&$a===$b,'canonical JSON is not deterministic across key order',array($a,$b));
$check(is_wp_error(MAD4B_SCP_Canonicalization::canonical_json(array('hidden'=>"a\\u{200B}b"))),'zero-width control entered canonical JSON hash material');
$check(is_wp_error(MAD4B_SCP_Canonicalization::canonical_json(array("bad\\u{202E}key"=>1))),'bidi control entered canonical JSON object key');
$check(is_wp_error(MAD4B_SCP_Canonicalization::canonical_json(array('float'=>1.2))),'float entered canonical JSON without explicit contract');

echo "mad4b.canonicalization-policy.runtime.v1: PASS\n";
