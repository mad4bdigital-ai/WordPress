<?php
/** Standalone PHP 7.4+ ACI01 profile-scope validation scenarios. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
final class MAD4B_SCP_Site_Profile {
	public static function site_uuid() { return '11111111-2222-3333-4444-555555555555'; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-content-experience-recipe-scope.php';
function assert_recipe( $ok, $reason ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $reason . PHP_EOL ); exit( 1 ); } }
function invalid_recipe( $rows, $code ) {
	$out = MAD4B_SCP_Content_Experience_Recipe_Scope::normalize( $rows );
	assert_recipe( is_wp_error( $out ) && $out->get_error_code() === $code, $code );
}
$uuid = MAD4B_SCP_Site_Profile::site_uuid();
$one = array('site_uuid'=>$uuid, 'brand_id'=>'all_royal', 'locale'=>'ar-EG', 'market'=>'eg', 'requirements'=>array('seo_title','image_alt'));
$two = array('site_uuid'=>$uuid, 'brand_id'=>'all_royal', 'locale'=>'en', 'market'=>'EG', 'requirements'=>array('seo_title'));
$out = MAD4B_SCP_Content_Experience_Recipe_Scope::normalize( array($one,$two) );
assert_recipe( is_array( $out ) && count($out)===2 && $out[0]['market']==='EG', 'normalized case and deterministic order' );
assert_recipe( $out[0]['requirements'][0]==='image_alt', 'requirements sorted' );
assert_recipe( array() === MAD4B_SCP_Content_Experience_Recipe_Scope::normalize( array() ), 'empty accepted' );
invalid_recipe( 'invalid', 'mad4b_aci01_recipe_registry_invalid' );
invalid_recipe( array_fill(0,25,$one), 'mad4b_aci01_recipe_registry_invalid' );
invalid_recipe( array($one,$one), 'mad4b_aci01_recipe_duplicate_scope' );
invalid_recipe( array(array_merge($one,array('unknown'=>true))), 'mad4b_aci01_recipe_fields_invalid' );
invalid_recipe( array(array_merge($one,array('site_uuid'=>'99999999-2222-3333-4444-555555555555'))), 'mad4b_aci01_recipe_scope_invalid' );
invalid_recipe( array(array_merge($one,array('locale'=>'../../etc'))), 'mad4b_aci01_recipe_scope_invalid' );
invalid_recipe( array(array_merge($one,array('requirements'=>array('same','same')))), 'mad4b_aci01_recipe_requirement_invalid' );
invalid_recipe( array(array_merge($one,array('requirements'=>array()))), 'mad4b_aci01_recipe_scope_invalid' );
echo "PASS: Content Experience Recipe Scope 11 native cases" . PHP_EOL;
