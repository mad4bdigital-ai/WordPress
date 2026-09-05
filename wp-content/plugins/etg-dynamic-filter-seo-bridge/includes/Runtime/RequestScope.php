<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;

final class RequestScope {
	private $config;
	private $profiles;
	public function __construct( Configuration $config, ProfileRegistry $profiles ) { $this->config=$config; $this->profiles=$profiles; }
	public function evaluate( array $parsed ): array {
		if ( empty( $parsed['active'] ) ) { return $this->decision( false, false, 'inactive' ); }
		if ( ! $this->config->enabled() ) { return $this->decision( false, false, 'disabled' ); }
		return $this->profiles->resolve( $parsed );
	}
	private function decision( bool $inScope, bool $valid, string $reason ): array {
		return array( 'in_scope'=>$inScope, 'scope_valid'=>$valid, 'reason'=>$reason, 'profile_id'=>'', 'profile'=>array(), 'configuration_revision'=>$this->config->revision() );
	}
}
