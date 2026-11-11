<?php
/**
 * MAD4B Shared Product Core — Last Minute event -> native Offer Campaign draft v1.
 * Event source: trusted server inventory connector / WP-CLI / admin / scheduled watch.
 * Pure pricing requests and public AJAX NEVER write campaigns.
 * No discounts, publication, checkout authorization, external dispatch or supplier calls.
 */
defined('ABSPATH') || exit;
final class MAD4B_Last_Minute_Campaign_Events_V1 {
    const WATCH_OPTION='mad4b_lm_inventory_watch_v1';
    const EVENTS_OPTION='mad4b_lm_campaign_event_receipts_v1';
    const STATE_PREFIX='mad4b_lm_state_';
    const CLAIM_PREFIX='mad4b_lm_claim_';
    const WATCH_HOOK='mad4b/last-minute/reconcile-watch-v1';
    const TRIGGER_HOOK='mad4b/shared_inventory/snapshot_ready_v1';
    const MAX_WATCH=200;
    const MAX_PER_RUN=25;
    private static function allowed():bool {
        return (defined('WP_CLI')&&WP_CLI) || (defined('DOING_CRON')&&DOING_CRON)
            || (is_user_logged_in()&&current_user_can('manage_woocommerce'));
    }
    private static function id(array $ctx):string {
        return substr(hash('sha256',home_url('/').'|'.(int)$ctx['tour_id'].'|'.
            (int)$ctx['rate_id'].'|'.(int)($ctx['program_term_id']??0).'|'.
            (string)$ctx['travel_date']),0,40);
    }
    public static function evaluate(array $ctx):array {
        if(!class_exists('MAD4B_Shared_Last_Minute_V1') ||
           !function_exists('shared_product_inventory_snapshot_v1'))
            return ['status'=>'missing_shared_product_core'];
        $tour=absint($ctx['tour_id']??0);
        $rate=absint($ctx['rate_id']??0);
        $date=(string)($ctx['travel_date']??'');
        if(!$tour||get_post_type($tour)!=='tours-and-activities'
            ||get_post_status($tour)!=='publish'
            ||!MAD4B_Shared_Last_Minute_V1::parse($date))
            return ['status'=>'invalid_context'];
        if($rate) {
            $covered=false;
            if(function_exists('shared_product_booking_v2_rate_covers_date'))
                $covered=(bool)shared_product_booking_v2_rate_covers_date($rate,$date);
            else {
                // Presentation-only may not load Booking v2. Reuse its stored
                // inclusive UTC date coverage, never assume the Rate is valid.
                $start=(int)get_post_meta($rate,'tour_rate_start_date',true);
                $finish=(int)get_post_meta($rate,'tour_rate_end_date',true);
                $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
                $stamp=$day?$day->getTimestamp():0;
                $covered=$stamp>0&&$start>0&&$finish>=$start
                    &&$stamp>=$start&&$stamp<=$finish;
            }
            if(get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'
                ||(int)get_post_meta($rate,'related_tour_id',true)!==$tour||!$covered)
                return ['status'=>'rate_not_eligible_for_date'];
        }
        $program=absint($ctx['program_term_id']??0);
        if($program && (!taxonomy_exists('programs')||!has_term($program,'programs',$tour)))
            return ['status'=>'program_not_linked'];
        foreach(array_filter([$tour,$rate]) as $product) {
            if(in_array(strtolower((string)get_post_meta($product,'stop_sale',true)),
                ['1','yes','true','on'],true))
                return ['status'=>'stop_sale'];
        }
        $effective=MAD4B_Shared_Last_Minute_V1::effective_policy_for($tour,$rate);
        if(is_wp_error($effective))return ['status'=>'invalid_policy'];
        $policy=$effective['policy'];
        if($policy['mode']!=='on'||$policy['inventory_mode']!=='adaptive')
            return ['status'=>'policy_disabled'];
        $cfg=['tour_id'=>$tour,'rate_id'=>$rate,'program_term_id'=>$program,
              'travel_date'=>$date,'quantities'=>['persons'=>1]];
        $snapshot=shared_product_inventory_snapshot_v1($cfg);
        if(($snapshot['eligible_for_dynamic_pricing']??false)!==true)
            return ['status'=>'snapshot_unavailable','reason'=>$snapshot['reason']??'not_current'];
        $percent=(float)$snapshot['available_percent'];
        $band=$percent<=$policy['scarce_below_percent']?'scarce':
            ($percent>=$policy['abundant_above_percent']?'abundant':'balanced');
        if($band==='balanced')
            return ['status'=>'balanced','band'=>'balanced','policy'=>$policy,
                'policy_scope'=>$effective['source']['scope'],
                'policy_target'=>$effective['source']['target'],
                'snapshot'=>$snapshot,'config'=>$cfg];
        $window=MAD4B_Shared_Last_Minute_V1::flag_window($policy,$band,$date);
        return ['status'=>$window['active']?'flag_active':'outside_branch_window',
            'band'=>$band,'policy'=>$policy,
            'policy_scope'=>$effective['source']['scope'],
            'policy_target'=>$effective['source']['target'],
            'snapshot'=>$snapshot,'flag_window'=>$window,'config'=>$cfg];
    }
    private static function no_mutations(array $result):array {
        unset($result['policy'],$result['snapshot']);
        return $result;
    }
    public static function preview(array $ctx):array {
        $result=self::evaluate($ctx);
        $result['dry_run']=true;
        $result['writes']=0;
        $result['campaign_published']=false;
        if(isset($result['policy'],$result['band'])&&in_array($result['band'],['scarce','abundant'],true))
            $result['candidate_enabled']=$result['policy'][$result['band'].'_campaign_mode']==='draft';
        return $result;
    }
    private static function update_watch(array $ctx):void {
        $watch=get_option(self::WATCH_OPTION,[]);
        if(!is_array($watch))$watch=[];
        $id=self::id($ctx);
        $watch[$id]=[
            'tour_id'=>absint($ctx['tour_id']),'rate_id'=>absint($ctx['rate_id']??0),
            'program_term_id'=>absint($ctx['program_term_id']??0),
            'travel_date'=>(string)$ctx['travel_date'],
            'last_seen_utc'=>gmdate('c')];
        // Remove old dates to bound the scheduled scan. Never scan all tours on a public request.
        $today=wp_date('Y-m-d');
        foreach($watch as $key=>$row)
            if(!is_array($row)||($row['travel_date']??'')<$today)unset($watch[$key]);
        if(count($watch)>self::MAX_WATCH){
            uasort($watch,static fn($a,$b)=>strcmp((string)($b['last_seen_utc']??''),(string)($a['last_seen_utc']??'')));
            $watch=array_slice($watch,0,self::MAX_WATCH,true);
        }
        update_option(self::WATCH_OPTION,$watch,false);
    }
    private static function log(array $event):void {
        $events=get_option(self::EVENTS_OPTION,[]);
        if(!is_array($events))$events=[];
        $events[]=array_intersect_key($event,array_flip([
            'event_id','event_type','site','tour_id','rate_id','program_term_id','travel_date',
            'from_band','to_band','policy_scope','policy_target','window_from','window_to',
            'campaign_id','candidate_status','snapshot_sha256','at_utc']));
        update_option(self::EVENTS_OPTION,array_slice($events,-150),false);
    }
    private static function make_draft(array $data,array $event):array {
        if(!post_type_exists('offer-campaign'))
            return ['status'=>'offer_campaign_cpt_missing','campaign_id'=>0];
        $id=(string)$event['event_id'];
        $slug='mad4b-lm-'.substr($id,0,30);
        // Repeat deliveries must reuse the same native candidate; never clone campaigns.
        $existing=get_posts(['post_type'=>'offer-campaign','name'=>$slug,'post_status'=>'any',
                             'posts_per_page'=>2,'suppress_filters'=>true]);
        if($existing)
            return ['status'=>'existing_draft','campaign_id'=>(int)$existing[0]->ID];
        $cfg=$data['config'];
        $policy=$data['policy'];
        $branch=$data['band'];
        $window=$data['flag_window'];
        $ttl=(int)$policy[$branch.'_campaign_hours'];
        $endOn=$window['flag_closes_on'];
        $now=new DateTimeImmutable('now',wp_timezone());
        $close=DateTimeImmutable::createFromFormat('!Y-m-d',$endOn,wp_timezone());
        $close=$close?$close->setTime(23,59,59):$now;
        $ends=$now->modify('+'.$ttl.' hours');
        if($ends>$close)$ends=$close;
        if($ends<=$now)
            return ['status'=>'campaign_expired_before_creation','campaign_id'=>0];
        $title='Last Minute · '.ucfirst($branch).' · '.get_the_title($cfg['tour_id'])
             .' · '.$cfg['travel_date'];
        $post=wp_insert_post([
            'post_type'=>'offer-campaign','post_status'=>'draft',
            'post_name'=>$slug,'post_title'=>sanitize_text_field($title),
            'post_content'=>sprintf(
                "Generated from verified inventory event. Review required.\nTour: %d\nRate: %d\nTravel: %s\nBand: %s\nRemaining allotment: %d of %d\nSnapshot: %s\nNo live discount or availability promise.\n",
                $cfg['tour_id'],$cfg['rate_id'],$cfg['travel_date'],$branch,
                $data['snapshot']['available'],$data['snapshot']['capacity'],
                $data['snapshot']['snapshot_sha256'])],true);
        if(is_wp_error($post)||!$post)return ['status'=>'campaign_draft_write_failed','campaign_id'=>0];
        $post=(int)$post;
        $meta=[
           '_mad4b_lm_event_id'=>$id,'_mad4b_lm_source'=>'shared_product_core',
           '_mad4b_lm_status'=>'candidate_review_only','_mad4b_lm_band'=>$branch,
           '_mad4b_lm_date'=>$cfg['travel_date'],'_mad4b_lm_rate_id'=>$cfg['rate_id'],
           '_mad4b_lm_program_term_id'=>$cfg['program_term_id'],
           '_mad4b_lm_snapshot_sha256'=>$data['snapshot']['snapshot_sha256'],
           '_mad4b_lm_policy_scope'=>$data['policy_scope'],
           '_mad4b_lm_policy_target'=>$data['policy_target'],
           '_mad4b_lm_effective_from_utc'=>$now->setTimezone(new DateTimeZone('UTC'))->format('c'),
           '_mad4b_lm_effective_until_utc'=>$ends->setTimezone(new DateTimeZone('UTC'))->format('c'),
           'starts_at'=>$now->format('Y-m-d'),'ends_at'=>$ends->format('Y-m-d'),
           'eligible_product_ids'=>(string)$cfg['tour_id'],
           'eligible_services'=>'tours'
        ];
        foreach($meta as $key=>$value)update_post_meta($post,$key,$value);
        // No promotion_rule_id, no market approval, no publish, no coupon issuance.
        return ['status'=>'draft_created','campaign_id'=>$post];
    }
    public static function handle($ctx):array {
        if(!self::allowed()||!is_array($ctx))return ['status'=>'unauthorized_event_source'];
        $data=self::evaluate($ctx);
        if(!in_array($data['status']??'',['flag_active','outside_branch_window','balanced'],true))
            return ['status'=>'not_processed','reason'=>$data['status']??'unknown'];
        $cfg=$data['config'];
        self::update_watch($cfg);
        $key=self::id($cfg);
        $stateKey=self::STATE_PREFIX.$key;
        $claimKey=self::CLAIM_PREFIX.$key;
        if(!add_option($claimKey,['started'=>time()],'',false)){
            $claim=get_option($claimKey,[]);
            if(!is_array($claim)||time()-(int)($claim['started']??0)<180)
                return ['status'=>'transition_in_progress'];
            delete_option($claimKey);
            if(!add_option($claimKey,['started'=>time()],'',false))
                return ['status'=>'transition_in_progress'];
        }
        try {
            $old=get_option($stateKey,[]);
            if(!is_array($old))$old=[];
            $prior=(string)($old['band']??'none');
            $next=($data['status']==='flag_active')?$data['band']:'none';
            $policySha=hash('sha256',wp_json_encode($data['policy']));
            $already=$prior===$next&&
                ($old['policy_sha256']??'')===$policySha;
            if($already)return ['status'=>'unchanged','band'=>$next,
                'campaign_id'=>(int)($old['campaign_id']??0)];
            // First observation of a neutral state is not a campaign event.
            if(!$old&&$next==='none') {
                update_option($stateKey,['band'=>'none','policy_sha256'=>$policySha,'cycle'=>0],false);
                return ['status'=>'baseline_none'];
            }
            $cycle=(int)($old['cycle']??0)+1;
            $eventId=hash('sha256',home_url('/').'|'.$key.'|'.$cycle.'|'.$prior.'|'.$next.'|'.$policySha);
            $event=[
                'contract'=>'mad4b.last-minute-flag-event.v1','event_id'=>$eventId,
                'event_type'=>$next==='none'?'flag_exited':($prior==='none'?'flag_entered':'flag_changed'),
                'site'=>home_url('/'),'tour_id'=>$cfg['tour_id'],'rate_id'=>$cfg['rate_id'],
                'program_term_id'=>$cfg['program_term_id'],'travel_date'=>$cfg['travel_date'],
                'from_band'=>$prior,'to_band'=>$next,
                'policy_scope'=>$data['policy_scope'],'policy_target'=>$data['policy_target'],
                'window_from'=>$data['flag_window']['flag_opens_on']??null,
                'window_to'=>$data['flag_window']['flag_closes_on']??null,
                'snapshot_sha256'=>$data['snapshot']['snapshot_sha256'],
                'at_utc'=>gmdate('c')];
            $campaign=['status'=>'no_campaign','campaign_id'=>0];
            if($next!=='none'&&$data['policy'][$next.'_campaign_mode']==='draft')
                $campaign=self::make_draft($data,$event);
            // If campaign creation fails, leave state unchanged for retry.
            if(in_array($campaign['status'],['campaign_draft_write_failed','offer_campaign_cpt_missing'],true))
                return ['status'=>'candidate_creation_failed','reason'=>$campaign['status']];
            $event['campaign_id']=(int)$campaign['campaign_id'];
            $event['candidate_status']=$campaign['status'];
            update_option($stateKey,[
              'band'=>$next,'policy_sha256'=>$policySha,'cycle'=>$cycle,
              'event_id'=>$eventId,'campaign_id'=>$event['campaign_id'],
              'last_event_at_utc'=>$event['at_utc']],false);
            self::log($event);
            do_action('mad4b/last-minute/flag-event-v1',$event);
            if($event['campaign_id'])
                do_action('mad4b/last-minute/campaign-draft-created-v1',$event);
            return ['status'=>'event_recorded','event_type'=>$event['event_type'],
                'event_id'=>$eventId,'from_band'=>$prior,'to_band'=>$next,
                'campaign_id'=>$event['campaign_id'],'candidate_status'=>$campaign['status'],
                'published'=>false,'checkout_authorized'=>false];
        } finally {
            delete_option($claimKey);
        }
    }
    public static function scan():array {
        if(!self::allowed())return ['status'=>'unauthorized'];
        $watch=get_option(self::WATCH_OPTION,[]);
        if(!is_array($watch)||!$watch)return ['status'=>'no_tracked_inventory'];
        $today=wp_date('Y-m-d');
        $cursor=(int)get_option('mad4b_lm_watch_cursor_v1',0);
        $keys=array_keys($watch);$count=count($keys);$events=0;$processed=0;
        foreach(range(0,min(self::MAX_PER_RUN,$count)-1) as $offset) {
            $idx=($cursor+$offset)%$count;$row=$watch[$keys[$idx]]??null;
            if(!is_array($row))continue;
            if(($row['travel_date']??'')<$today){unset($watch[$keys[$idx]]);continue;}
            $result=self::handle($row);
            $processed++;
            if(($result['status']??'')==='event_recorded')$events++;
        }
        update_option(self::WATCH_OPTION,$watch,false);
        update_option('mad4b_lm_watch_cursor_v1',$count?($cursor+$processed)%$count:0,false);
        return ['status'=>'checked','processed'=>$processed,'events'=>$events];
    }
    public static function admin_menu():void {
        if(!current_user_can('manage_woocommerce'))return;
        add_submenu_page('woocommerce','Last Minute Campaign Events','Last Minute Events',
            'manage_woocommerce','mad4b-last-minute-events',static function(){
                if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');
                $items=array_reverse((array)get_option(self::EVENTS_OPTION,[]));
                echo '<div class="wrap"><h1>Last Minute · Inventory Events</h1>';
                echo '<p>Read-only transition receipts. Events originate from trusted inventory updates, not visitor quotes. Campaigns are created as review-only drafts.</p>';
                echo '<p>Tracked inventory windows: '.count((array)get_option(self::WATCH_OPTION,[])).'</p>';
                echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Event</th><th>Tour / Rate</th><th>Travel</th><th>Transition</th><th>Draft</th></tr></thead><tbody>';
                foreach(array_slice($items,0,100) as $r){
                    $id=(int)($r['campaign_id']??0);
                    echo '<tr><td>'.esc_html($r['at_utc']??'').'</td><td>'.esc_html($r['event_type']??'').'</td>';
                    echo '<td>'.esc_html(($r['tour_id']??'').' / '.($r['rate_id']??'')).'</td><td>'.esc_html($r['travel_date']??'').'</td>';
                    echo '<td>'.esc_html(($r['from_band']??'').' → '.($r['to_band']??'')).'</td>';
                    echo '<td>'.($id?'<a href="'.esc_url(get_edit_post_link($id)).'">Draft #'.$id.'</a>':'—').'</td></tr>';
                }
                echo '</tbody></table></div>';
            });
    }
    public static function boot():void {
        // Presentation-only companies need the same editorial Campaign identity,
        // without loading Woo discounts, coupon handlers or checkout policies.
        add_action('init',static function(){
            $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
                && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
            $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
                && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
            if(!$presentation || $commerce || post_type_exists('offer-campaign'))return;
            register_post_type('offer-campaign',[
                'labels'=>['name'=>'Offer Campaigns','singular_name'=>'Offer Campaign',
                    'add_new_item'=>'Add campaign draft','edit_item'=>'Edit campaign draft'],
                'public'=>false,'show_ui'=>true,'show_in_menu'=>true,
                'show_in_rest'=>true,'exclude_from_search'=>true,
                'publicly_queryable'=>false,'has_archive'=>false,'rewrite'=>false,
                'supports'=>['title','editor','revisions'],
                'capability_type'=>'post','map_meta_cap'=>true
            ]);
        },30);
        add_action(self::TRIGGER_HOOK,[self::class,'handle'],20,1);
        add_action(self::WATCH_HOOK,[self::class,'scan'],20,0);
        add_action('admin_menu',[self::class,'admin_menu'],28);
        add_action('init',static function(){
            if(!wp_next_scheduled(self::WATCH_HOOK))
                wp_schedule_event(time()+600,'hourly',self::WATCH_HOOK);
        },99);
    }
}
MAD4B_Last_Minute_Campaign_Events_V1::boot();
