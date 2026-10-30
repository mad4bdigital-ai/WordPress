<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH'))exit;
final class SmartSeoLinkCrawler {
 const CONTRACT='mad4b.seo.real-link-crawl.v1';
 public static function key(string $url): string {
  $parts=wp_parse_url($url);
  $host=strtolower((string)($parts['host']??''));
  $own=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
  if($host!==$own || !in_array(strtolower((string)($parts['scheme']??'')),['https','http'],true))return '';
  return $host.'/'.trim((string)($parts['path']??'/'),'/');
 }
 public static function crawl(string $root,int $pageLimit=20,int $maxDepth=3): array {
  $pageLimit=max(1,min(30,$pageLimit));$maxDepth=max(0,min(4,$maxDepth));
  if(self::key($root)==='')return ['error'=>'non_site_url'];
  $queue=[[$root,0]];$seen=[];$pages=[];$links=[];$outcome=[];
  while($queue && count($pages)<$pageLimit) {
   [$url,$depth]=array_shift($queue);$key=self::key($url);
   if($key===''||isset($seen[$key]))continue;
   $seen[$key]=true;
   $response=wp_safe_remote_get($url,['timeout'=>7,'redirection'=>0,'limit_response_size'=>250000,
      'headers'=>['Accept'=>'text/html']]);
   $http=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
   $pages[]=['url'=>$url,'depth'=>$depth,'http'=>$http];
   if($http!==200||$depth>=$maxDepth)continue;
   $html=(string)wp_remote_retrieve_body($response);
   if(preg_match_all('~<a\\s+[^>]*href\\s*=\\s*["\x27]([^"\x27]+)["\x27][^>]*>(.*?)</a>~is',$html,$matches,PREG_SET_ORDER)) {
    $i=0;
    foreach($matches as $m) {
     if(++$i>150)break;
     $href=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
     if(str_starts_with($href,'/') && !str_starts_with($href,'//'))$href=home_url($href);
     $dest=self::key($href);
     if($dest==='')continue;
     $links[]=['from'=>$key,'to'=>$dest,
       'anchor'=>mb_substr(trim(wp_strip_all_tags($m[2])),0,100)];
     if(!isset($seen[$dest]) && count($queue)<100)$queue[]=[$href,$depth+1];
    }
   }
  }
  return ['contract'=>self::CONTRACT,'root'=>$root,'page_limit'=>$pageLimit,
    'max_depth'=>$maxDepth,'pages'=>$pages,'links'=>$links,
    'truncated'=>(bool)$queue,
    'scope'=>'Actual first-party HTML links reached in bounded crawl; paths not seen are unverified, not proven orphaned'];
 }
}