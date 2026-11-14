<?php
/**
 * Plugin Name: MAD4B Story & Media Observability
 * Description: Bounded per-tour media, itinerary, provenance, locale parity and gallery health monitoring; read-only to content.
 * Version: 2.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Story_Observability_V2 {
    const CONTRACT='mad4b.story-observability.v2';
    const STATE='mad4b_story_observability_v2_published';
    const STAGE='mad4b_story_observability_v2_stage';
    const LOCK='mad4b_story_observability_v2_lock';
    const AUDIT='mad4b_story_observability_v2_runs';
    const QA='mad4b_story_observability_v2_browser_qa';
    const MAX_EXPOSED=18;
    const SCAN_TTL=86400;
    private static function site():string {
        return class_exists('MAD4B_SCP_Site_Profile',false)?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
    }
    private static function canonical(int $post):int {
        $lang=(string)apply_filters('wpml_default_language','en');
        $c=(int)apply_filters('wpml_object_id',$post,'tours-and-activities',false,$lang);
        if($c&&get_post_status($c)==='publish')return $c;
        // Missing English master? Observe the other published translations as
        // ONE family rather than generating five unrelated high alerts.
        $trid=apply_filters('wpml_element_trid',null,$post,'post_tours-and-activities');
        $rows=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities'):[];
        $candidates=[];
        foreach((array)$rows as $x){
            $id=(int)($x->element_id??0);
            if($id&&get_post_status($id)==='publish')$candidates[]=$id;
        }
        return $candidates?min($candidates):$post;
    }
    private static function ids():array {
        global $wpdb;
        $all=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s ORDER BY ID",'tours-and-activities','publish'));
        $map=[];
        foreach((array)$all as $id){$id=(int)$id;$c=self::canonical($id);
            if(get_post_type($c)==='tours-and-activities'&&get_post_status($c)==='publish')$map[$c]=true;
        }
        $ids=array_map('intval',array_keys($map));sort($ids,SORT_NUMERIC);return $ids;
    }
    private static function translations(int $canonical):array {
        $trid=apply_filters('wpml_element_trid',null,$canonical,'post_tours-and-activities');
        $records=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities'):[];
        $out=[];
        foreach((array)$records as $lang=>$entry){
            $id=(int)($entry->element_id??0);
            if($id>0&&get_post_status($id)==='publish')$out[(string)$lang]=$id;
        }
        if(!$out)$out[(string)apply_filters('wpml_default_language','en')]=$canonical;
        ksort($out);return $out;
    }
    private static function issue(int $tour,string $code,string $severity,array $evidence=[]):array {
        $allowed=['story_empty'=>'high','story_wpml_parity'=>'high','story_duplicate_origin'=>'high',
          'story_destination_media_mismatch'=>'medium','story_curated_attachment_unavailable'=>'high',
          'story_unmapped_visit_days'=>'medium','story_monitor_stale'=>'medium',
          'story_gallery_assets_missing'=>'high','story_wpml_source_missing'=>'high',
          'story_monitor_alerts_truncated'=>'medium',
          'story_provenance_review_required'=>'medium','story_browser_popup_failure'=>'high',
          'story_browser_qa_stale'=>'medium','story_browser_qa_missing'=>'medium',
          'story_browser_qa_suspected_failure'=>'medium','story_browser_environment_inconclusive'=>'medium'];
        if(($allowed[$code]??'')!==$severity)throw new InvalidArgumentException('Invalid story signal');
        $labels=[
          'story_empty'=>'Tour photo sections have no eligible local images',
          'story_wpml_parity'=>'Tour photos differ between published languages',
          'story_duplicate_origin'=>'The same original image appears twice inside one tour',
          'story_wpml_source_missing'=>'Published translations lack the primary source language',
          'story_destination_media_mismatch'=>'Destination image metadata refers to another city',
          'story_curated_attachment_unavailable'=>'Curated tour photograph is unavailable or disallowed',
          'story_unmapped_visit_days'=>'Itinerary visit days lack a matching photo chapter',
          'story_gallery_assets_missing'=>'All Photos popup script or stylesheet is missing',
          'story_monitor_stale'=>'Photo monitoring results need a fresh scan',
          'story_monitor_alerts_truncated'=>'Additional photo alerts need review in the full monitoring report',
          'story_provenance_review_required'=>'Photographic provenance requires editorial verification before certification',
          'story_browser_popup_failure'=>'All Photos popup failed its live browser verification',
          'story_browser_qa_stale'=>'All Photos browser verification is outdated after a code or time change',
          'story_browser_qa_missing'=>'No recent live browser verification has been recorded for All Photos',
          'story_browser_qa_suspected_failure'=>'One browser check failed; confirm in another run before escalation',
          'story_browser_environment_inconclusive'=>'Browser check could not reach the gallery; environment or network needs review',
        ];
        return ['id'=>hash('sha256',get_current_blog_id().'|tours-and-activities|'.$tour.'|'.$code),
          'source'=>'mad4b-story-observability','code'=>$code,'severity'=>$severity,
          'object_id'=>$tour,'post_type'=>'tours-and-activities',
          'label'=>$labels[$code]??'Tour photo monitoring issue',
          'evidence'=>$evidence];
    }
    private static function content_sha(int $id):string {
        $keys=['gallery','image_vertical','grid_image_1_middle_upper','grid_image_2',
            'grid_image_3_middle_bottom','grid_image_4_','itinerary_item','tour_highlights_content',
            '_mad4b_story_section_curated_ids_v1','_thumbnail_id'];
        $pieces=['post_id'=>$id,'modified'=>(string)get_post_field('post_modified_gmt',$id),
            'media_revision'=>(int)get_option('mad4b_story_gallery_media_revision_v1',1)];
        foreach($keys as $key)$pieces[$key]=get_post_meta($id,$key,true);
        return hash('sha256',wp_json_encode($pieces,JSON_UNESCAPED_SLASHES));
    }
    public static function inspect(int $post):array {
        $canonical=self::canonical($post);
        if(get_post_status($canonical)!=='publish'||get_post_type($canonical)!=='tours-and-activities')
            return ['ok'=>false,'reason'=>'unpublished'];
        if(!class_exists('MAD4B_Tour_Story_Gallery_V1',false)||!class_exists('MAD4B_Tour_Photo_Quality_V1',false))
            return ['ok'=>false,'reason'=>'media_dependencies_missing'];
        $t0=microtime(true);$origLang=(string)apply_filters('wpml_current_language','en');
        $default=(string)apply_filters('wpml_default_language','en');$locales=self::translations($canonical);
        $metrics=[];$signals=[];$expected=[];
        if(!isset($locales[$default]))$signals['story_wpml_source_missing']=self::issue($canonical,
          'story_wpml_source_missing','high',['missing_source_locale'=>$default,
            'available_locales'=>array_keys($locales),'published_family_count'=>count($locales)]);
        try {
            foreach($locales as $lang=>$id){
                do_action('wpml_switch_language',$lang);
                $story=MAD4B_Tour_Story_Gallery_V1::build($id);
                $origins=[];$empty=0;$badUrls=0;$photos=0;
                foreach((array)($story['sections']??[]) as $section){
                    foreach((array)($section['images']??[]) as $image){
                        $photos++;$origins[]=(string)($image['origin']??'');
                        $url=(string)($image['url']??'');
                        if(!str_starts_with($url,'https://')&&!str_starts_with($url,'http://'))$badUrls++;
                    }
                }
                sort($origins,SORT_STRING);
                $metrics[$lang]=['post_id'=>$id,'photos'=>$photos,'unique_origins'=>count(array_unique($origins)),
                    'section_count'=>count((array)($story['sections']??[])),
                    'coverage_gap_count'=>count((array)($story['coverage_gaps']??[])),
                    'origin_list_sha256'=>hash('sha256',implode('|',$origins)),
                    'invalid_image_urls'=>$badUrls];
                if($id===$canonical)$expected=$origins;
                if($photos===0 || count((array)($story['sections']??[]))===0)$empty++;
                if($empty) $signals['story_empty']=self::issue($canonical,'story_empty','high',['locales'=>array_keys($metrics)]);
                if(count($origins)!==count(array_unique($origins)))
                    $signals['story_duplicate_origin']=self::issue($canonical,'story_duplicate_origin','high',
                        ['locale'=>$lang,'duplicates'=>count($origins)-count(array_unique($origins))]);
            }
        } finally {if($origLang!=='')do_action('wpml_switch_language',$origLang);}
        $reference=$metrics[$default]??reset($metrics);
        $mismatches=[];
        foreach($metrics as $lang=>$row) {
            if($row['origin_list_sha256']!==($reference['origin_list_sha256']??''))$mismatches[$lang]=[
              'post_id'=>$row['post_id'],'photos'=>$row['photos'],'sections'=>$row['section_count']];
        }
        if($mismatches)$signals['story_wpml_parity']=self::issue($canonical,'story_wpml_parity','high',
            ['canonical_photo_count'=>$reference['photos']??0,'divergent_languages'=>$mismatches]);
        // Canonical source audit is independent of visitor locale to avoid false differences.
        $canonicalStory=MAD4B_Tour_Story_Gallery_V1::build($canonical);
        $curated=get_post_meta($canonical,'_mad4b_story_section_curated_ids_v1',true);
        if(is_array($curated)){
            $approved=[];foreach($canonicalStory['sections']??[] as $section)foreach(($section['images']??[]) as $image)$approved[(int)$image['id']]=true;
            $unavailable=[];
            foreach($curated as $term=>$items)foreach((array)$items as $item){
                $id=(int)($item['attachment_id']??0);
                if($id<=0||isset($approved[$id]))continue;
                $f=$id?get_attached_file($id):'';
                $p=$id?MAD4B_Tour_Photo_Quality_V1::provenance($id):[];
                if(!$f||!is_file($f)||($p['status']??'')==='synthetic_confirmed')$unavailable[]=['term_id'=>(int)$term,'attachment_id'=>$id];
            }
            if($unavailable)$signals['story_curated_attachment_unavailable']=self::issue($canonical,
                'story_curated_attachment_unavailable','high',['items'=>array_slice($unavailable,0,10)]);
        }
        // A specified destination's featured image containing another city's
        // name is a REVIEW candidate, not proof of actual photo subject.
        $city_names=['Sharm el-Sheikh'=>'hurghada','Hurghada'=>'sharm'];
        $terms=wp_get_object_terms($canonical,'destinations',['fields'=>'all']);
        if(!is_wp_error($terms)&&is_array($terms)){
            $references=[];
            foreach($terms as $term){
                $all_ids=array_merge([$term->term_id],get_ancestors($term->term_id,'destinations','taxonomy'));
                foreach($all_ids as $tid){
                    $t=get_term((int)$tid,'destinations');if(!$t||is_wp_error($t))continue;
                    foreach($city_names as $city=>$other){
                        if(strcasecmp($t->name,$city)!==0)continue;
                        $url=(string)get_term_meta((int)$tid,'featured_image',true);
                        if($url!==''&&stripos($url,$other)!==false)$references[]=['term_id'=>(int)$tid,'term_name'=>$t->name,
                            'other_city_in_filename'=>$other,'photo_url_sha256'=>hash('sha256',$url)];
                    }
                }
            }
            if($references)$signals['story_destination_media_mismatch']=self::issue($canonical,
                'story_destination_media_mismatch','medium',['review'=>array_slice($references,0,8)]);
        }
        $sourceDays=get_post_meta($canonical,'itinerary_item',true);$mapped=[];
        foreach($canonicalStory['sections']??[] as $section)foreach($section['days']??[] as $day)$mapped[(int)$day]=true;
        $unmapped=[];
        foreach((array)$sourceDays as $index=>$day){
            if(!is_array($day))continue;
            $title=wp_strip_all_tags((string)($day['title']??''));
            // One-day tour steps are not independent itinerary DAYS.
            if(!preg_match('/\bDay\s*([1-9][0-9]*)\b/i',$title,$match))continue;
            $number=(int)$match[1];
            if(isset($mapped[$number])||preg_match('/\boptional\b/i',$title))continue;
            if(preg_match('/\b(?:visit|explore|discover|sightseeing|temple|museum|pyramids)\b/i',$title))
                $unmapped[]=$number;
        }
        if(count($unmapped)>=2)$signals['story_unmapped_visit_days']=self::issue($canonical,
            'story_unmapped_visit_days','medium',['unmapped_visit_days'=>array_slice(array_unique($unmapped),0,20)]);
        $assetBase=WP_CONTENT_DIR.'/mu-plugins/assets/';
        $native=self::current_renderer()==='uael';
        $nativeFiles=$native?self::active_uael_files():[];
        $storyScripts=glob($assetBase.'mad4b-tour-story-gallery.1.2.0-*.js')?:[];
        $assetsHealthy=$native?count($nativeFiles)===2:
            count($storyScripts)===1&&is_file($assetBase.'mad4b-tour-story-gallery.css');
        if(!$assetsHealthy)
            $signals['story_gallery_assets_missing']=self::issue($canonical,'story_gallery_assets_missing','high',
                ['active_renderer'=>$native?'uael':'story',
                'javascript_and_css_present'=>$assetsHealthy]);
        $unknown=0;$count=0;
        foreach($canonicalStory['sections']??[] as $section)foreach($section['images']??[] as $photo){
            $count++;$p=MAD4B_Tour_Photo_Quality_V1::provenance((int)$photo['id']);
            if(($p['status']??'')==='origin_unverified')$unknown++;
        }
        $metrics['_summary']=['canonical_post_id'=>$canonical,'published_languages'=>count($locales),
          'photo_count'=>$count,'photo_provenance_unverified'=>$unknown,
          'geo_review_count'=>count((array)($canonicalStory['geography_review']??[])),
          'coverage_gaps'=>count((array)($canonicalStory['coverage_gaps']??[])),
          'duration_ms'=>round((microtime(true)-$t0)*1000,2)];
        return ['ok'=>true,'canonical_post_id'=>$canonical,'updated_at'=>gmdate('c'),
          'input_sha256'=>self::content_sha($canonical),'locales'=>$metrics,'alerts'=>array_values($signals)];
    }
    private static function log(string $event,array $payload=[]):void {
        $rows=get_option(self::AUDIT,[]);if(!is_array($rows))$rows=[];
        $rows[]=['event'=>$event,'at'=>gmdate('c'),'site_uuid'=>self::site(),
          'data'=>array_intersect_key($payload,array_flip(['run_id','scanned','remaining','duration_ms','alerts','status','reason']))];
        update_option(self::AUDIT,array_slice($rows,-80),false);
    }
    private static function release(string $token):void {
        $lock=get_option(self::LOCK,[]);
        if(is_array($lock)&&($lock['token']??'')===$token)delete_option(self::LOCK);
    }
    public static function scan(int $limit=4):array {
        $limit=max(1,min(12,$limit));
        $lock=get_option(self::LOCK,[]);
        if(is_array($lock)&&!empty($lock)&&time()-(int)($lock['at']??0)>900)delete_option(self::LOCK);
        $token=wp_generate_uuid4();
        if(!add_option(self::LOCK,['at'=>time(),'token'=>$token],'','no'))return ['status'=>'locked','contract'=>self::CONTRACT];
        $t0=microtime(true);
        try {
            $ids=self::ids();$stage=get_option(self::STAGE,[]);
            $newCycle=!is_array($stage)||($stage['ids']??null)!==$ids||time()-(int)($stage['started']??0)>21600;
            if($newCycle)$stage=['run_id'=>wp_generate_uuid4(),'started'=>time(),'ids'=>$ids,'cursor'=>0,'records'=>[]];
            $slice=array_slice($ids,(int)$stage['cursor'],$limit);
            foreach($slice as $id){$result=self::inspect((int)$id);
                if(empty($result['ok'])){
                    self::log('scan_partial',['run_id'=>$stage['run_id'],'reason'=>$result['reason']??'unknown']);
                    return ['status'=>'incomplete','reason'=>$result['reason']??'unknown','run_id'=>$stage['run_id']];
                }
                $stage['records'][(int)$id]=$result;
            }
            $stage['cursor']+=count($slice);
            $complete=$stage['cursor']>=count($ids);
            if($complete){
                $pub=['contract'=>self::CONTRACT,'site_uuid'=>self::site(),'run_id'=>$stage['run_id'],
                    'completed_at'=>gmdate('c'),'records'=>$stage['records'],
                    'families'=>count($ids),'snapshot_status'=>'complete'];
                update_option(self::STATE,$pub,false);delete_option(self::STAGE);
            }else update_option(self::STAGE,$stage,false);
            $duration=round((microtime(true)-$t0)*1000,1);
            self::log($complete?'scan_completed':'scan_partial',['run_id'=>$stage['run_id'],'scanned'=>count($slice),
                'remaining'=>max(0,count($ids)-$stage['cursor']),'duration_ms'=>$duration,'status'=>$complete?'complete':'partial']);
            return ['contract'=>self::CONTRACT,'status'=>$complete?'complete':'partial','run_id'=>$stage['run_id'],
                'scanned'=>count($slice),'remaining'=>max(0,count($ids)-$stage['cursor']),
                'families_total'=>count($ids),'duration_ms'=>$duration];
        } catch(Throwable $e){self::log('scan_failed',['reason'=>substr(get_class($e),0,64)]);
            return ['status'=>'error','reason'=>get_class($e),'contract'=>self::CONTRACT];
        } finally {self::release($token);}
    }
    public static function scan_one(int $id):array {
        $r=self::inspect($id);if(empty($r['ok']))return $r;
        $state=get_option(self::STATE,[]);
        if(($state['contract']??'')!==self::CONTRACT)return ['status'=>'baseline_required','record'=>$r];
        $state['records'][$r['canonical_post_id']]=$r;
        $state['updated_at']=gmdate('c');update_option(self::STATE,$state,false);
        self::log('single_scanned',['scanned'=>1,'alerts'=>count($r['alerts'])]);
        return ['status'=>'single_updated','canonical_post_id'=>$r['canonical_post_id'],
            'alerts'=>array_column($r['alerts'],'code'),'result'=>$r];
    }
    public static function report():array {
        $s=get_option(self::STATE,[]);$stage=get_option(self::STAGE,[]);
        $codes=[];$total=0;$locales=[];$sum=0;$unknown=0;
        foreach((array)($s['records']??[]) as $record){
            foreach((array)($record['alerts']??[]) as $issue){$code=$issue['code'];$codes[$code]=($codes[$code]??0)+1;$total++;}
            $info=$record['locales']['_summary']??[];
            $sum+=(int)($info['photo_count']??0);$unknown+=(int)($info['photo_provenance_unverified']??0);
            foreach((array)($record['locales']??[]) as $lang=>$v)if($lang!=='_summary')$locales[$lang]=($locales[$lang]??0)+1;
        }
        ksort($codes);ksort($locales);
        $health=self::stale_diagnostics((array)$s);
        return ['contract'=>self::CONTRACT,'site_uuid'=>self::site(),
          'snapshot_health'=>$health,
          'baseline_complete'=>($s['snapshot_status']??'')==='complete',
          'completed_at'=>$s['completed_at']??null,'families'=>(int)($s['families']??0),
          'staging_remaining'=>isset($stage['ids'])?max(0,count($stage['ids'])-(int)$stage['cursor']):0,
          'alerts_total'=>$total,'codes'=>$codes,'published_locale_counts'=>$locales,
          'photo_count_in_canonical_stories'=>$sum,'photo_provenance_unverified'=>$unknown,
          'age_seconds'=>isset($s['completed_at'])?max(0,time()-(strtotime($s['completed_at'])?:time())):null,
          'browser_qa'=>self::browser_health(),
          'runs'=>array_slice((array)get_option(self::AUDIT,[]),-5)];
    }
    private static function active_uael_files():array {
        $root=WP_CONTENT_DIR.'/mu-plugins/';
        $code=@file_get_contents($root.'mad4b-uael-tour-gallery-addon.php');
        if(!is_string($code)||$code==='')return [];
        $files=[];
        foreach(['js','css'] as $ext){
            // Parse the actual approved WordPress enqueue source instead of
            // pinning a version that changes after a legitimate release.
            $pattern='/\$base\.\x27(mad4b-uael-tour-addon-[a-z0-9.-]+\.'.$ext.')\x27/i';
            if(!preg_match($pattern,$code,$match))return [];
            $file='assets/'.$match[1];
            if(basename($file)!==$match[1]||!is_file($root.$file))return [];
            $files[]=$file;
        }
        return $files;
    }
    public static function current_renderer():string {
        return (string)get_option('mad4b_uael_tour_gallery_mode','off')==='active'?'uael':'story';
    }
    public static function current_asset_sha():string {
        $root=WP_CONTENT_DIR.'/mu-plugins/';
        $files=['mad4b-tour-story-gallery.php'];
        if(self::current_renderer()==='uael'){
            $native=self::active_uael_files();
            if(count($native)!==2)return '';
            $files[]='mad4b-uael-tour-gallery-addon.php';
            $files=array_merge($files,$native);
        }else{
            $list=glob($root.'assets/mad4b-tour-story-gallery.1.2.0-*.js')?:[];
            if(count($list)!==1)return '';
            $files[]=substr($list[0],strlen($root));
            $files[]='assets/mad4b-tour-story-gallery.css';
        }
        $proof=['renderer'=>self::current_renderer()];
        foreach($files as $file){
            if(!is_file($root.$file))return '';
            $proof[$file]=hash_file('sha256',$root.$file);
        }
        return hash('sha256',wp_json_encode($proof,JSON_UNESCAPED_SLASHES));
    }
    public static function browser_health():array {
        $row=get_option(self::QA,[]);
        if(!is_array($row)||($row['contract']??'')!=='mad4b.story-browser-qa.v1')
            return ['status'=>'missing','case_count'=>0,'failed_cases'=>0];
        $age=max(0,time()-(strtotime((string)$row['checked_at'])?:time()));
        $sha=self::current_asset_sha();
        $expired=$age>36*3600||$sha===''||!hash_equals($sha,(string)$row['asset_sha256']);
        $fail=(int)$row['failed_cases'];
        $harnessFailures=0;
        foreach((array)($row['cases']??[]) as $case){
            if(!empty($case['success']))continue;
            $code=(string)($case['error_code']??'');
            if(in_array($code,['timeout_navigate','timeout_browser_launch','browser_launch_failed','http_error'],true))$harnessFailures++;
        }
        $status=$expired?'stale':($fail===0?'passed':
            ($harnessFailures===$fail?'inconclusive':
            ((int)($row['consecutive_failures']??1)>=2?'failed':'suspected_failure')));
        return ['status'=>$status,
            'checked_at'=>$row['checked_at'],'age_seconds'=>$age,'case_count'=>count($row['cases']),
            'failed_cases'=>$fail,'consecutive_failures'=>(int)($row['consecutive_failures']??0),
            'browser_asset_matches'=>!$expired,'renderer'=>self::current_renderer(),
            'failed_case_ids'=>array_values(array_map(fn($x)=>$x['case_id'],
                array_filter($row['cases'],fn($x)=>empty($x['success']))))];
    }
    public static function import_browser_qa(string $path):array {
        if(!defined('WP_CLI')||!WP_CLI)return ['status'=>'cli_required'];
        if(!preg_match('#^/tmp/mad4b-story-browser-qa-[a-z0-9_-]+\.json$#D',$path)||
           !is_file($path)||filesize($path)>100000)return ['status'=>'invalid_local_input'];
        $data=json_decode((string)file_get_contents($path),true);
        if(!is_array($data)||($data['contract']??'')!=='mad4b.story-browser-qa.v1'||
           ($data['site_uuid']??'')!==self::site()||
           rtrim((string)($data['site_url']??''),'/')!==rtrim(home_url(),'/')||
           ($data['asset_sha256']??'')!==self::current_asset_sha())return ['status'=>'invalid_site_evidence'];
        $stamp=strtotime((string)($data['checked_at']??''));
        if(!$stamp||abs(time()-$stamp)>1800)return ['status'=>'stale_input'];
        $cases=$data['cases']??null;
        if(!is_array($cases)||count($cases)<2||count($cases)>8)return ['status'=>'invalid_cases'];
        $allowed=['en_desktop','en_mobile','es_mobile','es_desktop','ar_mobile','fr_mobile','de_mobile','it_mobile'];
        $seen=[];$out=[];$failures=0;
        foreach($cases as $case){
            if(!is_array($case)||!in_array($case['case_id']??'', $allowed,true)||
               isset($seen[$case['case_id']])||!is_bool($case['success']??null))return ['status'=>'invalid_case'];
            $seen[$case['case_id']]=true;
            $success=$case['success'];$images=(int)($case['images']??0);$sections=(int)($case['sections']??0);
            $renderer=(string)($case['renderer']??'');
            if(!in_array($renderer,['uael','story'],true))return ['status'=>'invalid_renderer'];
            if($success&&($images<1||$sections<1||empty($case['lightbox_closed'])||
               $renderer!==self::current_renderer()))return ['status'=>'invalid_success_proof'];
            if($renderer==='uael'&&$success&&(
               (int)($case['timeline_days']??0)<1||
               (int)($case['timeline_tiles']??0)<1||
               abs((int)($case['active_center_offset_px']??9999))>30||
               empty($case['parent_popup_preserved'])))return ['status'=>'missing_timeline_proof'];
            $failures+=(int)!$success;
            $out[]=['case_id'=>$case['case_id'],'success'=>$success,'renderer'=>$renderer,
                'images'=>max(0,min(1000,$images)),'sections'=>max(0,min(100,$sections)),
                'lightbox_closed'=>(bool)($case['lightbox_closed']??false),
                'timeline_days'=>max(0,min(90,(int)($case['timeline_days']??0))),
                'timeline_tiles'=>max(0,min(2000,(int)($case['timeline_tiles']??0))),
                'active_center_offset_px'=>max(-500,min(500,(int)($case['active_center_offset_px']??0))),
                'parent_popup_preserved'=>(bool)($case['parent_popup_preserved']??false),
                'overflow'=>(bool)($case['overflow']??false),
                'error_code'=>substr(sanitize_key((string)($case['error_code']??'')),0,40)];
        }
        $previous=get_option(self::QA,[]);
        $previousFailures=is_array($previous)&&(int)($previous['failed_cases']??0)>0&&
            ($previous['asset_sha256']??'')===self::current_asset_sha()&&
            (int)(strtotime((string)($previous['checked_at']??''))?:0)>time()-48*HOUR_IN_SECONDS;
        $streak=$failures?($previousFailures?(int)($previous['consecutive_failures']??1)+1:1):0;
        $record=['contract'=>'mad4b.story-browser-qa.v1','site_uuid'=>self::site(),
            'checked_at'=>gmdate('c',$stamp),'asset_sha256'=>self::current_asset_sha(),
            'failed_cases'=>$failures,'consecutive_failures'=>min(100,$streak),'cases'=>$out];
        update_option(self::QA,$record,false);
        self::log('browser_qa_imported',['status'=>$failures?'failed':'passed','alerts'=>$failures]);
        return ['status'=>'accepted','health'=>self::browser_health()];
    }
    private static function stale_diagnostics(array $state):array {
        if(($state['snapshot_status']??'')!=='complete')return ['stale'=>false,'reason'=>'no_complete_baseline'];
        $age=max(0,time()-(strtotime((string)($state['completed_at']??''))?:time()));
        $changed=[];
        foreach((array)($state['records']??[]) as $post=>$row){
            $post=(int)$post;
            if(get_post_status($post)!=='publish'||($row['input_sha256']??'')!==self::content_sha($post))
                $changed[]=$post;
        }
        return ['stale'=>$age>self::SCAN_TTL||count($changed)>0,
          'age_seconds'=>$age,'changed_post_count'=>count($changed),
          'changed_post_ids'=>array_slice($changed,0,12)];
    }
    private static function live_issue(int $tour,string $code):?array {
        $r=(array)(get_option(self::STATE,[])['records'][$tour]??[]);
        foreach((array)($r['alerts']??[]) as $row)if(($row['code']??'')===$code)return $row;
        return null;
    }
    public static function accepts_issue(array $issue):bool {
        $source=(string)($issue['source']??'');$code=(string)($issue['code']??'');
        $post=(int)($issue['object_id']??0);
        if($source!=='mad4b-story-observability'||$post<=0||$code==='')return false;
        if(in_array($code,['story_monitor_stale','story_monitor_alerts_truncated',
             'story_provenance_review_required','story_browser_popup_failure',
             'story_browser_qa_stale','story_browser_qa_missing',
             'story_browser_qa_suspected_failure','story_browser_environment_inconclusive'],true)){
            $s=get_option(self::STATE,[]);
            $canonical=array_map('intval',array_keys((array)($s['records']??[])));
            $first=$canonical?min($canonical):0;
            $health=self::stale_diagnostics((array)$s);
            $allCount=0;foreach((array)($s['records']??[]) as $rr)$allCount+=count((array)($rr['alerts']??[]));
            $unknown=0;$photoCount=0;
            foreach((array)($s['records']??[]) as $record){
                $summary=$record['locales']['_summary']??[];
                $unknown+=(int)($summary['photo_provenance_unverified']??0);
                $photoCount+=(int)($summary['photo_count']??0);
            }
            $browser=self::browser_health();
            $eligible=match($code){
                'story_monitor_stale'=>!empty($health['stale']),
                'story_monitor_alerts_truncated'=>$allCount>self::MAX_EXPOSED,
                'story_provenance_review_required'=>$photoCount>=20&&$unknown/$photoCount>=0.75,
                'story_browser_popup_failure'=>$browser['status']==='failed',
                'story_browser_qa_stale'=>$browser['status']==='stale',
                'story_browser_qa_missing'=>$browser['status']==='missing',
                'story_browser_qa_suspected_failure'=>$browser['status']==='suspected_failure',
                'story_browser_environment_inconclusive'=>$browser['status']==='inconclusive',
                default=>false
            };
            // The browser popup failure is a HIGH severity signal in this
            // provider's own immutable code contract. Never downgrade it
            // during validation or throw from a legitimate readiness scan.
            $severity=$code==='story_browser_popup_failure'?'high':'medium';
            $expected=self::issue($first,$code,$severity);
            return $post===$first && $eligible &&
                hash_equals((string)$expected['id'],(string)($issue['id']??'')) &&
                ($issue['severity']??'')===$severity;
        }
        $stored=self::live_issue($post,$code);
        if(!$stored)return false;
        return hash_equals((string)$stored['id'],(string)($issue['id']??''))
          &&(string)($issue['severity']??'')===(string)$stored['severity']
          &&get_post_status($post)==='publish'&&self::canonical($post)===$post;
    }
    public static function issues($issues,$policy){
        if(!is_array($issues))return $issues;
        $s=get_option(self::STATE,[]);if(($s['contract']??'')!==self::CONTRACT)return $issues;
        $all=[];foreach((array)($s['records']??[]) as $post=>$record){
            if(get_post_status((int)$post)!=='publish')continue;
            foreach((array)($record['alerts']??[]) as $row)$all[]=$row;
        }
        usort($all,static function($a,$b){
            $sev=['high'=>0,'medium'=>1,'low'=>2];
            return ($sev[$a['severity']]??3)<=>($sev[$b['severity']]??3)
              ?:((int)$a['object_id']<=>(int)$b['object_id']);
        });
        $health=self::stale_diagnostics((array)$s);
        $max=self::MAX_EXPOSED;
        $exceeds=count($all)>$max;
        $needsFresh=!empty($health['stale']);
        $unknown=0;$photoCount=0;
        foreach((array)($s['records']??[]) as $record){
            $summary=$record['locales']['_summary']??[];
            $unknown+=(int)($summary['photo_provenance_unverified']??0);
            $photoCount+=(int)($summary['photo_count']??0);
        }
        $provenancePending=$photoCount>=20&&$unknown/$photoCount>=0.75;
        $browser=self::browser_health();
        $browserCode=match($browser['status']){
          'failed'=>'story_browser_popup_failure','stale'=>'story_browser_qa_stale',
          'missing'=>'story_browser_qa_missing',
          'suspected_failure'=>'story_browser_qa_suspected_failure',
          'inconclusive'=>'story_browser_environment_inconclusive',default=>''};
        $reserved=(int)$exceeds+(int)$needsFresh+(int)$provenancePending+(int)($browserCode!=='');
        $totalFound=count($all);
        $all=array_slice($all,0,max(0,$max-$reserved));
        $posts=array_map('intval',array_keys((array)($s['records']??[])));
        if($posts){
            $first=min($posts);
            if($exceeds)$all[]=self::issue($first,'story_monitor_alerts_truncated','medium',
                 ['total_findings'=>$totalFound,'published_limit'=>$max,'not_exposed'=>$totalFound-$max+$reserved]);
            if($needsFresh)$all[]=self::issue($first,'story_monitor_stale','medium',[
                 'age_seconds'=>$health['age_seconds']??0,
                 'changed_post_count'=>$health['changed_post_count']??0]);
            if($provenancePending)$all[]=self::issue($first,'story_provenance_review_required','medium',[
                 'unverified_photo_placements'=>$unknown,
                 'total_photo_placements'=>$photoCount,
                 'synthetic_status_not_inferred'=>true]);
            if($browserCode!=='')$all[]=self::issue($first,$browserCode,
                $browserCode==='story_browser_popup_failure'?'high':'medium',[
                  'checked_at'=>$browser['checked_at']??null,
                  'failed_case_ids'=>$browser['failed_case_ids']??[],
                  'case_count'=>$browser['case_count']??0]);
        }
        foreach($all as $row){
            $row['source_evidence']=['contract'=>self::CONTRACT,
                'site_uuid'=>self::site(),'run_id'=>$s['run_id']??'',
                'post_id'=>(int)$row['object_id'],'input_sha256'=>(string)($s['records'][$row['object_id']]['input_sha256']??''),
                'summary'=>array_slice((array)$row['evidence'],0,8)];
            unset($row['evidence']);
            $issues[]=$row;
        }
        return $issues;
    }
    public static function cli($args,$assoc):void {
        $cmd=(string)($args[0]??'status');
        if($cmd==='scan')$result=self::scan((int)($assoc['limit']??4));
        elseif($cmd==='one')$result=self::scan_one((int)($args[1]??0));
        elseif($cmd==='inspect')$result=self::inspect((int)($args[1]??0));
        elseif($cmd==='qa-import')$result=self::import_browser_qa((string)($args[1]??''));
        elseif($cmd==='qa-status')$result=self::browser_health();
        elseif($cmd==='status'||$cmd==='report')$result=self::report();
        else WP_CLI::error('Usage: wp mad4b story-monitor scan --limit=4 | one ID | inspect ID | status');
        WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function init():void {
        if(!wp_next_scheduled('mad4b_story_monitor_tick'))wp_schedule_event(time()+360,'mad4b_story_monitor_ten_minutes','mad4b_story_monitor_tick');
    }
    public static function on_update($post_id):void {
        $post_id=(int)$post_id;if(get_post_type($post_id)!=='tours-and-activities')return;
        $c=self::canonical($post_id);$key='mad4b_story_monitor_one';
        if(!wp_next_scheduled($key,[$c]))wp_schedule_single_event(time()+60,$key,[$c]);
    }
}
add_filter('cron_schedules',static function($s){$s['mad4b_story_monitor_ten_minutes']=['interval'=>600,'display'=>'MAD4B Story Monitoring 10min'];return $s;});
add_action('init',[MAD4B_Story_Observability_V2::class,'init'],70);
add_action('mad4b_story_monitor_tick',static function(){MAD4B_Story_Observability_V2::scan(3);});
add_action('mad4b_story_monitor_one',static function($id){MAD4B_Story_Observability_V2::scan_one((int)$id);});
add_action('save_post_tours-and-activities',[MAD4B_Story_Observability_V2::class,'on_update'],65,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook){
 add_action($hook,static function($meta_id,$post_id,$key){
   if(!in_array((string)$key,['itinerary_item','tour_highlights_content','gallery','image_vertical',
       'grid_image_1_middle_upper','grid_image_2','grid_image_3_middle_bottom','grid_image_4_',
       '_mad4b_story_section_curated_ids_v1','_thumbnail_id'],true))return;
   MAD4B_Story_Observability_V2::on_update((int)$post_id);
 },65,3);
}
foreach(['added_term_meta','updated_term_meta','deleted_term_meta'] as $hook){
 add_action($hook,static function($meta_id,$term_id,$key){
   if(!in_array((string)$key,['image_gellery','featured_image','locations_gallery_attachment_ids'],true))return;
   if(!wp_next_scheduled('mad4b_story_monitor_tick'))
       wp_schedule_single_event(time()+120,'mad4b_story_monitor_tick');
 },65,3);
}
add_filter('mad4b/content_readiness/external_issues',[MAD4B_Story_Observability_V2::class,'issues'],27,2);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b story-monitor',[MAD4B_Story_Observability_V2::class,'cli']);
