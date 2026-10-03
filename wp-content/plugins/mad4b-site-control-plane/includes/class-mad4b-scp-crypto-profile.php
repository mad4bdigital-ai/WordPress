<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Versioned receipt-signing crypto profiles.
 *
 * Private keys live only in an explicitly configured absolute directory outside
 * WordPress/document roots. The keyring manifest contains metadata + public PEM
 * only; private PEM files are separate 0600 files and never stored in WordPress.
 */
final class MAD4B_SCP_Crypto_Profile {
	const CONTRACT = 'mad4b.crypto-profiles.v1';
	const KEYRING_CONTRACT = 'mad4b.crypto-keyring.v1';
	const SIGNATURE_CONTRACT = 'mad4b.detached-signature.v1';
	private static $profiles = null;

	public static function clear_cache() { self::$profiles = null; }

	public static function default_profile( $purpose ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		$purpose = sanitize_key( (string) $purpose );
		$id = isset( $catalog['default_profiles'][ $purpose ] ) ? sanitize_key( (string) $catalog['default_profiles'][ $purpose ] ) : '';
		if ( '' === $purpose || '' === $id ) return new WP_Error( 'mad4b_crypto_default_profile_missing', 'No default cryptographic profile is configured for this purpose.' );
		$profile = self::profile( $id );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( ! hash_equals( $purpose, sanitize_key( isset( $profile['purpose'] ) ? (string) $profile['purpose'] : '' ) ) ) {
			return new WP_Error( 'mad4b_crypto_default_profile_purpose_mismatch', 'Default cryptographic profile is not bound to the requested receipt purpose.' );
		}
		return $id;
	}

	public static function profile( $profile_id ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		$profile_id = sanitize_key( (string) $profile_id );
		if ( '' === $profile_id || empty( $catalog['profiles'][ $profile_id ] ) || ! is_array( $catalog['profiles'][ $profile_id ] ) ) {
			return new WP_Error( 'mad4b_crypto_profile_unknown', 'Cryptographic profile is not registered.' );
		}
		$profile = $catalog['profiles'][ $profile_id ];
		$profile['profile_id'] = $profile_id;
		if ( empty( $profile['enabled'] ) ) return new WP_Error( 'mad4b_crypto_profile_disabled', 'Cryptographic profile is disabled.' );
		$alg = isset( $profile['algorithm'] ) ? strtoupper( trim( (string) $profile['algorithm'] ) ) : '';
		if ( ! in_array( $alg, array( 'RS256', 'RS512' ), true ) ) return new WP_Error( 'mad4b_crypto_algorithm_unsupported', 'Cryptographic profile algorithm is unsupported.' );
		$profile['algorithm'] = $alg;
		$profile['key_bits'] = max( 2048, min( 4096, (int) $profile['key_bits'] ) );
		$profile['overlap_seconds'] = max( 0, min( 604800, (int) $profile['overlap_seconds'] ) );
		$profile['max_active_keys'] = max( 1, min( 4, (int) $profile['max_active_keys'] ) );
		$profile['profile_sha256'] = self::digest( array(
			'profile_id'=>$profile_id,'purpose'=>(string)$profile['purpose'],'algorithm'=>$alg,
			'key_bits'=>$profile['key_bits'],'overlap_seconds'=>$profile['overlap_seconds'],
			'max_active_keys'=>$profile['max_active_keys'],
		) );
		$profile['authorizing'] = false;
		return $profile;
	}

	public static function provision_for_lifecycle( $profile_id ) {
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		$manifest = self::manifest( $profile_id );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$current = isset( $manifest['current_kid'] ) ? strtolower( trim( (string) $manifest['current_kid'] ) ) : '';
		if ( '' !== $current && isset( $manifest['keys'][ $current ] ) && empty( $manifest['keys'][ $current ]['revoked_at'] ) ) {
			return self::status( $profile_id );
		}
		return self::rotate( $profile_id );
	}

