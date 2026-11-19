<?php
/**
 * Plugin Name: MAD4B Rail Fare Quote Intake
 * Description: Provider-first rail quote requests. No quote is a published Rate, confirmed booking or authorized price.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Rail_Fare_Quote_Intake_V1 {
    const CONTRACT='mad4b.rail-fare-quote-request.v1';
    const STORE='mad4b_rail_fare_quote_requests_v1';

    static function site() {
        if(!class_exists('MAD4B_SCP_Site_Profile'))return '';
        return (string)MAD4B_SCP_Site_Profile::site_uuid();
    }
    static function can_write() {
        if(!current_user_can('manage_options') ||
           !class_exists('MAD4B_SCP_Policy') ||
           !MAD4B_SCP_Policy::can_mutate() ||
           !self::site())return false;
        if(class_exists('MAD4B_SCP_Operational_Integrity')) {
            $c=MAD4B_SCP_Operational_Integrity::capture();
            if(is_wp_error($c)||($c['scope']['site_uuid']??'')!==self::site())return false;
        }
        return true;
    }
    static function date_valid($date) {
        if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))return false;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d')===$date && $date>=wp_date('Y-m-d');
    }
    static function config($id) {
        $id=absint($id);
        if(get_post_type($id)!=='transportations'||get_post_status($id)!=='publish')
            return new WP_Error('rail_transport_not_published');
        $mode=get_post_meta($id,'service_mode',true);
        if(is_array($mode))$mode=reset($mode);
        if($mode!=='fixed_route_schedule')return new WP_Error('rail_transport_not_fixed_route');
        $num=trim((string)get_post_meta($id,'trans_train_number',true));
        if(!preg_match('/^[0-9]{1,5}$/D',$num))return new WP_Error('rail_train_number_required');
        $from=absint(get_post_meta($id,'trans_from_station_id',true));
        $to=absint(get_post_meta($id,'trans_to_station_id',true));
        if(!$from||!$to||$from===$to||get_post_type($from)!=='stations'||
           get_post_type($to)!=='stations'||get_post_status($from)!=='publish'||
           get_post_status($to)!=='publish')return new WP_Error('rail_route_stations_invalid');
        $typeTerm=absint(get_post_meta($id,'trans_type',true));
        $term=$typeTerm?get_term($typeTerm,'transportation-types'):null;
        $typeSlug=$term instanceof WP_Term?$term->slug:'';
        $classesByType=[
            'sleeping'=>['single_cabin','double_cabin','elite_single','elite_double'],
            'talgo'=>['talgo_first','talgo_second'],
            'vip'=>['vip_first','vip_second'],
        ];
        if(!isset($classesByType[$typeSlug]))
            return new WP_Error('rail_train_type_not_verified');
        $sleep=$typeSlug==='sleeping';
        $type=$typeSlug;
        $operator=$sleep?'abela':'enr';
        $source=$sleep?'https://abelatrains.com/':
            'https://obs.enr.gov.eg/o-city/obs/enr/railway/ar/booktickets';
        $classes=$classesByType[$typeSlug];
        // The existing ContentJob's price-evidence gap is the canonical intake source.
        $issueId=hash('sha256',get_current_blog_id().'|transportations|'.$id.'|geo_transport_price_evidence_unverified');
        $issues=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $issue=$issues[$issueId]??[];
        if(($issue['code']??'')!=='geo_transport_price_evidence_unverified' ||
           ($issue['content_job_request']['site_uuid']??'')!==self::site() ||
           empty($issue['content_job_id']))
            return new WP_Error('rail_canonical_content_job_not_found');
        return ['transport_id'=>$id,'title'=>get_the_title($id),
            'train_number'=>$num,'train_kind'=>$type,'provider'=>$operator,
            'provider_source'=>$source,'from_station_id'=>$from,'to_station_id'=>$to,
            'from_station'=>get_the_title($from),'to_station'=>get_the_title($to),
            'classes'=>$classes,'markets'=>['egyptian','arab','foreign'],
            'provider_booking_policy'=>$sleep?'abela_cabin_reservation':
                'enr_website_egyptian_only_other_markets_require_operator',
            'station_route_validation'=>$sleep?
                'operator_must_confirm_actual_boarding_station_not_only_cairo_city':
                'operator_must_confirm_train_stop_and_boarding_date',
            'site_uuid'=>self::site(),'issue_id'=>$issueId,
            'content_job_id'=>$issue['content_job_id'],
            'source_status'=>get_post_status($id),
            'legacy_rate_ids'=>get_posts(['post_type'=>'transport-rates',
                'post_status'=>['publish','draft','private','pending'],
                'posts_per_page'=>30,'fields'=>'ids',
                'meta_key'=>'source_post_id','meta_value'=>$id,'suppress_filters'=>true])];
    }
    static function discover() {
        $issues=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $out=[];
        foreach($issues as $row){
            if(($row['code']??'')!=='geo_transport_price_evidence_unverified' ||
               ($row['status']??'')!=='open')continue;
            $cfg=self::config((int)$row['object_id']);
            if(!is_wp_error($cfg))$out[]=$cfg;
        }
        usort($out,static fn($a,$b)=>$a['transport_id']<=>$b['transport_id']);
        return $out;
    }
    /**
     * ENR online booking window, not supplier inventory or authorization.
     * Booking window announced publicly by ENR in July 2026: 15 days -> 12 hours.
     * Abela cabin booking follows a separate operator process.
     */
    static function booking_window($cfg,$date) {
        if(($cfg['provider']??'')!=='enr')return [
            'state'=>'OPERATOR_BOOKING_RULES_REQUIRED','source'=>'https://sleep.abelatrains.com/',
            'booking_available'=>false,'supplier_verified'=>false
        ];
        $time=(string)get_post_meta((int)$cfg['transport_id'],'trans_departure_time',true);
        if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D',$time))
            return ['state'=>'INVALID_TRAIN_DEPARTURE_TIME','booking_available'=>false];
        $tz=wp_timezone();
        $depart=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,$tz);
        if(!$depart || $depart->format('Y-m-d H:i')!==$date.' '.$time)
            return ['state'=>'INVALID_TRAIN_DEPARTURE_DATE','booking_available'=>false];
        $now=new DateTimeImmutable('now',$tz);
        $opens=$depart->modify('-15 days');
        $latest=$depart->modify('-12 hours');
        $state=$now<$opens?'WAITING_BOOKING_WINDOW':($now>$latest?
            'OUTSIDE_ONLINE_BOOKING_WINDOW':'WITHIN_ONLINE_BOOKING_WINDOW');
        return ['state'=>$state,'window_opens'=>$opens->format(DATE_ATOM),
            'online_booking_cutoff'=>$latest->format(DATE_ATOM),
            'train_departure'=>$depart->format(DATE_ATOM),
            'source'=>'https://sis.gov.eg/ar/',
            'booking_available'=>false,'supplier_verified'=>false];
    }
    static function key($cfg,$date,$class,$market) {
        return hash('sha256',implode('|',[self::CONTRACT,$cfg['site_uuid'],$cfg['transport_id'],
            $cfg['train_number'],$cfg['from_station_id'],$cfg['to_station_id'],
            $date,$class,$market]));
    }
    static function store($row) {
        $all=(array)get_option(self::STORE,[]);
        $key=(string)$row['request_key'];
        if(isset($all[$key]))return ['row'=>$all[$key],'created'=>false];
        $all[$key]=$row;
        update_option(self::STORE,$all,false);
        $read=(array)get_option(self::STORE,[]);
        if(!isset($read[$key])||($read[$key]['source_sha256']??'')!==$row['source_sha256'])
            return new WP_Error('rail_intake_readback_failed');
        return ['row'=>$read[$key],'created'=>true];
    }
    static function request($id,$date,$class,$market) {
        if(!self::can_write())return new WP_Error('rail_intake_forbidden');
        $cfg=self::config($id);
        if(is_wp_error($cfg))return $cfg;
        if(!self::date_valid($date))return new WP_Error('rail_travel_date_required');
        if(!in_array($class,$cfg['classes'],true))return new WP_Error('rail_class_invalid_for_provider');
        if(!in_array($market,$cfg['markets'],true))return new WP_Error('rail_market_required');
        // A valid context is not an authenticated supplier price or booking grant.
        $key=self::key($cfg,$date,$class,$market);
        $snapshot=[
            'site_uuid'=>$cfg['site_uuid'],'transport_id'=>$cfg['transport_id'],
            'train_number'=>$cfg['train_number'],'travel_date'=>$date,
            'from_station_id'=>$cfg['from_station_id'],'to_station_id'=>$cfg['to_station_id'],
            'service_class'=>$class,'market'=>$market,'provider'=>$cfg['provider'],
            'supplier_url'=>$cfg['provider_source'],
        ];
        $row=['contract'=>self::CONTRACT,'request_key'=>$key,
            'source_sha256'=>hash('sha256',wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES)),
            'snapshot'=>$snapshot,'issue_id'=>$cfg['issue_id'],
            'content_job_id'=>$cfg['content_job_id'],
            'status'=>(($cfg['provider']==='enr'&&$market!=='egyptian')?
                'MANUAL_PROVIDER_QUOTE_REQUIRED':
                ((self::booking_window($cfg,$date)['state']??'')==='WAITING_BOOKING_WINDOW'?
                    'WAITING_BOOKING_WINDOW':'AWAITING_AUTHENTICATED_SUPPLIER_QUOTE')),
            'source_family'=>'dated_rail_quote',
            'quote_amount'=>null,'quote_currency'=>null,'rate_id'=>null,
            'supplier_verified'=>false,'external_booking_authorized'=>false,
            'supplier_channel'=>($cfg['provider']==='enr'&&$market!=='egyptian')?
                'MANUAL_PROVIDER_QUOTE_REQUIRED':'PROVIDER_VERIFICATION_REQUIRED',
            'online_booking_window'=>self::booking_window($cfg,$date),
            'route_station_confirmation_required'=>true,
            'pricing_approval_authorized'=>false,'publication_authorized'=>false,
            'booking_authorized'=>false,'created_at'=>gmdate('c'),
            'actor_user_id'=>get_current_user_id(),
            'notes'=>($cfg['provider']==='abela'?
                'Sleeping train: supplier-specific cabin, passport market, actual origin and departure date are mandatory.':
                'ENR: departure date, class, train number, passenger market and route must match official quote.'),
        ];
        $result=self::store($row);
        if(!is_wp_error($result)&&!empty($result['created'])&&class_exists('MAD4B_SCP_Audit'))
            MAD4B_SCP_Audit::record('mad4b/rail-quote-intake-request',[
                'site_uuid'=>$cfg['site_uuid'],'transport_id'=>$id,'request_key'=>$key,
                'source_sha256'=>$row['source_sha256'],'job_id'=>$cfg['content_job_id'],
                'amount_provided'=>false,'booking_authorized'=>false
            ],'ok');
        return $result;
    }
    static function templates() {
        if(!self::can_write())return new WP_Error('rail_intake_forbidden');
        $created=0;$already=0;$errors=[];
        $marked_stale=0;$window_refreshed=0;
        $all=(array)get_option(self::STORE,[]);
        foreach($all as $rowKey=>$existing){
            $snap=(array)($existing['snapshot']??[]);
            $id=absint($snap['transport_id']??0);
            $cfg=$id?self::config($id):null;
            if(is_wp_error($cfg)||!is_array($cfg))continue;
            $routeChanged=($snap['train_number']??'')!==$cfg['train_number'] ||
                (int)($snap['from_station_id']??0)!==(int)$cfg['from_station_id']||
                (int)($snap['to_station_id']??0)!==(int)$cfg['to_station_id']||
                ($snap['provider']??'')!==$cfg['provider'];
            if($routeChanged && !in_array(($existing['status']??''),['STALE_SOURCE_ROUTE','CANCELLED'],true)){
                $all[$rowKey]['status']='STALE_SOURCE_ROUTE';
                $all[$rowKey]['booking_authorized']=false;
                $all[$rowKey]['supplier_verified']=false;
                $all[$rowKey]['pricing_approval_authorized']=false;
                $all[$rowKey]['stale_at']=gmdate('c');
                $marked_stale++;continue;
            }
            if(!$routeChanged && !empty($snap['travel_date']) &&
                ($existing['status']??'')==='WAITING_BOOKING_WINDOW') {
                $window=self::booking_window($cfg,(string)$snap['travel_date']);
                if(($window['state']??'')==='WITHIN_ONLINE_BOOKING_WINDOW'){
                    $all[$rowKey]['status']='AWAITING_AUTHENTICATED_SUPPLIER_QUOTE';
                    $all[$rowKey]['online_booking_window']=$window;
                    $window_refreshed++;
                }
            }
        }
        if($marked_stale||$window_refreshed)update_option(self::STORE,$all,false);
        foreach(self::discover() as $cfg){
            $legacyKey=hash('sha256',self::CONTRACT.'|'.$cfg['site_uuid'].'|'.$cfg['transport_id'].'|CONTEXT_MISSING');
            $snap=['site_uuid'=>$cfg['site_uuid'],'transport_id'=>$cfg['transport_id'],
                'train_number'=>$cfg['train_number'],
                'from_station_id'=>$cfg['from_station_id'],
                'to_station_id'=>$cfg['to_station_id'],
                'provider'=>$cfg['provider'],'supplier_url'=>$cfg['provider_source'],
                'travel_date'=>null,'service_class'=>null,'market'=>null];
            $sha=hash('sha256',wp_json_encode($snap,JSON_UNESCAPED_SLASHES));
            // Preserve the immutable historic template and open a new revision
            // when the provider route changes; do not erase earlier evidence.
            $old=(array)get_option(self::STORE,[]);
            $key=isset($old[$legacyKey]) &&
                ($old[$legacyKey]['source_sha256']??'')!==$sha?
                hash('sha256',$legacyKey.'|'.$sha):$legacyKey;
            $row=['contract'=>self::CONTRACT,'request_key'=>$key,
                'source_sha256'=>$sha,
                'snapshot'=>$snap,'issue_id'=>$cfg['issue_id'],
                'content_job_id'=>$cfg['content_job_id'],
                'status'=>'AWAITING_TRAVEL_DATE_CLASS_MARKET',
                'quote_amount'=>null,'quote_currency'=>null,'rate_id'=>null,
                'supplier_verified'=>false,'pricing_approval_authorized'=>false,
                'publication_authorized'=>false,'booking_authorized'=>false,
                'created_at'=>gmdate('c'),'actor_user_id'=>get_current_user_id()];
            $r=self::store($row);
            if(is_wp_error($r))$errors[]=['transport_id'=>$cfg['transport_id'],'error'=>$r->get_error_code()];
            elseif($r['created'])$created++;else $already++;
        }
        return ['created'=>$created,'existing'=>$already,'errors'=>$errors,
            'marked_stale'=>$marked_stale,'booking_window_refreshed'=>$window_refreshed,
            'source_jobs'=>count(self::discover()),'quote_amounts_created'=>0];
    }
    static function cli($args,$assoc) {
        $op=$args[0]??'plan';
        if($op==='plan') {
            $cfg=self::discover();
            $rows=(array)get_option(self::STORE,[]);
            $out=['contract'=>self::CONTRACT,'site'=>home_url(),
                'services'=>$cfg,'requests_total'=>count($rows),
                'quote_status_counts'=>[],'price_writeback_disabled'=>true,
                'needs_authenticated_provider'=>true];
            foreach($rows as $r){$s=$r['status']??'UNKNOWN';$out['quote_status_counts'][$s]=($out['quote_status_counts'][$s]??0)+1;}
        }elseif($op==='reconcile')$out=self::templates();
        elseif($op==='request')$out=self::request(
            (int)($assoc['transport-id']??0),
            (string)($assoc['travel-date']??''),
            (string)($assoc['class']??''),
            (string)($assoc['market']??''));
        else WP_CLI::error('Use plan | reconcile | request --transport-id=N --travel-date=YYYY-MM-DD --class=... --market=...');
        if(is_wp_error($out))WP_CLI::error($out->get_error_code());
        WP_CLI::line(wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    static function admin_menu() {
        if(!current_user_can('manage_options'))return;
        add_management_page('Rail Fare Requests','Rail Fare Requests','manage_options',
            'mad4b-rail-fares',[__CLASS__,'admin_page']);
    }
    static function admin_page() {
        if(!current_user_can('manage_options'))wp_die('Forbidden');
        $cfg=self::discover();
        $all=(array)get_option(self::STORE,[]);
        echo '<div class="wrap"><h1>Rail Fare Quote Intake</h1>';
        echo '<p>Supplier-first research. A request does not set a price, create a Rate, approve a booking, or claim inventory.</p>';
        if(!self::can_write())echo '<p><strong>Write lane not authorized for this operator/site.</strong></p>';
        echo '<p>Saved requests: '.(int)count($all).'. Missing dated supplier proof remains a ContentJob review.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Train</th><th>Route</th><th>Provider</th><th>Request</th></tr></thead><tbody>';
        foreach($cfg as $r){
            echo '<tr><td>'.esc_html($r['train_number'].' — '.$r['title']).'</td><td>'.
             esc_html($r['from_station'].' → '.$r['to_station']).'</td><td><a href="'.
             esc_url($r['provider_source']).'" target="_blank" rel="noopener noreferrer">'.
             esc_html(strtoupper($r['provider'])).'</a></td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_rail_request_'.$r['transport_id']);
            echo '<input type="hidden" name="action" value="mad4b_rail_request">';
            echo '<input type="hidden" name="transport_id" value="'.(int)$r['transport_id'].'">';
            echo '<input type="date" required name="travel_date" min="'.esc_attr(wp_date('Y-m-d')).'">';
            echo '<select required name="service_class"><option value="">Class</option>';
            foreach($r['classes'] as $class)echo '<option value="'.esc_attr($class).'">'.esc_html(str_replace('_',' ',$class)).'</option>';
            echo '</select><select required name="market"><option value="">Market</option>';
            foreach($r['markets'] as $market)echo '<option value="'.esc_attr($market).'">'.esc_html($market).'</option>';
            echo '</select><button class="button" type="submit" '.(self::can_write()?'':'disabled').'>Queue supplier quote request</button></form></td></tr>';
        }
        echo '</tbody></table><p>No supplier price may become a live published Rate from this intake alone.</p></div>';
    }
    static function admin_submit() {
        $id=absint($_POST['transport_id']??0);
        if(!current_user_can('manage_options') ||
           !check_admin_referer('mad4b_rail_request_'.$id))wp_die('Denied');
        $r=self::request($id,sanitize_text_field(wp_unslash($_POST['travel_date']??'')),
            sanitize_key(wp_unslash($_POST['service_class']??'')),
            sanitize_key(wp_unslash($_POST['market']??'')));
        $code=is_wp_error($r)?$r->get_error_code():'request_recorded';
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-rail-fares','rail_result'=>$code],admin_url('tools.php')));
        exit;
    }
}
add_action('admin_menu',['MAD4B_Rail_Fare_Quote_Intake_V1','admin_menu']);
add_action('admin_post_mad4b_rail_request',['MAD4B_Rail_Fare_Quote_Intake_V1','admin_submit']);
if(defined('WP_CLI')&&WP_CLI){
    WP_CLI::add_command('mad4b rail-fare',['MAD4B_Rail_Fare_Quote_Intake_V1','cli']);
}
