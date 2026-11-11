<?php
/**
 * Plugin Name: MAD4B Media Incident Lifecycle Bridge (Staging)
 * Description: Copies only verified Readiness media transitions into a bounded local outbox. Original Broker exclusively owns external delivery and ACK.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Media_Incident_Bridge {
    const STORE = 'mad4b_media_incident_outbox_v1';
    const CONTRACT = 'mad4b.media.incident-transition.v1';
    const LIMIT = 100;
    const SOURCES = ['mad4b-listing-media', 'mad4b-listing-media-browser'];

    private static function on_staging(): bool {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'staging'
            && function_exists('home_url')
            && wp_parse_url(home_url(), PHP_URL_HOST) === 'tours.dev.mad4b.com';
    }
    // Pure validation: no browser observation can independently become an incident.
    public static function validate_transition(array $e, array $ledger, array $confirmed): bool {
        $id=$e['issue_id']??null;
        $event=$e['event']??null;
        $source=$e['source']??null;
        $code=$e['code']??null;
        $eid=$e['event_id']??null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id)
            || !is_string($eid) || !preg_match('/^[a-f0-9]{64}$/D',$eid)
            || !in_array($source,self::SOURCES,true)
            || !in_array($event,['discovered','reopened','resolved'],true)
            || !is_string($code) || !preg_match('/^media_(?:missing_reference|local_file_missing|verified_background_missing)[a-z0-9_]*$/D',$code)) return false;
        if ($source==='mad4b-listing-media-browser' && !str_starts_with($code,'media_verified_background_missing')) return false;
        if ($source==='mad4b-listing-media' && str_starts_with($code,'media_verified_background_missing')) return false;
        $row=$ledger[$id]??null;
        if (!is_array($row) || ($row['id']??$id)!==$id
            || ($row['source']??'')!==$source || ($row['code']??'')!==$code
            || (int)($row['object_id']??0)!==(int)($e['object_id']??0)
            || ($row['post_type']??'')!==($e['object_type']??'')
            || (int)($row['object_id']??0)<1) return false;
        $cycle=(int)($row['reopens']??0);
        if ($cycle<0 || !hash_equals(hash('sha256',$id.'|'.$event.'|'.$cycle),$eid)) return false;
        $isOpen=($row['status']??'open')==='open';
        $confirmedNow=isset($confirmed[$id]);
        if ($event==='resolved') return !$isOpen && !$confirmedNow;
        return $isOpen && $confirmedNow;
    }
    public static function record_changes($events): void {
        if (!self::on_staging() || !is_array($events) || !$events
            || !class_exists('MAD4B_Listing_Media_Diagnostics',false)) return;
        $candidates=[];
        foreach($events as $e) {
            if (is_array($e) && in_array($e['source']??'', self::SOURCES,true))
                $candidates[]=$e;
        }
        if (!$candidates) return;
        // Independent authoritative source check, not a browser-supplied verdict.
        $confirmed=[];
        foreach ((array)MAD4B_Listing_Media_Diagnostics::discover() as $row) {
            if (is_array($row) && is_string($row['id']??null)) $confirmed[$row['id']]=true;
        }
        $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $rows=(array)get_option(self::STORE,[]);
        foreach($candidates as $e) {
            if (!self::validate_transition($e,$ledger,$confirmed)) continue;
            $eventId=$e['event_id'];
            if (isset($rows[$eventId])) continue;
            if ($e['event']==='resolved') {
                // A closure receipt is meaningful only after this bridge
                // captured the same genuine issue in an earlier open cycle.
                $priorOpen=false;
                foreach ($rows as $prior) {
                    if (is_array($prior) &&
                        ($prior['issue_id']??'')===$e['issue_id'] &&
                        in_array($prior['event']??'',['discovered','reopened'],true) &&
                        ($prior['browser_test_only']??true)===false) {
                        $priorOpen=true;
                        break;
                    }
                }
                if (!$priorOpen) continue;
            }
            if (count($rows)>=self::LIMIT) break; // Fail closed: never discard pending delivery.
            $issue=$ledger[$e['issue_id']];
            $transition=[
                'contract'=>self::CONTRACT,
                'origin'=>'https://tours.dev.mad4b.com',
                'environment'=>'staging',
                'issue_id'=>$e['issue_id'],
                'event_id'=>$eventId,
                'event'=>$e['event'],
                'source'=>$e['source'],
                'code'=>$e['code'],
                'object_type'=>$e['object_type'],
                'object_id'=>(int)$e['object_id'],
                'severity'=>$e['severity'],
                'readiness_status'=>$issue['status']??'',
                'observed_at'=>$e['at']??gmdate('c'),
                'queued_at'=>gmdate('c'),
                'delivery_state'=>'pending_original_broker_ack',
                'external_delivery_attempted'=>false,
                'external_ack'=>null,
                'browser_test_only'=>false,
            ];
            $rows[$eventId]=$transition;
        }
        // Never dispatch externally from this hook.
        if ($rows!==(array)get_option(self::STORE,[])) update_option(self::STORE,$rows,false);
    }
    public static function summary(): array {
        $rows=(array)get_option(self::STORE,[]);
        $open=0;$resolved=0;$acked=0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if (($r['event']??'')==='resolved') $resolved++;
            else $open++;
            if (($r['delivery_state']??'')==='central_ack_verified') $acked++;
        }
        return [
            'contract'=>'mad4b.media.incident-outbox-status.v1',
            'total_transitions'=>count($rows),
            'open_transitions'=>$open,
            'resolved_transitions'=>$resolved,
            'central_acks_verified'=>$acked,
            'pending_original_broker_ack'=>count($rows)-$acked,
            'capacity_full'=>count($rows)>=self::LIMIT,
            'external_dispatch_performed_by_this_bridge'=>false,
            'readiness_outbound_policy_unchanged'=>true,
            'production_mutation'=>false,
        ];
    }
    public static function pending_readback(): array {
        $rows=(array)get_option(self::STORE,[]);
        $pending=[];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['delivery_state']??'')!=='pending_original_broker_ack') continue;
            // Only first-party event-envelope fields. No browser information or credentials.
            $pending[]=array_intersect_key($row,array_flip([
                'contract','origin','environment','issue_id','event_id','event',
                'source','code','object_type','object_id','severity','readiness_status',
                'observed_at','queued_at','delivery_state',
            ]));
            if (count($pending)>=25) break;
        }
        return [
            'contract'=>'mad4b.media.incident-pending-readback.v1',
            'events'=>$pending,
            'returned'=>count($pending),
            'total_pending'=>self::summary()['pending_original_broker_ack'],
            'read_only'=>true,
            'requires_original_broker_for_ack'=>true,
        ];
    }
    public static function rest_routes(): void {
        if (!self::on_staging()) return;
        register_rest_route('mad4b-media/v1','/incident-outbox',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static fn()=>rest_ensure_response(self::pending_readback()),
        ]);
    }
    public static function boot(): void {
        add_action('mad4b/readiness/events_recorded',[self::class,'record_changes'],30,1);
        add_action('rest_api_init',[self::class,'rest_routes']);
    }
}
MAD4B_Media_Incident_Bridge::boot();
