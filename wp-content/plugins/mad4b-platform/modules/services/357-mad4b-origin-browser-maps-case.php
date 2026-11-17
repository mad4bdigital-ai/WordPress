<?php
/**
 * Plugin Name: MAD4B Origin Browser Maps Case
 * Description: Short-lived read-only Maps JavaScript browser verification on actual site origin.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Origin_Browser_Maps_Case {
    const PREFIX='mad4b_origin_browser_map_';
    const CONTRACT='mad4b.browser-maps-evidence.v1';
    public static function issue($post_id,$ttl=120){
        if(!defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli')
            return new WP_Error('mad4b_browser_case_original_cli_required');
        if(wp_get_environment_type()!=='staging' || rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
            return new WP_Error('mad4b_browser_case_wrong_deployment');
        $p=get_post(absint($post_id));
        if(!$p || $p->post_status!=='publish' || $p->post_type!=='tours-and-activities')
            return new WP_Error('mad4b_browser_case_post_invalid');
        $itinerary=get_post_meta($p->ID,'itinerary_item_description',true);
        if(!is_string($itinerary) || !preg_match('/\\bCairo International Airport\\b/i',explode('|',$itinerary)[0]))
            return new WP_Error('mad4b_browser_case_airport_not_in_first_day');
        $ttl=max(30,min(120,(int)$ttl));
        $ticket=bin2hex(random_bytes(20));
        $details=['post_id'=>$p->ID,'address'=>'Cairo International Airport, Egypt',
            'evidence_sha256'=>hash('sha256',$itinerary),
            'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'issued'=>time()];
        if(!set_transient(self::PREFIX.$ticket,$details,$ttl))
            return new WP_Error('mad4b_browser_case_ticket_store_failed');
        return ['contract'=>self::CONTRACT,'post_id'=>$p->ID,
            'url'=>admin_url('admin-ajax.php?action=mad4b_origin_maps_case&ticket='.$ticket),
            'expires_seconds'=>$ttl,'source_sha256'=>$details['evidence_sha256']];
    }
    public static function receipt(){
        if(wp_get_environment_type()!=='staging'||rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
            wp_send_json_error(['code'=>'wrong_environment'],403);
        $ticket=(string)($_POST['ticket']??'');
        $status=sanitize_key((string)($_POST['state']??''));
        $states=['maps_geocoded','maps_auth_denied','maps_provider_error','maps_load_timeout','zero_results'];
        if(!preg_match('/^[a-f0-9]{40}$/D',$ticket)||!in_array($status,$states,true))
            wp_send_json_error(['code'=>'invalid_browser_receipt'],400);
        $details=get_transient(self::PREFIX.$ticket);
        if(!is_array($details)||empty($details['rendered_at'])||
           (int)$details['rendered_at']<time()-90 ||
           $details['site_uuid']!==MAD4B_SCP_Site_Profile::site_uuid()||
           (int)$details['post_id']!==(int)($_POST['post_id']??0))
            wp_send_json_error(['code'=>'expired_or_invalid_ticket'],403);
        $id=(int)$details['post_id'];
        $post=get_post($id);
        if(!$post || $post->post_status!=='publish' ||
           $details['evidence_sha256']!==hash('sha256',(string)get_post_meta($id,'itinerary_item_description',true)))
            wp_send_json_error(['code'=>'source_changed'],409);
        $issue_key=hash('sha256',get_current_blog_id().'|tours-and-activities|'.$id.'|origin_missing');
        $issues=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $issue=$issues[$issue_key]??null;
        if(!is_array($issue)||($issue['status']??'')!=='open'||
           empty($issue['content_job_id']) ||
           ($issue['content_job_request']['site_uuid']??'')!==$details['site_uuid'])
            wp_send_json_error(['code'=>'no_open_canonical_job'],409);
        $observation=[
            'contract'=>self::CONTRACT,'state'=>strtoupper($status),
            'source'=>'maps_javascript_real_browser',
            'post_id'=>$id,'site_uuid'=>$details['site_uuid'],
            'job_id'=>$issue['content_job_id'],
            'source_sha256'=>$details['evidence_sha256'],
            'browser_origin'=>rtrim(home_url(),'/'),
            'recorded_at'=>gmdate('c'),
            'browser_provider_response_not_server_verified'=>true,
            'exact_pickup_verified'=>false,
            'writeback_authorized'=>false
        ];
        // Provider diagnostic only; not authority or a location claim.
        if($status==='maps_provider_error'){
            $code=sanitize_key((string)($_POST['provider_error_code']??''));
            if(strlen($code)>80)$code=substr($code,0,80);
            if($code!=='')$observation['provider_error_code']=$code;
        }
        if($status==='maps_geocoded'){
            $lat=filter_var($_POST['lat']??null,FILTER_VALIDATE_FLOAT);
            $lng=filter_var($_POST['lng']??null,FILTER_VALIDATE_FLOAT);
            $place=preg_replace('/[^a-zA-Z0-9_:-]/','',(string)($_POST['place_id']??''));
            $address=sanitize_text_field((string)($_POST['address']??''));
            if($lat===false||$lng===false||abs($lat)>90||abs($lng)>180||
               strlen($place)>160||strlen($address)>300)
                wp_send_json_error(['code'=>'invalid_location_candidate'],400);
            $observation['candidate']=[
                'place_id'=>$place,'address'=>$address,'latitude'=>$lat,'longitude'=>$lng,
                'verified_for_pickup'=>false
            ];
        }
        delete_transient(self::PREFIX.$ticket);
        $issues[$issue_key]['origin_maps_browser']=$observation;
        update_option('mad4b_content_readiness_issues_v1',$issues,false);
        $fresh=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        if(($fresh[$issue_key]['origin_maps_browser']['state']??'')!==strtoupper($status))
            wp_send_json_error(['code'=>'receipt_readback_failed'],500);
        wp_send_json_success(['contract'=>self::CONTRACT,'state'=>strtoupper($status),
            'post_id'=>$id,'job_id'=>$issue['content_job_id'],'readback_verified'=>true]);
    }
    public static function render(){
        if(wp_get_environment_type()!=='staging' || rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
            wp_die('not_available','',403);
        $ticket=(string)($_GET['ticket']??'');
        if(!preg_match('/^[a-f0-9]{40}$/D',$ticket))wp_die('invalid_ticket','',403);
        $data=get_transient(self::PREFIX.$ticket);
        if(!is_array($data) || empty($data['post_id']) ||
            ($data['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid())
            wp_die('expired_ticket','',403);
        $key=(string)get_option('elementor_google_maps_api_key','');
        if(!$key)wp_die('browser_key_missing','',503);
        // The browser may render exactly once; its matching one-time receipt
        // consumes the ticket after a bounded, read-only observation.
        if(isset($data['rendered_at']))wp_die('ticket_already_opened','',409);
        $data['rendered_at']=time();
        if(!set_transient(self::PREFIX.$ticket,$data,90))
            wp_die('ticket_not_renewed','',503);
        $p=get_post((int)$data['post_id']);
        $first=is_object($p)?explode('|',(string)get_post_meta($p->ID,'itinerary_item_description',true))[0]:'';
        if(!$p || $p->post_status!=='publish' ||
            hash('sha256',(string)get_post_meta($p->ID,'itinerary_item_description',true))!==$data['evidence_sha256'] ||
            !preg_match('/\\bCairo International Airport\\b/i',$first))
            wp_die('source_changed','',409);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, private, max-age=0');
        $values=['contract'=>self::CONTRACT,'post_id'=>$p->ID,
            'query'=>(string)$data['address'],'source_sha256'=>$data['evidence_sha256'],
            'site_uuid'=>$data['site_uuid']];
        ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>MAD4B Browser Maps Verification</title>
<meta name="robots" content="noindex,nofollow"></head><body>
<p id="status">MAPS_BROWSER_PROBE_RUNNING</p><pre id="mad4b-maps-result">pending</pre>
<script>
const CASE = <?php echo wp_json_encode($values,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const TICKET = <?php echo wp_json_encode($ticket); ?>;
let finished=false;
function finish(status,fields={}) {
  if(finished)return; finished=true;
  const v={...CASE,status,provider:"google_maps_javascript",
    browser_origin:location.origin,source_is_original_tour:true,
    pickup_meeting_point_verified:false,writeback_authorized:false,...fields};
  document.getElementById("status").textContent=status;
  document.getElementById("mad4b-maps-result").textContent=JSON.stringify(v);
  // Browser observations are evidence only, never publication or geo writeback.
  const body=new URLSearchParams({action:"mad4b_origin_maps_receipt",ticket:TICKET,
      post_id:String(CASE.post_id),state:String(status),
      provider_error_code:String(fields.error_code||"").slice(0,80),
      place_id:String(fields.place_id||""),
      address:String(fields.formatted_address||""),
      lat:String(fields.latitude??""),lng:String(fields.longitude??"")});
  fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{
      method:"POST",credentials:"same-origin",
      headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},
      body:body.toString(),cache:"no-store"
  }).catch(()=>{});
}
window.gm_authFailure=()=>finish("MAPS_AUTH_DENIED");
window.mad4bRunMapsCase=async()=>{
  try {
    const geo=new google.maps.Geocoder();
    const result=await geo.geocode({address:CASE.query,region:"EG"});
    const values=result.results||[];
    const r=values[0];
    if(!r) return finish("ZERO_RESULTS");
    const loc=r.geometry?.location;
    finish("MAPS_GEOCODED",{
      place_id:String(r.place_id||""),
      formatted_address:String(r.formatted_address||""),
      result_types:Array.isArray(r.types)?r.types:[],
      latitude:loc?Number(loc.lat()):null,
      longitude:loc?Number(loc.lng()):null,
      candidate_count:values.length,
      airport_place_verified:false
    });
  } catch(e){finish("MAPS_PROVIDER_ERROR",{error_code:String(e.code||e.message||"failed").slice(0,100)})}
};
setTimeout(()=>finish("MAPS_LOAD_TIMEOUT"),18000);
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode($key); ?>&callback=mad4bRunMapsCase&v=weekly"></script>
</body></html><?php
        exit;
    }
}
add_action('wp_ajax_nopriv_mad4b_origin_maps_case',['MAD4B_Origin_Browser_Maps_Case','render']);
add_action('wp_ajax_mad4b_origin_maps_case',['MAD4B_Origin_Browser_Maps_Case','render']);
add_action('wp_ajax_nopriv_mad4b_origin_maps_receipt',['MAD4B_Origin_Browser_Maps_Case','receipt']);
add_action('wp_ajax_mad4b_origin_maps_receipt',['MAD4B_Origin_Browser_Maps_Case','receipt']);
