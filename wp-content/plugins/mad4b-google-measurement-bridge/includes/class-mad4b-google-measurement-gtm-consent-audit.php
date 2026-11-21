<?php
/**
 * Central GTM consent audit and guarded source container provisioning.
 *
 * Uses the existing owner-authorized broker in memory. Never exports tokens,
 * writes company tags, creates company workspaces, or bypasses company publishing policy.
 * Enabled only while the first-party Central Company Portfolio Manager is live.
 */
if (!defined('ABSPATH')) exit;

final class MAD4B_Google_Measurement_GTM_Consent_Audit {
    const VERSION='mad4b.gtm-consent-audit.v1';
    const OPTION='mad4b_gtm_portfolio_authenticated_audits_v1';
    private const BASE='https://tagmanager.googleapis.com/tagmanager/v2/';

    public static function operator(): bool {
        return current_user_can('manage_options')
            && class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Google_Measurement_Bridge::gate();
    }
    private static function read(string $path) {
        if(!preg_match('~^accounts/[0-9]{1,20}/containers/[0-9]{1,20}(?:/versions:live)?$~D',$path))
            return new WP_Error('mad4b_gtm_resource_path_invalid','Exact container resource path required.');
        $credential=MAD4B_Google_Measurement_API::token(MAD4B_Google_Measurement_Bridge::SCOPE_GTM_READ);
        if(is_wp_error($credential))return $credential;
        $reply=wp_remote_get(self::BASE.$path,[
            'timeout'=>14,'redirection'=>0,'sslverify'=>true,
            'limit_response_size'=>1200000,
            'headers'=>['Authorization'=>'Bearer '.$credential,'Accept'=>'application/json']
        ]);
        unset($credential);
        if(is_wp_error($reply))return new WP_Error('mad4b_gtm_read_transport','Google API read unavailable.');
        $http=(int)wp_remote_retrieve_response_code($reply);
        if($http!==200)return new WP_Error('mad4b_gtm_read_rejected',
            'Google API rejected GTM read.',['http'=>$http]);
        $decoded=json_decode((string)wp_remote_retrieve_body($reply),true);
        return is_array($decoded)?$decoded:new WP_Error('mad4b_gtm_read_invalid','Unexpected provider response.');
    }
    public static function audit(array $input=[]) {
        if(!self::operator())return new WP_Error('mad4b_gtm_operator_required','Central owner permission required.');
        $account=trim((string)($input['account_id']??''));
        $container=trim((string)($input['container_id']??''));
        $public=trim((string)($input['public_id']??''));
        if(!preg_match('/^[0-9]{1,20}$/D',$account)
            ||!preg_match('/^[0-9]{1,20}$/D',$container)
            ||!preg_match('/^GTM-[A-Z0-9]{6,20}$/D',$public))
            return new WP_Error('mad4b_gtm_exact_identity_missing','Exact Google numeric resource IDs and public ID required.');
        $path="accounts/$account/containers/$container";
        $identity=self::read($path);
        if(is_wp_error($identity))return $identity;
        if(($identity['publicId']??'')!==$public)
            return new WP_Error('mad4b_gtm_identity_mismatch',
              'Public GTM container ID does not match selected Google resource.');
        $live=self::read($path.'/versions:live');
        if(is_wp_error($live))return $live;
        $rows=[];$activeChecksReviewed=0;
        $counts=['active'=>0,'paused'=>0,'additional_needed'=>0,'not_set'=>0,'other'=>0];
        foreach((array)($live['tag']??[]) as $tag) {
            if(!is_array($tag))continue;
            $cons=(array)($tag['consentSettings']??[]);
            $status=(string)($cons['consentStatus']??'notProvided');
            $types=[];
            foreach((array)($cons['consentType']['list']??[]) as $type){
                $value=is_array($type)?(string)($type['value']??''):'';
                if(in_array($value,[
                  'analytics_storage','ad_storage','ad_user_data',
                  'ad_personalization','functionality_storage',
                  'personalization_storage','security_storage'
                ],true))$types[]=$value;
            }
            $paused=(bool)($tag['paused']??false);
            $counts[$paused?'paused':'active']++;
            if(!$paused && $status==='needed' && count($types)>0)$activeChecksReviewed++;
            if($status==='needed')$counts['additional_needed']++;
            elseif($status==='notSet')$counts['not_set']++;
            else $counts['other']++;
            $rows[]=[
              'tag_id'=>(string)($tag['tagId']??''),
              'name'=>mb_substr(wp_strip_all_tags((string)($tag['name']??'')),0,120),
              'type'=>(string)($tag['type']??''),
              'paused'=>$paused,
              'additional_consent_status'=>$status,
              'additional_consent_types'=>$types
            ];
        }
        if(count($rows)>1000)return new WP_Error('mad4b_gtm_too_many_tags','Too many tags for bounded audit.');
        $report=[
          'contract'=>self::VERSION,
          'source'=>'authenticated_google_tag_manager_api_live_version',
          'container_name'=>mb_substr((string)($identity['name']??''),0,120),
          'account_id'=>$account,'container_id'=>$container,'public_id'=>$public,
          'version_id'=>(string)($live['containerVersionId']??''),
          'checked_at_utc'=>gmdate('c'),'counts'=>$counts,'tag_count'=>count($rows),
          'tags'=>$rows,
          'additional_consent_checks_verified'=>$counts['active']>0
             && $counts['active']===$activeChecksReviewed,
          'published_resource_fingerprint'=>hash('sha256',wp_json_encode($rows)),
          'note'=>'The status notSet does not by itself disprove built-in Google consent checks. Every additional requirement must be reviewed before a company changes partial-loading mode.'
        ];
        // Keep only a small tenant-keyed, noncredential audit snapshot.
        $all=(array)get_option(self::OPTION,[]);
        $all[$public]=$report;
        if(count($all)>30)$all=array_slice($all,-30,null,true);
        update_option(self::OPTION,$all,false);
        return $report;
    }

