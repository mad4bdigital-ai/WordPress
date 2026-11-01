/** Read-only Central Staging host identity. Wayfa Production is a distinct target.
 * Neither this observer nor its status can enroll a Native Executor or issue grants.
 */
import {spawnSync} from 'node:child_process';

export const CENTRAL_ORIGIN='https://tours.dev.mad4b.com';
const CENTRAL_HOST='srv2030908';
const CENTRAL_DB='etg_commerce_staging';
const UUID=/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
export const centralIdentityExpression=[
 "if(DB_NAME!=='etg_commerce_staging'||home_url()!=='https://tours.dev.mad4b.com'||wp_get_environment_type()!=='staging')WP_CLI::error('CENTRAL_STAGING_SCOPE_MISMATCH');",
 "$s=MAD4B_Native_VPS_Principal_V1::scope();$p=MAD4B_Native_VPS_Principal_V1::existing();",
 "echo wp_json_encode(['contract'=>'mad4b.central-native-host-observation.v2',",
 "'db_name'=>DB_NAME,'home'=>home_url(),'wordpress_environment'=>wp_get_environment_type(),",
 "'site_uuid'=>$s['site_uuid']??null,'canonical_origin'=>$s['canonical_origin']??null,",
 "'environment'=>$s['environment']??null,'host_id'=>$s['host_id']??null,",
 "'scope_kind'=>$s['scope_kind']??null,'workload'=>$s['workload']??null,",
 "'company_target'=>$s['company_target']??null,'owner_enrolled'=>$s['owner_enrolled']??null,",
 "'central_host_identity'=>$s['state']??null,'principal_enrolled'=>is_array($p),",
 "'ledger_verified'=>is_array($p)&&MAD4B_Native_VPS_Principal_V1::ledger($p,$s)]);"
].join('');

const denied=(state)=>({
 state,verified:false,central_host_original_principal_verified:false,
 central_dispatcher_binding_verified:false,company_write_authorized:false,
 native_executor_certified:false,execution_authorized:false,
 production_mutated:false,wayfa_production:{checked:false,mutated:false},
 blockers:[state]
});
export function classifyCoreObservation(x){
 if(!x||x.contract!=='mad4b.central-native-host-observation.v2'||
   x.db_name!==CENTRAL_DB||x.home!==CENTRAL_ORIGIN||
   x.wordpress_environment!=='staging'||x.environment!=='staging'||
   x.canonical_origin!==CENTRAL_ORIGIN||x.host_id!==CENTRAL_HOST||
   x.scope_kind!=='central_native_host'||x.workload!=='tours-central'||
   x.company_target!==false||x.owner_enrolled!==true||
   typeof x.site_uuid!=='string'||!UUID.test(x.site_uuid)||
   typeof x.principal_enrolled!=='boolean'||typeof x.ledger_verified!=='boolean'||
   typeof x.central_host_identity!=='string')
  return denied('CENTRAL_STAGING_SCOPE_UNVERIFIED');
 const host=x.central_host_identity==='CENTRAL_HOST_IDENTITY_VERIFIED'&&
   x.principal_enrolled===true&&x.ledger_verified===true;
 return {
  state:host?'CENTRAL_STAGING_HOST_VERIFIED_EXECUTOR_PENDING':'CENTRAL_STAGING_PRINCIPAL_NOT_VERIFIED',
  verified:true,central_host_original_principal_verified:host,
  // Host identity is NOT Native enrollment, dispatcher binding, or a Wayfa grant.
  central_dispatcher_binding_verified:false,company_write_authorized:false,
  native_executor_certified:false,execution_authorized:false,
  production_mutated:false,wayfa_production:{checked:false,mutated:false},
  central_site_uuid:x.site_uuid,
  blockers:host?['NATIVE_EXECUTOR_INDEPENDENT_CERTIFICATION_PENDING']:
                 ['CENTRAL_ORIGINAL_PRINCIPAL_NOT_VERIFIED']
 };
}
export function observeCoreAuthority(){
 const proc=spawnSync('docker',[
  'exec','-u','33:33','etg-commerce-staging-php',
  'php','-d','memory_limit=512M','/usr/local/bin/wp',
  'eval',centralIdentityExpression,'--path=/var/www/html','--skip-themes'
 ],{encoding:'utf8',timeout:20000,maxBuffer:256*1024,stdio:['ignore','pipe','pipe']});
 if(proc.error||proc.status!==0||!proc.stdout)
  return denied('CENTRAL_STAGING_READBACK_UNAVAILABLE');
 try{
  const lines=proc.stdout.trim().split(/\r?\n/);
  return classifyCoreObservation(JSON.parse(lines.at(-1)));
 }catch{
  return denied('CENTRAL_STAGING_READBACK_INVALID');
 }
}
