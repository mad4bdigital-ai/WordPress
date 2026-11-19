'use strict';
// Pure, reusable proof composition; response-only fields are authoritative.
function composeSample(base,responseHeaders,metrics) {
 const headers=Object.fromEntries(Object.entries(responseHeaders||{}).map(([key,value])=>[key.toLowerCase(),value]));
 const {readyHeader: ignoredReady, nativeHeader: ignoredNative, status: ignoredStatus,
        cssRequestFailures: ignoredFailures, errorCount: ignoredErrors, ...safeMetrics}=metrics||{};
 return {...base, ...safeMetrics,
   readyHeader:headers['x-mad4b-mobile-css-ready']??'none',
   nativeHeader:headers['x-mad4b-mobile-css-native-complete']??'none'};
}
function evaluateSamples(samples) {
 const invalid=samples.some(r=>r.status!==200 || r.readyHeader!=='1' ||
   !r.restCssLoaded || r.error || r.errorCount || r.cssRequestFailures?.length ||
   r.pageWidth>r.viewportWidth ||
   (r.criticalCssMedia!=='(max-width:767px)' && r.criticalCssMedia!==''));
 return {tested:samples.length,invalid};
}
module.exports={composeSample,evaluateSamples};
