<?php
defined('ABSPATH') || exit;
/**
 * Demand-time evidence from the exact ACTIVE WordPress provider plugin.
 * Runtime identity is observed evidence, NOT a certified vendor package,
 * behavioral proof, delegated permission or implicit write approval.
 */
final class MAD4B_SCP_Provider_Installed_Artifact {
    const CONTRACT = 'mad4b.provider-installed-artifact.v1';
    const MAX_CANDIDATES = 8;
    public static function observe($provider, array $declaration, $adapter = null, array $contract = array()) {
        $provider = sanitize_key((string)$provider);
        $candidates = array();
        if(!empty($contract['plugin_file'])) $candidates[]=(string)$contract['plugin_file'];
        foreach((array)($contract['components']??array()) as $component) {
            if(is_array($component) && !empty($component['plugin_file']))
                $candidates[]=(string)$component['plugin_file'];
        }
        foreach((array)($declaration['artifact_plugin_files']??array()) as $candidate) {
            if(is_string($candidate)) $candidates[]=$candidate;
        }
        $candidates=array_values(array_unique($candidates));
        $result=array(
            'contract'=>self::CONTRACT, 'provider'=>$provider,
            'state'=>'not_resolved','bound'=>false,'plugin_file'=>'',
            'installed_version'=>'','main_file_sha256'=>'',
            'artifact_fingerprint'=>'','source'=>'wordpress_active_plugin_header',
            'runtime_identity_only'=>true,'external_package_certified'=>false,
            'behavioral_verified'=>false,'rollback_verified'=>false,
            'write_authorized'=>false,'mutation_performed'=>false,
        );
        if(!$candidates || count($candidates)>self::MAX_CANDIDATES
            || !defined('WP_PLUGIN_DIR')) return $result;
        if(!function_exists('get_plugins')) {
            if(!defined('ABSPATH') || !is_file(ABSPATH.'wp-admin/includes/plugin.php'))return $result;
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        $known=get_plugins();
        $active=(array)get_option('active_plugins',array());
        $root=realpath(WP_PLUGIN_DIR);
        if(!$root)return $result;
        $matches=array();
        foreach($candidates as $file) {
            $file=str_replace('\\','/',$file);
            if(!preg_match('#^[a-zA-Z0-9_./-]{3,180}$#D',$file)
                || str_contains($file,'..') || str_starts_with($file,'/')
                || !isset($known[$file]) || !in_array($file,$active,true))continue;
            $full=realpath(WP_PLUGIN_DIR.'/'.$file);
            if(!$full || !str_starts_with($full,$root.DIRECTORY_SEPARATOR)
                || !is_file($full) || !is_readable($full))continue;
            $version=trim((string)($known[$file]['Version']??''));
            $sha=hash_file('sha256',$full);
            if(!$version || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha))continue;
            $matches[]=array('plugin_file'=>$file,'installed_version'=>$version,
                'main_file_sha256'=>$sha);
        }
        // More than one candidate needs a declared composite contract and its
        // independent runtime integrity. Do not choose a provider arbitrarily.
        if(count($matches)!==1)return $result;
        $m=$matches[0];
        $adapter_version=is_object($adapter)&&method_exists($adapter,'runtime_version')
            ?trim((string)$adapter->runtime_version()):'';
        if($adapter_version && !hash_equals($adapter_version,$m['installed_version'])) {
            $result['state']='adapter_version_mismatch';
            return $result;
        }
        $result=array_merge($result,$m);
        $result['bound']=true;
        $result['state']='exact_active_plugin_observed';
        $result['artifact_fingerprint']=hash('sha256',wp_json_encode(array(
            self::CONTRACT,$provider,$m['plugin_file'],$m['installed_version'],$m['main_file_sha256']
        ),JSON_UNESCAPED_SLASHES));
        return $result;
    }
}
