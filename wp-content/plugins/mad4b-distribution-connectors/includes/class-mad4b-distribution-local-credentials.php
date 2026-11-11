<?php
/** Company-local encrypted credentials, no external vault dependency. */
defined('ABSPATH') || exit;
final class MAD4B_Distribution_Local_Credentials_V1 {
    public const CONTRACT = 'mad4b.distribution-local-credentials.v1';
    public const STORE = 'mad4b_distribution_local_secrets_v1';
    private const PREFIX = 'MAD4B$DL1$';

    public static function available(): bool {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            && function_exists('hash_hkdf') && function_exists('wp_salt')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES');
    }
    public static function status(): array {
        return [
            'contract'=>self::CONTRACT,
            'storage'=>'company_local_encrypted_wp_option',
            'external_vault_required'=>false,
            'encryption_available'=>self::available(),
            'algorithm'=>'XChaCha20-Poly1305',
            'key_basis'=>'wordpress_site_specific_salts',
            'domain_and_blog_bound'=>true,
            'secrets_shared_across_companies'=>false,
            'secret_values_exposed'=>false,
            'salt_rotation_requires_reentering_secrets'=>true,
            'wp_runtime_can_decrypt'=>true,
        ];
    }
    private static function key(): string|false {
        if (!self::available()) return false;
        $auth=wp_salt('auth');
        $secure=wp_salt('secure_auth');
        if (!is_string($auth)||!is_string($secure)||strlen($auth)<32||strlen($secure)<32)
            return false;
        $binding=strtolower(untrailingslashit(home_url('/'))).'|'.get_current_blog_id();
        return hash_hkdf('sha256',$auth.'|'.$secure,32,
            'mad4b-distribution-credentials-local-v1',$binding);
    }
    private static function aad(string $id): string {
        return self::CONTRACT.'|'.strtolower(untrailingslashit(home_url('/')))
            .'|'.get_current_blog_id().'|'.$id;
    }
    private static function valid_id(string $id): bool {
        return (bool)preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$id);
    }
    private static function stored(): array {
        $rows=get_option(self::STORE,[]);
        return is_array($rows)?$rows:[];
    }
    /** Never return plaintext, even to WordPress admins. */
    public static function has(string $id): bool {
        if (!self::valid_id($id))return false;
        $rows=self::stored();
        $encoded=$rows[$id]??null;
        return is_string($encoded) && str_starts_with($encoded,self::PREFIX)
            && false!==self::key() && self::decrypt($id,$encoded)!==false;
    }
    private static function decrypt(string $id,string $stored): string|false {
        $key=self::key();
        if ($key===false||!str_starts_with($stored,self::PREFIX))return false;
        $raw=base64_decode(substr($stored,strlen(self::PREFIX)),true);
        $n=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if($raw===false||strlen($raw)<$n+16)return false;
        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw,$n),self::aad($id),substr($raw,0,$n),$key);
    }
    /** Used only by trusted, purpose-bound provider adapters; no REST reveal endpoint. */
    public static function for_adapter(string $id,string $purpose): string|false {
        if(!self::valid_id($id) || !preg_match('/^[a-z0-9_]{3,55}$/D',$purpose))return false;
        if(!apply_filters('mad4b/distribution/allow_local_credential_access',false,$id,$purpose))
            return false;
        $stored=self::stored()[$id]??null;
        return is_string($stored)?self::decrypt($id,$stored):false;
    }
    public static function put(string $id,string $secret) {
        if(!current_user_can('manage_options'))return new WP_Error('local_credential_admin_required');
        if(!self::valid_id($id))return new WP_Error('local_credential_channel_invalid');
        if(!isset(MAD4B_Distribution_Channel_Registry_V1::rows()[$id]))
            return new WP_Error('local_credential_unknown_channel');
        if(strlen($secret)<8||strlen($secret)>8192||str_contains($secret,"\0"))
            return new WP_Error('local_credential_value_invalid');
        $key=self::key();
        if($key===false)return new WP_Error('local_encryption_unavailable');
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $secret,self::aad($id),$nonce,$key);
        $value=self::PREFIX.base64_encode($nonce.$cipher);
        $rows=self::stored();$previous=$rows[$id]??null;
        $rows[$id]=$value;
        if (!update_option(self::STORE,$rows,false))return new WP_Error('local_credential_save_failed');
        if (!self::has($id)) {
            if ($previous===null)unset($rows[$id]);else $rows[$id]=$previous;
            update_option(self::STORE,$rows,false);
            return new WP_Error('local_credential_readback_failed');
        }
        return ['status'=>'SAVED_ENCRYPTED_LOCALLY','channel'=>$id,
            'credential_present'=>true,'provider_connection_verified'=>false,
            'external_delivery_enabled'=>false];
    }
    public static function delete(string $id) {
        if(!current_user_can('manage_options'))return new WP_Error('local_credential_admin_required');
        if(!self::valid_id($id))return new WP_Error('local_credential_channel_invalid');
        $rows=self::stored();
        if(!array_key_exists($id,$rows))return ['status'=>'ABSENT','channel'=>$id];
        unset($rows[$id]);
        if(!update_option(self::STORE,$rows,false))return new WP_Error('local_credential_delete_failed');
        if(isset(self::stored()[$id]))return new WP_Error('local_credential_delete_readback_failed');
        return ['status'=>'REVOKED_LOCALLY','channel'=>$id];
    }
}
