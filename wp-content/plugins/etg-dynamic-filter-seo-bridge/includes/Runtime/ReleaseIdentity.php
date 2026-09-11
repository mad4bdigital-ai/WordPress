<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;
final class ReleaseIdentity{
	public function report():array{$path=defined('ETG_DFSB_DIR')?ETG_DFSB_DIR.'release-identity.json':'';$data=array();if($path&&is_readable($path)){$decoded=json_decode((string)file_get_contents($path),true);if(is_array($decoded)){$data=$decoded;}}$git=$this->gitObjectId($data['git_sha']??'');$tree=$this->gitObjectId($data['tree_sha']??'');$manifest=$this->sha256($data['source_manifest_sha256']??'');return array('contract'=>'etg.dfsb.runtime-release-identity.v2','plugin_version'=>defined('ETG_DFSB_VERSION')?ETG_DFSB_VERSION:'','plugin_basename'=>defined('ETG_DFSB_PLUGIN_BASENAME')?ETG_DFSB_PLUGIN_BASENAME:'','git_sha'=>$git,'tree_sha'=>$tree,'source_manifest_sha256'=>$manifest,'identity_contract'=>sanitize_text_field((string)($data['contract']??'')),'build_identity_available'=>''!==$git&&''!==$tree&&''!==$manifest);}
	private function gitObjectId($value):string{$value=strtolower(trim((string)$value));return preg_match('/^[a-f0-9]{40,64}$/',$value)?$value:'';}private function sha256($value):string{$value=strtolower(trim((string)$value));return preg_match('/^[a-f0-9]{64}$/',$value)?$value:'';}
}
