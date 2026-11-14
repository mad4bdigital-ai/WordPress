<?php
/**
 * Site-scoped, reversible Content Readiness remediation operator.
 * PHP CLI / WP-CLI only. Reads current MAD4B issue evidence, creates a
 * source-bound job plan, and applies an exact compare-and-swap on postmeta.
 * Never auto-publishes content or bypasses an existing publish transition.
 *
 * MAD4B_BRAND_MODE=plan|apply|verify
 * MAD4B_BRAND_PLAN=/tmp/mad4b-brand-content-plan.json
 * MAD4B_BRAND_CONFIRM=<expected plan SHA256> [apply]
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli')
    throw new RuntimeException('WordPress CLI is required');
if (!class_exists('MAD4B_SCP_Brand_Integrity') ||
    !class_exists('MAD4B_SCP_Brand_Experience_Quality') ||
    !class_exists('MAD4B_SCP_Content_Readiness_Alerts'))
    throw new RuntimeException('Original MAD4B Content Readiness provider unavailable');

global $wpdb;
$mode = (string)getenv('MAD4B_BRAND_MODE');
$plan_file = (string)getenv('MAD4B_BRAND_PLAN');
if (!in_array($mode,['plan','apply','verify'],true) ||
    !preg_match('~^/tmp/mad4b-brand-[a-z0-9_-]+\.json$~',$plan_file))
    throw new RuntimeException('Invalid mode or private plan location');

$brand=MAD4B_SCP_Brand_Experience_Quality::brand_context();
$scope=(array)($brand['scope']??[]);
$site=(string)($scope['site_uuid']??'');
$name=(string)($scope['brand_name']??'');
$rev=(int)($scope['revision']??0);
if (!preg_match('/^[a-f0-9-]{36}$/D',$site)||$name===''||$rev<1)
    throw new RuntimeException('Unverified site-bound Brand Core identity');
$host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
if (!$host) throw new RuntimeException('Missing site host');

$lexicon=[
    'en'=>['Explore our selection of','Egypt tours and experiences','Learn more about','our approach to travel'],
    'es'=>['Explora nuestra selección de','tours y experiencias en Egipto','Descubre más sobre','nuestra forma de viajar'],
    'fr'=>['Découvrez notre sélection de',"circuits et expériences en Égypte",'En savoir plus sur','notre approche du voyage'],
    'de'=>['Entdecken Sie unsere Auswahl an','Ägypten-Rundreisen und Erlebnissen','Erfahren Sie mehr über','unsere Reisephilosophie'],
    'it'=>['Scopri la nostra selezione di','tour ed esperienze in Egitto','Scopri di più su','il nostro modo di viaggiare'],
    'ar'=>['استكشف مجموعتنا من','الجولات والتجارب في مصر','تعرّف على','أسلوبنا في تنظيم الرحلات'],
];
function mad4b_brand_repair_lang($id) {
    $d=apply_filters('wpml_post_language_details',null,$id);
    $l=is_array($d)?(string)($d['language_code']??''):'';
    return in_array($l,['en','es','fr','de','it','ar'],true)?$l:'en';
}
function mad4b_brand_repair_new_link($lang,$lexicon) {
    $labels=$lexicon[$lang]??$lexicon['en'];
    $default=(string)apply_filters('wpml_default_language',null);
    if ($default==='')$default='en';
    $archive=get_post_type_archive_link('tours-and-activities');
    if (!$archive) throw new RuntimeException('Tour archive does not exist');
    $base=(string)wp_parse_url($archive,PHP_URL_PATH);
    $base='/'.ltrim($base,'/');
    $prefix=$lang===$default?'':'/'.$lang;
    $relative=$prefix.$base;
    if (!preg_match('~^/[a-z0-9/_-]+/?$~i',$relative))
        throw new RuntimeException('Unexpected locale archive route');
    $html='<p>'.esc_html($labels[0]).' <a href="'.esc_attr($relative).'">'.esc_html($labels[1]).'</a>.</p>';
    $about=get_page_by_path('about-us');
    if ($about instanceof WP_Post && $about->post_status==='publish') {
        $linked=$lang===$default?$about->ID:(int)apply_filters('wpml_object_id',$about->ID,'page',false,$lang);
        if ($linked && get_post_status($linked)==='publish') {
            $url=get_permalink($linked);
            if ($lang!==$default && $url)
                $url=apply_filters('wpml_permalink',$url,$lang,true);
            if (is_string($url) && strtolower((string)wp_parse_url($url,PHP_URL_HOST))===strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))) {
                $path=(string)wp_parse_url($url,PHP_URL_PATH);
                if (str_starts_with($path,'/'))
                    $html.="\n".'<p>'.esc_html($labels[2]).' <a href="'.esc_attr($path).'">'.esc_html($labels[3]).'</a>.</p>';
            }
        }
    }
    return $html."\n";
}
function mad4b_brand_repair_replace_leaf(array &$repeater, $field_path, $candidate, $replacement, $meta_key, $offset) {
    $prefix='meta.'.$meta_key.'.'.$offset.'.';
    if (!str_starts_with($field_path,$prefix))
        throw new RuntimeException('Repeater path must be exact and site-scoped');
    $segments=explode('.',substr($field_path,strlen($prefix)));
    if (count($segments)<2 || count($segments)>7)
        throw new RuntimeException('Unexpected repeater leaf depth');
    $cursor=&$repeater;
    foreach($segments as $segment){
        if (!is_array($cursor) || !array_key_exists($segment,$cursor))
            throw new RuntimeException('Expected repeater leaf does not exist');
        $cursor=&$cursor[$segment];
    }
    if (!is_string($cursor) || $candidate==='' || !str_contains($cursor,$candidate))
        throw new RuntimeException('Repeater source value changed or candidate absent');
    $cursor=str_replace($candidate,$replacement,$cursor);
}

function mad4b_brand_repair_digest(array $plan) {
    $copy=$plan; unset($copy['plan_sha256']);
    return hash('sha256',wp_json_encode($copy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function mad4b_brand_repair_assert_document($value,$key) {
    if (!is_string($value) || $value==='' || strlen($value)>1048576) throw new RuntimeException('Invalid content payload');
    if ($key==='tour_internal_linking_exploration_content') {
        if (!preg_match('/<a\s/i',$value) || preg_match('~href\s*=\s*["\x27]https?://~i',$value))
            throw new RuntimeException('Replacement promotional links are not site-relative');
    }
}
if ($mode==='plan') {
    $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
    $scan=MAD4B_SCP_Brand_Integrity::scan_batch(['tours-and-activities'],0,500);
    if (empty($scan['complete'])) throw new RuntimeException('Partial scan; refusing plan');
    $per_post=[];$review_ids=[];$admission_by_issue=[];$blocked_unadmitted=[];
    $default=(string)apply_filters('wpml_default_language',null);
    if($default==='')$default='en';
    $admission_index=[];
    foreach($ledger as $lid=>$stored){
        $request=(array)($stored['content_job_request']??[]);
        if (($request['site_uuid']??'')!==$site ||
            !in_array(($request['status']??''),['PRIORITY_INTAKE','LINKED'],true)) continue;
        $k=(int)($stored['object_id']??0).'|'.(string)($stored['code']??'');
        $admission_index[$k]=(string)$lid;
    }
    foreach ((array)$scan['issues'] as $issue) {
        $id=(int)$issue['object_id'];$e=(array)$issue['source_evidence'];
        $key=(string)($issue['id']??'');
        $stored=(array)($ledger[$key]??[]);
        if (($stored['status']??'')!=='open')
            throw new RuntimeException('Issue state changed before plan '.$key);
        $canonical=(int)apply_filters('wpml_object_id',$id,'tours-and-activities',false,$default);
        if($canonical<1 || get_post_status($canonical)!=='publish')$canonical=$id;
        // ContentJob intake is deliberately canonical-only. A translated
        // sibling inherits its actual source family's job admission rather
        // than fabricating one ContentJob per language.
        $admit=$admission_index[$id.'|'.(string)$issue['code']]
            ??$admission_index[$canonical.'|'.(string)$issue['code']]??'';
        if ($admit==='' || !isset($ledger[$admit])) {
            // A published translation whose canonical parent is only a draft
            // has no eligible native ContentJob request. Do not fabricate one.
            $blocked_unadmitted[]=[
                'issue_id'=>$key,'post_id'=>$id,'canonical_post_id'=>$canonical,
                'code'=>(string)$issue['code'],'language'=>mad4b_brand_repair_lang($id),
                'blocker'=>'missing_native_contentjob_admission'
            ];
            continue;
        }
        $request=(array)($ledger[$admit]['content_job_request']??[]);
        $admitted_target=(int)($request['source_post_id']??0);
        if (($request['site_uuid']??'')!==$site
            || !in_array($admitted_target,array($id,$canonical),true))
            throw new RuntimeException('ContentJob site or translated-family source mismatch '.$admit);
        if (($e['brand_scope']['site_uuid']??'')!==$site) throw new RuntimeException('Wrong site issue');
        $issue['admission_issue_id']=$admit;
        $per_post[$id][]=$issue;
        $admission_by_issue[$key]=$admit;
        $review_ids[$key]=true;
    }
    $changes=[];$skipped=[];
    foreach ($per_post as $id=>$issues) {
        if (get_post_status($id)!=='publish')throw new RuntimeException('Unexpected post status '.$id);
        $lang=mad4b_brand_repair_lang($id);
        $entries=$wpdb->get_results($wpdb->prepare(
            "SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key IN (%s,%s,%s,%s,%s) ORDER BY meta_id ASC",
             $id,'tour_internal_linking_exploration_content','tour_why_book_with_us',
             'itinerary_item_description','itinerary_item','_description'),ARRAY_A);
        $index=[];
        foreach((array)$entries as $entry){
            $key=(string)$entry['meta_key'];
            $offset=$index[$key]??0; $index[$key]=$offset+1;
            $path='meta.'.$key.'.'.$offset;
            $relevant=array_values(array_filter($issues,static function($issue) use ($path,$key){
                $at=(string)($issue['source_evidence']['field_path']??'');
                return $at===$path ||
                    ($key==='itinerary_item' && str_starts_with($at,$path.'.'));
            }));
            if (!$relevant) continue;
            $before=(string)$entry['meta_value'];
            $after=$before;
            if ($key==='tour_internal_linking_exploration_content') {
                $after=mad4b_brand_repair_new_link($lang,$lexicon);
            } elseif ($key==='itinerary_item') {
                if (!is_serialized($before)) throw new RuntimeException('Expected serialized JetEngine itinerary repeater');
                $decoded=maybe_unserialize($before);
                if (!is_array($decoded)) throw new RuntimeException('Invalid itinerary repeater data');
                foreach($relevant as $issue){
                    if (($issue['code']??'')!=='brand_identity_attribution_mismatch') continue;
                    $e=(array)$issue['source_evidence'];
                    mad4b_brand_repair_replace_leaf($decoded,(string)$e['field_path'],
                        (string)($e['matched_entity']??''),esc_html($name),$key,$offset);
                }
                // WordPress repeater data is serialized at the meta-value level:
                // serializing the modified array recalculates all byte lengths.
                $after=serialize($decoded);
                if(!is_array(maybe_unserialize($after)))
                    throw new RuntimeException('Serialized source readback invalid');
            } else {
                foreach($relevant as $issue) {
                    if (($issue['code']??'')!=='brand_identity_attribution_mismatch') continue;
                    $candidate=(string)($issue['source_evidence']['matched_entity']??'');
                    if ($candidate==='' || !str_contains($after,$candidate))
                        throw new RuntimeException('Candidate cannot be replaced in exact source field '.$id.'/'.$path);
                    $after=str_replace($candidate,esc_html($name),$after);
                }
            }
            mad4b_brand_repair_assert_document($after,$key);
            if ($after===$before) { $skipped[]=['id'=>$id,'field'=>$path,'reason'=>'no_change']; continue; }
            $changes[]=[
                'post_id'=>$id,'post_type'=>'tours-and-activities','language'=>$lang,
                'meta_id'=>(int)$entry['meta_id'],'meta_key'=>$key,'field_path'=>$path,
                'old_sha256'=>hash('sha256',$before),'new_sha256'=>hash('sha256',$after),
                'old_raw'=>$before,'new_raw'=>$after,
                'issue_ids'=>array_map(static fn($issue)=>(string)$issue['id'],$relevant),
                'admission_issue_ids'=>array_values(array_unique(array_map(
                    static fn($issue)=>(string)$issue['admission_issue_id'],$relevant))),
                'job_contract'=>'mad4b.brand-quality-contentjob-intake.v1',
            ];
        }
    }
    $plan=[
        'contract'=>'mad4b.brand-integrity-source-field-remediation.v1',
        'site_uuid'=>$site,'site_host'=>$host,'brand_id'=>(string)($scope['brand_id']??''),
        'brand_name'=>$name,'brand_revision'=>$rev,
        'brand_evidence_sha256'=>(string)($brand['brand_evidence_sha256']??''),
        'created_utc'=>gmdate('c'),'issues_scanned'=>count($scan['issues']),
        'issue_requests_matched'=>count($review_ids),'changes'=>$changes,'skipped'=>$skipped,'blocked_unadmitted'=>$blocked_unadmitted,
    ];
    $plan['plan_sha256']=mad4b_brand_repair_digest($plan);
    if(file_put_contents($plan_file,wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)===false)
        throw new RuntimeException('Cannot save plan');
    chmod($plan_file,0600);
    $by_key=[];$by_lang=[];$posts=[];
    foreach($changes as $r){$by_key[$r['meta_key']]=($by_key[$r['meta_key']]??0)+1;$by_lang[$r['language']]=($by_lang[$r['language']]??0)+1;$posts[$r['post_id']]=true;}
    echo wp_json_encode([
        'site'=>$site,'plan_sha256'=>$plan['plan_sha256'],
        'issue_requests_matched'=>count($review_ids),'changes'=>count($changes),
        'affected_posts'=>count($posts),'by_key'=>$by_key,'by_lang'=>$by_lang,
        'skipped'=>count($skipped),'blocked_unadmitted'=>count($blocked_unadmitted),'plan_file'=>$plan_file
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    return;
}
$plan=json_decode((string)file_get_contents($plan_file),true);
if(!is_array($plan) || ($plan['contract']??'')!=='mad4b.brand-integrity-source-field-remediation.v1'
   || ($plan['site_uuid']??'')!==$site || ($plan['site_host']??'')!==$host
   || ($plan['brand_id']??'')!==(string)($scope['brand_id']??'')
   || ($plan['brand_revision']??-1)!==$rev
   || ($plan['brand_evidence_sha256']??'')!==(string)($brand['brand_evidence_sha256']??'')
   || !hash_equals((string)($plan['plan_sha256']??''),mad4b_brand_repair_digest($plan)))
    throw new RuntimeException('Site identity or immutable source plan mismatch');

if($mode==='apply') {
    $confirm=(string)getenv('MAD4B_BRAND_CONFIRM');
    if(!hash_equals((string)$plan['plan_sha256'],$confirm))
        throw new RuntimeException('Required exact plan authorization missing');

    // All changes are checked before the first write, so a stale source or
    // a missing ContentJob request prevents partial batch application.
    $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
    $valid=0;
    foreach($plan['changes'] as $r){
        $raw=$wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d AND post_id=%d AND meta_key=%s",
            $r['meta_id'],$r['post_id'],$r['meta_key']));
        if (!is_string($raw) || hash('sha256',$raw)!==$r['old_sha256'] || $raw!==$r['old_raw'])
            throw new RuntimeException('Source drift (CAS failure) for meta '.$r['meta_id']);
        if (get_post_status((int)$r['post_id'])!=='publish')throw new RuntimeException('Post status drift');
        if(mad4b_brand_repair_lang((int)$r['post_id'])!==$r['language'])throw new RuntimeException('Language drift');
        foreach($r['issue_ids'] as $issue_id) {
            $stored=(array)($ledger[$issue_id]??[]);
            if (($stored['status']??'')!=='open') throw new RuntimeException('Issue status drift '.$issue_id);
        }
        foreach($r['admission_issue_ids'] as $issue_id) {
            $stored=(array)($ledger[$issue_id]??[]);
            $request=(array)($stored['content_job_request']??[]);
            if (($stored['status']??'')!=='open' || ($request['site_uuid']??'')!==$site ||
                !in_array(($request['status']??''),['PRIORITY_INTAKE','LINKED'],true))
                throw new RuntimeException('Canonical ContentJob intake drift '.$issue_id);
        }
        $valid++;
    }
    $applied=[];$affected=[];
    foreach ($plan['changes'] as $r) {
        $wpdb->query('START TRANSACTION');
        try {
            $current=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d FOR UPDATE",$r['meta_id']));
            if(!is_string($current)||$current!==$r['old_raw']) throw new RuntimeException('Concurrent edit detected');
            $updated=$wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND post_id=%d AND meta_key=%s AND meta_value=%s",
                $r['new_raw'],$r['meta_id'],$r['post_id'],$r['meta_key'],$r['old_raw']));
            if($updated!==1)throw new RuntimeException('Atomic CAS update failed');
            $readback=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$r['meta_id']));
            if(!is_string($readback)||hash('sha256',$readback)!==$r['new_sha256'])
                throw new RuntimeException('Write-readback SHA mismatch');
            $wpdb->query('COMMIT');
            $applied[]=$r['meta_id'];$affected[$r['post_id']]=true;
        } catch (Throwable $err) {
            $wpdb->query('ROLLBACK');
            echo wp_json_encode(['state'=>'PARTIAL_FAILURE','applied'=>count($applied),
                'failed_meta'=>$r['meta_id'],'reason'=>$err->getMessage()])."\n";
            throw $err;
        }
    }
    foreach(array_keys($affected) as $id){
        wp_cache_delete($id,'post_meta');clean_post_cache($id);
    }
    $receipt=[
        'contract'=>'mad4b.brand-integrity-contentjob-cas-receipt.v1',
        'site_uuid'=>$site,'brand_revision'=>$rev,
        'plan_sha256'=>$plan['plan_sha256'],'applied'=>count($applied),
        'affected_posts'=>count($affected),'completed_utc'=>gmdate('c'),
        'source'=>'root_host_wpcli_operator',
        'original313_release_receipt'=>false,
        'approved_content_job_execution_receipt'=>false,
        'post_meta_readback_verified'=>true
    ];
    $receipt_file=$plan_file.'.receipt';
    file_put_contents($receipt_file,wp_json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX);
    chmod($receipt_file,0600);
    echo wp_json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} elseif($mode==='verify'){
    $remaining=[];$good=0;$affected=[];
    foreach($plan['changes'] as $r){
        $val=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$r['meta_id']));
        if(is_string($val)&&hash('sha256',$val)===$r['new_sha256']){$good++;$affected[$r['post_id']]=true;}
        else $remaining[]=$r['meta_id'];
    }
    $scan=MAD4B_SCP_Brand_Integrity::scan_batch(['tours-and-activities'],0,500);
    $codes=[];
    foreach($scan['issues'] as $r)$codes[$r['code']]=($codes[$r['code']]??0)+1;
    echo wp_json_encode([
        'contract'=>'mad4b.brand-integrity-contentjob-verify.v1',
        'site_uuid'=>$site,'planned'=>count($plan['changes']),
        'verified'=>$good,'drift_meta_ids'=>$remaining,
        'scan_complete'=>$scan['complete'],'remaining_findings'=>$codes,
        'affected_posts'=>count($affected)
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}
