<?php
/**
 * Plugin Name: MAD4B Ordered Public Critical CSS Consumer V2
 * Description: Strict, source-bound companion to the existing 422 V1 runtime.
 * Version: 0.1.0
 *
 * No independent WordPress hook: the original 422 runtime owns the request
 * context and calls this only after its own mobile/cookie/route checks.
 */
defined('ABSPATH') || exit;

final class MAD4B_Public_Critical_CSS_Consumer_V2 {
    private const CONTRACT='mad4b.public-critical-css.route-certificate.v2';
    private const MANIFEST='mad4b.public-css.ordered-dependencies.v1';
    private const ROOT='/mad4b-public-critical-css-v1';
    private const MAX_SOURCE=25165824;
    private const MAX_CRITICAL=1048576;
    private const MAX_FILES=256;
    private const LEGACY_CONSUMER_SHA='249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1';
    private static int $expected_links=0;
    private static int $expected_styles=0;
    private static bool $provisional=false;
    public static function provisional(): bool {return self::$provisional;}

    private static function digest($data): string {
        if (is_array($data)) {
            if (array_is_list($data)) $data=array_map([__CLASS__,'normalize'],$data);
            else $data=self::normalize($data);
        }
        return hash('sha256',(string)json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private static function normalize($data) {
        if (!is_array($data))return $data;
        if (array_is_list($data))return array_map([__CLASS__,'normalize'],$data);
        ksort($data,SORT_STRING);
        foreach ($data as &$item)$item=self::normalize($item);
        return $data;
    }
    private static function sha($v): bool {
        return is_string($v) && preg_match('/^[a-f0-9]{64}$/D',$v)===1;
    }
    private static function path(string $original): ?string {
        if (!preg_match('~^/(?:wp-content|wp-includes)/[A-Za-z0-9_./-]+\.css$~D',$original)
            || str_contains($original,'..') || str_contains($original,'//')) return null;
        $wp=realpath(WP_CONTENT_DIR);
        if (!$wp)return null;
        if (str_starts_with($original,'/wp-content/')) {
            $root=$wp;
            $relative=substr($original,strlen('/wp-content/'));
        } else {
            $root=realpath(ABSPATH);
            $relative=substr($original,1);
        }
        if (!$root)return null;
        $file=realpath($root.'/'.$relative);
        if (!$file || !str_starts_with($file,$root.'/') || !is_file($file))return null;
        return $file;
    }
    /**
     * Strict event-by-event proof that a standard day tour has not changed
     * despite unrelated edits to Properties elsewhere on the site.
     *
     * The original listing-generation gate remains authoritative unless ALL
     * intervening generations are present, exclusively Properties events,
     * and neither a JetEngine relation nor the rendered page/post-meta
     * references any changed Property. Unknown => invalidate.
     */
    private static function approved_consumer_revision($recorded): bool {
        if (!self::sha($recorded))return false;
        $installed=hash_file('sha256',__FILE__);
        return (is_string($installed) && hash_equals($installed,$recorded))
            || hash_equals(self::LEGACY_CONSUMER_SHA,$recorded);
    }

    private static function ordered_unrelated_property_events(int $old,int $now,int $dirtyGeneration): ?array {
        // UNKNOWN is never treated as an empty set. All generations in the
        // required interval must have original 421 provenance, in order.
        if ($old<1 || $now<=$old || $now-$old>256
            || $dirtyGeneration<$now)return null;
        $doc=get_option('mad4b_ccss_public_event_journal_v1',[]);
        if (!is_array($doc)
            || ($doc['contract']??null)!=='mad4b.css.source-ordered-events.v1'
            || ($doc['latest_generation']??null)!==$dirtyGeneration
            || !is_array($doc['events']??null)
            || !array_is_list($doc['events'])
            || count($doc['events'])<1 || count($doc['events'])>512)
            return null;
        $next=$old+1;$changed=[];
        foreach ($doc['events'] as $event) {
            if (!is_array($event) || !is_int($event['generation']??null))
                return null;
            $generation=$event['generation'];
            if ($generation<=$old)continue;
            if ($generation>$now)break;
            if ($generation!==$next
                || ($event['scope']??null)!=='post:properties'
                || !is_int($event['id']??null) || $event['id']<1)return null;
            $changed[]=$event['id'];$next++;
        }
        if ($next!==$now+1 || !$changed)return null;
        return array_values(array_unique($changed));
    }

    private static function unrelated_property_events(array $ctx,int $old,int $now,string $html): bool {
        if ($old<1 || $now<=$old || $now-$old>256
            || ($ctx['type']??'')!=='tours-and-activities'
            || !is_int($ctx['object_id']??null)
            || $ctx['object_id']<=0
            || $html===''
            || stripos($html,'jet-listing-grid')!==false
            || stripos($html,'data-mad4b-property')!==false
            || !function_exists('get_post_meta')
            || !function_exists('get_post_status')
            || get_post_status($ctx['object_id'])!=='publish'
            || (string)get_post_meta($ctx['object_id'],'tour_mode',true)!=='tour')
            return false;
        // Packages, cruises and related-rate programs may inherit Property
        // data through unmodeled supplier relationships: fail closed.
        foreach (['cruise_runs','tour_related_rate_plans','tour_queried_rates',
                  'related_program_terms','tour_cruise_amenities'] as $field) {
            $value=get_post_meta($ctx['object_id'],$field,true);
            if ($value!=='' && $value!==null && $value!==false && $value!==[])return false;
        }
        $ledger=get_option('mad4b_ccss_public_dirty_v1',[]);
        if (!is_array($ledger)||!is_array($ledger['ids']??null)
            || (int)($ledger['generation']??0)<$now)return false;
        $seen=[];$changed=[];$oldMapComplete=true;
        foreach ($ledger['ids'] as $key=>$entry) {
            if (!is_array($entry)){$oldMapComplete=false;break;}
            $gen=$entry['generation']??null;
            if (!is_int($gen) || $gen<=$old || $gen>$now)continue;
            if (!is_string($key)
                || !preg_match('/^post:properties:([1-9][0-9]*)$/D',$key,$match)
                || isset($seen[$gen])){$oldMapComplete=false;break;}
            $seen[$gen]=true;$changed[]=(int)$match[1];
        }
        if ($oldMapComplete && count($seen)===$now-$old) {
            for($n=$old+1;$n<=$now;$n++)
                if(!isset($seen[$n])){$oldMapComplete=false;break;}
        } else $oldMapComplete=false;
        if (!$oldMapComplete) {
            // Append-only 421 journal can prove repeated same-object
            // changes that the original per-key map overwrote. Unknown,
            // missing, foreign, out-of-order or mixed post types => reject.
            $changed=self::ordered_unrelated_property_events(
                $old,$now,(int)$ledger['generation']);
            if ($changed===null)return false;
        }
        $changed=array_values(array_unique($changed));
        global $wpdb;
        if (!is_object($wpdb)||!isset($wpdb->prefix)
            || !method_exists($wpdb,'prepare')||!method_exists($wpdb,'get_var'))
            return false;
        $relation=$wpdb->prefix.'jet_rel_default';
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$relation))return false;
        // Unknown JetEngine links (including indirect program links) fail
        // closed for the target tour. Bounded query for this object only.
        $sql=$wpdb->prepare("SELECT COUNT(*) FROM {$relation} WHERE parent_object_id=%d OR child_object_id=%d",
                            $ctx['object_id'],$ctx['object_id']);
        $links=$wpdb->get_var($sql);
        // NULL means SQL failure or missing relation table, not zero links.
        if ($links===null || !is_numeric($links) || (int)$links!==0)return false;
        $values=get_post_meta($ctx['object_id']);
        if (!is_array($values))return false;
        foreach($changed as $property) {
            $name=function_exists('get_the_title')?(string)get_the_title($property):'';
            if (preg_match('/(?<![0-9])'.preg_quote((string)$property,'/').'(?![0-9])/',$html)
                || ($name!=='' && stripos($html,$name)!==false))return false;
            // Explicit Property or accommodation IDs in tour metadata
            // are dependencies even if the page uses lazy panels.
            foreach($values as $key=>$entries){
                if (!is_string($key)||!preg_match('/property|hotel|cruise|accommodation|relation|rate|program|ship/i',$key))
                    continue;
                foreach((array)$entries as $value){
                    // Nested JetEngine/ACF repeaters can store Property IDs
                    // inside arrays or serialized objects. Unless a precise
                    // relation parser attests them, UNKNOWN is dependent.
                    if (!is_scalar($value))return false;
                    if (preg_match('/(?<![0-9])'.preg_quote((string)$property,'/').'(?![0-9])/',
                                  (string)$value))return false;
                }
            }
        }
        return true;
    }

