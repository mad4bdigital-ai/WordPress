<?php
/**
 * Plugin Name: MAD4B Rail Source Monitor
 * Description: Source-change evidence receipts for public rail references. Never imports a price or verifies booking availability.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Rail_Source_Monitor_V1 {
    const CONTRACT='mad4b.rail-source-monitor.v1';
    const STORE='mad4b_rail_source_observations_v1';
    const LINKS='mad4b_rail_source_operator_links_v1';
    const HOLD='mad4b_rail_source_change_hold_v1';
    static function registry(): array {
        $link=(array)get_option(self::LINKS,[]);
        $default='https://sleep.abelatrains.com/files/train_price_list_and_statins_new.pdf';
        $candidate=(string)($link['abela_annual_tariff']['url']??'');
        $current=self::valid_abela_url($candidate)?$candidate:$default;
        return [
            'abela_annual_tariff'=>[
                'title'=>'Abela official train fare document',
                'url'=>$current,
                'kind'=>'pdf_sha256','cadence'=>'daily',
                'source_type'=>'operator_official_reference',
                'source_approval_scope'=>'public_document_change_only',
                'price_approval'=>false,'inventory_approval'=>false,
            ],
            'cairo_railway_tariffs'=>[
                'title'=>'Cairo Governorate railway reference table',
                'url'=>'https://cairo.gov.eg/en/governorate-services/transportation-services/railway/',
                'kind'=>'html_table_sha256','cadence'=>'weekly',
                'source_type'=>'government_public_reference',
                'source_approval_scope'=>'public_table_change_only',
                'price_approval'=>false,'inventory_approval'=>false,
            ],
        ];
    }
    static function valid_abela_url($url):bool {
        return is_string($url) && strlen($url)<=220 &&
          preg_match('~^https://sleep\.abelatrains\.com/files/train_price_list_and_statins[a-zA-Z0-9_-]*\.pdf$~D',$url)===1;
    }
    /**
     * Operator homepage discovery is performed by a bounded HTTPS watcher.
     * A link rotation is audited and reviewed; this never accepts booking URLs.
     */
    static function operator_link(array $report) {
        if(!self::authorized())return new WP_Error('rail_source_link_forbidden');
        $url=(string)($report['discovered_url']??'');
        $homepage=(string)($report['operator_homepage']??'');
        $hash=(string)($report['homepage_sha256']??'');
        if(!self::valid_abela_url($url)||
           $homepage!=='https://sleep.abelatrains.com/'||
           !preg_match('/^[a-f0-9]{64}$/D',$hash)||
           ($report['pdf_validated']??false)!==true)
           return new WP_Error('rail_source_link_proof_invalid');
        $current=self::registry()['abela_annual_tariff']['url'];
        if($current===$url)
          return ['state'=>'UNCHANGED','url'=>$url,'changed'=>false];
        global $wpdb;
        $mutex='mad4b_rail_source_link_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$mutex))!==1)
          return new WP_Error('rail_source_link_locked');
        try {
          $latest=self::registry()['abela_annual_tariff']['url'];
          if($latest===$url)return ['state'=>'UNCHANGED','url'=>$url,'changed'=>false];
          update_option(self::HOLD,[
              'source_id'=>'abela_annual_tariff',
              'reason'=>'operator_link_rotated',
              'old_url'=>$latest,'new_url'=>$url,
              'new_homepage_sha256'=>$hash,'detected_at'=>gmdate('c'),
              'supplier_fare_approval'=>false
          ],false);
          $checkpoint=MAD4B_SCP_Operational_Integrity::capture();
          if(is_wp_error($checkpoint))return $checkpoint;
          $scope=(array)($checkpoint['scope']??[]);
          $group=MAD4B_SCP_Research_Groups::group('rail:source:abela_annual_tariff',
              (string)($scope['site_uuid']??''),'en');
          if(is_wp_error($group))return $group;
          $record=MAD4B_SCP_Research_Groups::add_record((int)$group['id'],
              'native_wp_source','en',$homepage,[
                 'contract'=>'mad4b.rail-operator-link-discovery.v1',
                 'source'=>'Abela official homepage',
                 'homepage_sha256'=>$hash,
                 'previous_pdf_url'=>$latest,
                 'new_pdf_url'=>$url,
                 'pdf_validated'=>true,
                 'discovered_at'=>gmdate('c'),
                 'supplier_fares_validated'=>false,
                 'booking_authorized'=>false
              ]);
          if(is_wp_error($record))return $record;
          $created=MAD4B_SCP_Content_Jobs::create_job([
               'brand_id'=>(string)($scope['brand_ref']??''),
               'language'=>'en','country'=>'eg',
               'content_type'=>'research_observation',
               'subject'=>'Abela official tariff source link changed — review required',
               'reason'=>'Abela homepage PDF URL changed. Old: '.$latest.'; new: '.$url.
                   '. Confirm provenance, document version and tariff table before restoring commercial pricing; record '.($record['record_uid']??''),
               'primary_keyword'=>'Abela supplier tariff PDF source',
               'research_depth'=>'standard'
          ]);
          if(is_wp_error($created))return $created;
          $jobId=(string)($created['job']['job_id']??$created['job_id']??'');
          if(!$jobId)return new WP_Error('rail_source_link_job_receipt_missing');
          $links=(array)get_option(self::LINKS,[]);
          $links['abela_annual_tariff']=[
              'url'=>$url,'operator_homepage'=>$homepage,
              'homepage_sha256'=>$hash,'link_changed_from'=>$latest,
              'discovered_at'=>gmdate('c'),'review_job_id'=>$jobId,
              'status'=>'LINK_UPDATED_TARIFF_REVIEW_REQUIRED',
              'tariff_price_verification'=>false
          ];
          update_option(self::LINKS,$links,false);
          $read=(array)get_option(self::LINKS,[]);
          if(($read['abela_annual_tariff']['url']??'')!==$url)
             return new WP_Error('rail_source_link_readback_failed');
          return ['state'=>'LINK_CHANGED_REVIEW_JOB_CREATED','url'=>$url,
              'changed'=>true,'job_id'=>$jobId,'rate_created'=>false];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$mutex));}
    }
    static function authorized():bool {
        return home_url()==='https://tours.dev.mad4b.com' &&
            class_exists('MAD4B_SCP_Site_Profile') &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment() &&
            current_user_can('manage_options') &&
            class_exists('MAD4B_SCP_Policy') &&
            MAD4B_SCP_Policy::can_mutate() &&
            class_exists('MAD4B_SCP_Operational_Integrity') &&
            class_exists('MAD4B_SCP_Research_Groups') &&
            class_exists('MAD4B_SCP_Content_Jobs');
    }
    static function reconcile(array $data) {
        if(!self::authorized())return new WP_Error('rail_source_monitor_not_authorized');
        $id=(string)($data['source_id']??'');
        $registry=self::registry();
        $spec=$registry[$id]??null;
        if(!$spec)return new WP_Error('rail_source_unknown');
        $sha=(string)($data['sha256']??'');
        $url=(string)($data['url']??'');
        $kind=(string)($data['kind']??'');
        $status=(int)($data['http_status']??0);
        $length=filter_var($data['bytes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>20,'max_range'=>12582912]]);
        $tables=filter_var($data['tables_count']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
        if($url!==$spec['url']||$kind!==$spec['kind']||
            !preg_match('/^[a-f0-9]{64}$/D',$sha)||
            $status!==200||$length===false||$tables===false||
            ($kind==='html_table_sha256'&&$tables<1))
            return new WP_Error('rail_source_monitor_receipt_invalid');
        $checkpoint=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($checkpoint))return $checkpoint;
        $scope=(array)($checkpoint['scope']??[]);
        $uuid=(string)($scope['site_uuid']??'');
        if(!$uuid)return new WP_Error('rail_source_site_binding_missing');
        global $wpdb;
        $mutex='mad4b_rail_source_monitor_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$mutex))!==1)
            return new WP_Error('rail_source_monitor_ledger_busy');
        try {
            $saved=(array)get_option(self::STORE,[]);
            $old=(array)($saved[$id]??[]);
            $previous=(string)($old['sha256']??'');
            $changed=($previous!=='' && ($previous!==$sha || ($old['url']??'')!==$url));
            $version=$previous===''?1:(int)($old['version']??1)+($changed?1:0);
            if($changed && $id==='abela_annual_tariff'){
                update_option(self::HOLD,[
                    'source_id'=>$id,'reason'=>'official_pdf_bytes_or_url_changed',
                    'old_source_sha256'=>$previous,
                    'new_source_sha256'=>$sha,
                    'old_url'=>(string)($old['url']??''),
                    'new_url'=>$url,'detected_at'=>gmdate('c'),
                    'supplier_fare_approval'=>false
                ],false);
            }
            $now=gmdate('c');
            if(!$changed && $previous!=='' && isset($old['record_uid'])){
                $saved[$id]['last_checked_at']=$now;
                $saved[$id]['last_http_status']=$status;
                $saved[$id]['last_modified']=(string)($data['last_modified']??'');
                update_option(self::STORE,$saved,false);
                return ['source_id'=>$id,'state'=>'UNCHANGED',
                    'version'=>$version,'current_sha256'=>$sha,
                    'research_record_uid'=>$old['record_uid'],
                    'job_id'=>$old['latest_job_id']??null,
                    'prices_modified'=>0];
            }
            $group=MAD4B_SCP_Research_Groups::group('rail:source:'.$id,$uuid,'en');
            if(is_wp_error($group))return $group;
            $evidence=[
                'contract'=>self::CONTRACT,'site_uuid'=>$uuid,
                'source_id'=>$id,'source_type'=>$spec['source_type'],
                'monitor_kind'=>$kind,'source_url'=>$url,
                'source_sha256'=>$sha,'previous_sha256'=>$previous,
                'version'=>$version,'changed'=>$changed,
                'bytes'=>$length,'table_count'=>$tables,
                'observed_at'=>$now,
                'last_modified'=>(string)($data['last_modified']??''),
                'content_not_copied'=>true,
                'operator_quote_verified'=>false,
                'live_inventory_verified'=>false,
                'price_writeback_authorized'=>false,
                'booking_authorized'=>false,
                'rights_scope'=>$spec['source_approval_scope'],
            ];
            $record=MAD4B_SCP_Research_Groups::add_record((int)$group['id'],
                'native_wp_source','en',$url,$evidence);
            if(is_wp_error($record))return $record;
            $uid=(string)($record['record_uid']??'');
            if(!$uid)return new WP_Error('rail_source_research_uid_missing');
            $history=(array)($old['history']??[]);
            $jobId=null;
            // One review job per link rotation: reuse the job already created
            // when the official homepage exposed the new PDF, instead of
            // opening a second job when the PDF fingerprint is persisted.
            if($changed && $id==='abela_annual_tariff'){
                $links=(array)get_option(self::LINKS,[]);
                $entry=(array)($links[$id]??[]);
                $linkJob=(string)($entry['review_job_id']??'');
                if(($entry['url']??'')===$url &&
                   ($entry['link_changed_from']??'')===($old['url']??'') &&
                   $linkJob!==''){
                   $existingJob=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$linkJob]);
                   if(!is_wp_error($existingJob))
                      $jobId=$linkJob;
                }
            }
            if($changed && $jobId===null) {
                $created=MAD4B_SCP_Content_Jobs::create_job([
                    'brand_id'=>(string)($scope['brand_ref']??''),
                    'language'=>'en','country'=>'eg',
                    'content_type'=>'research_observation',
                    'subject'=>'Review changed public railway source: '.$spec['title'].'; version '.$version,
                    'primary_keyword'=>'railway source monitoring',
                    'reason'=>'Rail tariff/schedule source hash changed from '.$previous.' to '.$sha.'. Verify the new document/table and market-class scope before updating any commercial Rates. Research record: '.$uid.'. No supplier inventory is implied.',
                    'research_depth'=>'standard',
                ]);
                if(is_wp_error($created))return $created;
                $jobId=(string)($created['job']['job_id']??$created['job_id']??'');
                if(!$jobId)return new WP_Error('rail_source_job_receipt_missing');
            }
            $history[]=[
                'version'=>$version,'sha256'=>$sha,'observed_at'=>$now,
                'record_uid'=>$uid,'change_job_id'=>$jobId,
            ];
            $saved[$id]=[
                'source_id'=>$id,'title'=>$spec['title'],
                'url'=>$url,'kind'=>$kind,'cadence'=>$spec['cadence'],
                'sha256'=>$sha,'version'=>$version,
                'record_uid'=>$uid,
                'latest_job_id'=>$jobId?:($old['latest_job_id']??null),
                'last_checked_at'=>$now,
                'last_changed_at'=>$changed?$now:($old['last_changed_at']??null),
                'last_modified'=>(string)($data['last_modified']??''),
                'last_http_status'=>$status,
                'history'=>array_slice($history,-24),
                'source_quote_verified'=>false,
                'pricing_publication_enabled'=>false,
            ];
            update_option(self::STORE,$saved,false);
            $read=(array)get_option(self::STORE,[]);
            if(($read[$id]['sha256']??'')!==$sha||
               ($read[$id]['record_uid']??'')!==$uid)
                return new WP_Error('rail_source_monitor_readback_failed');
            return [
                'source_id'=>$id,'state'=>$changed?'CHANGED_REVIEW_JOB_CREATED':'BASELINE_RECORDED',
                'version'=>$version,'current_sha256'=>$sha,
                'research_record_uid'=>$uid,'job_id'=>$jobId,
                'prices_modified'=>0,'booking_authorized'=>false,
            ];
        }finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$mutex));
        }
    }
    static function summary(): array {
        $state=(array)get_option(self::STORE,[]);
        $out=[];
        foreach(self::registry() as $id=>$spec) {
            $row=(array)($state[$id]??[]);
            $out[$id]=[
                'source_url'=>$spec['url'],'kind'=>$spec['kind'],
                'cadence'=>$spec['cadence'],
                'version'=>$row['version']??0,
                'last_checked_at'=>$row['last_checked_at']??null,
                'sha256'=>$row['sha256']??null,
                'observed_url'=>$row['url']??null,
                'last_change_job_id'=>$row['latest_job_id']??null,
            ];
        }
        return [
            'contract'=>self::CONTRACT,'site'=>home_url(),
            'sources'=>$out,'created_rate_count'=>0,
            'pricing_writeback_permitted'=>false,
            'live_inventory_confirmed'=>false,
        ];
    }
    static function cli($args) {
        $op=$args[0]??'status';
        if($op==='status')$result=self::summary();
        elseif($op==='link'){
            $json=(string)getenv('MAD4B_RAIL_MONITOR_INPUT');
            if(strlen($json)>4096||trim($json)==='')WP_CLI::error('Link discovery receipt missing');
            $input=json_decode($json,true);
            if(!is_array($input))WP_CLI::error('Invalid link receipt');
            $result=self::operator_link($input);
        }
        elseif($op==='record'){
            $json=(string)getenv('MAD4B_RAIL_MONITOR_INPUT');
            if(strlen($json)>4096 || trim($json)==='')WP_CLI::error('Monitor input missing');
            $input=json_decode($json,true);
            if(!is_array($input))WP_CLI::error('Invalid monitor receipt');
            $result=self::reconcile($input);
        } else WP_CLI::error('Use status | record');
        if(is_wp_error($result))WP_CLI::error($result->get_error_code());
        WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    static function admin_panel() {
        if(!current_user_can('manage_woocommerce'))return;
        $state=self::summary();
        echo '<hr><h3>Rail Source Monitor — public source changes</h3>';
        echo '<p>Observes official documents/tables only. A source change creates a research review job; no price or inventory is auto-approved.</p>';
        echo '<table class="widefat striped" style="max-width:970px"><thead><tr><th>Source</th><th>Cadence</th><th>Version</th><th>Last checked</th><th>Action</th></tr></thead><tbody>';
        foreach($state['sources'] as $id=>$row){
            echo '<tr><td>'.esc_html(str_replace('_',' ',$id)).'</td>';
            echo '<td>'.esc_html($row['cadence']).'</td>';
            echo '<td>'.esc_html((string)$row['version']).'</td>';
            echo '<td>'.esc_html((string)($row['last_checked_at']??'Never')).'</td>';
            echo '<td><a target="_blank" rel="noopener noreferrer" href="'.esc_url($row['source_url']).'">Official source</a></td></tr>';
        }
        echo '</tbody></table>';
    }
}
add_action('woocommerce_settings_tabs_booking_experience',
    ['MAD4B_Rail_Source_Monitor_V1','admin_panel'],90);
if(defined('WP_CLI')&&WP_CLI)
    WP_CLI::add_command('mad4b rail-sources',['MAD4B_Rail_Source_Monitor_V1','cli']);
