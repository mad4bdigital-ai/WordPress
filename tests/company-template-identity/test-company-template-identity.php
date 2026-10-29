<?php
/**
 * Runtime-independent contract tests for MU company template name.
 * Run: php tests/company-template-identity/test-company-template-identity.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$GLOBALS['mad4b_test_option_name'] = 'Wo Vacation';
$GLOBALS['mad4b_test_hooks'] = array();

function get_option( $name, $fallback = false ) {
	return 'blogname' === $name ? $GLOBALS['mad4b_test_option_name'] : $fallback;
}
function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}
function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
}
function add_shortcode( $name, $handler ) {
	$GLOBALS['mad4b_test_hooks']['shortcode'][ $name ] = $handler;
}
function add_filter( $name, $handler, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['mad4b_test_hooks']['filter'][ $name ] = $handler;
}
function assert_same( $expect, $actual, $label ) {
	if ( $expect !== $actual ) {
		fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
		exit( 1 );
	}
}
class Demo_Widget {
	private $name;
	public function __construct( $name ) { $this->name = $name; }
	public function get_name() { return $this->name; }
}

require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/mad4b-company-template-identity.php';

assert_same(
	'Wo Vacation',
	mad4b_company_template_public_name(),
	'Public name comes from the current site'
);
$original = '<p>Here, at [site_title][copyright], we prioritize your well-being.</p>';
$expected = '<p>Here, at Wo Vacation, we prioritize your well-being.</p>';
assert_same(
	$expected,
	mad4b_company_template_legacy_label( $original ),
	'Exact legacy pair replaced without markup or punctuation drift'
);
assert_same(
	$expected,
	call_user_func( $GLOBALS['mad4b_test_hooks']['filter']['elementor/widget/render_content'], $original, new Demo_Widget( 'text-editor' ) ),
	'Only text editor applies replacement'
);
assert_same(
	$original,
	call_user_func( $GLOBALS['mad4b_test_hooks']['filter']['elementor/widget/render_content'], $original, new Demo_Widget( 'heading' ) ),
	'Heading output unchanged'
);
assert_same(
	$original,
	call_user_func( $GLOBALS['mad4b_test_hooks']['filter']['elementor/widget/render_content'], $original, new Demo_Widget( 'nested-tabs' ) ),
	'Nested tabs remain untouched'
);
assert_same(
	'Wo Vacation',
	call_user_func( $GLOBALS['mad4b_test_hooks']['shortcode']['mad4b_company_name'] ),
	'Explicit token works'
);
assert_same(
	'<p>© [copyright] — [site_title]</p>',
	mad4b_company_template_legacy_label( '<p>© [copyright] — [site_title]</p>' ),
	'Standalone tokens and unrelated copyright remain untouched'
);
$GLOBALS['mad4b_test_option_name'] = 'Other Company';
assert_same(
	'<p>Here, at Other Company, we prioritize your well-being.</p>',
	mad4b_company_template_legacy_label( $original ),
	'Second company receives its OWN site name, not Wo Vacation'
);
$GLOBALS['mad4b_test_option_name'] = '<script>bad</script>Only & Co';
assert_same(
	'<p>Here, at badOnly &amp; Co, we prioritize your well-being.</p>',
	mad4b_company_template_legacy_label( $original ),
	'Untrusted public name escaped'
);
$GLOBALS['mad4b_test_option_name'] = '';
assert_same(
	'<p>Here, at , we prioritize your well-being.</p>',
	mad4b_company_template_legacy_label( $original ),
	'Blank site name cannot fall back to another company'
);
echo "PASS: 10 company template identity and design-safe behavior checks\n";