    private static function verify_deps(array $cert,array $ctx,string $html): bool {
        $deps=$cert['dependencies']??null;
        if (!is_array($deps)
            || ($deps['global_generation']??null)!== (int)get_option('mad4b_ccss_global_generation_v1',0))
            return false;
        $revision=in_array($ctx['type'],['page','post','tours-and-activities','properties'],true)
            ? (int)get_post_meta($ctx['object_id'],'_mad4b_ccss_route_generation_v1',true):0;
        if (($deps['route_generation']??null)!==$revision)return false;
        if (!empty($deps['contains_dynamic_listing']) ||
            in_array($ctx['type'],['destinations','tour-types','property-types','tour-categories',
                'category','tours-and-activities','properties',
                'archive:tours-and-activities','archive:properties'],true)) {
            $last=$deps['listing_generation']??null;
            $now=(int)get_option('mad4b_ccss_listing_generation_v1',0);
            if ($last!==$now && (!is_int($last)
                || !self::unrelated_property_events($ctx,$last,$now,$html)))return false;
        }
        return true;
    }
    private static function certificate(array $ctx,string $html): ?array {
        if ((string)get_option('mad4b_perf_multi_css_v2','off')!=='on')return null;
        $routeKey=hash('sha256',$ctx['host'].'|'.$ctx['language'].'|'.$ctx['path']
            .'|'.$ctx['market_country'].'|'.$ctx['currency']);
        $path=WP_CONTENT_DIR.self::ROOT.'/certs-v2/'.$routeKey.'.json';
        if (is_link($path)||!is_file($path)||filesize($path)>180000)return null;
        $cert=json_decode((string)@file_get_contents($path),true);
        $status=$cert['status']??null;
        $provisional=$status==='released_pending_qa';
        if (!is_array($cert)
            || ($cert['contract']??null)!==self::CONTRACT
            || !in_array($status,['certified','released_pending_qa'],true)
            || ($provisional && (!is_int($cert['released_at']??null)
                 || !is_int($cert['post_release_deadline']??null)
                 || $cert['post_release_deadline']!=$cert['released_at']+21600
                 || $cert['released_at']>time()+300
                 || time()>$cert['post_release_deadline']))
            || ($cert['runtime_sha']??null)!==hash_file('sha256',__DIR__.'/422-mad4b-public-critical-css-runtime.php')
            || !self::approved_consumer_revision($cert['consumer_sha']??null)
            || !self::verify_deps($cert,$ctx,$html))return null;
        foreach (['host','path','language','type','object_id','market_country','currency','css_epoch']
                 as $field)if (($cert[$field]??null)!==($ctx[$field]??null))return null;
        if (($cert['critical_cap_px']??null)!==767
            || ($cert['native_mobile_breakpoint']??null)!==767)return null;
        $e=$cert['evidence']??null;
        if (!is_array($e))return null;
        if ($provisional) {
            // Publisher validated all original CSS/asset/route/context SHAs.
            // Browser performance happens AFTER release. No shared cache
            // while provisional, and the 6h lease expires to Native CSS.
            if (($e['decision']??null)!=='pending_post_release'
                || ($e['tested']??null)!==0
                || ($e['viewports']??null)!==[]
                || ($e['performance_source']??null)!=='post-release-real-original-css-urls'
                || ($e['verified_at']??null)!==0)return null;
        } elseif (($e['decision']??null)!=='passed_evidence'
            || ($e['viewports']??null)!==[320,390,414,767,768]
            || ($e['tested']??null)!==5
            || ($e['performance_source']??null)!=='real-original-css-urls'
            || !self::sha($e['qa_sha256']??null)
            || !is_numeric($e['minimum_mobile_fcp_gain_ms']??null)
            || $e['minimum_mobile_fcp_gain_ms']<150)return null;
        $m=$cert['source_manifest']??null;
        if (!is_array($m)||($m['contract']??null)!==self::MANIFEST
            || ($m['host']??null)!==$ctx['host']
            || !self::sha($m['manifest_sha256']??null)
            || ($m['manifest_sha256']??null)!==($cert['source_manifest_sha256']??null)
            || !is_array($m['files']??null)||count($m['files'])<2
            || count($m['files'])>self::MAX_FILES
            || !is_int($m['total_bytes']??null)||$m['total_bytes']>self::MAX_SOURCE
            || $m['total_bytes']<0)return null;
        $canonical=$m;unset($canonical['manifest_sha256']);
        if (!hash_equals($m['manifest_sha256'],self::digest($canonical)))return null;
        $chunks=$cert['critical_slots']??null;
        if (!is_array($chunks)||count($chunks)<1||count($chunks)>self::MAX_FILES
            || !is_int($cert['critical_total_bytes']??null)
            || $cert['critical_total_bytes']<16000
            || $cert['critical_total_bytes']>self::MAX_CRITICAL)return null;
        return $cert;
    }
    private static function extract(array $ctx,string $html,array $manifest): ?array {
        $headEnd=stripos($html,'</head>');
        if ($headEnd===false || stripos($html,'<head')===false)return null;
        $head=substr($html,0,$headEnd);
        preg_match_all('~<style\b[^>]*>[\s\S]*?</style\s*>|<link\b[^>]*>~i',
            $head,$tags,PREG_OFFSET_CAPTURE);
        $positions=[];$index=0;$slot=0;$n=count($manifest);
        foreach ($tags[0] as [$tag,$position]) {
            if (preg_match('~^<style\b~i',$tag)){$slot++;continue;}
            if (!preg_match('~\brel\s*=\s*(["\x27])([^"\x27]*)\1~i',$tag,$rel))continue;
            $rels=preg_split('/\s+/',strtolower(trim($rel[2])));
            if (!in_array('stylesheet',$rels,true))continue;
            if (in_array('alternate',$rels,true)||preg_match('~\s(?:disabled|onload|onerror|integrity|crossorigin)(?:\s|=|>)~i',$tag))
                return null;
            if ($index >= $n
                || !preg_match('~\bhref\s*=\s*(["\x27])([^"\x27]*)\1~i',$tag,$href))
                return null;
            $v=html_entity_decode($href[2],ENT_QUOTES,'UTF-8');
            if (str_starts_with($v,'/'))$v='https://'.$ctx['host'].$v;
            if ($v!==($manifest[$index]['href']??null))return null;
            $media='all';
            if (preg_match('~\bmedia\s*=\s*(["\x27])([^"\x27]*)\1~i',$tag,$originalMedia))
                $media=strtolower(trim($originalMedia[2]));
            if ($media!==($manifest[$index]['media']??null)
                || $slot!==($manifest[$index]['slot']??null)
                || $index!==($manifest[$index]['order']??null))return null;
            $positions[]=[$tag,$position,$slot,$media];
            $index++;
        }
        return $index===$n?$positions:null;
    }
    public static function transform(string $html,array $ctx): ?string {
        self::$expected_links=0;self::$expected_styles=0;self::$provisional=false;
        $cert=self::certificate($ctx,$html);
        if (!$cert||str_contains($html,'data-mad4b-ccss-v2-rest='))return null;
        $body=stripos($html,'<body');
        if ($body===false)return null;
        preg_match_all('/(?<![a-z0-9_-])elementor-(\d+)\b/i',substr($html,$body),$found);
        $docs=array_values(array_unique(array_map('intval',$found[1])));
        sort($docs,SORT_NUMERIC);
        if (!self::sha($cert['elementor_doc_fingerprint']??null)
            || !hash_equals($cert['elementor_doc_fingerprint'],hash('sha256',implode(',',$docs))))return null;
        $m=$cert['source_manifest'];
        $positions=self::extract($ctx,$html,$m['files']);
        if ($positions===null)return null;
        $total=0;$lastSlot=-1;$count=count($positions);
        foreach ($m['files'] as $i=>$entry) {
            if (!isset($positions[$i])||!is_array($entry)
                || ($entry['order']??null)!==$i
                || !is_int($entry['slot']??null)
                || $entry['slot']<$lastSlot
                || !is_int($entry['bytes']??null)
                || $entry['bytes']<1 || $entry['bytes']>6291456
                || !self::sha($entry['sha256']??null)
                || !is_string($entry['path']??null)
                || !preg_match('/^[a-z0-9\s(),:%.-]{1,180}$/D',$entry['media']??'')
                )return null;
            $lastSlot=$entry['slot'];
            $source=self::path($entry['path']);
            if (!$source||filesize($source)!==$entry['bytes']||
                !hash_equals($entry['sha256'],(string)hash_file('sha256',$source)))return null;
            $total+=$entry['bytes'];
            if ($total>self::MAX_SOURCE)return null;
        }
        if ($total!==$m['total_bytes'])return null;
        $chunks=[];$totalCritical=0;$lastChunk=-1;
        foreach ($cert['critical_slots'] as $row) {
            if (!is_array($row)||!is_int($row['slot']??null)
                || $row['slot']<0 || $row['slot']<=$lastChunk
                || !is_int($row['critical_bytes']??null)
                || $row['critical_bytes']<0
                || !self::sha($row['critical_sha256']??null))return null;
            $lastChunk=$row['slot'];
            $bytes=$row['critical_bytes'];$totalCritical+=$bytes;
            if ($totalCritical>self::MAX_CRITICAL)return null;
            $critical='';
            if ($bytes) {
                $filename=$row['critical_file']??null;
                if (!is_string($filename)||$filename!==$row['critical_sha256'].'.css')return null;
                $file=WP_CONTENT_DIR.self::ROOT.'/assets/'.$filename;
                if (is_link($file)||!is_file($file)||filesize($file)!==$bytes)return null;
                $critical=(string)file_get_contents($file);
                if (!hash_equals($row['critical_sha256'],hash('sha256',$critical))
                    || str_contains(strtolower($critical),'</style'))return null;
            } elseif (($row['critical_file']??null)!==null)return null;
            $chunks[$row['slot']]=$critical;
        }
        if ($totalCritical!==$cert['critical_total_bytes']||
            array_keys($chunks)!==array_values(array_unique(array_column($m['files'],'slot'))))return null;
        $changes=[];$used=[];
        foreach ($positions as $i=>[$tag,$offset,$slot,$media]) {
            $content='';
            if (!isset($used[$slot])) {
                $used[$slot]=true;
                if ($chunks[$slot]!=='') {
                    $content='<style id="mad4b-public-critical-css-v2-'.$slot
                        .'" data-mad4b-ccss-v2-critical="1" media="(max-width:767px)">'
                        .$chunks[$slot].'</style>';
                    self::$expected_styles++;
                }
            }
            $id='mad4b-public-css-rest-v2-'.$i;
            // Original stylesheet URL and media are already exact-manifest
            // verified. The full CSS stays at its original DOM position.
            $url=esc_url($m['files'][$i]['href']);
            $safeMedia=htmlspecialchars($media,ENT_QUOTES,'UTF-8');
            $content.='<link id="'.$id.'" data-mad4b-ccss-v2-rest="1" rel="stylesheet"'
                .' href="'.$url.'" data-mad4b-original-media="'.$safeMedia.'"'
                .' media="(min-width:768px)" fetchpriority="auto"'
                .' onload="this.onload=null;this.media=this.getAttribute(&#39;data-mad4b-original-media&#39;)" />';
            $content.='<noscript>'.$tag.'</noscript>';
            $changes[]=[$offset,strlen($tag),$content];
        }
        $result=$html;
        foreach (array_reverse($changes) as [$at,$length,$replacement])
            $result=substr_replace($result,$replacement,$at,$length);
        // A slow stylesheet, interrupted request, or browser extension
        // must not leave the full CSS stuck at the desktop-only media.
        // This is the same bounded recovery behavior used by the existing
        // v1 runtime, preserving each original CSS media after fallback.
        $recovery='<script id="mad4b-public-ccss-v2-recovery" data-no-optimize="1">'
            .'(function(){function f(){document.querySelectorAll("link[data-mad4b-ccss-v2-rest]").forEach(function(e){'
            .'if(e.media==="(min-width:768px)")e.media=e.getAttribute("data-mad4b-original-media")||"all";'
            .'});}window.addEventListener("load",f,{once:true});setTimeout(f,6500)})()</script>';
        $end=stripos($result,'</head>');
        if ($end===false)return null;
        $result=substr_replace($result,$recovery,$end,0);
        self::$expected_links=$count;
        self::$provisional=($cert['status']==='released_pending_qa');
        return $result;
    }
    public static function readback(string $html): bool {
        if (self::$expected_links<2)return false;
        $linkCount=preg_match_all('~<link\b[^>]*data-mad4b-ccss-v2-rest="1"[^>]*>~i',$html);
        $styleCount=preg_match_all('~<style\b[^>]*data-mad4b-ccss-v2-critical="1"[^>]*>~i',$html);
        $valid=(substr_count($html,'data-mad4b-ccss-v2-rest="1"')===self::$expected_links
            && substr_count($html,'data-mad4b-ccss-v2-critical="1"')===self::$expected_styles
            && $linkCount===self::$expected_links
            && $styleCount===self::$expected_styles
            && substr_count($html,'id="mad4b-public-ccss-v2-recovery"')===1
            && substr_count($html,'media="(min-width:768px)"')>=self::$expected_links
            && substr_count($html,'media="(max-width:767px)"')>=self::$expected_styles);
        self::$expected_links=0;self::$expected_styles=0;
        return $valid;
    }
}