	public static function rotate( $profile_id ) {
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		$dir = self::keyring_dir();
		if ( is_wp_error( $dir ) ) return $dir;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_crypto_keyring_directory_unavailable', 'Unable to create receipt keyring directory.' );
		@chmod( $dir, 0700 );
		$dir = self::keyring_dir();
		if ( is_wp_error( $dir ) ) return $dir;

		return self::with_keyring_lock( $profile_id, static function () use ( $profile_id, $profile, $dir ) {
			$manifest = self::manifest( $profile_id );
			if ( is_wp_error( $manifest ) ) return $manifest;
			$expected_revision = (int) $manifest['revision'];

			$private = openssl_pkey_new( array( 'private_key_bits'=>$profile['key_bits'], 'private_key_type'=>OPENSSL_KEYTYPE_RSA ) );
			if ( false === $private ) return new WP_Error( 'mad4b_crypto_key_generation_failed', 'Unable to generate receipt signing key.' );
			$pem = '';
			if ( ! openssl_pkey_export( $private, $pem ) || '' === $pem ) return new WP_Error( 'mad4b_crypto_key_export_failed', 'Unable to export receipt signing key.' );
			$details = openssl_pkey_get_details( $private );
			if ( ! is_array( $details ) || empty( $details['key'] ) || empty( $details['bits'] ) || (int)$details['bits'] < $profile['key_bits'] ) {
				return new WP_Error( 'mad4b_crypto_public_key_unavailable', 'Unable to derive receipt public key.' );
			}
			$public_pem = (string) $details['key'];
			$kid = hash( 'sha256', self::SIGNATURE_CONTRACT . '|' . $profile_id . '|' . $public_pem );
			$private_file = 'private-' . $profile_id . '-' . $kid . '.pem';
			$private_path = trailingslashit( $dir ) . $private_file;
			$write = self::atomic_private_write( $private_path, $pem );
			if ( is_wp_error( $write ) ) return $write;

			$now = self::now_epoch();
			$old = isset( $manifest['current_kid'] ) ? strtolower( trim( (string)$manifest['current_kid'] ) ) : '';
			if ( '' !== $old && isset( $manifest['keys'][ $old ] ) ) {
				$manifest['keys'][ $old ]['signing_not_after'] = $now;
				$manifest['keys'][ $old ]['overlap_until'] = $now + $profile['overlap_seconds'];
				$manifest['keys'][ $old ]['state'] = 'overlap';
			}
			$manifest['keys'][ $kid ] = array(
				'kid'=>$kid,'profile_id'=>$profile_id,'algorithm'=>$profile['algorithm'],
				'public_key_pem'=>$public_pem,'private_key_file'=>$private_file,
				'created_at'=>$now,'not_before'=>$now,'signing_not_after'=>0,'overlap_until'=>0,
				'revoked_at'=>0,'state'=>'current'
			);
			$manifest['current_kid'] = $kid;
			$manifest['revision'] = $expected_revision + 1;
			$manifest['updated_at'] = $now;
			self::retire_excess_keys( $manifest, $profile );
			$saved = self::write_manifest( $profile_id, $manifest, $expected_revision );
			if ( is_wp_error( $saved ) ) {
				@unlink( $private_path );
				return $saved;
			}
			return self::status( $profile_id );
		} );
	}

	public static function revoke( $profile_id, $kid ) {
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		$kid = strtolower( trim( (string) $kid ) );
		return self::with_keyring_lock( $profile_id, static function () use ( $profile_id, $kid ) {
			$manifest = self::manifest( $profile_id );
			if ( is_wp_error( $manifest ) ) return $manifest;
			$expected_revision = (int) $manifest['revision'];
			if ( empty( $manifest['keys'][ $kid ] ) ) return new WP_Error( 'mad4b_crypto_key_unknown', 'Receipt signing key is not registered.' );
			$manifest['keys'][ $kid ]['revoked_at'] = self::now_epoch();
			$manifest['keys'][ $kid ]['state'] = 'revoked';
			if ( isset( $manifest['current_kid'] ) && hash_equals( (string)$manifest['current_kid'], $kid ) ) $manifest['current_kid'] = '';
			$manifest['revision'] = $expected_revision + 1;
			$manifest['updated_at'] = self::now_epoch();
			$saved = self::write_manifest( $profile_id, $manifest, $expected_revision );
			if ( is_wp_error( $saved ) ) return $saved;
			return self::status( $profile_id );
		} );
	}

