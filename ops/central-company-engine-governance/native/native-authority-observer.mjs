/** MAD4B original Central Host observation (read-only).
 * Company Production, including Wayfa, remains an independently authorized
 * target. Its readiness must not gate Central Staging identity or enrollment.
 * Staging's authoritative binding is taken from root-owned host policy,
 * matched to the original WordPress Core scope, not a hardcoded company/UUID.
 */
import {lstatSync,readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';

const POLICY='/opt/mad4b-company-transport/native-operator-v1/host-authority.json';
const UUID=/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
const WORKLOAD=/^[a-z][a-z0-9-]{2,47}$/;
const HTTPS=/^https:\/\/[a-z0-9][a-z0-9.-]+(?::[0-9]+)?$/i;
const denied=(reason)=>Object.freeze({
 contract:'mad4b.central-native-host-observation.v3',
 state:reason,verified:false,central_host_original_principal_verified:false,
 central_dispatcher_binding_verified:false,company_write_authorized:false,
 native_executor_certified:false,execution_authorized:false,
 production_mutated:false,
 readiness:{central_host:'UNVERIFIED',native_executor:'INDEPENDENT_ENROLLMENT_REQUIRED',
  company_targets:'NOT_EVALUATED'},
 next_action:'CHECK_CENTRAL_HOST_SCOPE_AND_ORIGINAL_SIGNED_LEDGER',
 blockers:[reason]
});

export const centralIdentityExpression=[
 "if(!defined('DB_NAME')||!class_exists('MAD4B_Native_VPS_Principal_V1',false))WP_CLI::error('CENTRAL_CORE_NOT_AVAILABLE');",
 "$s=MAD4B_Native_VPS_Principal_V1::scope();$p=MAD4B_Native_VPS_Principal_V1::existing();",
 "echo wp_json_encode(['contract'=>'mad4b.central-original-core-scope.v2',",
 "'db_name'=>DB_NAME,'home'=>untrailingslashit(home_url()),",
 "'runtime_environment'=>wp_get_environment_type(),'scope'=>$s,",
 "'principal_enrolled'=>is_array($p),",
 "'ledger_verified'=>is_array($p)&&MAD4B_Native_VPS_Principal_V1::ledger($p,$s)]);"
].join('');

export function readEnrolledHostPolicy(path=POLICY){
 const st=lstatSync(path);
 if(!st.isFile()||st.isSymbolicLink()||st.uid!==0||
    (st.mode&0o077)!==0||st.size<20||st.size>131072)
   throw Error('ORIGINAL_HOST_POLICY_FILE_NOT_TRUSTED');
 const policy=JSON.parse(readFileSync(path,'utf8'));
 if(policy?.contract!=='mad4b.native-vps-operator.v1'||
    policy.revoked!==false||typeof policy.host_id!=='string'||
    !policy.scopes||typeof policy.scopes!=='object'||Array.isArray(policy.scopes))
   throw Error('ORIGINAL_HOST_POLICY_INVALID');
 return policy;
}

export function classifyCoreObservation(observation,policy){
 const x=observation,s=x?.scope;
 if(x?.contract!=='mad4b.central-original-core-scope.v2'||
    x.runtime_environment!=='staging'||typeof x.db_name!=='string'||
    !x.db_name||!s||s.contract!=='mad4b.central-host-scope.v1'||
    s.scope_kind!=='central_native_host'||s.company_target!==false||
    s.authorizing!==false||s.host_grant_issued!==false||
    s.execution_performed!==false||s.owner_enrolled!==true||
    s.environment!=='staging'||!WORKLOAD.test(s.workload||'')||
    !UUID.test(s.site_uuid||'')||!HTTPS.test(s.canonical_origin||'')||
    x.home!==s.canonical_origin||
    typeof x.principal_enrolled!=='boolean'||typeof x.ledger_verified!=='boolean'||
    typeof s.state!=='string')
   return denied('CENTRAL_STAGING_WORDPRESS_SCOPE_UNVERIFIED');
 const target=policy?.scopes?.[s.workload+':'+s.environment];
 if(policy?.contract!=='mad4b.native-vps-operator.v1'||
    policy.revoked!==false||policy.host_id!==s.host_id||
    !target||typeof target!=='object'||Array.isArray(target)||
    target.site_uuid!==s.site_uuid||
    target.canonical_origin!==s.canonical_origin||
    (target.site_environment!=null&&target.site_environment!==s.environment))
   return denied('CENTRAL_HOST_POLICY_BINDING_MISMATCH');
 const identity=(s.state==='CENTRAL_HOST_IDENTITY_VERIFIED'&&
    x.principal_enrolled===true&&x.ledger_verified===true);
 return Object.freeze({
  contract:'mad4b.central-native-host-observation.v3',
  state:identity?'CENTRAL_STAGING_HOST_VERIFIED_EXECUTOR_PENDING':
                 'CENTRAL_STAGING_ORIGINAL_PRINCIPAL_NOT_VERIFIED',
  verified:true,
  central_host_original_principal_verified:identity,
  central_dispatcher_binding_verified:false,
  company_write_authorized:false,
  native_executor_certified:false,execution_authorized:false,
  production_mutated:false,
  central_scope:{workload:s.workload,environment:s.environment,
   site_uuid:s.site_uuid,canonical_origin:s.canonical_origin,host_id:s.host_id},
  readiness:{central_host:identity?'VERIFIED':'UNVERIFIED',
    native_executor:'INDEPENDENT_ENROLLMENT_REQUIRED',
    company_targets:'NOT_EVALUATED'},
  next_action:identity?'RECONCILE_NATIVE_EXECUTOR_FROM_INDEPENDENT_HOST_PROOF':
                       'VERIFY_ORIGINAL_HOST_PRINCIPAL_LEDGER',
  blockers:identity?['NATIVE_EXECUTOR_INDEPENDENT_CERTIFICATION_PENDING']:
                      ['ORIGINAL_HOST_PRINCIPAL_LEDGER_UNVERIFIED']
 });
}

export function observeCoreAuthority(){
 let policy;
 try{policy=readEnrolledHostPolicy();}
 catch{return denied('CENTRAL_HOST_POLICY_UNAVAILABLE');}
 const proc=spawnSync('docker',[
  'exec','-u','33:33','etg-commerce-staging-php',
  'php','-d','memory_limit=512M','/usr/local/bin/wp',
  'eval',centralIdentityExpression,'--path=/var/www/html','--skip-themes'
 ],{encoding:'utf8',timeout:20000,maxBuffer:256*1024,stdio:['ignore','pipe','pipe']});
 if(proc.error||proc.status!==0||!proc.stdout)
  return denied('CENTRAL_STAGING_READBACK_UNAVAILABLE');
 try{
  const rows=proc.stdout.trim().split(/\r?\n/);
  return classifyCoreObservation(JSON.parse(rows.at(-1)),policy);
 }catch{return denied('CENTRAL_STAGING_READBACK_INVALID');}
}
