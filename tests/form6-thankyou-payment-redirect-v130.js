const fs = require('node:fs');
const path=require('node:path');
const vm = require('node:vm');
const src = fs.readFileSync(process.argv[2] || path.join(__dirname,'../wp-content/mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.js'),'utf8');
const marker='/* Thank-you transition.';
const code=src.slice(src.indexOf(marker));
if(!code.startsWith(marker)) throw new Error('Timer code missing');
function scenario({seconds=15, link='https://wayfatours.com/checkout/order-pay/100/?key=wc_order_test_only',
                   cancelAt=null, hideAt=null, doubleMount=false}={}) {
  const assigned=[];
  const timers=new Map(); let next=0;
  const number={textContent:String(seconds)};
  const cancel={hidden:false,handlers:{},addEventListener(event,callback){this.handlers[event]=callback;}};
  const cancelled={hidden:true};
  const payLink={getAttribute(name){return name==='href'?link:null;}};
  const section={querySelector(sel){return sel==='a.mad4b-request-pay-link[href]'?payLink:null}};
  const confirmation={};
  const panel={
    nodeType:1,matches(sel){return sel==='.mad4b-request-confirmation .mad4b-form6-payment-countdown';},querySelectorAll(){return [];},
    dataset:{mad4bRedirectSeconds:String(seconds)},isConnected:true,
    closest(sel){return sel==='.mad4b-request-confirmation'?confirmation:sel==='.mad4b-request-payment-panel'?section:null},
    querySelector(sel){
      if(sel==='.mad4b-form6-countdown-number')return number;
      if(sel==='button.mad4b-form6-countdown-cancel')return cancel;
      if(sel==='.mad4b-form6-countdown-cancelled')return cancelled;
      return null;
    }
  };
  const selector='.mad4b-request-confirmation .mad4b-form6-payment-countdown';
  const doc={readyState:'complete',visibilityState:'visible',
    body:{nodeType:1,matches(){return false;},querySelectorAll(sel){return sel===selector?[panel]:[];}}};
  let obs;
  const win={
    location:{origin:'https://wayfatours.com',href:'https://wayfatours.com/tour/60629',assign(u){assigned.push(u);}},
    setInterval(fn){const id=++next;timers.set(id,fn);return id;},
    clearInterval(id){timers.delete(id);}
  };
  const env={window:win,document:doc,URL,WeakSet,MutationObserver:class {
    constructor(fn){obs=fn;}
    observe(){}
  },queueMicrotask,console};
  vm.runInNewContext(code,env);
  const startCount=timers.size;
  if(doubleMount) obs([{addedNodes:[panel]}]);
  const initialTimers=timers.size;
  for(let t=1;t<=seconds+2;t++){
    if(hideAt===t)doc.visibilityState='hidden';
    if(hideAt!==null && t===hideAt+1)doc.visibilityState='visible';
    if(cancelAt===t&&cancel.handlers.click)cancel.handlers.click();
    for(const fn of [...timers.values()])fn();
  }
  return {assigned,initialTimers,startCount,display:number.textContent,cancelHidden:cancel.hidden,
    cancelledVisible:!cancelled.hidden};
}
const tests=[];
const standard=scenario();
if(standard.assigned.length!==1||!standard.assigned[0].startsWith('https://wayfatours.com/checkout/order-pay/')||standard.display!=='0')throw new Error('15 second redirect failed');
tests.push({scenario:'countdown_15',redirect:true});
const cancelled=scenario({cancelAt:3});
if(cancelled.assigned.length||!cancelled.cancelledVisible||!cancelled.cancelHidden)throw new Error('Cancel did not stop redirect');
tests.push({scenario:'cancel_redirect',redirect:false});
const foreign=scenario({link:'https://attacker.example/checkout/order-pay/100/?key=secret'});
if(foreign.startCount!==0||foreign.assigned.length)throw new Error('Cross-origin redirect not blocked');
tests.push({scenario:'cross_origin_rejected',redirect:false});
const missingKey=scenario({link:'https://wayfatours.com/checkout/order-pay/100/'});
if(missingKey.startCount!==0||missingKey.assigned.length)throw new Error('Missing bearer key not blocked');
tests.push({scenario:'missing_payment_key_rejected',redirect:false});
const noPayRoute=scenario({link:'https://wayfatours.com/destination/?key=foo'});
if(noPayRoute.startCount!==0||noPayRoute.assigned.length)throw new Error('Non-Woo page redirect not blocked');
tests.push({scenario:'not_checkout_route_rejected',redirect:false});
const short=scenario({seconds:1});
if(short.assigned.length!==1)throw new Error('1 second failed');
tests.push({scenario:'one_second',redirect:true});
const double=scenario({doubleMount:true});
if(double.startCount!==1||double.initialTimers!==1||double.assigned.length!==1)throw new Error('Duplicate timer');
tests.push({scenario:'no_duplicate_timer',redirect:true});
const hidden=scenario({hideAt:2});
if(hidden.assigned.length!==1)throw new Error('Hidden tab resume did not navigate once');
tests.push({scenario:'background_tab_pauses_and_resumes',redirect:true});
console.log('PASS: Form 6 countdown, cancellation, link security and observer isolation ('+tests.length+' cases)');
console.log(JSON.stringify({status:'PASS',cases:tests}));