	public static function sign_digest_for_purpose( $purpose, $payload_sha256 ) {
		$profile_id = self::default_profile( $purpose );
		if ( is_wp_error( $profile_id ) ) return $profile_id;
		return self::sign_digest( $profile_id, $payload_sha256 );
	}

	public static function sign_digest( $profile_id, $payload_sha256 ) {
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		$payload_sha256 = strtolower( trim( (string) $payload_sha256 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload_sha256 ) ) return new WP_Error( 'mad4b_crypto_payload_digest_invalid', 'Receipt signing requires a canonical SHA-256 digest.' );
		$manifest = self::manifest( $profile_id );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$kid = isset( $manifest['current_kid'] ) ? strtolower( trim( (string)$manifest['current_kid'] ) ) : '';
		if ( '' === $kid || empty( $manifest['keys'][ $kid ] ) || ! empty( $manifest['keys'][ $kid ]['revoked_at'] ) ) {
			$provisioned = self::provision_for_lifecycle( $profile_id );
			if ( is_wp_error( $provisioned ) ) return $provisioned;
			$manifest = self::manifest( $profile_id );
			if ( is_wp_error( $manifest ) ) return $manifest;
			$kid = isset( $manifest['current_kid'] ) ? strtolower( trim( (string)$manifest['current_kid'] ) ) : '';
			if ( '' === $kid || empty( $manifest['keys'][ $kid ] ) || ! empty( $manifest['keys'][ $kid ]['revoked_at'] ) ) {
				return new WP_Error( 'mad4b_crypto_current_key_unavailable', 'Current receipt signing key remains unavailable after bounded lifecycle convergence.' );
			}
		}
		$key = $manifest['keys'][ $kid ];
		if ( ! hash_equals( $profile['algorithm'], (string)$key['algorithm'] ) ) return new WP_Error( 'mad4b_crypto_key_algorithm_mismatch', 'Receipt signing key algorithm does not match its profile.' );
		$dir = self::keyring_dir();
		if ( is_wp_error( $dir ) ) return $dir;
		$path = trailingslashit( $dir ) . basename( (string)$key['private_key_file'] );
		if ( is_link( $path ) ) return new WP_Error( 'mad4b_crypto_private_key_symlink_denied', 'Receipt private signing key may not be a symbolic link.' );
		if ( ! is_readable( $path ) ) return new WP_Error( 'mad4b_crypto_private_key_unavailable', 'Receipt private signing key is unavailable.' );
		$pem = file_get_contents( $path );
		if ( ! is_string( $pem ) || strlen( $pem ) > 32768 || false === strpos( $pem, 'PRIVATE KEY' ) ) return new WP_Error( 'mad4b_crypto_private_key_invalid', 'Receipt private signing key is invalid.' );
		$private = openssl_pkey_get_private( $pem );
		if ( false === $private ) return new WP_Error( 'mad4b_crypto_private_key_invalid', 'Receipt private signing key cannot be loaded.' );
		$signature = '';
		$openssl_alg = self::openssl_algorithm( $profile['algorithm'] );
		if ( false === $openssl_alg || ! openssl_sign( $payload_sha256, $signature, $private, $openssl_alg ) ) {
			return new WP_Error( 'mad4b_crypto_sign_failed', 'Unable to sign execution receipt digest.' );
		}
		return array(
			'contract'=>self::SIGNATURE_CONTRACT,'profile_id'=>$profile_id,'profile_sha256'=>$profile['profile_sha256'],
			'algorithm'=>$profile['algorithm'],'kid'=>$kid,'signed_sha256'=>$payload_sha256,
			'signed_at'=>self::now_epoch(),'signature_b64url'=>self::b64url( $signature ),'authorizing'=>false
		);
	}

