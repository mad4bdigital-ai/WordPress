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
module.exports={needsReobservation};
