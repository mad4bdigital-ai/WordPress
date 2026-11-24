<?php
/**
 * Candidate for the original Governor asset identity adapter.
 * Read-only pure functions. No hooks, cache writes or approval issuance.
 * Inputs must come from the native ordered CSS plan, not client data.
 */
final class MAD4B_Navigation_CSS_Dependency_V1 {
    private const CONTRACT='mad4b.navigation-css-dependency.v1';
    public static function identity(array $orderedSources,string $runtimeSha):?array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$runtimeSha)
            ||count($orderedSources)<3||count($orderedSources)>70)return null;
        $seen=[];$members=[];$keys=[];$bytes=0;
        foreach($orderedSources as $s){
            $h=$s['handle']??null;$body=$s['body']??null;
            if(!is_string($h)||!preg_match('/^elementor-post-[1-9][0-9]*$/D',$h)
                ||isset($seen[$h])||!is_string($body)||$body===''||strlen($body)>500000
                ||($s['media']??null)!=='all'||($s['native_eligible']??null)!==true
                ||preg_match('/@(?:import|charset|layer)\b/i',$body)
                ||preg_match('/\n\/\* elementor-post-[0-9]+ \*\/\n/',$body))return null;
            $seen[$h]=true;$sha=hash('sha256',$body);
            $members[]=['handle'=>$h,'sha256'=>$sha,'bytes'=>strlen($body),'media'=>'all'];
            $keys[]=$h.':'.$sha;$bytes+=strlen($body)+strlen("\n/* ".$h." */\n\n");
            if($bytes>1500000)return null;
        }
        if(($members[0]['handle']??'')!=='elementor-post-26212'||!isset($seen['elementor-post-67125']))return null;
        return ['contract'=>self::CONTRACT,'runtime_sha256'=>$runtimeSha,'members'=>$members,
            'native_segment_digest'=>substr(hash('sha256',implode('|',$keys)),0,24)];
    }
    public static function digest(array $identity):string {
        return hash('sha256',json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function segment_matches(array $identity,string $body):bool {
        if(($identity['contract']??'')!==self::CONTRACT||strlen($body)>1500000)return false;
        $offset=0;
        foreach($identity['members']??[] as $m){
            $prefix="\n/* ".$m['handle']." */\n";
            if(substr($body,$offset,strlen($prefix))!==$prefix)return false;
            $offset+=strlen($prefix);
            $part=substr($body,$offset,$m['bytes']);$offset+=$m['bytes'];
            if(strlen($part)!==$m['bytes']||!hash_equals($m['sha256'],hash('sha256',$part))
                ||substr($body,$offset,1)!=="\n")return false;
            $offset++;
        }
        return $offset===strlen($body)&&$offset>0;
    }
    // This component is only one part of the original full asset manifest.
    // Other JS/CSS dependencies must still be attested by the native adapter.
    public static function bind(array $identity,string $otherAssetsSha):?string {
        if(($identity['contract']??'')!==self::CONTRACT
            ||!preg_match('/^[a-f0-9]{64}$/D',$otherAssetsSha))return null;
        return hash('sha256',self::digest($identity).':'.$otherAssetsSha);
    }
    public static function continuity(array $original,array $fresh,string $segment):bool {
        return hash_equals(self::digest($original),self::digest($fresh))
            &&self::segment_matches($fresh,$segment);
    }
}