	public static function verify_digest( array $signature, $payload_sha256 ) {
		$payload_sha256 = strtolower( trim( (string) $payload_sha256 ) );
		if ( self::SIGNATURE_CONTRACT !== ( isset($signature['contract'])?(string)$signature['contract']:'' ) ) return new WP_Error( 'mad4b_crypto_signature_contract_invalid', 'Receipt signature contract is invalid.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload_sha256 ) || ! hash_equals( $payload_sha256, strtolower((string)$signature['signed_sha256']) ) ) {
			return new WP_Error( 'mad4b_crypto_signature_payload_mismatch', 'Receipt signature is not bound to the exact receipt digest.' );
		}
		$profile_id = sanitize_key( isset($signature['profile_id'])?(string)$signature['profile_id']:'' );
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( ! hash_equals( $profile['profile_sha256'], strtolower((string)$signature['profile_sha256']) ) || ! hash_equals( $profile['algorithm'], strtoupper((string)$signature['algorithm']) ) ) {
			return new WP_Error( 'mad4b_crypto_signature_profile_mismatch', 'Receipt signature profile or algorithm no longer matches the certified profile.' );
		}
		$manifest = self::manifest( $profile_id );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$kid = strtolower( trim( (string)$signature['kid'] ) );
		if ( empty( $manifest['keys'][ $kid ] ) ) return new WP_Error( 'mad4b_crypto_signature_key_unknown', 'Receipt signature key is unknown.' );
		$key = $manifest['keys'][ $kid ];
		if ( ! empty( $key['revoked_at'] ) ) return new WP_Error( 'mad4b_crypto_signature_key_revoked', 'Receipt signature key is revoked.' );
		if ( isset( $key['state'] ) && 'retired' === (string) $key['state'] ) return new WP_Error( 'mad4b_crypto_signature_key_retired', 'Receipt signature key has been retired from the active verification set.' );
		$signed_at = isset($signature['signed_at'])?(int)$signature['signed_at']:0;
		if(class_exists('MAD4B_SCP_Time_Policy')){$tc=MAD4B_SCP_Time_Policy::assert_timestamp('crypto_signature',$signed_at);if(is_wp_error($tc))return$tc;}
		if ( $signed_at < (int)$key['not_before'] || ( ! empty($key['signing_not_after']) && $signed_at > (int)$key['signing_not_after'] ) ) {
			return new WP_Error( 'mad4b_crypto_signature_time_invalid', 'Receipt signature time is outside the key signing interval.' );
		}
		$current_kid = isset( $manifest['current_kid'] ) ? strtolower( trim( (string) $manifest['current_kid'] ) ) : '';
		if ( '' !== $current_kid && ! hash_equals( $current_kid, $kid ) && ! empty( $key['signing_not_after'] ) ) {
			$overlap_until = isset( $key['overlap_until'] ) ? (int) $key['overlap_until'] : 0;
			if ( $overlap_until < 1 || self::now_epoch() > $overlap_until ) {
				return new WP_Error( 'mad4b_crypto_signature_overlap_expired', 'Receipt signature key rotation overlap window has expired.' );
			}
		}
		return self::verify_with_public_key( $signature, $payload_sha256, self::public_key_block_from_key( $key, $profile ) );
	}

	public static function verify_digest_for_purpose( array $signature, $payload_sha256, $purpose ) {
		$purpose = sanitize_key( (string) $purpose );
		$profile_id = sanitize_key( isset( $signature['profile_id'] ) ? (string) $signature['profile_id'] : '' );
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( '' === $purpose || ! hash_equals( $purpose, sanitize_key( isset( $profile['purpose'] ) ? (string) $profile['purpose'] : '' ) ) ) {
			return new WP_Error( 'mad4b_crypto_signature_purpose_mismatch', 'Receipt signature profile is not valid for the requested receipt purpose.' );
		}
		$verified = self::verify_digest( $signature, $payload_sha256 );
		if ( is_wp_error( $verified ) ) return $verified;
		$verified['purpose'] = $purpose;
		return $verified;
	}

