<?php
/**
 * Plugin Name: MAD4B Origin Maps Support Review
 * Description: Google Maps URLs for human-assisted origin review; no API key, scraping, or location writeback.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Origin_Maps_Support_Review {
    const CONTRACT='mad4b.origin-maps-support-review.v2';
    const LEDGER='mad4b_content_readiness_issues_v1';
    const AUDIT='mad4b_origin_maps_support_audit_v1';
    const ACTION='mad4b_origin_maps_support_review';
    const FINDER_URL='https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder';
    const CODES=['origin_missing','pickup_guest_location_capture_unverified'];

    private static function enabled(){
        return wp_get_environment_type()==='staging' &&
           class_exists('MAD4B_SCP_Site_Profile') &&
           class_exists('MAD4B_SCP_Content_Jobs');
    }
    private static function authorized(){
        return self::enabled() && is_user_logged_in() && current_user_can('manage_options');
    }
    public static function cases(){
        if(!self::enabled())return [];
        $issues=(array)get_option(self::LEDGER,[]);
        $site=(string)MAD4B_SCP_Site_Profile::site_uuid();
        $out=[];
        foreach($issues as $fingerprint=>$row){
            if(!is_array($row) ||
               !in_array($row['code']??'',self::CODES,true) ||
               ($row['status']??'')!=='open' ||
               ($row['post_type']??'')!=='tours-and-activities' ||
               !preg_match('/^[0-9a-f]{64}$/D',(string)$fingerprint))continue;
            $post_id=absint($row['object_id']??0);
            if(!$post_id || get_post_status($post_id)!=='publish' ||
               get_post_type($post_id)!=='tours-and-activities')continue;
            $job_id=(string)($row['content_job_id']??'');
            if(!preg_match('/^[0-9a-f-]{36}$/D',$job_id))continue;
            $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
            if(is_wp_error($job) || !is_array($job['job']??null) ||
               ($job['job']['site_uuid']??'')!==$site)continue;
            $day=explode('|',(string)get_post_meta($post_id,'itinerary_item_description',true))[0];
            $queries=[];
            if(preg_match('/Cairo International Airport/i',$day))
                $queries[]='Cairo International Airport, Egypt';
            if(preg_match('/Luxor Airport/i',$day))
                $queries[]='Luxor International Airport, Egypt';
            if(preg_match('/Aswan Airport/i',$day))
                $queries[]='Aswan International Airport, Egypt';
            if(preg_match('/(?:train terminal|train station)/i',$day)){
                if(stripos($day,'Luxor')!==false)$queries[]='Luxor Railway Station, Egypt';
                if(stripos($day,'Aswan')!==false)$queries[]='Aswan Railway Station, Egypt';
            }
            // For unspecified pickup, a search for tour title is a prompt to
            // inspect manually, NOT an assertion about its meeting point.
            if(!$queries)$queries[]=(string)get_the_title($post_id);
            $queries=array_values(array_unique($queries));
            $maps=[];
            foreach($queries as $query)
                $maps[]=['query'=>$query,
                  'url'=>'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query)];
            $out[]=[
                'issue_id'=>$fingerprint,'post_id'=>$post_id,
                'title'=>get_the_title($post_id),'issue_code'=>$row['code'],
                'job_id'=>$job_id,'job_state'=>$job['job']['state']??'',
                'maps_urls'=>$maps,'evidence'=>(array)($row['origin_support_review']??[]),
                'post_url'=>get_permalink($post_id)
            ];
        }
        usort($out,static fn($a,$b)=>$a['post_id']<=>$b['post_id']);
        return $out;
    }
    public static function menu(){
        if(!self::authorized())return;
        add_management_page('MAD4B Maps Support Review','MAD4B Maps Support','manage_options',
          'mad4b-maps-support',[__CLASS__,'render']);
    }
    public static function enqueue($hook){
        if($hook!=='tools_page_mad4b-maps-support' || !self::authorized())return;
        wp_enqueue_script('mad4b-places-finder-local',
            content_url('mu-plugins/mad4b-support-assets/place-id-finder.js'),
            [],'0.2.0',true);
        $key=trim((string)get_option('elementor_google_maps_api_key',''));
        if($key==='')return;
        // The Maps and Places libraries stay hosted by Google; only our
        // application UI code is served locally from MAD4B.
        $url='https://maps.googleapis.com/maps/api/js?key='.
            rawurlencode($key).'&v=weekly&libraries=places,marker&loading=async&callback=mad4bPlaceIdFinderInit';
        wp_enqueue_script('mad4b-places-finder-google',$url,
            ['mad4b-places-finder-local'],null,true);
    }
    private static function valid_place_id($value){
        $value=trim((string)$value);
        if($value==='')return '';
        return strlen($value)<=250 &&
            preg_match('/^[A-Za-z0-9_:-]{8,250}$/D',$value)?$value:false;
    }
    private static function place_link($label,$place_id){
        return 'https://www.google.com/maps/search/?api=1&query='.
            rawurlencode($label).'&query_place_id='.rawurlencode($place_id);
    }
    public static function render(){
        if(!self::authorized())wp_die('Not authorized','',403);
        $cases=self::cases();
        echo '<div class="wrap"><h1>MAD4B · Maps Support Review</h1>';
        echo '<p>This is an operator-assistance tool. Google Maps search links need no API key. ';
        echo 'Do not record guest personal data or private hotel addresses in these notes. ';
        echo 'Do not treat a map pin as a supplier-confirmed pickup or cruise berth. ';
        echo 'No origin fields are updated and no alert is closed from this screen.</p>';
        if(isset($_GET['saved']) && $_GET['saved']==='1')
            echo '<div class="notice notice-success"><p>Review note saved and independently read back.</p></div>';
        echo '<p><strong>'.count($cases).' open mapped Content Jobs</strong> · ';
        echo '<a target="_blank" rel="noopener noreferrer" href="'.esc_url(self::FINDER_URL).'">Google-hosted Place ID Finder (fallback)</a></p>';
        echo '<section id="mad4b-place-finder" hidden style="background:white;border:1px solid #c3c4c7;padding:18px;margin:16px 0;max-width:950px">';
        echo '<h2>Place ID Finder — <span id="mad4b-place-finder-target"></span></h2>';
        echo '<p id="mad4b-place-finder-status" role="status">Select a tour to start</p>';
        echo '<div id="mad4b-place-finder-search"></div>';
        echo '<div id="mad4b-place-finder-suggestions" aria-live="polite" style="padding:10px 0"></div>';
        echo '<div id="mad4b-place-finder-map" style="height:310px;max-width:800px;margin:12px 0;background:#f4f5f6"></div>';
        echo '<div id="mad4b-place-finder-selected" hidden aria-live="polite"></div>';
        echo '<p><button type="button" class="button" id="mad4b-place-finder-close">Close finder</button></p></section>';
        echo '<table class="widefat striped"><thead><tr><th>Tour / Issue</th><th>Google Maps</th><th>Reviewer evidence</th></tr></thead><tbody>';
        foreach($cases as $case){
            $id=(int)$case['post_id'];$issue=$case['issue_id'];
            $plan=class_exists('MAD4B_Dynamic_Location_Resolver')?
                MAD4B_Dynamic_Location_Resolver::resolve($case):null;
            if(is_wp_error($plan))$plan=null;
            echo '<tr><td><strong>'.esc_html($case['title']).'</strong><br>';
            echo '<code>#'.esc_html((string)$id).'</code> · '.esc_html($case['issue_code']).'<br>';
            echo '<small>Job: '.esc_html($case['job_id']).' · '.esc_html($case['job_state']).'</small><br>';
            if(class_exists('MAD4B_Origin_Closure_Autopilot')){
                $flow=MAD4B_Origin_Closure_Autopilot::status($case['issue_id']);
                echo '<small><strong>Autopilot:</strong> '.esc_html(str_replace('_',' ',$flow['state']??'unknown')).'</small><br>';
                echo '<small>Next: '.esc_html(str_replace('_',' ',$flow['next_action']??'unknown')).'</small><br>';
            }
            echo '<a target="_blank" rel="noopener noreferrer" href="'.esc_url($case['post_url']).'">View published tour</a></td><td>';
            if($plan){
                echo '<p><strong>Resolver:</strong> '.esc_html(str_replace('_',' ',$plan['location_kind'])).'</p>';
                foreach($plan['queries'] as $suggestion){
                    echo '<p><small>Evidence: '.esc_html($suggestion['source']).'</small><br>';
                    echo '<a target="_blank" rel="noopener noreferrer" href="'.esc_url($suggestion['maps_url']).'">Google: '.esc_html($suggestion['query']).'</a> · ';
                    echo '<a target="_blank" rel="noopener noreferrer" href="'.esc_url($suggestion['osm_url']).'">OpenStreetMap</a></p>';
                }
                if(!$plan['queries'])echo '<p><em>No precise source location; operator must research rather than assume a pickup.</em></p>';
                foreach($plan['notes'] as $note)echo '<p><small>'.esc_html($note).'</small></p>';
            } else echo '<p><em>Resolver unavailable; do not approve a candidate.</em></p>';
            echo '<p><button type="button" class="button" data-mad4b-find-place data-tour-title="'.esc_attr($case['title']).'"'.
                 ($plan?' data-location-kind="'.esc_attr($plan['location_kind']).'" data-resolution-queries="'.
                    esc_attr(wp_json_encode($plan['queries'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'"':' disabled').
                 '>Find Place ID inside MAD4B</button></p>';
            echo '<p><em>Search result ≠ approved pickup location</em></p></td><td>';
            $saved=$case['evidence'];
            if(!empty($saved['recorded_at'])){
                echo '<p><strong>Latest:</strong> '.esc_html((string)$saved['state']).' · '.esc_html($saved['recorded_at']).'</p>';
                $candidate=(array)($saved['place_id_candidate']??[]);
                if(!empty($candidate['place_id']) && !empty($candidate['label'])){
                    echo '<p><strong>Candidate Place ID:</strong> <code>'.esc_html($candidate['place_id']).'</code><br>';
                    echo '<a target="_blank" rel="noopener noreferrer" href="'.esc_url(self::place_link($candidate['label'],$candidate['place_id'])).'">Open candidate on Google Maps</a></p>';
                }
            }
            echo '<form data-mad4b-support-review action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
            wp_nonce_field(self::ACTION.'|'.$issue);
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
            echo '<input type="hidden" name="issue_id" value="'.esc_attr($issue).'">';
            echo '<input type="hidden" name="resolver_plan_sha256" value="'.esc_attr($plan['plan_sha256']??'').'">';
            echo '<p><label>Resolution provider<br><select name="review_provider" required>';
            $chosen=$plan['selected_provider']??'supplier_confirmation';
            foreach([
                'google_places_browser'=>'Google Places browser (if authorized)',
                'google_maps_urls'=>'Google Maps URLs — manual',
                'openstreetmap_manual'=>'OpenStreetMap — manual',
                'supplier_confirmation'=>'Supplier documentation / confirmation'
            ] as $value=>$label){
                echo '<option value="'.esc_attr($value).'"'.
                    selected($value,$chosen,false).'>'.esc_html($label).'</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>Review outcome<br><select name="state" required>
               <option value="needs_supplier_confirmation">Needs supplier confirmation</option>
               <option value="visual_candidate_reviewed">Visual candidate reviewed (not verified)</option>
               <option value="no_reliable_match">No reliable match</option>
               <option value="variable_guest_pickup">Guest hotel/location required</option>
               </select></label></p>';
            echo '<p><label>Candidate Place ID (optional)<br><input type="text" name="place_id" class="regular-text" maxlength="250" autocomplete="off" placeholder="Select a result using the embedded Finder" /></label></p>';
            echo '<p><label>Google place name (required with Place ID)<br><input type="text" name="place_label" class="regular-text" maxlength="240" autocomplete="off" /></label></p>';
            echo '<p><label>Google Maps link (optional)<br><input type="url" name="maps_url" class="regular-text" maxlength="1000" placeholder="https://www.google.com/maps/..." /></label></p>';
            echo '<p><label>Operator note (required)<br><textarea name="note" rows="2" cols="45" maxlength="1200" required></textarea></label></p>';
            echo '<button class="button button-secondary" type="submit">Save review evidence only</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private static function valid_maps_url($value){
        $value=trim((string)$value);
        if($value==='')return '';
        if(strlen($value)>1000 || !wp_http_validate_url($value))return false;
        $parts=wp_parse_url($value);
        if(!is_array($parts)||($parts['scheme']??'')!=='https')return false;
        $host=strtolower((string)($parts['host']??''));
        $path=(string)($parts['path']??'');
        $google=in_array($host,['google.com','www.google.com','maps.google.com'],true) &&
            str_starts_with($path,'/maps');
        $osm=$host==='www.openstreetmap.org' &&
            in_array($path,['/search','/'],true);
        if(!$google&&!$osm)return false;
        return esc_url_raw($value);
    }
    /** Support observations must not attach to stale source data. */
    private static function source_is_current($row){
        $id=absint($row['object_id']??0);
        if(!$id || get_post_status($id)!=='publish')return false;
        if(($row['code']??'')==='pickup_guest_location_capture_unverified'){
            $expected=(string)($row['source_evidence']['source_meta_sha256']??'');
            $current=get_post_meta($id,'tour_highlights_content',true);
            return is_string($current) && strlen($expected)===64 &&
              hash_equals($expected,hash('sha256',$current));
        }
        if(($row['code']??'')!=='origin_missing')return false;
        $request=(array)($row['content_job_request']??[]);
        $canonical=absint($request['canonical_post_id']??0);
        if(!$canonical || (int)($request['source_post_id']??0)!==$id)return false;
        $terms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
        if(is_wp_error($terms))return false;
        $terms=array_map('intval',(array)$terms);sort($terms,SORT_NUMERIC);
        $source=hash('sha256',wp_json_encode([
            'canonical_id'=>$canonical,
            'origin_term'=>get_post_meta($id,'tour_origin_location_term',true),
            'destination_terms'=>$terms],JSON_UNESCAPED_SLASHES));
        return hash_equals((string)($request['source_sha256']??''),$source);
    }
    public static function save(){
        if(!self::authorized())wp_die('Not authorized','',403);
        $id=(string)($_POST['issue_id']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))wp_die('Invalid issue','',400);
        check_admin_referer(self::ACTION.'|'.$id);
        $state=sanitize_key((string)($_POST['state']??''));
        if(!in_array($state,[
            'needs_supplier_confirmation','visual_candidate_reviewed',
            'no_reliable_match','variable_guest_pickup'],true))wp_die('Invalid outcome','',400);
        $note=sanitize_textarea_field((string)($_POST['note']??''));
        if(mb_strlen($note)<5 || mb_strlen($note)>1200)wp_die('Review note required','',400);
        $url=self::valid_maps_url((string)($_POST['maps_url']??''));
        if($url===false)wp_die('Only official Google Maps URLs are allowed','',400);
        $place_id=self::valid_place_id((string)($_POST['place_id']??''));
        $place_label=sanitize_text_field((string)($_POST['place_label']??''));
        if($place_id===false || mb_strlen($place_label)>240 ||
           (($place_id==='')!==($place_label==='')))
           wp_die('A valid candidate Place ID and exact place name are required together','',400);
        if($place_id!=='' && in_array($state,['no_reliable_match','variable_guest_pickup'],true))
           wp_die('Candidate Place ID conflicts with this review outcome','',400);
        $provider=sanitize_key((string)($_POST['review_provider']??''));
        if(!in_array($provider,['google_places_browser','google_maps_urls',
            'openstreetmap_manual','supplier_confirmation'],true))
            wp_die('Invalid resolution provider','',400);
        if($place_id!=='' && $provider==='openstreetmap_manual')
            wp_die('Google Place ID cannot originate from OpenStreetMap','',400);
        if($url!=='' && str_contains((string)wp_parse_url($url,PHP_URL_HOST),'openstreetmap.org') &&
           $provider!=='openstreetmap_manual')
            wp_die('Source URL/provider mismatch','',400);
        if($place_id!=='' && $url==='')$url=self::place_link($place_label,$place_id);
        $plan_sha=(string)($_POST['resolver_plan_sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$plan_sha))
            wp_die('Resolver plan binding required','',409);
        $cases=self::cases();
        $case=null;
        foreach($cases as $item)if($item['issue_id']===$id){$case=$item;break;}
        if(!$case)wp_die('Case not active or not authorized','',409);
        if(!class_exists('MAD4B_Dynamic_Location_Resolver'))
            wp_die('Resolver unavailable','',503);
        $plan=MAD4B_Dynamic_Location_Resolver::resolve($case);
        if(is_wp_error($plan) || !hash_equals((string)($plan['plan_sha256']??''),$plan_sha))
            wp_die('Resolver plan changed; reload the review page','',409);
        global $wpdb;
        $locks=['mad4b_origin_intake_'.get_current_blog_id(),
            'mad4b_wpml_url_jobs_'.get_current_blog_id()];
        $held=[];
        foreach($locks as $lock){
            if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1){
                foreach(array_reverse($held) as $l)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$l));
                wp_die('Review ledger is busy; retry','',423);
            }
            $held[]=$lock;
        }
        try{
            $rows=(array)get_option(self::LEDGER,[]);
            $row=$rows[$id]??null;
            if(!is_array($row)||($row['status']??'')!=='open' ||
              (string)($row['content_job_id']??'')!==$case['job_id']||
              (int)($row['object_id']??0)!==$case['post_id'] ||
              !self::source_is_current($row))
                wp_die('Ledger or tour source changed before save','',409);
            $rows[$id]['origin_support_review']=[
              'contract'=>self::CONTRACT,'state'=>strtoupper($state),
              'maps_url'=>$url,'note'=>$note,
              'resolution'=>[
                  'contract'=>MAD4B_Dynamic_Location_Resolver::CONTRACT,
                  'plan_sha256'=>$plan['plan_sha256'],
                  'source_sha256'=>$plan['source_sha256'],
                  'provider'=>$provider,'location_kind'=>$plan['location_kind'],
                  'exact_pickup_verified'=>false,'writeback_authorized'=>false
              ],
              'place_id_candidate'=>$place_id!==''?[
                 'place_id'=>$place_id,'label'=>$place_label,
                 'source'=>'human_selected_google_place_id_candidate',
                 'maps_provider_verified'=>false,'supplier_confirmed'=>false,
                 'usable_as_pickup'=>false
              ]:null,
              'job_id'=>$case['job_id'],'post_id'=>$case['post_id'],
              'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
              'reviewer_user_id'=>get_current_user_id(),'recorded_at'=>gmdate('c'),
              'human_supplied_evidence'=>true,'maps_api_verified'=>false,
              'supplier_confirmed'=>false,'pickup_location_verified'=>false,
              'writeback_authorized'=>false,'alert_resolved'=>false
            ];
            update_option(self::LEDGER,$rows,false);
            $check=(array)get_option(self::LEDGER,[]);
            if(($check[$id]['origin_support_review']['job_id']??'')!==$case['job_id'])
                wp_die('Review readback failed','',500);
            $audit=(array)get_option(self::AUDIT,[]);
            $audit[]=['issue_id'=>$id,'job_id'=>$case['job_id'],
                'state'=>strtoupper($state),'actor_id'=>get_current_user_id(),
                'at'=>gmdate('c')];
            update_option(self::AUDIT,array_slice($audit,-100),false);
        }finally{
            foreach(array_reverse($held) as $l)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$l));
        }
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-maps-support','saved'=>'1'],
           admin_url('tools.php')));
        exit;
    }
    public static function boot(){
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue']);
        add_action('admin_post_'.self::ACTION,[__CLASS__,'save']);
    }
}
MAD4B_Origin_Maps_Support_Review::boot();
