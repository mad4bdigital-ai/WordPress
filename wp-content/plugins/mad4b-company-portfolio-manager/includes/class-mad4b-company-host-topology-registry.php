<?php
/**
 * MAD4B Dynamic Company Host Topology — ORIGINAL Company Manager read owner.
 *
 * PERMANENT BEHAVIOR:
 * - Do not infer webhead count from one Docker container or one VPS.
 * - A signed host binding proves an enrolled HOST, not every app NODE.
 * - The original 305/312/313, Cross-SSH, Native WP-CLI and MCP remain
 *   independent working routes; topology is evidence, never a new grant.
 * - UNKNOWN is a legitimate result; do not create a fake single-node cluster.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Host_Topology_Registry_V1 {
    const CONTRACT='mad4b.company-host-topology-discovery.v1';
    const VERIFIED_CONTRACT='mad4b.company-host-topology-registry.v1';
    const STORE='mad4b_company_host_topology_registry_v1';

    private static function permitted():bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging()
            && current_user_can('manage_options');
    }
    private static function valid_scope(string $company,string $environment):bool {
        return preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)===1
            && in_array($environment,['staging','production'],true);
    }
    /** Original registry is authoritative for company and site; never a caller URL. */
    public static function target(string $company,string $environment):array {
        if(!self::valid_scope($company,$environment)
          || !class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return ['state'=>'INVALID_OR_UNAVAILABLE_ENVIRONMENT'];
        $t=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
        if(!is_array($t) || ($t['company_id']??null)!==$company
          || ($t['environment']??null)!==$environment
          || !wp_is_uuid((string)($t['site_uuid']??''))
          || !is_string($t['canonical_origin']??null))
            return ['state'=>'COMPANY_ENVIRONMENT_SITE_NOT_VERIFIED'];
        return $t;
    }
    /**
     * Original Host Proof validates the enrolled 305 pair at read-time,
     * including current grant/transport/site/host policy and signer. Read the
     * host ID from its already validated bound receipt only AFTER status PASS.
     */
    private static function enrolled_host(string $company,string $environment,array $target):array {
        // PERMANENT SOURCE/DEMO PARITY: the central demo workload is NOT a
        // tenant 305 Host Proof. Its own native enrolled-host ledger is the
        // original owner; do not try to fabricate a tenant grant for itself.
        if(($target['role']??'')==='self_hosted_demo_target'
           && class_exists('MAD4B_Native_VPS_Principal_V1',false)){
            $scope=MAD4B_Native_VPS_Principal_V1::scope();
            $principal=MAD4B_Native_VPS_Principal_V1::existing();
            if(is_array($scope)&&is_array($principal)
               && ($scope['state']??'')==='CENTRAL_HOST_IDENTITY_VERIFIED'
               && ($scope['workload']??null)===$company
               && ($scope['environment']??null)===$environment
               && ($scope['site_uuid']??null)===$target['site_uuid']
               && MAD4B_Native_VPS_Principal_V1::ledger($principal,$scope))
               return ['state'=>'ENROLLED_HOST_VERIFIED',
                 'host_id'=>(string)($scope['host_id']??''),
                 'binding_source'=>'original_central_host_ledger',
                 'runtime_nodes_inventory_verified'=>false,
                 'write_authority_issued'=>false];
        }
        if(!class_exists('MAD4B_Company_Native_Host_Proof_V1',false))
            return ['state'=>'ORIGINAL_HOST_PROOF_NOT_LOADED'];
        $proof=MAD4B_Company_Native_Host_Proof_V1::status($company,$environment);
        if(empty($proof['verified']) || ($proof['company']??null)!==$company
          || ($proof['environment']??null)!==$environment)
            return ['state'=>'ORIGINAL_HOST_PROOF_NOT_VERIFIED'];
        $key=MAD4B_Company_Native_Host_Proof_V1::RECEIPT.$company.'_'.$environment;
        $row=get_option($key,false);
        if(!is_array($row) || ($row['site_uuid']??null)!==$target['site_uuid']
          || ($row['environment']??null)!==$environment
          || ($row['company']??null)!==$company
          || !is_string($row['host_id']??null))
            return ['state'=>'ORIGINAL_HOST_PROOF_SOURCE_DRIFT'];
        return [
            'state'=>'ENROLLED_HOST_VERIFIED','host_id'=>$row['host_id'],
            'binding_source'=>'original_signed_305_host_proof',
            'proof_sha256'=>(string)($row['signed_sha256']??''),
            'runtime_nodes_inventory_verified'=>false,
            'write_authority_issued'=>false
        ];
    }
    public static function discover(string $company,string $environment):array {
        $base=[
            'contract'=>self::CONTRACT,'company_code'=>$company,
            'environment'=>$environment,'topology'=>'UNKNOWN',
            'topology_state'=>'HOST_INVENTORY_READBACK_REQUIRED',
            'topology_revision'=>null,'required_node_ids'=>[],
            'cluster_id'=>null,'deployment_complete'=>false,
            'cluster_aggregation_required'=>null,
            'authority_granted'=>false,'mutation_performed'=>false,
        ];
        $target=self::target($company,$environment);
        if(!isset($target['site_uuid']))return $base+[
            'topology_state'=>'ORIGINAL_COMPANY_ENVIRONMENT_UNAVAILABLE',
            'target_state'=>$target['state']??'UNKNOWN'];
        $base['site_uuid']=$target['site_uuid'];
        $base['canonical_origin']=$target['canonical_origin'];
        $base['origin_sha256']=hash('sha256',$target['canonical_origin']);
        $host=self::enrolled_host($company,$environment,$target);
        $base['enrolled_host']=$host;
        $base['routes']=self::routes($company,$environment,$target,$host);
        // Dynamic provider discovery, not a permanent list of supported
        // Host Runner releases. The currently deployed Host Bridge owns its
        // operation catalog; an absent operation is a real provider gap.
        $cap=class_exists('MAD4B_SCP_Host_Bridge',false)
            ? MAD4B_SCP_Host_Bridge::capabilities() : [];
        $ids=array_column((array)($cap['operations']??[]),'operation_id');
        // PERMANENT DYNAMIC NATIVE CAPABILITY CONTRACT:
        // A readback verifier is not a deployed Host Runner operation.
        // Do not mistake the ability's existence for a signed node receipt.
        // The unimplemented historical operator.host-topology.read must not
        // appear as an alternative authorized provider.
        $base['host_inventory_receipt_verifier_ready'] =
            class_exists('MAD4B_SCP_Host_Bridge',false)
            && method_exists('MAD4B_SCP_Host_Bridge','node_inventory_verification');
        $base['host_inventory_native_provider_available']=
            in_array('host.node-inventory.read',$ids,true);
        $base['host_inventory_provider_state']=$base['host_inventory_native_provider_available']
            ? 'ORIGINAL_NATIVE_NODE_READ_PROVIDER_REGISTERED'
            : ($base['host_inventory_receipt_verifier_ready']
                ? 'SIGNED_RECEIPT_VERIFIER_READY_RUNNER_NOT_ENROLLED'
                : 'ORIGINAL_HOST_RUNNER_INVENTORY_PROVIDER_MISSING');
        if($host['state']==='ENROLLED_HOST_VERIFIED')
            $base['topology_state']='HOST_VERIFIED_NODE_INVENTORY_REQUIRED';
        // A company/host link never certifies a single webhead, nor 313
        // completion. Keep workflow routes alive independently.
        return $base;
    }
    /** Route discovery is advisory; every execution still calls its OWNER. */
    public static function routes(string $company,string $environment,array $target,array $host):array {
        $original_host=($host['state']??'')==='ENROLLED_HOST_VERIFIED';
        $enrolled=class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
            ? MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($company,$environment) : [];
        $pair=($enrolled['state']??'')==='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED';
        return [
            'mcp'=>['state'=>'EXISTING_CONNECTOR_SCOPE_INDEPENDENT','readiness_inferred'=>false],
            'wpcli'=>['state'=>$original_host&&$pair?'ORIGINAL_PAIR_BOUND_VERIFY_OPERATION'
                :'ORIGINAL_ENROLLMENT_PENDING','original_owner'=>'325+327','authorizing'=>false],
            'cross_ssh'=>['state'=>$original_host?'ENROLLED_HOST_VERIFY_EXACT_312'
                :'EXACT_TRANSPORT_READBACK_PENDING','original_owner'=>'311+312','authorizing'=>false],
            'native_executor'=>['state'=>'ORIGINAL_PROVIDER_312_READBACK_REQUIRED',
                'original_owner'=>'313+native_provider','authorizing'=>false],
            'broker_305'=>['state'=>$pair?'ORIGINAL_PAIR_ENROLLED':'PENDING',
                'authorizing'=>false],
            'broker_312'=>['state'=>'OPERATION_SPECIFIC_EXACT_GRANT_REQUIRED','authorizing'=>false],
            'broker_313'=>['state'=>'OPERATION_SPECIFIC_SIGNED_RECEIPT_REQUIRED',
                'original_owner'=>'314|345','authorizing'=>false],
            'host_operations'=>['state'=>'HOST_BRIDGE_ORIGINAL_RECEIPT_REQUIRED',
                'original_owner'=>'MAD4B_SCP_Host_Bridge','authorizing'=>false]
        ];
    }
    /**
     * Live existing-site discovery (unbounded by an arbitrary company count).
     * Registry entries remain UNKNOWN until a native verified node inventory
     * is independently available. This is not a host register mutation.
     */
    public static function fleet():array {
        if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))return [];
        $rows=MAD4B_Company_Environment_Registry_V2::discover();
        $out=[];
        foreach($rows as $code=>$row){
            if(!is_array($row)||!is_string($code))continue;
            foreach((array)($row['environments']??[]) as $env=>$target){
                if(!self::valid_scope($code,(string)$env))continue;
                $out[$code.':'.$env]=self::readback($code,$env);
            }
        }
        return $out;
    }
    /**
     * ORIGINAL SIGNED INVENTORY ONLY:
     * Host Bridge verifies the signed worker receipt and its site-bound seal.
     * A caller-supplied manifest, WordPress option, 305 claim or plain SHA
     * NEVER registers nodes. No new signer, approvals or transport provider.
     */
    private static function verified_original_inventory(string $job_id,array $target) {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$job_id)
           || !class_exists('MAD4B_SCP_Host_Bridge',false))
            return new WP_Error('mad4b_topology_original_host_bridge_unavailable');
        $source=MAD4B_SCP_Host_Bridge::receipt(['job_id'=>$job_id]);
        if(is_wp_error($source))return $source;
        $check=(array)($source['operator_verification']??[]);
        $body=(array)($source['receipt']['body']??[]);
        if(($source['contract']??'')!=='mad4b.host-operation-receipt-read.v1'
           || ($check['contract']??'')!=='mad4b.operator-receipt-verified.v1'
           || ($check['verified']??false)!==true
           || ($check['job_id']??'')!==$job_id
           || ($check['operation_id']??'')!=='operator.host-topology.read'
           || ($check['verdict']??'')!=='PASS'
           || ($check['exit_code']??1)!==0
           || ($body['job_id']??'')!==$job_id
           || ($body['operation_id']??'')!=='operator.host-topology.read'
           || ($body['verdict']??'')!=='PASS')
            return new WP_Error('mad4b_topology_original_signed_inventory_required');
        $manifest=$body['topology_inventory']??null;
        if(!is_array($manifest)
           || ($manifest['contract']??'')!=='mad4b.host-inventory-observation.v1'
           || ($manifest['company_code']??'')!==($target['company_id']??'')
           || ($manifest['environment']??'')!==($target['environment']??'')
           || ($manifest['site_uuid']??'')!==($target['site_uuid']??'')
           || ($manifest['canonical_origin']??'')!==($target['canonical_origin']??''))
            return new WP_Error('mad4b_topology_original_inventory_scope_mismatch');
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($manifest['target_build']??''))
           ||!preg_match('/^[a-f0-9]{64}$/D',(string)($manifest['mu_sha256']??'')))
            return new WP_Error('mad4b_topology_original_inventory_runtime_fingerprint_missing');
        $nodes=$manifest['required_nodes']??null;
        if(!is_array($nodes)||!array_is_list($nodes)
            || count($nodes)<1||count($nodes)>64)
            return new WP_Error('mad4b_topology_original_inventory_nodes_invalid');
        $ids=[];$hosts=[];
        foreach($nodes as $node){
            if(!is_array($node))return new WP_Error('mad4b_topology_original_inventory_node_invalid');
            $id=(string)($node['node_id']??'');
            $host=(string)($node['host_registry_id']??'');
            $role=(string)($node['runtime_role']??'');
            $fingerprint=(string)($node['target_fingerprint']??'');
            // PERMANENT RUNNER-PROFILE CONTRACT: a signed inventory may
            // carry its exact enrolled runner profile per WordPress node.
            // This is a read-only identity binding, not a Host grant. Do not
            // guess a profile from company, domain, path or container name.
            $runner=(string)($node['runner_profile_id']??'');
            if($runner!=='' && !preg_match('/^[a-z][a-z0-9-]{2,63}$/D',$runner))
                return new WP_Error('mad4b_topology_original_inventory_runner_profile_invalid');
            if(!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))
                return new WP_Error('mad4b_topology_original_inventory_node_fingerprint_invalid');
            if(!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$id)
              ||!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$host)
              ||!in_array($role,['wordpress_mcp','wordpress_webhead','native_executor','cache_worker'],true)
              ||isset($ids[$id]))
                return new WP_Error('mad4b_topology_original_inventory_node_identity_invalid');
            $ids[$id]=true;$hosts[$host]=true;
        }
        $topology=count($ids)===1?'single_node':'multi_node';
        $cluster=(string)($manifest['cluster_id']??'');
        if(($manifest['topology']??'')!==$topology
           ||($topology==='multi_node'&&!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$cluster))
           ||($topology==='single_node'&&$cluster!==''))
            return new WP_Error('mad4b_topology_original_inventory_cluster_invalid');
        return [
            'contract'=>self::VERIFIED_CONTRACT,'state'=>'VERIFIED',
            'company_code'=>$target['company_id'],
            'environment'=>$target['environment'],
            'site_uuid'=>$target['site_uuid'],
            'canonical_origin'=>$target['canonical_origin'],
            'origin_sha256'=>hash('sha256',$target['canonical_origin']),
            'target_build'=>(string)$manifest['target_build'],
            'expected_mu_sha256'=>(string)$manifest['mu_sha256'],
            'topology'=>$topology,'cluster_id'=>$topology==='multi_node'?$cluster:'',
            'required_node_ids'=>array_keys($ids),
            'required_nodes'=>$nodes,
            'registration_readback_verified'=>true,
            'original_receipt_job_id'=>$job_id,
            'original_receipt_sha256'=>hash('sha256',wp_json_encode($body,JSON_UNESCAPED_SLASHES)),
            'original_receipt_contract'=>$check['contract'],
            'registration_provenance'=>'original_host_bridge_signed_worker_receipt',
            'node_inventory_not_a_write_grant'=>true
        ];
    }
    /**
     * Current readback re-verifies the independent ORIGINAL receipt on each
     * new request. Thus a database flag cannot self-certify a Cluster.
     */
    public static function readback(string $company,string $environment):array {
        $target=self::target($company,$environment);
        $base=self::discover($company,$environment);
        if(!isset($target['site_uuid']))return $base;
        $rows=get_option(self::STORE,[]);
        $key=$company.':'.$environment;
        $row=is_array($rows)?($rows[$key]??null):null;
        if(!is_array($row)||($row['state']??'')!=='VERIFIED')return $base;
        $verified=self::verified_original_inventory(
            (string)($row['original_receipt_job_id']??''),$target);
        if(is_wp_error($verified))return $base+['registry_error'=>$verified->get_error_code()];
        $must=['company_code','environment','site_uuid','canonical_origin',
            'origin_sha256','target_build','expected_mu_sha256',
            'topology','cluster_id','required_node_ids',
            'original_receipt_job_id','original_receipt_sha256'];
        foreach($must as $field) {
            if(($verified[$field]??null)!==($row[$field]??null))
                return $base+['registry_error'=>'TOPOLOGY_DRIFT_'.$field];
        }
        if(!is_int($row['revision']??null)||$row['revision']<1)
            return $base+['registry_error'=>'TOPOLOGY_REVISION_INVALID'];
        $verified['revision']=$row['revision'];
        $verified['target_build']=$row['target_build']??null;
        $verified['registration_authority_readback']='ORIGINAL_SIGNED_RECEIPT_VERIFIED';
        $verified['mutation_performed']=false;
        return $verified;
    }
    /**
     * Explicit owner-controlled CAS reconcile. Never creates a company or a
     * grant. The operator supplies only a source job ID, not a topology.
     */
    public static function reconcile(string $company,string $environment,string $job_id,int $expected_revision=0) {
        if(!self::permitted())return new WP_Error('mad4b_topology_original_owner_required');
        $target=self::target($company,$environment);
        if(!isset($target['site_uuid']))return new WP_Error('mad4b_topology_target_missing');
        $candidate=self::verified_original_inventory($job_id,$target);
        if(is_wp_error($candidate))return $candidate;
        if(!class_exists('MAD4B_SCP_Endpoint_Diagnostic',false))
            return new WP_Error('mad4b_topology_build_source_missing');
        // The target build comes from the signed Host inventory. The
        // Central Controller build MUST NEVER substitute for a remote node.
        if(!preg_match('/^[a-f0-9]{64}$/D',$candidate['target_build']))
            return new WP_Error('mad4b_topology_signed_target_build_invalid');
        global $wpdb;
        $lock='mad4b:topology:'.substr(hash('sha256',$company.'|'.$environment),0,32);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return new WP_Error('mad4b_topology_reconcile_busy');
        try{
            $rows=get_option(self::STORE,[]);
            if(!is_array($rows))return new WP_Error('mad4b_topology_store_invalid');
            $key=$company.':'.$environment;
            $previous=$rows[$key]??null;
            $rev=is_array($previous)?(int)($previous['revision']??0):0;
            if($rev!==$expected_revision)return new WP_Error('mad4b_topology_revision_conflict');
            $candidate['revision']=$rev+1;
            $candidate['verified_at_utc']=gmdate('c');
            $before=$rows;
            $rows[$key]=$candidate;
            if(!update_option(self::STORE,$rows,false))
                return new WP_Error('mad4b_topology_store_write_failed');
            $read=self::readback($company,$environment);
            if(($read['state']??'')!=='VERIFIED'
               ||($read['revision']??0)!==$candidate['revision']){
                // Original CAS lock still held: preserve previous usable
                // record rather than leaving an unverified topology applied.
                update_option(self::STORE,$before,false);
                return new WP_Error('mad4b_topology_independent_readback_failed');
            }
            do_action('mad4b_company_host_topology_reconciled',$company,$environment,$read);
            return $read;
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    /**
     * Local Site Profile selects its company/environment from the ORIGINAL
     * Environment Registry. The local MCP reader is never allowed to treat
     * arbitrary get_option() flags as a verified Host Inventory.
     */
    public static function verified_local_registry(string $site_uuid,string $environment,string $origin):array {
        if(!wp_is_uuid($site_uuid) || !in_array($environment,['staging','production'],true))
            return [];
        if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))return [];
        // Avoid expensive company/Host Bridge scans on unregistered sites.
        $store=get_option(self::STORE,[]);
        if(!is_array($store)||!$store)return [];
        $seen=0;$found=[];
        foreach(MAD4B_Company_Environment_Registry_V2::discover() as $company=>$item){
            if(!is_string($company)||!is_array($item))continue;
            $candidate=$item['environments'][$environment]??null;
            if(!is_array($candidate)
              ||($candidate['site_uuid']??'')!==$site_uuid
              ||($candidate['canonical_origin']??'')!==$origin)continue;
            ++$seen;
            $value=self::readback($company,$environment);
            if(($value['state']??'')==='VERIFIED')$found=$value;
        }
        // Two companies cannot silently share the same Site Profile UUID.
        return $seen===1?$found:[];
    }

    public static function rest():void {
        if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
          || !MAD4B_Company_Portfolio_Manager_V1::staging())return;
        register_rest_route('mad4b/v1',
          '/company-management/companies/(?P<company>[a-z][a-z0-9-]{2,47})/environments/(?P<environment>staging|production)/host-topology',[
            [
              'methods'=>'GET',
            'permission_callback'=>[__CLASS__,'permission'],
            'callback'=>static function($request){
                $v=self::readback((string)$request['company'],(string)$request['environment']);
                $r=rest_ensure_response($v);
                $r->header('Cache-Control','private, no-store');
                return $r;
            }
            ],
            [
              'methods'=>'POST',
              'permission_callback'=>['MAD4B_Company_Portfolio_Manager_V1','rest_write_permission'],
              'callback'=>static function($request){
                  $body=$request->get_json_params();
                  if(!is_array($body)||array_keys($body)!==['job_id','expected_revision'])
                      return new WP_Error('mad4b_topology_expected_job_and_revision_required','Exact original Host Runner job and CAS revision required',['status'=>422]);
                  if(!is_string($body['job_id']) || !is_int($body['expected_revision']))
                      return new WP_Error('mad4b_topology_payload_invalid','Invalid signed job reference',['status'=>422]);
                  $value=self::reconcile((string)$request['company'],
                      (string)$request['environment'],$body['job_id'],$body['expected_revision']);
                  $r=rest_ensure_response($value);
                  if($r instanceof WP_REST_Response)$r->header('Cache-Control','private, no-store');
                  return $r;
              }
            ]]);
    }
    public static function permission():bool{return self::permitted();}
    public static function cli($args,$assoc):void {
        if(!defined('WP_CLI')||!WP_CLI || !self::permitted())
            WP_CLI::error('ORIGINAL_CENTRAL_STAGING_OWNER_REQUIRED');
        $act=(string)($args[0]??'status');
        $company=(string)($assoc['company']??'');
        $environment=(string)($assoc['environment']??'');
        if(!self::valid_scope($company,$environment))
            WP_CLI::error('EXACT_COMPANY_ENVIRONMENT_REQUIRED');
        if($act==='status')$value=self::readback($company,$environment);
        elseif($act==='reconcile')
            $value=self::reconcile($company,$environment,(string)($assoc['job']??''),
                (int)($assoc['expected-revision']??0));
        else WP_CLI::error('UNKNOWN_TOPOLOGY_ACTION');
        if(is_wp_error($value))WP_CLI::error($value->get_error_code());
        WP_CLI::line(wp_json_encode($value,JSON_UNESCAPED_SLASHES));
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'rest'],90);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b company-host-topology',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Host_Topology_Registry_V1::boot();