	public static function verify_with_public_key( array $signature, $payload_sha256, array $public_key ) {
		$payload_sha256 = strtolower( trim( (string)$payload_sha256 ) );
		if ( self::SIGNATURE_CONTRACT !== ( isset($signature['contract'])?(string)$signature['contract']:'' ) ) return new WP_Error( 'mad4b_crypto_signature_contract_invalid', 'Receipt signature contract is invalid.' );
		if ( ! hash_equals( $payload_sha256, strtolower((string)$signature['signed_sha256']) ) ) return new WP_Error( 'mad4b_crypto_signature_payload_mismatch', 'Receipt signature payload digest mismatch.' );
		foreach(array('profile_id','profile_sha256','algorithm','kid') as $field){
			if(empty($public_key[$field]) || !isset($signature[$field]) || !hash_equals((string)$public_key[$field],(string)$signature[$field])) return new WP_Error('mad4b_crypto_public_key_binding_mismatch','Public verification key does not match the receipt signature metadata.');
		}
		$pem = isset($public_key['public_key_pem'])?(string)$public_key['public_key_pem']:'';
		if(''===$pem||false===strpos($pem,'PUBLIC KEY'))return new WP_Error('mad4b_crypto_public_key_invalid','Public verification key is invalid.');
		$binary=self::b64url_decode(isset($signature['signature_b64url'])?(string)$signature['signature_b64url']:'');
		if(false===$binary)return new WP_Error('mad4b_crypto_signature_encoding_invalid','Receipt signature encoding is invalid.');
		$openssl_alg=self::openssl_algorithm((string)$signature['algorithm']);
		if(false===$openssl_alg)return new WP_Error('mad4b_crypto_algorithm_unsupported','Receipt signature algorithm is unsupported.');
		$public=openssl_pkey_get_public($pem);
		if(false===$public)return new WP_Error('mad4b_crypto_public_key_invalid','Public verification key cannot be loaded.');
		$ok=openssl_verify($payload_sha256,$binary,$public,$openssl_alg);
		return 1===$ok ? array(
			'contract'=>'mad4b.detached-signature-verification.v1','valid'=>true,'profile_id'=>(string)$signature['profile_id'],
			'algorithm'=>(string)$signature['algorithm'],'kid'=>(string)$signature['kid'],'signed_sha256'=>$payload_sha256,'authorizing'=>false
		) : new WP_Error('mad4b_crypto_signature_invalid','Receipt signature verification failed.');
	}

	public static function public_key( $profile_id, $kid ) {
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		$manifest = self::manifest( $profile_id );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$kid = strtolower( trim( (string)$kid ) );
		if ( empty( $manifest['keys'][ $kid ] ) ) return new WP_Error( 'mad4b_crypto_key_unknown', 'Receipt signing key is not registered.' );
		return self::public_key_block_from_key( $manifest['keys'][ $kid ], $profile );
	}

	public static function status( $profile_id ) {
		$profile = self::profile( $profile_id );
		if ( is_wp_error( $profile ) ) return $profile;
		$manifest = self::manifest( $profile_id );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$keys = array();
		foreach ( $manifest['keys'] as $kid=>$key ) $keys[] = array(
			'kid'=>$kid,'algorithm'=>(string)$key['algorithm'],'state'=>(string)$key['state'],
			'created_at'=>(int)$key['created_at'],'signing_not_after'=>(int)$key['signing_not_after'],
			'overlap_until'=>(int)$key['overlap_until'],'revoked_at'=>(int)$key['revoked_at'],
			'private_key_exposed'=>false
		);
		return array(
			'contract'=>'mad4b.crypto-profile-status.v1','profile_id'=>$profile_id,'profile_sha256'=>$profile['profile_sha256'],
			'algorithm'=>$profile['algorithm'],'current_kid'=>(string)$manifest['current_kid'],'revision'=>(int)$manifest['revision'],
			'keys'=>$keys,'private_key_stored_in_database'=>false,'private_key_exposed'=>false,'authorizing'=>false
		);
	}

