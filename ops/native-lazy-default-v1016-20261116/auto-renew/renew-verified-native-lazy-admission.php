<?php
/**
 * Root-invoked WP-CLI worker. Renews an existing valid site/market/locale
 * certificate only after fresh independent full-SSR vs real signed AJAX QA.
 * It does NOT mint a new cohort, allow expired proofs, or authorize Inner.
 */
if(!defined('WP_CLI') || !WP_CLI){exit(9);}
global $wpdb;
$mode=(string)(getenv('MAD4B_LAZY_RENEW_MODE')?:'check');
if(!in_array($mode,['check','validate','renew'],true))throw new RuntimeException('Invalid mode');
if((string)wp_parse_url(home_url('/'),PHP_URL_HOST)!=='tours.dev.mad4b.com'
    ||wp_get_environment_type()!=='staging')throw new RuntimeException('Not the enrolled site');
$option='mad4b_secondary_grid_lazy_layout_proofs_v1';
$registry=get_option($option,[]);
$cssEpoch=(int)get_option('mad4b_css_ai_epoch_v1',-1);
$market=mad4b_render_query_market_identity();
if(!is_array($market)||($market['country']??'')!=='DE'
   ||($market['currency']??'')!=='USD'){
    echo json_encode(['decision'=>'skip','reason'=>'different-WCML-market']),"\n";
    return;
}
if(!is_array($registry)||($registry['site_host']??'')!=='tours.dev.mad4b.com'
    ||($registry['contract']??'')!=='mad4b.native-listing.layout-proof.v1'
    ||(int)($registry['css_epoch']??-1)!==$cssEpoch
    ||(int)($registry['mobile_breakpoint']??-1)!==767){
    echo json_encode(['decision'=>'skip','reason'=>'invalid-source-epoch']),"\n";
    return;
}
$now=time();
$siteLocale=(string)apply_filters('wpml_current_language','en');
try{
    $items=[];
    foreach(['en'=>25927,'es'=>26001] as $lang=>$document){
        do_action('wpml_switch_language',$lang);
        if((string)apply_filters('wpml_current_language','')!==$lang)
            throw new RuntimeException('Missing WPML locale '.$lang);
        foreach(['mobile'=>[320,390,767],'wide'=>[768,1024,1920]] as $band=>$widths){
            $key='45938|'.$lang.'|'.$band;
            $item=$registry['entries'][$key]??[];
            $proof=$item['ajax_parity_by_market']['de-usd']??[];
            if(!is_array($proof)||($proof['status']??'')!=='pass'
               ||($proof['contract']??'')!=='mad4b.native-listing.ajax-parity.v2'
               ||(int)($proof['query_id']??0)!==291
               ||(int)($proof['document_id']??0)!==$document
               ||($proof['market']??'')!=='de-usd'
               ||($proof['locale']??'')!==$lang
               ||($proof['signed_ajax_verified']??false)!==true
               ||($proof['semantic_output_equivalent']??false)!==true){
                echo json_encode(['decision'=>'skip','reason'=>'proof-not-current','cohort'=>$key]),"\n";
                return;
            }
            $age=$now-(int)($proof['audited_at']??0);
            if($age<0||$age>7*DAY_IN_SECONDS){
                echo json_encode(['decision'=>'skip','reason'=>'proof-expired','cohort'=>$key]),"\n";
                return;
            }
            if(($proof['document_sha256']??'')!==hash('sha256',(string)get_post_meta($document,'_elementor_data',true))
               ||($proof['query_generation']??'')!==mad4b_render_query_generation_for_id(291)){
                echo json_encode(['decision'=>'skip','reason'=>'document-or-query-changed','cohort'=>$key]),"\n";
                return;
            }
            $listSource=(string)get_post_meta(45938,'_elementor_data',true);
            $css=WP_CONTENT_DIR.'/uploads/elementor/css/post-45938.css';
            if(strlen($listSource)<200 || !is_file($css)||is_link($css)
                ||($item['listing_source_sha256']??'')!==hash('sha256',$listSource)
                ||($item['listing_css_sha256']??'')!==hash_file('sha256',$css)){
                echo json_encode(['decision'=>'skip','reason'=>'listing-source-changed','cohort'=>$key]),"\n";
                return;
            }
            $actual=array_values(array_map('intval',(array)($proof['tested_widths']??[])));
            sort($actual);
            if($actual!==$widths){
                echo json_encode(['decision'=>'skip','reason'=>'viewport-coverage-changed','cohort'=>$key]),"\n";
                return;
            }
            $items[$key]=['age'=>$age,'proof'=>$proof];
        }
    }
    if(count($items)!==4)throw new RuntimeException('Incomplete cohorts');
    $oldest=max(array_column($items,'age'));
    if($mode==='check'){
        $due=$oldest>=4*DAY_IN_SECONDS;
        echo json_encode(['decision'=>$due?'due':'skip',
           'reason'=>$due?'due-for-refresh':'certificate-young',
           'oldest_age_seconds'=>$oldest,
           'remaining_seconds'=>7*DAY_IN_SECONDS-$oldest,'cohorts'=>count($items)]),"\n";
        return;
    }
    $path=(string)getenv('MAD4B_LAZY_RENEW_EVIDENCE');
    if($path!=='/tmp/mad4b-lazy-renew-audit.jsonl'||!is_file($path)
       ||is_link($path)||filesize($path)<100||filesize($path)>200000)
       throw new RuntimeException('Unsafe browser evidence input');
    $raw=file_get_contents($path);
    $evidence=[];
    $completion=0;
    $started=null;$finished=null;
    foreach(explode("\n",$raw) as $line){
        if(str_starts_with($line,'RENEW_STARTED ')){
            if($started!==null)throw new RuntimeException('Duplicate audit timestamp');
            $started=strtotime(substr($line,14));
        }
        if(str_starts_with($line,'RENEW_FINISHED ')){
            if($finished!==null)throw new RuntimeException('Duplicate audit completion time');
            $finished=strtotime(substr($line,15));
        }
        if(str_starts_with($line,'RENEW_PASS ')){
            if(trim($line)!=='RENEW_PASS 12')throw new RuntimeException('Unexpected audit completion');
            ++$completion;
        }
        if(!str_starts_with($line,'RENEW_CASE '))continue;
        $row=json_decode(substr($line,11),true);
        if(!is_array($row)||($row['pass']??false)!==true)
            throw new RuntimeException('Browser reported failed parity');
        $locale=(string)($row['locale']??'');
        $width=(int)($row['width']??0);
        if(!in_array($locale,['en','es'],true)
           ||!in_array($width,[320,390,767,768,1024,1920],true)
           ||($row['signed_ajax_verified']??false)!==true
           ||!is_numeric($row['max_cls']??null)
           ||(float)$row['max_cls']<0||(float)$row['max_cls']>0.10)
           throw new RuntimeException('Bad browser row');
        $rowKey=$locale.'|'.$width;
        if(isset($evidence[$rowKey]))throw new RuntimeException('Duplicate viewport');
        $evidence[$rowKey]=$row;
    }
    if($completion!==1||count($evidence)!==12)
       throw new RuntimeException('Missing complete 12-width evidence');
    if(!is_int($started)||!is_int($finished)
       ||$started>$finished ||$finished-$started>20*60
       ||abs($now-$finished)>10*60)
       throw new RuntimeException('Stale, long-running or replayed browser evidence');
    foreach($items as $key=>$item){
        [$listingId,$lang,$band]=explode('|',$key);
        $widths=$band==='mobile'?[320,390,767]:[768,1024,1920];
        foreach($widths as $width){
            $report=$evidence[$lang.'|'.$width]??null;
            $prior=$item['proof']['viewport_evidence'][(string)$width]??null;
            if(!is_array($prior)||!is_array($report)
               ||(int)($report['ssr_items']??0)<2
               ||(int)($report['ssr_items']??0)!==(int)($report['ajax_items']??-1)
               ||(int)($report['ssr_items']??0)!==(int)($prior['ssr_items']??-1)
               ||(int)($report['ssr_height_px']??0)!==(int)($prior['ssr_height_px']??-1)
               ||(int)($report['reserved_height_px']??0)!==(int)($prior['reserved_height_px']??-1)
               ||(int)($report['ssr_width_px']??0)!==(int)($prior['ssr_width_px']??-1)
               ||(int)($report['reserved_width_px']??0)!==(int)($prior['reserved_width_px']??-1)){
               throw new RuntimeException('Evidence counts/footprint changed '.$lang.'/'.$width);
            }
            foreach(['content','links'] as $field){
                foreach(['ssr','ajax'] as $kind){
                    $name=$kind.'_'.$field.'_sha256';
                    $given=(string)($report[$name]??'');
                    if(!preg_match('/^[a-f0-9]{64}$/D',$given)
                       ||$given!==(string)($prior[$name]??'')){
                        throw new RuntimeException('Browser content drift '.$lang.'/'.$width.'/'.$name);
                    }
                }
            }
        }
    }
    if($mode==='validate'){
        echo 'PASS 12/12 browser parity; source+market+WPML+CSS+all stored fingerprints match'."\n";
        return;
    }
    // Never silently re-enroll expired, held or nonparticipating cohorts.
    if($oldest<4*DAY_IN_SECONDS){
        echo json_encode(['decision'=>'skip','reason'=>'not-due','oldest_age_seconds'=>$oldest]),"\n";
        return;
    }
    $lock='mad4b_lazy_renew_de_usd_en_es_v1';
    if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,8)',$lock))!==1)
       throw new RuntimeException('Another browser auditor is updating');
    try{
        $original=$wpdb->get_var($wpdb->prepare(
          "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$option));
        if(!is_string($original)||!hash_equals($original,maybe_serialize($registry)))
           throw new RuntimeException('Proof changed since preflight');
        foreach(['en','es'] as $lang)foreach(['mobile','wide'] as $band){
            $key='45938|'.$lang.'|'.$band;
            $proof=&$registry['entries'][$key]['ajax_parity_by_market']['de-usd'];
            $proof['audited_at']=$now;
            $max=0.0;
            foreach($proof['tested_widths'] as $width){
                $newCls=(float)$evidence[$lang.'|'.$width]['max_cls'];
                $proof['viewport_evidence'][(string)$width]['max_cls']=$newCls;
                $max=max($max,$newCls);
            }
            $proof['max_cls']=$max;
            $proof['renewed_by']='mad4b.native-lazy.browser-parity-v1';
            $proof['browser_evidence_sha256']=hash('sha256',$raw);
            unset($proof);
        }
        $updated=$wpdb->query($wpdb->prepare(
          "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
          maybe_serialize($registry),$option,$original));
        if($updated!==1)throw new RuntimeException('Concurrent proof registry change');
        wp_cache_delete($option,'options');
        wp_cache_delete('alloptions','options');
        echo "PASS renewed 4/4 active DE-USD certs after independent 12/12 browser proof\n";
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
} finally {
    if($siteLocale!=='')do_action('wpml_switch_language',$siteLocale);
}
