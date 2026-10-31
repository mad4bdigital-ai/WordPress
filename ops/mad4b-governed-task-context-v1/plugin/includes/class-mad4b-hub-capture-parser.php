<?php
defined('ABSPATH') || exit;
/**
 * Bounded, network-free extraction for any provider's captured HTML/Markdown.
 * Parsing is not capture verification or an image-reuse license.
 */
final class MAD4B_Hub_Capture_Parser {
 const CONTRACT='mad4b.hub.capture-parser.v1';
 const HTML_LIMIT=1200000;
 const MARKDOWN_LIMIT=900000;
 const MAX_IMAGES=45;
 const MAX_LINKS=150;
 private static function error($code){return new WP_Error('mad4b_capture_parser_'.$code,$code,['status'=>422]);}
 private static function url($candidate,$base){
  $candidate=trim(html_entity_decode((string)$candidate,ENT_QUOTES|ENT_HTML5,'UTF-8'));
  if($candidate===''||strlen($candidate)>2048||preg_match('~^(?:data|javascript|file|blob):~i',$candidate))return '';
  if(str_starts_with($candidate,'//'))$candidate='https:'.$candidate;
  elseif(str_starts_with($candidate,'/')){ $p=wp_parse_url($base);if(!$p||empty($p['host']))return ''; $candidate='https://'.$p['host'].$candidate; }
  elseif(!preg_match('~^https?://~i',$candidate)){
   $p=wp_parse_url($base);if(!$p||empty($p['host']))return '';
   $dir=dirname((string)($p['path']??'/'));$candidate='https://'.$p['host'].rtrim($dir,'/').'/'.$candidate;
  }
  $parts=wp_parse_url($candidate);
  if(!is_array($parts)||empty($parts['host'])||!in_array(strtolower($parts['scheme']??''),['https','http'],true)
     ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))return '';
  return esc_url_raw($candidate);
 }
 private static function pick($a,$keys){
  foreach($keys as $key)if(isset($a[$key])&&is_scalar($a[$key])&&(string)$a[$key]!=='')return (string)$a[$key];
  return '';
 }
 public static function normalize($provider,$payload,$source_url){
  if(!is_array($payload))return self::error('payload_invalid');
  $provider=sanitize_key((string)$provider);
  if($provider===''||strlen($provider)>70)return self::error('provider_invalid');
  $source=self::url($source_url,$source_url);
  if(!$source||!str_starts_with($source,'https://'))return self::error('source_url_invalid');
  $data=is_array($payload['data']??null)?$payload['data']:$payload;
  $html=self::pick($data,['rawHtml','html','raw_html']);
  $markdown=self::pick($data,['markdown','content','md']);
  if(strlen($html)>self::HTML_LIMIT||strlen($markdown)>self::MARKDOWN_LIMIT)
    return self::error('payload_too_large');
  if(trim($html)===''&&trim($markdown)==='')return self::error('empty_capture');
  $meta=is_array($data['metadata']??null)?$data['metadata']:[];
  return ['contract'=>self::CONTRACT,'provider_id'=>$provider,'source_url'=>$source,
    'html'=>$html,'markdown'=>$markdown,'metadata'=>$meta,
    'source_html_sha256'=>$html!==''?hash('sha256',$html):'',
    'source_markdown_sha256'=>$markdown!==''?hash('sha256',$markdown):'',
    'html_available'=>$html!=='','markdown_available'=>$markdown!==''];
 }
 private static function text($s,$max=350){
  $s=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$s)));
  return mb_substr($s,0,$max);
 }
 private static function push_image(&$images,&$seen,$raw,$base,$alt='',$caption='',$role='gallery'){
  if(count($images)>=self::MAX_IMAGES)return;
  $url=self::url($raw,$base);
  if($url===''||isset($seen[$url]))return;
  $seen[$url]=true;
  $images[]=['kind'=>'scraped_candidate','role'=>$role,'source_url'=>$url,
    'alt'=>self::text($alt,220),'caption'=>self::text($caption,500),
    'capture_verified'=>false,'visual_verified'=>false,'rights_verified'=>false,
    'publish_authorized'=>false];
 }
 public static function parse($capture){
  if(!is_array($capture)||($capture['contract']??'')!==self::CONTRACT)return self::error('normalized_capture_required');
  $html=(string)$capture['html'];$md=(string)$capture['markdown'];$base=$capture['source_url'];
  $images=[];$seen=[];$links=[];$headings=[];$title='';$description='';$plain='';
  if($html!=='' && class_exists('DOMDocument')){
   $dom=new DOMDocument();
   $previous=libxml_use_internal_errors(true);
   $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
   libxml_clear_errors();libxml_use_internal_errors($previous);
   $xp=new DOMXPath($dom);
   foreach($xp->query('//script|//style|//noscript|//template') as $node){$node->parentNode?->removeChild($node);}
   $titleNode=$xp->query('//title')->item(0);
   if($titleNode)$title=self::text($titleNode->textContent,220);
   foreach($xp->query('//meta[@name or @property]') as $node){
    $key=strtolower((string)($node->getAttribute('name')?:$node->getAttribute('property')));
    $val=(string)$node->getAttribute('content');
    if($key==='description' && $description==='')$description=self::text($val,650);
    if(in_array($key,['og:image','twitter:image'],true))
      self::push_image($images,$seen,$val,$base,'','','featured');
   }
   foreach($xp->query('//h1|//h2|//h3|//h4') as $node){
    if(count($headings)>=75)break;
    $heading=self::text($node->textContent,240);
    if($heading!=='')$headings[]=['level'=>(int)substr(strtolower($node->nodeName),1),'text'=>$heading];
   }
   foreach($xp->query('//img') as $node){
    $src=(string)$node->getAttribute('src');
    foreach(['data-src','data-lazy-src','data-original'] as $name)
      if($src===''||str_starts_with($src,'data:'))$src=(string)($node->getAttribute($name)?:$src);
    $caption='';$parent=$node->parentNode;
    if($parent && strtolower($parent->nodeName)==='figure'){
      foreach($parent->childNodes as $child)if(strtolower($child->nodeName)==='figcaption')
        $caption=self::text($child->textContent,500);
    }
    self::push_image($images,$seen,$src,$base,$node->getAttribute('alt'),$caption);
   }
   foreach($xp->query('//a[@href]') as $node){
    if(count($links)>=self::MAX_LINKS)break;
    $url=self::url($node->getAttribute('href'),$base);
    if($url!=='')$links[]=['url'=>$url,'text'=>self::text($node->textContent,120)];
   }
   $body=$xp->query('//main')->item(0)?:$xp->query('//body')->item(0);
   if($body)$plain=self::text($body->textContent,15000);
  }
  if($md!==''){
   preg_match_all('/!\[([^\]\r\n]{0,220})\]\((https?:\/\/[^\s)]+)(?:\s+"([^"]+)")?\)/u',$md,$m,PREG_SET_ORDER);
   foreach(array_slice($m,0,self::MAX_IMAGES) as $item)
     self::push_image($images,$seen,$item[2],$base,$item[1],$item[3]??'');
   if($plain==='')$plain=self::text(preg_replace('/!\[[^\]]*\]\([^)]*\)/','',$md),15000);
   if(!$headings && preg_match_all('/^#{1,4}\s+(.+)$/mu',$md,$matches,PREG_SET_ORDER))
    foreach(array_slice($matches,0,75) as $h)$headings[]=['level'=>strspn($h[0],'#'),'text'=>self::text($h[1],240)];
  }
  if($title==='')$title=self::text($capture['metadata']['title']??'',220);
  return ['contract'=>self::CONTRACT,'provider_id'=>$capture['provider_id'],
    'source_url'=>$base,'title'=>$title,'description'=>$description,
    'headings'=>$headings,'plain_text'=>$plain,
    'images'=>$images,'links'=>$links,
    'image_count'=>count($images),'link_count'=>count($links),
    'html_sha256'=>$capture['source_html_sha256'],
    'markdown_sha256'=>$capture['source_markdown_sha256'],
    'html_available'=>$capture['html_available'],'capture_receipt_id'=>'',
    'capture_verified'=>false,'rights_checked'=>false,
    'publication_authorized'=>false,'network_performed'=>false];
 }
}
