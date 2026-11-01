<?php
if (!defined('ABSPATH'))exit;

/** Five independently SHA-pinned, explicit test-source owner adapters. */
final class MAD4B_SCP_AI_Native_Owner_Adapter implements MAD4B_SCP_AI_Domain_Executor {
    const SOURCES='mad4b_scp_ai_native_owner_review_sources_v1';
    private $domain;
    public function __construct($domain) {$this->domain=(string)$domain;}
    public function descriptor() {
        return array('contract'=>MAD4B_SCP_AI_Domain_Executors::ADAPTER_CONTRACT,
            'domain'=>$this->domain,'owner'=>MAD4B_SCP_AI_Native_Owner_Review::owner($this->domain),
            'review_method'=>'ai_original_review_verify_receipt',
            'source_sha256'=>hash_file('sha256',__FILE__));
    }
    public function next_review() {
        if(wp_get_environment_type()!=='local')return new WP_Error('mad4b_ai_native_lab_only','Lab only.');
        $queue=get_option(self::SOURCES,array());
        $candidate=is_array($queue)?($queue[$this->domain]??null):null;
        if(!is_string($candidate) ||!preg_match('/^(?:job:[a-f0-9-]{36}|post:[1-9][0-9]{0,9})$/D',$candidate))
            return null; // No explicit owner-enrolled review target.
        $owner=MAD4B_SCP_AI_Native_Owner_Review::owner($this->domain);
        if(!$owner ||!method_exists($owner,'ai_original_review_evidence'))
            return new WP_Error('mad4b_ai_native_owner_missing','Original owner evidence method missing.');
        $live=call_user_func(array($owner,'ai_original_review_evidence'),array('item_id'=>$candidate));
        if(is_wp_error($live))return $live;
        return array('item_id'=>$candidate,'content_hash'=>$live['content_hash'],
            'owner_revision'=>$live['owner_revision']);
    }
    public function exact_evidence($item) {
        $owner=MAD4B_SCP_AI_Native_Owner_Review::owner($this->domain);
        return $owner?call_user_func(array($owner,'ai_original_review_evidence'),$item):
            new WP_Error('mad4b_ai_native_owner_unavailable','Owner missing.');
    }
    private static function vault() {
        if(!defined('MAD4B_LOCAL_CLIENT_VAULT_PATH'))return new WP_Error(
            'mad4b_ai_native_agent_vault_missing','Local delegated Agent vault missing.');
        $path=dirname((string)MAD4B_LOCAL_CLIENT_VAULT_PATH).'/ai-only-review.json';
        if(!is_file($path)||is_link($path)||(fileperms($path)&0077)!==0||filesize($path)>8192)
            return new WP_Error('mad4b_ai_native_agent_vault_invalid','Secure original Agent vault required.');
        $record=json_decode((string)file_get_contents($path),true);
        if(!is_array($record)||empty($record['login'])||empty($record['password'])
           ||(int)($record['expires_at']??0)<=time()
           ||(string)($record['site_uuid']??'')!==(string)MAD4B_SCP_Site_Profile::site_uuid())
            return new WP_Error('mad4b_ai_native_agent_vault_expired','Exact original Agent identity expired.');
        return $record;
    }
    public function submit_original_review($item,$decision,$note,$source_binding) {
        if(wp_get_environment_type()!=='local')
            return new WP_Error('mad4b_ai_native_environment_denied','Owner review only in Local Lab.');
        $identity=self::vault();
        if(is_wp_error($identity))return $identity;
        if(!class_exists('MAD4B_SCP_AI_Only_Local_Activation')
           ||empty(MAD4B_SCP_AI_Only_Local_Activation::status()['identity_ready']))
            return new WP_Error('mad4b_ai_native_delegate_not_ready','Original delegated Agent is not ready.');
        // The local immutable nginx origin is controlled by the Site Profile's
        // host, not the model, workflow or incoming user request.
        if(!defined('MAD4B_AI_LOCAL_REVIEW_LOOPBACK_URL'))
            return new WP_Error('mad4b_ai_native_loopback_missing','Host-owned Local Lab service origin missing.');
        $origin=(string)MAD4B_AI_LOCAL_REVIEW_LOOPBACK_URL;
        if(!preg_match('#^http://[a-z0-9][a-z0-9-]*(?::[0-9]{2,5})?$#D',$origin))
            return new WP_Error('mad4b_ai_native_loopback_invalid','Host-owned loopback invalid.');
        $canonical=wp_parse_url(home_url());
        $host=(string)($canonical['host']??'');
        if($host==='')return new WP_Error('mad4b_ai_native_site_origin_missing','Original site host missing.');
        $port=(int)($canonical['port']??0);
        $hostHeader=$port>0?$host.':'.$port:$host;
        $url=$origin.'/wp-json/mad4b-local-ai/v1/domain-review';
        $body=array('domain'=>$this->domain,'item'=>$item,'decision'=>$decision,
            'review_note'=>$note,'source_binding'=>$source_binding);
        $response=wp_remote_post($url,array('timeout'=>65,'redirection'=>0,
            'headers'=>array('Authorization'=>'Basic '.base64_encode($identity['login'].':'.$identity['password']),
                'Host'=>$hostHeader,'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($body),'limit_response_size'=>16384));
        if(is_wp_error($response))return $response;
        if(wp_remote_retrieve_response_code($response)!==200)
            return new WP_Error('mad4b_ai_native_owner_endpoint_rejected',
                'Authenticated native owner endpoint rejected review.',
                array('http_status'=>wp_remote_retrieve_response_code($response)));
        $json=json_decode(wp_remote_retrieve_body($response),true);
        $result=$json['receipt']??null;
        if(!is_array($result)||empty($json['owner_receipt_verified']))
            return new WP_Error('mad4b_ai_native_owner_http_receipt_missing','Native owner did not provide a verified receipt.');
        return $result;
    }
    public function verify_original_receipt($item,$receipt,$source_binding) {
        $owner=MAD4B_SCP_AI_Native_Owner_Review::owner($this->domain);
        return $owner &&call_user_func(array($owner,'ai_original_review_verify_receipt'),
            $item,$receipt,$source_binding)===true;
    }
    public static function register_reviewed_adapters() {
        if(wp_get_environment_type()!=='local')return;
        foreach(array('content_job','product_catalog','seo_metadata','localization','design_template') as $domain) {
            $result=MAD4B_SCP_AI_Domain_Executors::register($domain,new self($domain));
            // Missing source pins remain a visible, honest registration blocker.
        }
    }
}
add_action('mad4b/ai/register_certified_domain_executors',
    array('MAD4B_SCP_AI_Native_Owner_Adapter','register_reviewed_adapters'));
