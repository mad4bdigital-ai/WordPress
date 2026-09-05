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
		$scope=$this->profiles->resolve( $parsed );
		$scope['authorizing']=true;
		$scope['evidence_only']=false;
		$scope['global_enabled']=true;
		return $scope;
	}

	/**
	 * Resolve the exact configured profile without opening the Global kill switch.
	 * This is for read-only Production dark-validation and publication planning only.
	 */
	public function evaluateForEvidence( array $parsed ): array {
		if ( empty( $parsed['active'] ) ) {
			$scope=$this->decision( false, false, 'inactive' );
		} else {
			$scope=$this->profiles->resolve( $parsed );
		}
		$scope['authorizing']=false;
		$scope['evidence_only']=true;
		$scope['global_enabled']=$this->config->enabled();
		return $scope;
	}

	private function decision( bool $inScope, bool $valid, string $reason ): array {
		return array(
			'in_scope'=>$inScope,
			'scope_valid'=>$valid,
			'reason'=>$reason,
			'profile_id'=>'',
			'profile'=>array(),
			'configuration_revision'=>$this->config->revision(),
			'authorizing'=>false,
			'evidence_only'=>false,
			'global_enabled'=>$this->config->enabled(),
		);
	}
}
