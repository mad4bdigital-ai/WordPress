(function(w,d){
  "use strict";
  var gate=w.MAD4BConsent,root=d.getElementById("mad4b-consent-root");
  if(!gate||!root)return;
  var banner=d.getElementById("mad4b-consent-banner");
  var options=d.getElementById("mad4b-consent-categories");
  var a=root.querySelector('[data-mad4b-consent="a"]');
  var m=root.querySelector('[data-mad4b-consent="m"]');
  var save=root.querySelector('[data-mad4b-choice="save"]');
  var customize=root.querySelector('[data-mad4b-choice="customize"]');
  var previousFocus=null;
  function show(){
    var current=gate.choice();
    previousFocus=d.activeElement;
    a.checked=current?current.a:false;
    m.checked=current?current.m:false;
    options.hidden=true;
    save.hidden=true;
    customize.hidden=false;
    banner.hidden=false;
    root.classList.add("mad4b-consent-open");
    // Accept is the primary, initially focused action; no tags run until a click.
    var target=banner.querySelector('[data-mad4b-choice="accept"]');
    if(target)target.focus({preventScroll:true});
  }
  function close(){
    banner.hidden=true;
    root.classList.remove("mad4b-consent-open");
    if(previousFocus&&typeof previousFocus.focus==="function")previousFocus.focus({preventScroll:true});
  }
  function choose(analytics,marketing){gate.choose(analytics,marketing);close();}
  d.addEventListener("click",function(event){
    var link=event.target.closest("[data-mad4b-open-consent]");
    if(!link)return;
    event.preventDefault();
    show();
  });
  d.addEventListener("mad4b:openconsent",show);
  root.addEventListener("click",function(event){
    var btn=event.target.closest("[data-mad4b-choice]");
    if(!btn)return;
    switch(btn.getAttribute("data-mad4b-choice")){
      case "accept": choose(true,true);break;
      case "reject": choose(false,false);break;
      case "customize":
        options.hidden=false;save.hidden=false;customize.hidden=true;a.focus({preventScroll:true});break;
      case "save": choose(a.checked,m.checked);break;
    }
  });
  if(!gate.hasChoice()||(w.MAD4BConsentConfig||{}).preview)show();
})(window,document);
