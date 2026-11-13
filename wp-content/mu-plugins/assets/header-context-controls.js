/* Elementor header presentation. Validation and persistence remain server-owned. */
(() => {
  'use strict';
  const roots = () => [...document.querySelectorAll('.elementor-location-header .header-context-controls')];
  const apiBase = new URL('/wp-json/visitor-preferences/v1/', window.location.origin);
  let pending = false;
  let currentNonces = null;
  function endpoint(path) { return new URL(path, apiBase).href; }
  function update(root) {
    const country = root.querySelector('select[name="market_country"]');
    const summary = root.querySelector('[data-header-context-label]');
    if(summary){
      const code=country?.value || '';
      const mobile=root.classList.contains('header-context-mobile-inline');
      summary.textContent=mobile && code
        ? (country.selectedOptions[0]?.textContent || code)
        : (code || summary.dataset.fallback || 'Market');
    }
    const toggle = root.querySelector('[data-header-context-toggle]');
    const visible = (summary?.textContent || '').trim();
    if (toggle && visible) toggle.setAttribute('aria-label', visible + ' — ' + mobileCopy[localeKey()].prefs);
  }
  function close(root) {
    root.classList.remove('is-open');
    root.querySelector('[data-header-context-toggle]')?.setAttribute('aria-expanded','false');
  }
  function status(root, message) {
    const panel = root.querySelector('.header-context-panel');
    if (!panel) return;
    let messageNode = panel.querySelector('[data-header-context-status]');
    if (!messageNode) {
      messageNode = document.createElement('span');
      messageNode.dataset.headerContextStatus = '1';
      messageNode.setAttribute('role', 'status');
      messageNode.setAttribute('aria-live', 'polite');
      panel.appendChild(messageNode);
    }
    const labels = root.classList.contains('header-context-mobile-inline') ? mobileCopy[localeKey()] : null;
    messageNode.textContent = labels ? ({'Saving…':labels.saving,'Saved':labels.saved,
      'Could not save. Try again.':labels.fail}[message] ?? message ?? '') : (message || '');
  }
  async function nonces(refresh = false) {
    if (!refresh && currentNonces) return currentNonces;
    const response = await fetch(endpoint('state'), {
      cache: 'no-store', credentials: 'same-origin', headers: {Accept: 'application/json'}
    });
    if (!response.ok) throw new Error('nonce state failed');
    const payload = await response.json();
    if (payload.contract !== 'visitor-preferences.state.v1' ||
        !payload.nonces?.country || !payload.nonces?.passport) throw new Error('invalid nonce state');
    currentNonces = payload.nonces;
    return currentNonces;
  }
  function sync(data) {
    roots().forEach(root => {
      for (const [field, value] of [['market_country',data.country],['nationality',data.passport]]) {
        const input = root.querySelector('select[name="' + field + '"]');
        if (input) {
          input.value = value || '';
          input.dataset.saved = input.value;
          if (field === 'nationality') {
            input.closest('form')?.dispatchEvent(new CustomEvent('passport-search:sync'));
            input.closest('form')?.dispatchEvent(new CustomEvent('passport-preference:mode',{
              detail: {mode: data.mode || 'auto'}
            }));
          }
        }
      }
      update(root);
      const flag = root.querySelector('img.mad4b-country-flag');
      if (flag) {
        flag.hidden = !data.country_flag;
        if (data.country_flag) { flag.src = data.country_flag; flag.alt = data.country || ''; }
      }
      const passportFlag = root.querySelector('img.travel-booking-country-flag');
      if (passportFlag) {
        passportFlag.hidden = !data.passport_flag;
        if (data.passport_flag) { passportFlag.src = data.passport_flag; passportFlag.alt = data.passport || ''; }
      }
    });
  }
  async function save(root, action, value, changedSelect) {
    if (pending) return;
    pending = true;
    root.setAttribute('aria-busy','true');
    const before = roots().map(r => ({
      root:r, country:r.querySelector('select[name="market_country"]')?.dataset.saved || '',
      passport:r.querySelector('select[name="nationality"]')?.dataset.saved || ''
    }));
    status(root,'Saving…');
    try {
      async function send(token) {
        const response = await fetch(endpoint('apply'), {
          method:'POST', credentials:'same-origin', cache:'no-store',
          headers:{'Content-Type':'application/json',Accept:'application/json'},
          body:JSON.stringify({action,country:value || '',nonce:token})
        });
        return {response,payload:await response.json()};
      }
      const nonceKey = action === 'country' ? 'country' : 'passport';
      let available = await nonces();
      let saved = await send(available[nonceKey]);
      if (saved.response.status === 403 && saved.payload.code === 'invalid_nonce') {
        available = await nonces(true);
        saved = await send(available[nonceKey]);
      }
      if (!saved.response.ok || saved.payload.contract !== 'visitor-preferences.v1') {
        throw new Error('save rejected');
      }
      sync(saved.payload);
      document.dispatchEvent(new CustomEvent('visitor-preferences:saved',{detail:saved.payload}));
      if (action === 'country') {
        window.dispatchEvent(new CustomEvent('visitor-market:changed',{detail:saved.payload}));
        document.querySelectorAll('[data-market-price],[data-market-quote]').forEach(node => {
          node.dispatchEvent(new CustomEvent('visitor-market:revalidate',{bubbles:true,detail:saved.payload}));
        });
      }
      status(root,'Saved');
    } catch (error) {
      before.forEach(record => {
        const country=record.root.querySelector('select[name="market_country"]');
        const passport=record.root.querySelector('select[name="nationality"]');
        if (country) country.value = record.country;
        if (passport) {
          passport.value = record.passport;
          passport.closest('form')?.dispatchEvent(new CustomEvent('passport-search:sync'));
        }
        update(record.root);
      });
      status(root,'Could not save. Try again.');
    } finally {
      pending = false;
      root.removeAttribute('aria-busy');
    }
  }
  // Mobile-only layout and WPML-aware presentation; never replace the forms.
  const mobileCopy = {
    en:{market:'Country',prefs:'Country and passport preferences',country:'Country',choose:'Select country',passport:'Passport',search:'Select passport country',currency:'Currency',saved:'Saved',saving:'Saving…',fail:'Could not save. Try again.'},
    ar:{market:'الدولة',prefs:'تفضيلات الدولة وجنسية جواز السفر',country:'الدولة',choose:'اختر الدولة',passport:'جنسية جواز السفر',search:'اختر جنسية جواز السفر',currency:'العملة',saved:'تم الحفظ',saving:'جارٍ الحفظ…',fail:'تعذّر الحفظ. حاول مجددًا.'},
    de:{market:'Land',prefs:'Land und Passnationalität',country:'Land',choose:'Land auswählen',passport:'Passnationalität',search:'Passland auswählen',currency:'Währung',saved:'Gespeichert',saving:'Wird gespeichert…',fail:'Speichern fehlgeschlagen.'},
    it:{market:'Paese',prefs:'Paese e nazionalità del passaporto',country:'Paese',choose:'Seleziona paese',passport:'Nazionalità del passaporto',search:'Seleziona paese del passaporto',currency:'Valuta',saved:'Salvato',saving:'Salvataggio…',fail:'Impossibile salvare.'},
    es:{market:'País',prefs:'País y nacionalidad del pasaporte',country:'País',choose:'Seleccionar país',passport:'Nacionalidad del pasaporte',search:'Seleccionar país del pasaporte',currency:'Moneda',saved:'Guardado',saving:'Guardando…',fail:'No se pudo guardar.'},
    fr:{market:'Pays',prefs:'Pays et nationalité du passeport',country:'Pays',choose:'Choisir un pays',passport:'Nationalité du passeport',search:'Choisir pays du passeport',currency:'Devise',saved:'Enregistré',saving:'Enregistrement…',fail:'Enregistrement impossible.'}
  };
  function localeKey(){const key=(document.documentElement.lang || 'en').slice(0,2).toLowerCase();return mobileCopy[key] ? key : 'en';}
  function mobileRoot(){return document.querySelector('.elementor-location-header .header-context-controls-mobile');}
  function localizeMobile(root){
    if(!root)return;
    const code=localeKey(),t=mobileCopy[code];
    const button=root.querySelector('[data-header-context-toggle]');
    const label=root.querySelector('[data-header-context-label]');
    const country=root.querySelector('select[name="market_country"]');
    const passport=root.querySelector('select[name="nationality"]');
    if(button)button.setAttribute('aria-label', ((label?.textContent || '').trim() || t.market) + ' — ' + t.prefs);
    if(label)label.dataset.fallback=t.market;
    const countryLabel=root.querySelector('.mad4b-switcher-label');
    if(countryLabel)countryLabel.textContent=t.country;
    const passportLabel=root.querySelector('.travel-booking-nationality-label');
    if(passportLabel)passportLabel.textContent=t.passport;
    const passportInput=root.querySelector('.travel-booking-passport-search__input');
    if(passportInput){passportInput.placeholder=t.search;passportInput.setAttribute('aria-label',t.passport);}
    if(country?.options[0])country.options[0].text=t.choose;
    if(passport?.options[0])passport.options[0].text=t.search;
    try{
      const display=new Intl.DisplayNames([code],{type:'region'});
      [country,passport].forEach(select=>{
        if(!select)return;
        [...select.options].forEach(o=>{
          if(!/^[A-Z]{2}$/.test(o.value))return;
          const translated=display.of(o.value);
          if(translated&&translated!==o.value)o.textContent=translated;
        });
      });
      const results=root.querySelector('.travel-booking-passport-search__results');
      if(results && !results.dataset.localizedObserver){
        results.dataset.localizedObserver='1';
        const refresh=()=>results.querySelectorAll('[role="option"][data-code]').forEach(item=>{
          const translated=display.of(item.dataset.code);
          if(translated&&translated!==item.dataset.code&&item.textContent!==translated)item.textContent=translated;
        });
        new MutationObserver(refresh).observe(results,{childList:true});
      }
    }catch(e){/* Browser lacks Intl region names; keep plugin's native labels. */}
    const currency=document.querySelector('.elementor-location-header .elementor-element-9779089 .pricing-currency-select');
    if(currency)currency.setAttribute('aria-label',t.currency);
    country?.setAttribute('aria-label',t.country);
    passport?.closest('form')?.dispatchEvent(new CustomEvent('passport-search:sync'));
    if(label) {
      const value=country?.value;
      label.textContent=value ? (country.selectedOptions[0]?.textContent||value) : t.market;
    }
  }
  function positionMobilePanel(root){
    if(!root?.classList.contains('header-context-mobile-inline'))return;
    const button=root.querySelector('[data-header-context-toggle]');
    if(!button)return;
    const rect=button.getBoundingClientRect();
    const inset=10, gap=4;
    const width=Math.min(340,Math.max(0,window.innerWidth-2*inset));
    const isRtl=document.documentElement.dir==='rtl';
    const preferredX=isRtl?rect.left:rect.right-width;
    const left=Math.max(inset,Math.min(window.innerWidth-inset-width,preferredX));
    const top=Math.max(0,rect.bottom+gap);
    root.style.setProperty('--header-context-panel-left',left+'px');
    root.style.setProperty('--header-context-panel-top',top+'px');
    root.style.setProperty('--header-context-panel-max-height',Math.max(120,window.innerHeight-top-10)+'px');
  }
  // The native Elementor template now owns the DOM position for every
  // breakpoint. JS only translates labels, binds controls and positions panel.
  function arrangeMobile(){
    const root=mobileRoot();
    if(!root)return;
    const mobile=window.matchMedia('(max-width:767px)').matches;
    root.classList.toggle('header-context-mobile-inline',mobile);
    if(mobile){
      const panel=root.querySelector('.header-context-panel');
      if(panel&&!panel.id)panel.id='header-mobile-preferences-panel';
      root.querySelector('[data-header-context-toggle]')?.setAttribute('aria-controls',panel?.id||'');
      localizeMobile(root);
      window.setTimeout(()=>localizeMobile(root),250);
    }else close(root);
  }
  let wasMobile=null;
  function scheduleMobileLayout(){
    const mobile=window.matchMedia('(max-width:767px)').matches;
    if(wasMobile===mobile)return;
    wasMobile=mobile;
    arrangeMobile();
  }

  function init() {
    roots().forEach(root => {
      if (root.dataset.contextInitialized === '1') return;
      const button = root.querySelector('[data-header-context-toggle]');
      const panel = root.querySelector('.header-context-panel');
      if (!button || !panel) return;
      root.dataset.contextInitialized = '1';
      button.setAttribute('aria-controls',panel.id);
      button.addEventListener('click',() => {
        const open=!root.classList.contains('is-open');
        roots().filter(r => r.classList.contains('is-open')).forEach(close);
        if(open) {
          positionMobilePanel(root);
          root.classList.add('is-open');
          button.setAttribute('aria-expanded','true');
        }
      });
      root.addEventListener('change',event => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement) ||
            !['market_country','nationality'].includes(select.name)) return;
        update(root);
        if (!select.value) return;
        if (select.value === select.dataset.saved) return;
        save(root,select.name === 'market_country'?'country':'manual_passport',select.value,select);
      });
      root.addEventListener('passport-preference:reset',event => {
        if (event.target.closest('.header-context-controls') === root) {
          event.stopPropagation();
          save(root,'auto_passport','',null);
        }
      });
      update(root);
    });
    scheduleMobileLayout();
  }
  function repositionOpenPanels(){
    roots().filter(root=>root.classList.contains('is-open')).forEach(positionMobilePanel);
  }
  window.addEventListener('resize',()=>{scheduleMobileLayout();repositionOpenPanels();},{passive:true});
  window.addEventListener('scroll',repositionOpenPanels,{passive:true});
  document.addEventListener('click',event => {
    if (event.target.closest('.elementor-location-header .header-context-controls')) return;
    roots().filter(r => r.classList.contains('is-open')).forEach(close);
  });
  document.addEventListener('keydown',event => {
    if (event.key === 'Escape') roots().filter(r => r.classList.contains('is-open')).forEach(close);
  });
  document.addEventListener('header-preferences:state',() => roots().forEach(update));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init);
  else init();
  document.addEventListener('elementor/popup/show',init);
})();
