/* MAD4B JetEngine Page Recipes v1 — native controls only, reversible canary. */
(() => {
  'use strict';
  const cfg = window.MAD4BJetModePage;
  const recipe = cfg?.pageRecipe;
  if (!recipe || recipe.id !== 'pricing-general-v1') return;
  const root = document.querySelector('.jet-engine-options-page-wrap');
  const workspace = root?.querySelector('.mad4b-workspace');
  const content = workspace?.querySelector('.mad4b-ws-content');
  const heading = content?.querySelector('.mad4b-ws-section-heading');
  const banner = workspace?.querySelector('.mad4b-ws-actions');
  const original = root?.querySelector('#settings_top');
  if (!root || !content || !heading || !banner || !original) return;

  const create = (tag, cls, text) => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text !== undefined) el.textContent = text;
    return el;
  };

  // Persisted escape hatch. This control is separate from JetEngine's
  // setting form and doesn't serialize or modify any of its native inputs.
  if (recipe.can_toggle) {
    const toggle = create('button', 'mad4b-recipe-toggle', recipe.enabled
      ? 'Restore previous layout' : 'Enable page recipe');
    toggle.type = 'button';
    toggle.setAttribute('aria-label', recipe.enabled
      ? 'Disable the new page recipe and return to the previous JetEngine layout'
      : 'Enable the General and availability page recipe');
    toggle.addEventListener('click', () => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = recipe.save_url;
      form.hidden = true;
      for (const [name,value] of Object.entries({
        action: recipe.action, recipe_nonce: recipe.nonce,
        mode: recipe.mode === 'off' ? 'canary' : 'off'
      })) {
        const input = document.createElement('input');
        input.type='hidden';
        input.name=name;
        input.value=value;
        form.append(input);
      }
      document.body.append(form);
      // Changing the UI recipe is a separate, explicit admin operation.
      // It does not submit the JetEngine options form.
      if (!form.requestSubmit) form.submit();
      else form.requestSubmit();
    });
    banner.append(toggle);
  }
  if (!recipe.enabled) return;

  const FIELDS = [
    {
      id:'booking', name:'Booking & fulfillment',
      icon:'calendar-check', description:'Guest requests, confirmation and booking behavior',
      keys:['booking_enabled','booking_fulfillment_mode']
    },
    {
      id:'currency', name:'Currency & price engine',
      icon:'wallet', description:'Pricing engine and storefront currency',
      keys:['pricing_engine_enabled','default_currency']
    },
    {
      id:'availability', name:'Availability confirmation',
      icon:'calendar-days', description:'Availability requirements for booking',
      keys:['require_availability']
    },
    {
      id:'safety', name:'Overrides & stop sale',
      icon:'shield-check', description:'Manual price overrides and commercial stop rules',
      keys:['allow_manual_overrides','stop_sale_global']
    }
  ];
  const known = new Map();
  for (const native of original.querySelectorAll('.cx-control[data-control-name]')) {
    const key=native.getAttribute('data-control-name');
    if (key && !known.has(key)) known.set(key,native);
  }
  const groups = FIELDS.map(group=>({
    ...group,
    activeKeys:group.keys.filter(key=>known.has(key))
  })).filter(group=>group.activeKeys.length);
  if (groups.length < 2) return; // no unsafe partial UI with unmapped fields

  const shell=create('section','mad4b-recipe mad4b-recipe--pricing');
  shell.setAttribute('aria-label','General and availability workflow');
  shell.dataset.recipe='pricing-general-v1';

  // Single navigation layer: four actionable category cards. We deliberately
  // do not also render a parallel accordion that repeats the same four labels.
  // The original JetEngine controls remain the only editable field instances.
  const actionsHeading=create('div','mad4b-recipe-subhead');
  actionsHeading.append(
    create('strong','','Configure native settings'),
    create('small','','Choose one area or show all · saves through JetEngine')
  );
  shell.append(actionsHeading);

  const actions=create('div','mad4b-recipe-choices');
  let focused=null;
  const groupButtons=new Map();

  // A recipe only controls visibility/presentation of existing controls.
  // It never moves, clones, names, or submits JetEngine inputs.
  const resetFocus=()=>{
    focused=null;
    for(const control of known.values()) control.classList.remove('mad4b-recipe-sidelined');
    for(const btn of groupButtons.values()){
      btn.classList.remove('is-active');
      btn.setAttribute('aria-pressed','false');
    }
  };
  const focusGroup=(id)=>{
    const group=groups.find(x=>x.id===id);
    if (!group) return;
    if(focused===id) { resetFocus(); return; }
    focused=id;
    const allowed=new Set(group.activeKeys);
    for(const [key,el] of known) {
      el.classList.toggle('mad4b-recipe-sidelined',
        el.dataset.mad4bGroup==='overview' && !allowed.has(key));
    }
    for(const [key,btn] of groupButtons){
      btn.classList.toggle('is-active',key===id);
      btn.setAttribute('aria-pressed',String(key===id));
    }
    const first=known.get(group.activeKeys[0]);
    if(first && typeof first.scrollIntoView==='function')
      first.scrollIntoView({behavior:'smooth',block:'center'});
  };

  for(const group of groups){
    const button=create('button','mad4b-recipe-choice');
    button.type='button';
    button.dataset.recipeGroup=group.id;
    button.setAttribute('aria-pressed','false');
    button.append(
      create('strong','',group.name),
      create('small','',group.description)
    );
    // Visual focus marker, not a second field-count/readiness indicator.
    button.append(create('span','mad4b-recipe-choice-affordance','View fields'));
    button.addEventListener('click',()=>focusGroup(group.id));
    groupButtons.set(group.id,button);
    actions.append(button);
  }
  shell.append(actions);

  // One overview action per setting group. The repeated Settings groups
  // accordion was removed: it only listed labels already present in these
  // cards and created a second navigation layer above the native controls.
  const showAll=create('button','mad4b-recipe-show-all','Show all settings');
  showAll.type='button';
  showAll.addEventListener('click',resetFocus);
  actionsHeading.append(showAll);
  // Keep saved-vs-unsaved guidance in the existing Workspace header; do not
  // repeat a separate "current form state" panel above the actual controls.

  heading.after(shell);
  const nativeRoot=root.querySelector('.mad4b-ws-original');
  const isGeneral=()=>root.classList.contains('mad4b-ws-visual') &&
    root.querySelector('.mad4b-ws-nav-btn.is-active')?.dataset.section==='overview' &&
    !(root.querySelector('.mad4b-ws-search')?.value||'').trim();
  const sync=()=>{
    const show=isGeneral();
    shell.hidden=!show;
    root.classList.toggle('mad4b-recipe-active',show);
    if(!show) resetFocus();
  };
  root.addEventListener('click',event=>{
    if(event.target.closest('.mad4b-ws-nav-btn, .mad4b-ws-secondary'))
      requestAnimationFrame(sync);
  });
  root.querySelector('.mad4b-ws-search')?.addEventListener('input',()=>requestAnimationFrame(sync));
  sync();
})();