    /**
     * Provision/reconcile a first source-only web container under an explicitly
     * named MAD4B CENTRAL Google Tag Manager account. Never inside a company
     * account, and never from a company-local OAuth grant.
     */
    public static function createSourceContainer(string $parentAccount) {
        if(!self::operator() || site_url()!=='https://tours.dev.mad4b.com')
            return new WP_Error('mad4b_gtm_source_operator_required','Central source administrator required.');
        if(!preg_match('/^[0-9]{1,20}$/D',$parentAccount))
            return new WP_Error('mad4b_gtm_parent_invalid','Numeric GTM account ID required.');
        $plan=(array)get_option('mad4b_source_gtm_provisioning_v1',[]);
        if(($plan['contract']??'')!=='mad4b.source-gtm-container-provisioning.v1' ||
            ($plan['site']??'')!==site_url() ||
            !empty($plan['actual_public_id']))
            return new WP_Error('mad4b_gtm_source_plan_invalid','Source provisioning already completed or plan missing.');
        $accounts=MAD4B_Google_Measurement_API::execute(['action'=>'gtm_accounts']);
        if(is_wp_error($accounts))return $accounts;
        $approved=null;
        foreach((array)($accounts['account']??[]) as $account){
            if((string)($account['accountId']??'')===$parentAccount){$approved=$account;break;}
        }
        if(!$approved || ($accounts['nextPageToken']??'')!=='')
            return new WP_Error('mad4b_gtm_source_account_not_discovered','Exact Google account not in a complete authorized account listing.');
        $name=trim((string)($approved['name']??''));
        if(!in_array($name,['MAD4B Central','MAD4B Control','MAD4B Source'],true))
            return new WP_Error('mad4b_gtm_not_central_account','A dedicated MAD4B Central account is required; existing company accounts are excluded.');
        $existing=MAD4B_Google_Measurement_API::execute([
          'action'=>'gtm_containers','resource_id'=>'accounts/'.$parentAccount
        ]);
        if(is_wp_error($existing))return $existing;
        if(!empty($existing['nextPageToken']))
            return new WP_Error('mad4b_gtm_parent_not_empty','Cannot certify this account contains only the source container.');
        $containers=(array)($existing['container']??[]);
        if(count($containers)>1)
            return new WP_Error('mad4b_gtm_parent_not_empty','Company or multiple containers are not eligible for source enrollment.');
        $creation='existing_google_ui';
        if(count($containers)===1){
            // New GTM accounts created in Google's UI require one initial
            // container. Enroll that one instead of creating a duplicate.
            $created=$containers[0];
            $expected=['MAD4B Source Demo | tours.dev.mad4b.com','tours.dev.mad4b.com'];
            if(!in_array(trim((string)($created['name']??'')),$expected,true) ||
                !in_array('web',(array)($created['usageContext']??[]),true))
                return new WP_Error('mad4b_gtm_parent_not_empty','Existing container is not the source web container.');
        }else{
            $creation='mad4b_api_created';
            $token=MAD4B_Google_Measurement_API::token(MAD4B_Google_Measurement_Bridge::SCOPE_GTM_EDIT);
            if(is_wp_error($token))return $token;
            $url=self::BASE.'accounts/'.$parentAccount.'/containers';
            $payload=['name'=>'MAD4B Source Demo | tours.dev.mad4b.com','usageContext'=>['web']];
            $result=wp_remote_post($url,[
               'timeout'=>18,'redirection'=>0,'sslverify'=>true,
               'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json'],
               'body'=>wp_json_encode($payload)
            ]);
            unset($token);
            if(is_wp_error($result))
                return new WP_Error('mad4b_gtm_source_write_uncertain','GTM create request did not return a reliable response. Inspect Google before retrying.');
            $http=(int)wp_remote_retrieve_response_code($result);
            if($http<200||$http>=300)
                return new WP_Error('mad4b_gtm_source_create_failed','Google declined creation.',['http'=>$http]);
            $created=json_decode((string)wp_remote_retrieve_body($result),true);
        }
        if(!is_array($created)||!preg_match('/^[0-9]{1,20}$/D',(string)($created['containerId']??''))||
           !preg_match('/^GTM-[A-Z0-9]{6,20}$/D',(string)($created['publicId']??'')))
            return new WP_Error('mad4b_gtm_source_create_readback_required','Google returned incomplete container identity. Inspect before retrying.');
        $path='accounts/'.$parentAccount.'/containers/'.$created['containerId'];
        $readback=self::read($path);
        if(is_wp_error($readback)||($readback['publicId']??'')!==$created['publicId'])
            return new WP_Error('mad4b_gtm_source_create_readback_mismatch','Created container could not be verified; manual reconciliation required.');
        $plan['actual_account_id']=$parentAccount;
        $plan['actual_container_id']=(string)$created['containerId'];
        $plan['actual_public_id']=(string)$created['publicId'];
        $plan['state']='created_in_google_pending_site_binding_and_publication';
        $plan['origin']=$creation;
        $plan['created_google_utc']=gmdate('c');
        update_option('mad4b_source_gtm_provisioning_v1',$plan,false);
        return ['container_id'=>$created['containerId'],'public_id'=>$created['publicId'],'state'=>$plan['state'],'source_site_unchanged'=>true];
    }
    public static function provisionPost():void {
        if(!self::operator()||!current_user_can('manage_options')||
           strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'||
           !check_admin_referer('mad4b_gtm_source_create'))
           wp_die('Not authorized');
        $account=sanitize_text_field(wp_unslash($_POST['account_id']??''));
        $confirm=sanitize_text_field(wp_unslash($_POST['confirmation']??''));
        if($confirm!=='CREATE MAD4B SOURCE CONTAINER')
            wp_die('Exact confirmation required');
        $r=self::createSourceContainer($account);
        $message=is_wp_error($r)?$r->get_error_code():'created';
        wp_safe_redirect(add_query_arg('gtm_source_result',$message,
           admin_url('tools.php?page=mad4b-gtm-auth-audit')));
        exit;
    }
    public static function register():void {
        if(!function_exists('wp_register_ability'))return;
        $ability='mad4b/google-gtm-portfolio-consent-audit';
        if(function_exists('wp_has_ability')&&wp_has_ability($ability))return;
        wp_register_ability($ability,[
          'label'=>'MAD4B GTM Consent Audit (Central Operator)',
          'description'=>'Owner-scoped read-only GTM published version and Additional Consent Checks; exact container IDs required.',
          'category'=>'mad4b-read','execute_callback'=>[self::class,'audit'],
          'permission_callback'=>[self::class,'operator'],
          'input_schema'=>[
            'type'=>'object','additionalProperties'=>false,
            'required'=>['account_id','container_id','public_id'],
            'properties'=>[
              'account_id'=>['type'=>'string','pattern'=>'^[0-9]+$'],
              'container_id'=>['type'=>'string','pattern'=>'^[0-9]+$'],
              'public_id'=>['type'=>'string','pattern'=>'^GTM-[A-Z0-9]+$'],
            ],
          ],
          'output_schema'=>['type'=>'object','additionalProperties'=>true],
          'meta'=>['public'=>false,'show_in_rest'=>false,
            'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
            'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>false]]
        ]);
    }
    public static function adminMenu():void {
        if(!self::operator())return;
        add_management_page('Google Tag Manager Consent Audit','GTM Consent Audit',
            'manage_options','mad4b-gtm-auth-audit',[self::class,'adminPage']);
    }
    public static function adminPage():void {
        if(!self::operator())wp_die('Central operator permission required.');
        echo '<div class="wrap"><h1>Authenticated GTM Consent Audit</h1>';
        echo '<p>Uses the Google connection already approved in MAD4B Central. Read-only: no tag changes, no publication, no OAuth token transfer to company sites.</p>';
        $base=admin_url('tools.php?page=mad4b-gtm-auth-audit');
        $discovery=isset($_GET['gtm_discover']) && $_GET['gtm_discover']==='1';
        $requested=isset($_GET['gtm_audit']) && $_GET['gtm_audit']==='1';
        if(($discovery||$requested) &&
           !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'mad4b_gtm_portfolio_audit')){
            echo '<p>Invalid or expired audit request.</p></div>';return;
        }
        if($requested){
            $report=self::audit([
              'account_id'=>sanitize_text_field(wp_unslash($_GET['account_id']??'')),
              'container_id'=>sanitize_text_field(wp_unslash($_GET['container_id']??'')),
              'public_id'=>sanitize_text_field(wp_unslash($_GET['public_id']??''))
            ]);
            if(is_wp_error($report))echo '<p>Read unavailable: '.esc_html($report->get_error_code()).'</p>';
            else echo '<p><strong>Audited:</strong> '.esc_html($report['public_id']).
                  ', published version '.esc_html($report['version_id']).' ('.(int)$report['tag_count'].' tags)</p>';
        }
        echo '<p><a class="button button-primary" href="'.
            esc_url(wp_nonce_url(add_query_arg('gtm_discover','1',$base),'mad4b_gtm_portfolio_audit')).
            '">Discover Google GTM accounts and containers</a></p>';
        if($discovery){
            $acc=MAD4B_Google_Measurement_API::execute(['action'=>'gtm_accounts']);
            if(is_wp_error($acc))echo '<p>Google discovery failed: '.esc_html($acc->get_error_code()).'</p>';
            else{
                echo '<table class="widefat striped"><thead><tr><th>Account</th><th>Container</th><th>Published public ID</th><th>Action</th></tr></thead><tbody>';
                foreach(array_slice((array)($acc['account']??[]),0,30) as $a){
                  $id=(string)($a['accountId']??'');
                  if(!preg_match('/^[0-9]+$/D',$id))continue;
                  $list=MAD4B_Google_Measurement_API::execute(['action'=>'gtm_containers','resource_id'=>'accounts/'.$id]);
                  if(is_wp_error($list))continue;
                  foreach(array_slice((array)($list['container']??[]),0,40) as $c){
                    $cid=(string)($c['containerId']??'');
                    $public=(string)($c['publicId']??'');
                    if(!preg_match('/^[0-9]+$/D',$cid)||!preg_match('/^GTM-[A-Z0-9]+$/D',$public))continue;
                    $link=wp_nonce_url(add_query_arg([
                      'gtm_audit'=>'1','account_id'=>$id,'container_id'=>$cid,'public_id'=>$public
                    ],$base),'mad4b_gtm_portfolio_audit');
                    echo '<tr><td>'.esc_html((string)($a['name']??$id)).'</td><td>'.
                       esc_html((string)($c['name']??$cid)).'</td><td>'.esc_html($public).
                       '</td><td><a href="'.esc_url($link).'">Audit live version</a></td></tr>';
                  }
                }
                echo '</tbody></table>';
            }
        }
        $reports=(array)get_option(self::OPTION,[]);
        // Staged permissions do not disturb the currently active owner grant.
        $pending=(array)get_option('mad4b_google_gtm_publish_grant_request_v1',[]);
        $source=(array)get_option('mad4b_source_gtm_provisioning_v1',[]);
        echo '<h2>GTM Publish grant</h2>';
        if(($pending['requested_mode']??'')==='publish'){
            $grantAvailable=class_exists('MAD4B_SCP_Google_Drive_Context',false);
            $grant=$grantAvailable?MAD4B_SCP_Google_Drive_Context::workspace_grants_status():[];
            $connection=$grantAvailable?MAD4B_SCP_Google_Drive_Context::connection_status():[];
            $selected=($grant['selection']['tag_manager']??'')==='publish';
            $consentDue=$selected && !empty($grant['incremental_consent_required'])
                && (int)($grant['missing_scope_count']??0)>0
                && !empty($connection['connected'])
                && empty($connection['revocation_pending'])
                && empty($connection['token_unreadable']);
            $granted=$selected && (int)($grant['missing_scope_count']??-1)===0
                && !empty($connection['connected']) && empty($connection['revocation_pending']);
            $scopeRecord=(array)get_option('mad4b_scp_google_drive_oauth_token_v1',[]);
            $grantedScopes=preg_split('/\s+/',(string)($scopeRecord['scope']??''),-1,PREG_SPLIT_NO_EMPTY);
            $publishHasScope=in_array(MAD4B_SCP_Google_Drive_Context::TAG_MANAGER_PUBLISH_SCOPE,$grantedScopes,true);
            $versionsNeedConsent=defined('MAD4B_SCP_Google_Drive_Context::TAG_MANAGER_EDIT_VERSIONS_SCOPE')
                && !in_array(MAD4B_SCP_Google_Drive_Context::TAG_MANAGER_EDIT_VERSIONS_SCOPE,$grantedScopes,true);
            $nextScope=$publishHasScope && $versionsNeedConsent
                ? 'Create GTM container versions'
                : 'Publish GTM versions';
            $status=$granted?'Granted by Google':($consentDue?'Google approval required: '.$nextScope:'Review Google Workspace grant');
            echo '<p><strong>Requested:</strong> GTM Publish lifecycle · <strong>Status:</strong> '
                .esc_html($status).'</p>';
            if($consentDue){
                echo '<div class="notice notice-warning inline"><p>'
                    .'One additional Google permission is required to complete the selected GTM Publish lifecycle. Existing scopes remain active.'
                    .'</p></div>';
                // Reuse the existing first-party OAuth POST handler, which validates
                // WordPress admin capability and redirects through the saved Google
                // Dedicated/Managed OAuth flow. No direct OAuth token handling here.
                echo '<form class="mad4b-gtm-publish-consent-form" method="post" action="'
                    .esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_context_google_connect');
                echo '<input type="hidden" name="action" value="mad4b_context_google_connect"/>';
                echo '<input type="hidden" name="access_mode" value="'
                    .esc_attr(($grant['selection']['drive']??'read')==='full'?'read_write':'read_only').'"/>';
                if(MAD4B_SCP_Google_Drive_Context::auth_mode()===MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED)
                    echo '<input type="hidden" name="managed_signin" value="1"/>';
                echo '<button type="submit" class="button button-primary">'
                    .esc_html($publishHasScope && $versionsNeedConsent
                       ? 'Authorize GTM version creation with Google'
                       : 'Authorize GTM Publish with Google').'</button>';
                echo '</form><p class="description">Google opens the approval screen. No disconnect or revoke is needed.</p>';
            }elseif($granted){
                echo '<p>Google Publish scope is present in the verified current token.</p>';
            }
            echo '<p><a class="button" href="'.
                esc_url(admin_url('admin.php?page=mad4b-control-plane-context&tab=google-drive#mad4b-google-grants')).
                '">Review Google Workspace grants</a></p>';
        }
        echo '<h2>Dedicated source/demo GTM container</h2>';
        echo '<p><strong>Planned container:</strong> MAD4B Source Demo | tours.dev.mad4b.com · <strong>Status:</strong> '
            .esc_html((string)($source['state']??'not_requested')).'</p>';
        if(empty($source['actual_public_id'])){
            echo '<p>Create an independent Google Tag Manager account named <code>MAD4B Central</code>. In Google account setup, create the first <strong>Web</strong> container named <code>tours.dev.mad4b.com</code>. MAD4B will verify and enroll it automatically, without creating another container. If the account is empty, MAD4B can create it through the API.</p>';
            echo '<p><a class="button" href="https://tagmanager.google.com/" target="_blank" rel="noopener noreferrer">Open Google Tag Manager</a></p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="mad4b_gtm_source_create"/>';
            wp_nonce_field('mad4b_gtm_source_create');
            echo '<p><label>MAD4B Central Account numeric ID ';
            echo '<input name="account_id" pattern="[0-9]+" required /></label></p>';
            echo '<p><label>Confirmation <input name="confirmation" required placeholder="CREATE MAD4B SOURCE CONTAINER" size="38"/></label></p>';
            submit_button('Verify or create dedicated source container');
            echo '</form>';
        }else{
            echo '<p>Verified public GTM container: <code>'.
                esc_html((string)$source['actual_public_id']).'</code></p>';
        }
        echo '<h2>Last verified published versions</h2>';
        foreach($reports as $key=>$r){
          if(!is_array($r))continue;
          echo '<details><summary>'.esc_html((string)$key).' · '.esc_html((string)($r['checked_at_utc']??'')).
               ' · '.(int)($r['tag_count']??0).' tags</summary>';
          echo '<p>Additional Consent Checks are <strong>not approved</strong> by this read-only audit.</p>';
          echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Tag</th><th>Type</th><th>Paused</th><th>Additional Consent</th><th>Required consent types</th></tr></thead><tbody>';
          foreach((array)($r['tags']??[]) as $t){
            echo '<tr><td>'.esc_html((string)($t['tag_id']??'')).
                 '</td><td>'.esc_html((string)($t['name']??'')).
                 '</td><td>'.esc_html((string)($t['type']??'')).
                 '</td><td>'.(empty($t['paused'])?'No':'Yes').
                 '</td><td>'.esc_html((string)($t['additional_consent_status']??'')).
                 '</td><td>'.esc_html(implode(', ',(array)($t['additional_consent_types']??[]))).
                 '</td></tr>';
          }
          echo '</tbody></table></details>';
        }
        echo '</div>';
    }
    public static function boot():void {
        add_action('wp_abilities_api_init',[self::class,'register'],62);
        add_action('admin_menu',[self::class,'adminMenu'],45);
        add_action('admin_post_mad4b_gtm_source_create',[self::class,'provisionPost']);
    }
}
MAD4B_Google_Measurement_GTM_Consent_Audit::boot();
