/** MAD4B two-lane central execution orchestration.
 *
 * An authenticated transport implementation must be injected by the host.
 * This does NOT use exec/eval, open SSH, load credentials, or accept shell
 * commands from general Executor queue payloads. SSH commands are supported
 * as general operator operations with a separately valid SSH authority.
 */
import {createHash} from 'node:crypto';
import {routeDual} from './central_dual_route_policy.mjs';

export async function dispatchGoverned(policy,request,context,adapters) {
  const route=routeDual(policy,request,context);
  if(route.status!=='AUTHORIZED_FOR_DISPATCH')return {...route,executed:false};
  const mutate=request.intent!=='read'&&request.intent!=='diagnostic';
  if(!adapters?.journal||typeof adapters.journal.start!=='function'
    ||typeof adapters.journal.finish!=='function') {
    return {status:'DENIED',reason:'AUDIT_JOURNAL_MISSING',executed:false};
  }
  const transport=adapters?.transports?.[route.lane];
  if(!transport||typeof transport.execute!=='function')return {status:'DENIED',reason:'TRANSPORT_UNREGISTERED',executed:false};
  if(mutate&&typeof adapters?.readback!=='function')return {status:'DENIED',reason:'READBACK_NOT_REGISTERED',executed:false};
  if(route.lane==='vps_ssh') {
    if(typeof request.command!=='string'||!request.command.trim()
      ||createHash('sha256').update(request.command,'utf8').digest('hex')!==request.command_sha256)
      return {status:'DENIED',reason:'COMMAND_BYTES_NOT_BOUND',executed:false};
  } else if(Object.hasOwn(request,'command'))return {status:'DENIED',reason:'EXECUTOR_COMMAND_NOT_ALLOWED',executed:false};
  const identity={lane:route.lane,host_id:route.host_id||null,
    site_uuid:request.site_uuid||null,origin:request.origin||null,
    principal_id:request.principal_id,correlation_id:request.correlation_id,
    authorization_ref:request.authorization_ref,environment:request.environment,
    intent:request.intent,command_sha256:route.lane==='vps_ssh'?request.command_sha256:null};
  let claimed=false;
  try {
    const started=await adapters.journal.start({...identity,status:'CLAIMED',operation_summary:request.command_summary||request.action||''});
    if(!started||started.claimed!==true)return {status:'DENIED',reason:'JOURNAL_CLAIM_FAILED',executed:false};
    claimed=true;
    const dispatchInput=route.lane==='vps_ssh'
      ? {command:request.command,host_id:route.host_id,transport_session_id:request.transport_session_id,
          correlation_id:request.correlation_id,timeout_seconds:Math.min(900,Math.max(1,request.timeout_seconds||120))}
      : {action:request.action,site_uuid:request.site_uuid,origin:request.origin,
          plan_sha256:request.plan_sha256,authorization_ref:request.authorization_ref,
          correlation_id:request.correlation_id};
    const result=await transport.execute(dispatchInput);
    if(!result||result.exit_code!==0||result.timed_out===true) {
      await adapters.journal.finish({...identity,status:'FAILED_EXECUTION',
        exit_code:result?.exit_code??null,timed_out:result?.timed_out===true});
      return {status:'FAILED_EXECUTION',executed:true,verified:false};
    }
    if(mutate){
      const readback=await adapters.readback({request,route,transport_receipt:result});
      if(!readback||readback.verified!==true){
        await adapters.journal.finish({...identity,status:'FAILED_READBACK'});
        return {status:'FAILED_READBACK',executed:true,verified:false,rollback_required:true};
      }
    }
    await adapters.journal.finish({...identity,status:'VERIFIED',exit_code:result.exit_code});
    return {status:'VERIFIED',executed:true,verified:true,lane:route.lane,
      host_id:route.host_id||null,correlation_id:request.correlation_id};
  } catch(e){
    if(claimed){
      try{await adapters.journal.finish({...identity,status:'ERROR_UNCERTAIN',error_code:'TRANSPORT_OR_JOURNAL_ERROR'})}
      catch(_){}
    }
    return {status:'ERROR_UNCERTAIN',executed:claimed,verified:false,error:'TRANSPORT_OR_JOURNAL_ERROR'};
  }
}
