<?php
/**
 * Standalone regression for the exact-artifact-gated WP All Import read bootstrap.
 * No WordPress state, provider job, filesystem scan or mutation is performed.
 */
define( 'ABSPATH', __DIR__ . '/' );

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B WP Import readonly bootstrap] ' . $message . PHP_EOL );
	exit( 1 );
};

abstract class MAD4B_SCP_Adapter_Base {}

final class MAD4B_SCP_Provider_Contracts {
	public static $certified = false;
	public static function runtime_status( $provider, $available = null ) {
		$integrity = array(
			'required' => true,
			'manifest_present' => true,
			'verified' => array( 'models/import/list.php', 'models/import/record.php', 'wp-all-import-pro.php' ),
			'missing' => array(),
			'mismatched' => array(),
		);
		return array(
			'provider' => $provider,
			'status' => self::$certified ? 'certified' : 'component_drift',
			'runtime_contract_ok' => self::$certified,
			'components' => array(
				'import' => array(
					'status' => self::$certified ? 'certified' : 'version_drift',
					'runtime_integrity' => $integrity,
				),
			),
		);
	}
}

final class PMXI_Plugin {
	public static $autoloaded = array();
	private static $instance;
	public static function getInstance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}
	public function autoload( $class ) {
		self::$autoloaded[] = $class;
		if ( 'PMXI_Model' === $class ) eval( 'class PMXI_Model extends ArrayObject {}' );
		elseif ( 'PMXI_Model_Record' === $class ) eval( 'class PMXI_Model_Record extends PMXI_Model {}' );
		elseif ( 'PMXI_Model_List' === $class ) eval( 'class PMXI_Model_List extends PMXI_Model {}' );
		elseif ( 'PMXI_Import_Record' === $class ) eval( 'class PMXI_Import_Record extends PMXI_Model_Record {}' );
		elseif ( 'PMXI_Import_List' === $class ) eval( 'class PMXI_Import_List extends PMXI_Model_List {}' );
		else $fail = 'unexpected';
	}
}

require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-wp-import-export-adapter.php';

$adapter = new MAD4B_SCP_WP_Import_Export_Adapter();

if ( $adapter->is_available() ) $fail( 'Uncertified fixture unexpectedly became available.' );
if ( ! empty( PMXI_Plugin::$autoloaded ) ) $fail( 'Provider autoload executed before exact artifact certification.' );
if ( class_exists( 'PMXI_Import_Record', false ) || class_exists( 'PMXI_Import_List', false ) ) $fail( 'Import model classes loaded before exact artifact certification.' );

MAD4B_SCP_Provider_Contracts::$certified = true;
if ( ! $adapter->is_available() ) $fail( 'Exact-certified fixture did not become available.' );

$expected = array( 'PMXI_Model', 'PMXI_Model_Record', 'PMXI_Model_List', 'PMXI_Import_Record', 'PMXI_Import_List' );
if ( $expected !== PMXI_Plugin::$autoloaded ) $fail( 'Provider autoload class order/allowlist drifted.' );
foreach ( $expected as $class ) if ( ! class_exists( $class, false ) ) $fail( 'Expected class was not loaded: ' . $class );

echo "mad4b.wp-import-export-readonly-bootstrap.runtime.v1: PASS\n";
