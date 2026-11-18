<?php
/** WordPress user is authoritative for staff identity; JetEngine stores assignments. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Communication_Team {
    private const OPTIONS='company-communication-operations';
    private static function on($value):bool {
        return in_array(strtolower(trim((string)$value)),array('yes','1','true','enabled'),true);
    }
    public static function members(string $team, bool $onlyEnabled=false):array {
        if(!in_array($team,array('internal','external'),true))return array();
        $field='operations_'.$team.'_team';
        $c=MAD4B_SCP_Company_Communication::config();$source=$c[$field]??array();
        if(!is_array($source))return array();
        $result=array();
        foreach(array_slice($source,0,60) as $row){
            if(!is_array($row))continue;
            $id=absint($row['wp_user_id']??0);
            $user=$id?get_userdata($id):false;
            // Never trust manually entered names or emails when a WP User is linked.
            if($id && !$user){$valid=false;$name='';$email='';}
            elseif($user){$valid=is_email($user->user_email)!==false;
                $name=sanitize_text_field($user->display_name);
                $email=sanitize_email($user->user_email);
            }else{
                // External contacts without WP accounts may be stored explicitly.
                $name=$team==='external'?sanitize_text_field((string)($row['contact_name']??'')):'';
                $email=$team==='external'?sanitize_email((string)($row['email']??'')):'';
                $valid=$team==='external' && $name!=='' && is_email($email)!==false;
            }
            $enabled=self::on($row['enabled']??'no');
            if($onlyEnabled && (!$enabled||!$valid))continue;
            $result[]=array(
                'wp_user_id'=>$user?(int)$user->ID:($id?:null),
                'linked_to_wp_user'=>(bool)$user,
                'enabled'=>$enabled,
                'valid'=>$valid,
                'display_name'=>$name,'email'=>$email,
                'phone'=>$user?sanitize_text_field((string)get_user_meta($user->ID,'phone',true)):'',
                'wp_roles'=>$user?array_values((array)$user->roles):array(),
                'team'=>$team,
                'responsibility'=>sanitize_key((string)($row['responsibility']??'')),
                'service_scope'=>sanitize_key((string)($row['service_scope']??'')),
                'partner'=>$team==='external'?sanitize_text_field((string)($row['partner']??'')):'',
            );
        }
        return $result;
    }
    public static function teamEmails(string $team, ?string $responsibility=null):array {
        $emails=array();
        foreach(self::members($team,true) as$member){
            if($responsibility!==null && $member['responsibility']!==$responsibility)continue;
            $emails[]=strtolower($member['email']);
        }
        return array_values(array_unique($emails));
    }
    public static function selector($fields,$page) {
        if(!current_user_can('mad4b_manage_company_operations') || !is_object($page)
            || ($page->slug??'')!==self::OPTIONS || !is_array($fields))return $fields;
        $userOptions=array(array('key'=>'0','value'=>'— Select a WordPress user —'));
        foreach(get_users(array('number'=>200,'orderby'=>'display_name','order'=>'ASC',
            'fields'=>array('ID','display_name','user_email'))) as$u){
            if(!is_email($u->user_email)||!$u->ID)continue;
            $ud=get_userdata($u->ID);
            if(!$ud || in_array('administrator',$ud->roles,true)||in_array('master_admin',$ud->roles,true))continue;
            $userOptions[]=array('key'=>(string)$u->ID,
                'value'=>sanitize_text_field($u->display_name).' (#'.(int)$u->ID.') · '.sanitize_email($u->user_email));
        }
        foreach($fields as &$group){
            if(!in_array($group['name']??'',array('operations_internal_team','operations_external_team'),true))continue;
            foreach($group['repeater-fields'] as &$f){
                if(($f['name']??'')==='wp_user_id'){
                    $f['type']='select';$f['options_source']='manual';$f['options']=$userOptions;
                    $f['title']='Linked WordPress User';
                    $f['description']='Name and email are read LIVE from WP Users. No duplicated identity.';
                }
                if(($f['name']??'')==='enabled'){
                    $f['type']='select';$f['options_source']='manual';
                    $f['options']=array(array('key'=>'no','value'=>'Inactive (default)'),
                        array('key'=>'yes','value'=>'Active'));
                }
                if(($group['name']??'')==='operations_internal_team'
                  && ($f['name']??'')==='responsibility'){
                    $f['type']='select';$f['options_source']='manual';
                    $f['options']=array(array('key'=>'booking','value'=>'Booking operations'),
                        array('key'=>'follow_up','value'=>'Traveler follow-up'),
                        array('key'=>'sales','value'=>'Sales & quotation'),
                        array('key'=>'support','value'=>'Traveler support'),
                        array('key'=>'escalation','value'=>'Escalation'));
                }
                if(($group['name']??'')==='operations_internal_team'
                  && in_array($f['name']??'',array('display_name','email'),true)){
                    // Hide legacy duplicate identity inputs, not operational metadata.
                    $f['type']='hidden';
                    $f['description']='Unused; WP User is authoritative.';
                }
                if(($group['name']??'')==='operations_external_team'
                  && in_array($f['name']??'',array('contact_name','email'),true)){
                    $f['description']='Manual fallback only when no linked WP User is selected.';
                }
            }unset($f);
        }unset($group);
        return $fields;
    }
    public static function teamREST($req){
        $team=sanitize_key((string)$req->get_param('team'));
        if(!in_array($team,array('internal','external'),true))
            return new WP_Error('mad4b_team_invalid','Team must be internal or external.',array('status'=>422));
        $rows=self::members($team);
        $r=rest_ensure_response(array('contract'=>'mad4b.communication.team.v1','team'=>$team,
            'members'=>$rows,'count'=>count($rows),'source_of_truth'=>'wp_users_when_linked',
            'diagnostic_only'=>true,'messages_sent'=>0));
        $r->header('Cache-Control','private, no-store');return $r;
    }
    public static function usersREST($req){
        $q=trim(sanitize_text_field((string)$req->get_param('search')));
        if(mb_strlen($q)>80)return new WP_Error('mad4b_user_search_too_long','Search too long.',array('status'=>422));
        $args=array('number'=>25,'orderby'=>'display_name','order'=>'ASC',
          'fields'=>array('ID','display_name','user_email'));
        if($q!=='')$args['search']='*'.$q.'*';
        $items=array();
        foreach(get_users($args) as $user){
            if(!is_email($user->user_email))continue;
            $full=get_userdata($user->ID);
            if(!$full||in_array('administrator',$full->roles,true)||in_array('master_admin',$full->roles,true))continue;
            $items[]=array('id'=>(int)$user->ID,'display_name'=>sanitize_text_field($user->display_name),
                'email'=>sanitize_email($user->user_email));
        }
        $r=rest_ensure_response(array('contract'=>'mad4b.communication.wp-users.v1',
            'source'=>'wordpress_users','items'=>$items,'count'=>count($items),'read_only'=>true));
        $r->header('Cache-Control','private, no-store');return $r;
    }
    public static function boot():void {
        add_filter('jet-engine/options-pages/raw-fields',array(__CLASS__,'selector'),20,2);
        add_action('rest_api_init',function(){
            register_rest_route('mad4b/v1','/admin/company-communication/team',array(
              'methods'=>'GET','callback'=>array(__CLASS__,'teamREST'),
              'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')));
            register_rest_route('mad4b/v1','/admin/company-communication/users',array(
              'methods'=>'GET','callback'=>array(__CLASS__,'usersREST'),
              'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')));
        },30);
    }
}
MAD4B_SCP_Company_Communication_Team::boot();