	private static function public_key_block_from_key( array $key, array $profile ) {
		return array(
			'contract'=>'mad4b.crypto-public-key.v1','profile_id'=>(string)$profile['profile_id'],
			'profile_sha256'=>(string)$profile['profile_sha256'],'algorithm'=>(string)$profile['algorithm'],
			'kid'=>(string)$key['kid'],'public_key_pem'=>(string)$key['public_key_pem'],
			'not_before'=>(int)$key['not_before'],'signing_not_after'=>(int)$key['signing_not_after'],
			'overlap_until'=>(int)$key['overlap_until'],'revoked_at'=>(int)$key['revoked_at'],'authorizing'=>false
		);
	}

	private static function catalog() {
		if ( is_array( self::$profiles ) ) return self::$profiles;
		$path = defined('MAD4B_SCP_DIR') ? MAD4B_SCP_DIR . 'config/crypto-profiles.json' : dirname(__DIR__) . '/config/crypto-profiles.json';
		if ( ! is_readable( $path ) ) return new WP_Error( 'mad4b_crypto_profiles_missing', 'Cryptographic profile catalog is unavailable.' );
		$data=json_decode((string)file_get_contents($path),true);
		if(!is_array($data)||self::CONTRACT!==(isset($data['contract'])?(string)$data['contract']:'')||empty($data['profiles'])||!is_array($data['profiles']))return new WP_Error('mad4b_crypto_profiles_invalid','Cryptographic profile catalog is invalid.');
		return self::$profiles=$data;
	}

	private static function manifest( $profile_id ) {
		$dir=self::keyring_dir(); if(is_wp_error($dir))return$dir;
		$path=trailingslashit($dir).'keyring-'.sanitize_key((string)$profile_id).'.json';
		if(!is_file($path))return array('contract'=>self::KEYRING_CONTRACT,'profile_id'=>sanitize_key((string)$profile_id),'revision'=>0,'current_kid'=>'','keys'=>array(),'updated_at'=>0);
		if(!is_readable($path))return new WP_Error('mad4b_crypto_keyring_unreadable','Receipt keyring manifest is unreadable.');
		$data=json_decode((string)file_get_contents($path),true);
		if(!is_array($data)||self::KEYRING_CONTRACT!==(isset($data['contract'])?(string)$data['contract']:'')||!isset($data['keys'])||!is_array($data['keys']))return new WP_Error('mad4b_crypto_keyring_invalid','Receipt keyring manifest is invalid.');
		return$data;
	}

	private static function write_manifest( $profile_id, array $manifest, $expected_revision = null ) {
		$dir=self::keyring_dir();if(is_wp_error($dir))return$dir;
		if(!is_dir($dir)&&!wp_mkdir_p($dir))return new WP_Error('mad4b_crypto_keyring_directory_unavailable','Unable to create receipt keyring directory.');
		@chmod($dir,0700);
		$manifest['contract']=self::KEYRING_CONTRACT;$manifest['profile_id']=sanitize_key((string)$profile_id);
		$path=trailingslashit($dir).'keyring-'.$manifest['profile_id'].'.json';
		if ( is_link( $path ) ) return new WP_Error( 'mad4b_crypto_keyring_manifest_symlink_denied', 'Receipt keyring manifest may not be a symbolic link.' );
		if ( null !== $expected_revision ) {
			$current = self::manifest( $profile_id );
			if ( is_wp_error( $current ) ) return $current;
			if ( (int) $current['revision'] !== (int) $expected_revision ) return new WP_Error(
				'mad4b_crypto_keyring_revision_conflict',
				'Receipt keyring manifest changed during mutation; reread before retrying.',
				array( 'expected_revision'=>(int)$expected_revision, 'current_revision'=>(int)$current['revision'] )
			);
		}
		$json=wp_json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
		if(!is_string($json))return new WP_Error('mad4b_crypto_keyring_encode_failed','Receipt keyring manifest could not be encoded.');
		$tmp=$path.'.tmp-'.substr(hash('sha256',uniqid('',true)),0,12);
		if(false===file_put_contents($tmp,$json."\n",LOCK_EX)){@unlink($tmp);return new WP_Error('mad4b_crypto_keyring_write_failed','Unable to write receipt keyring manifest.');}
		@chmod($tmp,0600);
		if(!@rename($tmp,$path)){@unlink($tmp);return new WP_Error('mad4b_crypto_keyring_commit_failed','Unable to atomically commit receipt keyring manifest.');}
		@chmod($path,0600);return true;
	}

