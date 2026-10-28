<?php
namespace DynamicFilterSEOBridge\Presentation;

/** Per-site, explicit shared/private image reference resolver (no media copying). */
final class SharedMediaSources {
    public const OPTION = 'dfsb_shared_media_settings';
    public const DEFAULT_SETTINGS = array('enabled'=>false,'base_url'=>'');

    public static function validateBase($base): string {
        if(!is_string($base))return '';
        $base=trim($base);
        if($base==='' || strlen($base)>600 || preg_match('/[[:space:]<>"{}]/',$base) || str_contains($base,chr(92)) || str_contains($base,chr(39)))return '';
        $p=wp_parse_url($base);
        if(!is_array($p) || strtolower((string)($p['scheme']??''))!=='https'
            || empty($p['host']) || isset($p['user']) || isset($p['pass'])
            || isset($p['query']) || isset($p['fragment']) || isset($p['port']))return '';
        $host=strtolower((string)$p['host']);
        if(!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',$host)
            || filter_var($host,FILTER_VALIDATE_IP)
            || preg_match('/(?:^|\\.)(?:localhost|local|internal|test|invalid)$/',$host))return '';
        $path=(string)($p['path']??'');
        if(str_contains($path,'\\') || str_contains($path,'%')
            || preg_match('~(?:^|/)\\.{1,2}(?:/|$)~',$path) || preg_match('~//~',$path))return '';
        return 'https://'.$host.rtrim($path,'/');
    }

    public static function settings(): array {
        $value=get_option(self::OPTION,array());
        if(!is_array($value))$value=array();
        $base=self::validateBase($value['base_url']??'');
        return array('enabled'=>!empty($value['enabled']) && $base!=='', 'base_url'=>$base);
    }

    public static function normalizeRelative($path): string {
        if(!is_string($path))return '';
        $path=trim($path);
        if($path===''||strlen($path)>1000 || str_starts_with($path,'/')
            || str_contains($path,'\\') || str_contains($path,'?') || str_contains($path,'#')
            || str_contains($path,'%') || str_contains($path,'//'))return '';
        $parts=explode('/',$path);
        if(count($parts)>20)return '';
        foreach($parts as $part){
            if($part==='' || $part==='.' || $part==='..'
                || !preg_match('/^[A-Za-z0-9._~-]+$/D',$part))return '';
        }
        if(!preg_match('/\\.(?:jpe?g|png|webp|gif|avif)$/iD',$path))return '';
        return $path;
    }

    public static function urlForShared($relative): string {
        $settings=self::settings();
        if(!$settings['enabled'])return '';
        $relative=self::normalizeRelative($relative);
        return $relative!=='' ? $settings['base_url'].'/'.$relative : '';
    }

    public static function reference($value,array $policy=array()): ?array {
        $mode=(string)($policy['mode']??'mixed');
        if(!in_array($mode,array('mixed','local_only','shared_only'),true))$mode='mixed';
        $source='';$id=0;$relative='';
        if(is_array($value)){
            $source=(string)($value['source']??$value['library']??'');
            if($source==='shared')$relative=(string)($value['path']??$value['relative_path']??'');
            elseif($source==='' || $source==='local'){
                $id=isset($value['id'])&&is_numeric($value['id'])?(int)$value['id']:0;
                if(!$id && isset($value['attachment_id'])&&is_numeric($value['attachment_id']))$id=(int)$value['attachment_id'];
                $source='local';
            }
        }elseif(is_int($value) || (is_string($value)&&preg_match('/^\\d{1,12}$/D',$value))){
            $source='local';$id=(int)$value;
        }elseif(is_string($value) && str_starts_with($value,'local:')){
            $candidate=substr($value,6);
            if(preg_match('/^\\d{1,12}$/D',$candidate)){$source='local';$id=(int)$candidate;}
        }elseif(is_string($value) && str_starts_with($value,'shared:')){
            $source='shared';$relative=substr($value,7);
        }
        if($source==='local' && $mode!=='shared_only' && $id>0){
            $url=PortableMediaGallery::attachmentUrl($id);
            if($url!=='')return array('id'=>$id,'url'=>$url,'source'=>'local');
        }
        if($source==='shared' && $mode!=='local_only'){
            $url=self::urlForShared($relative);
            if($url!=='')return array('id'=>0,'url'=>$url,'source'=>'shared','path'=>self::normalizeRelative($relative));
        }
        return null;
    }
}
