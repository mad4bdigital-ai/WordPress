'use strict';
/**
 * Re-observe the earlier snapshot when the later Chromium sample saw LCP
 * during a period that was not measured by its peer. Never permit a pass
 * merely by discarding the late LCP; measure both variants at comparable
 * navigation-relative elapsed time.
 *
 * Standalone pure helper; no timers, page state, credentials or browser APIs.
 */
function needsReobservation(earlier,later){
  const t0=earlier?.observedUntilMs;
  const t1=later?.observedUntilMs;
  if(!Number.isFinite(t0)||!Number.isFinite(t1)||t1-t0<1200)return false;
  return Array.isArray(later.lcpTrace)&&later.lcpTrace.some(entry=>
    Number.isFinite(entry.t)&&entry.t>t0+200&&entry.t<=t1+300);
}
/**
 * Flag ONLY a noisy 320px LCP comparison where the same LCP image arrives
 * later in the candidate by almost exactly the measured regression. This
 * is permission to RETEST, never permission to pass a failing QA pair.
 */
function needsImageNetworkReplay(source,candidate){
 const sourceLcp=source?.lcpTrace?.at(-1);
 const candidateLcp=candidate?.lcpTrace?.at(-1);
 if(!sourceLcp?.resource||sourceLcp.resource!==candidateLcp?.resource)return false;
 const s=source?.lcp_image_resource_timing;
 const c=candidate?.lcp_image_resource_timing;
 if(!s||!c||s.requests!==1||c.requests!==1)return false;
 const lastS=s.last_response_end_ms,lastC=c.last_response_end_ms;
 const lcpS=source?.lcp,lcpC=candidate?.lcp;
 if(![lastS,lastC,lcpS,lcpC].every(x=>Number.isFinite(x)&&x>0))return false;
 const networkLag=lastC-lastS,regression=lcpC-lcpS;
 return networkLag>=650 && regression>400
   && Math.abs(networkLag-regression)<=500;
}
module.exports={needsReobservation,needsImageNetworkReplay};