	private static function with_keyring_lock( $profile_id, $callback ) {
		$dir=self::keyring_dir();if(is_wp_error($dir))return$dir;
		if(!is_dir($dir)&&!wp_mkdir_p($dir))return new WP_Error('mad4b_crypto_keyring_directory_unavailable','Unable to create receipt keyring directory.');
		@chmod($dir,0700);
		$dir=self::keyring_dir();if(is_wp_error($dir))return$dir;
		$lock_path=trailingslashit($dir).'keyring-'.sanitize_key((string)$profile_id).'.lock';
		if(is_link($lock_path))return new WP_Error('mad4b_crypto_keyring_lock_symlink_denied','Receipt keyring lock may not be a symbolic link.');
		$handle=@fopen($lock_path,'c+');
		if(false===$handle)return new WP_Error('mad4b_crypto_keyring_lock_unavailable','Receipt keyring mutation lock is unavailable.');
		@chmod($lock_path,0600);
		if(!@flock($handle,LOCK_EX)){@fclose($handle);return new WP_Error('mad4b_crypto_keyring_lock_failed','Receipt keyring mutation lock could not be acquired.');}
		try{return call_user_func($callback);}
		finally{@flock($handle,LOCK_UN);@fclose($handle);}
	}

	private static function keyring_dir() {
		$doc = isset( $_SERVER['DOCUMENT_ROOT'] ) ? trim( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
		if ( defined( 'MAD4B_SCP_CRYPTO_KEYRING_DIR' ) ) {
			$path = trim( (string) constant( 'MAD4B_SCP_CRYPTO_KEYRING_DIR' ) );
		} else {
			$path = '';
			if ( class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' )
				&& method_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy', 'safe_default_path_for_roots' ) ) {
				$oauth_path = MAD4B_SCP_Local_OAuth_Key_Path_Policy::safe_default_path_for_roots( ABSPATH, $doc );
				if ( ! is_wp_error( $oauth_path ) ) {
					$mad4b_root = dirname( dirname( (string) $oauth_path ) );
					$path = trailingslashit( $mad4b_root ) . 'crypto/execution-receipts';
				} elseif ( 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI ) {
					return new WP_Error( 'mad4b_crypto_document_root_unknown', 'Receipt keyring safe path cannot be proven outside the HTTP document root.' );
				}
			}
			if ( '' === $path ) {
				$wp_root = rtrim( (string) ABSPATH, '/\\' );
				if ( '' !== $doc ) $base = dirname( rtrim( $doc, '/\\' ) );
				elseif ( 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ) $base = dirname( $wp_root );
				else return new WP_Error( 'mad4b_crypto_document_root_unknown', 'Receipt keyring safe path cannot be proven outside the HTTP document root.' );
				$path = trailingslashit( $base ) . '.mad4b/crypto/execution-receipts';
			}
		}
		if ( '' === $path || 1 !== preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', $path ) ) return new WP_Error( 'mad4b_crypto_keyring_path_invalid', 'Receipt keyring path must be absolute.' );
		$canonical=self::canonicalize_policy_path($path);if(is_wp_error($canonical))return$canonical;
		$wp=self::canonicalize_policy_path(ABSPATH);if(is_wp_error($wp))return$wp;
		if(self::path_within($canonical,$wp))return new WP_Error('mad4b_crypto_keyring_path_wordpress_exposed','Receipt keyring path must be outside WordPress root after canonical path resolution.');
		if(''!==$doc){$canonical_doc=self::canonicalize_policy_path($doc);if(is_wp_error($canonical_doc))return$canonical_doc;if(self::path_within($canonical,$canonical_doc))return new WP_Error('mad4b_crypto_keyring_path_document_root_exposed','Receipt keyring path must be outside HTTP document root after canonical path resolution.');}
		if(is_link($path))return new WP_Error('mad4b_crypto_keyring_path_symlink_denied','Receipt keyring directory may not be a symbolic link.');
		return rtrim($canonical,'/\\\\');
	}

	private static function canonicalize_policy_path( $path ) {
		$path=rtrim((string)$path,'/\\\\');
		if(''===$path)return new WP_Error('mad4b_crypto_keyring_path_invalid','Receipt keyring path is empty.');
		$probe=$path;$tail=array();
		while(!file_exists($probe)&&!is_link($probe)){
			$base=basename($probe);if(''===$base||'.'===$base)break;
			array_unshift($tail,$base);$parent=dirname($probe);if($parent===$probe)break;$probe=$parent;
		}
		$resolved=realpath($probe);
		if(false===$resolved)return new WP_Error('mad4b_crypto_keyring_path_unresolvable','Receipt keyring path cannot be canonically resolved from its nearest existing parent.');
		foreach($tail as$component)$resolved=rtrim($resolved,'/\\\\').DIRECTORY_SEPARATOR.$component;
		return function_exists('wp_normalize_path')?wp_normalize_path($resolved):str_replace('\\\\','/',$resolved);
	}

	private static function path_within( $candidate, $root ) {
		$candidate=rtrim(str_replace('\\\\','/',(string)$candidate),'/').'/';
		$root=rtrim(str_replace('\\\\','/',(string)$root),'/').'/';
		if('\\\\'===DIRECTORY_SEPARATOR){$candidate=strtolower($candidate);$root=strtolower($root);}
		return 0===strpos($candidate,$root);
	}

	private static function atomic_private_write( $path, $pem ) {
		if(is_link($path))return new WP_Error('mad4b_crypto_private_key_symlink_denied','Receipt private key destination may not be a symbolic link.');
		$tmp=$path.'.tmp-'.substr(hash('sha256',uniqid('',true)),0,12);
		if(false===file_put_contents($tmp,(string)$pem,LOCK_EX)){@unlink($tmp);return new WP_Error('mad4b_crypto_private_key_write_failed','Unable to write receipt private key.');}
		@chmod($tmp,0600);
		if(!@rename($tmp,$path)){@unlink($tmp);return new WP_Error('mad4b_crypto_private_key_commit_failed','Unable to atomically commit receipt private key.');}
		@chmod($path,0600);return true;
	}

	private static function retire_excess_keys( array &$manifest, array $profile ) {
		$keys=array_keys($manifest['keys']);
		usort($keys,static function($a,$b)use($manifest){return (int)$manifest['keys'][$b]['created_at'] <=> (int)$manifest['keys'][$a]['created_at'];});
		$keep=max(1,(int)$profile['max_active_keys']);$seen=0;
		foreach($keys as $kid){
			if(!empty($manifest['keys'][$kid]['revoked_at']))continue;
			$seen++;
			if($seen>$keep && $kid!==$manifest['current_kid'])$manifest['keys'][$kid]['state']='retired';
		}
	}

	private static function openssl_algorithm( $algorithm ) {
		$algorithm=strtoupper((string)$algorithm);
		if('RS256'===$algorithm)return OPENSSL_ALGO_SHA256;
		if('RS512'===$algorithm)return OPENSSL_ALGO_SHA512;
		return false;
	}
	private static function digest( $value ) {
		$value=self::canon($value);$json=function_exists('wp_json_encode')?wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return is_string($json)?hash('sha256',$json):'';
	}
	private static function canon($value){if(!is_array($value))return$value;$keys=array_keys($value);$list=empty($value)||$keys===range(0,count($value)-1);if($list)return array_map(array(__CLASS__,'canon'),$value);ksort($value,SORT_STRING);foreach($value as$k=>$v)$value[$k]=self::canon($v);return$value;}
	private static function b64url($value){return rtrim(strtr(base64_encode((string)$value),'+/','-_'),'=');}
	private static function b64url_decode($value){$value=strtr((string)$value,'-_','+/');$pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);return base64_decode($value,true);}

	private static function now_epoch(){return class_exists('MAD4B_SCP_Time_Policy')?MAD4B_SCP_Time_Policy::now_epoch():time();}
}
