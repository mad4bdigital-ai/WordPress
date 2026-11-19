/**
 * MAD4B Pricing Lab Quote Testing navigation.
 * Original diagnostic forms, inputs, AJAX handlers and quote engines remain
 * owned by mad4b-platform. This only changes the selected visible panel.
 */
(() => {
  'use strict';
  const root=document.getElementById('mad4b-pricing-lab-quote-testing');
  if(!root)return;
  const buttons=[...root.querySelectorAll('[role="tab"][data-quote-kind]')];
  const panels=new Map([
    ['tour',root.querySelector('#mad4b-lab-tour-panel')],
    ['stay',root.querySelector('#mad4b-lab-stay-panel')]
  ]);
  const select=(kind,focus=false)=>{
    if(!panels.has(kind))return;
    for(const btn of buttons){
      const selected=btn.dataset.quoteKind===kind;
      btn.setAttribute('aria-selected',String(selected));
      btn.tabIndex=selected?0:-1;
      if(selected&&focus)btn.focus();
    }
    for(const [name,panel] of panels)if(panel)panel.hidden=name!==kind;
    if(history.replaceState && window.location.hash==='#mad4b-pricing-lab-quote-testing'){
      // Intentionally no navigation side effect; the hash is merely a link target.
    }
  };
  buttons.forEach((button,index)=>{
    button.addEventListener('click',()=>select(button.dataset.quoteKind));
    button.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
      event.preventDefault();
      const pos=event.key==='Home'?0:event.key==='End'?buttons.length-1
        :(index+(event.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;
      select(buttons[pos].dataset.quoteKind,true);
    });
  });
  select('tour');
})();
