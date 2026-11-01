(function () {
  'use strict';
  var config = window.travelBookingTravellerBlocks;
  var wp = window.wp, wc = window.wc;
  if (!config || !wp || !wp.element || !wp.plugins || !wp.data || !wc || !wc.blocksCheckout || !wc.wcBlocksData) return;
  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var namespace = config.namespace;
  var CheckoutStoreKey = wc.wcBlocksData.CHECKOUT_STORE_KEY;
  function readItems(cart) {
    return (cart && Array.isArray(cart.items) ? cart.items : []).filter(function (item) {
      var info = item.extensions && item.extensions[namespace];
      return info && info.bookable;
    });
  }
  function initialState(items) {
    var state = {};
    items.forEach(function (item) {
      var count = Math.min(Number(config.max) || 999, Number(item.extensions[namespace].travellers) || 0);
      var mode = item.extensions[namespace].suggestion || 'first';
      state[item.key] = Array.from({length: count}, function (_, i) {
        return config.preferred && (mode === 'all' || (mode === 'first' && i === 0))
          ? config.preferred : '';
      });
    });
    return state;
  }
  function Picker(props) {
    var items = readItems(props.cart);
    var itemSignature = items.map(function (i) {
      return i.key + ':' + (i.id || '') + ':' + (i.variation_id || '') + ':' +
        i.extensions[namespace].travellers + ':' +
        (i.extensions[namespace].suggestion || 'first');
    }).join('|');
    var pair = useState(function () { return initialState(items); });
    var values = pair[0], setValues = pair[1];
    var reviewed = useState({});
    var confirmed = reviewed[0], setConfirmed = reviewed[1];
    var pager = useState({});
    var pages = pager[0], setPages = pager[1];
    var batch = Math.max(1, Math.min(20, Number(config.batch_size) || 20));
    useEffect(function () {
      setValues(function (old) {
        var next = initialState(items);
        Object.keys(next).forEach(function (key) {
          if (old[key] && old[key].length === next[key].length) next[key] = old[key];
        });
        return next;
      });
      // Any change to party identity/count invalidates an earlier confirmation.
      setConfirmed({});
    }, [itemSignature]);
    useEffect(function () {
      var store = wp.data.dispatch(CheckoutStoreKey);
      if (store && store.setExtensionData) {
        var current = {};
        items.forEach(function (item) { current[item.key] = confirmed[item.key] === true; });
        store.setExtensionData(namespace, { travellers: values, confirmed: current });
      }
    }, [values, confirmed, itemSignature]);
    if (!items.length) return null;
    function invalidate(key) {
      setConfirmed(function (prev) {
        var next = Object.assign({}, prev);
        next[key] = false;
        return next;
      });
    }
    function fillEmpty(key, count, code) {
      if (!code) return;
      invalidate(key);
      setValues(function (old) {
        var next = Object.assign({}, old);
        var prior = (next[key] || []).slice();
        for (var i = 0; i < count; i += 1) if (!prior[i]) prior[i] = code;
        next[key] = prior;
        return next;
      });
    }
    function update(key, index, code) {
      invalidate(key);
      setValues(function (prev) {
        var next = Object.assign({}, prev);
        next[key] = (next[key] || []).slice();
        next[key][index] = code;
        return next;
      });
    }
    return el('fieldset', {'className': 'travel-booking-block-travellers'},
      el('legend', null, 'Traveller passport nationalities'),
      items.map(function (item) {
        var count = Math.min(Number(config.max) || 999, Number(item.extensions[namespace].travellers) || 0);
        var codes = values[item.key] || [];
        var mode = item.extensions[namespace].suggestion || 'first';
        var complete = count > 0 && codes.length === count &&
          codes.every(function (code) { return !!code; });
        var totalPages = Math.ceil(count / batch);
        var page = Math.max(0, Math.min(totalPages - 1, Number(pages[item.key]) || 0));
        var start = page * batch;
        var visible = Math.min(batch, count - start);
        function changePage(next) {
          setPages(function (old) {
            var value = Object.assign({}, old);
            value[item.key] = Math.max(0, Math.min(totalPages - 1, next));
            return value;
          });
        }
        return el('div', {'className': 'travel-booking-block-travellers__line', key: item.key},
          el('strong', null, item.name),
          count < 1 ? el('p', {role:'alert'}, 'Traveller count is not yet verified for this booking. Choose a valid booking configuration before checkout.') : null,
          config.preferred && mode !== 'none' && count > 0
            ? el('p', {'className':'travel-booking-suggestion'}, 'Suggestion: ' + config.preferred +
                (mode === 'all' ? ' has been prefilled as a draft for all travellers.' :
                 ' has been prefilled for the first traveller.') +
                ' Check each passport; these values are not confirmed yet.') : null,
          config.preferred && count > 0
            ? el('button', {type:'button','className':'travel-booking-apply-suggestion',
                onClick:function(){fillEmpty(item.key,count,config.preferred);}},
                'Use ' + config.preferred + ' for unfilled travellers') : null,
          count > batch ? el('p', {'aria-live':'polite'}, 'Travellers ' + (start + 1) + '–' + (start + visible) + ' of ' + count) : null,
          Array.from({length: Math.max(0, visible)}, function (_, index) {
            var i = start + index;
            var id = 'travel-booking-passport-' + item.key.replace(/[^a-z0-9_-]/gi, '') + '-' + i;
            return el('label', {key: i, htmlFor: id, 'className': 'travel-booking-block-travellers__field'},
              el('span', null, 'Traveller ' + (i + 1)),
              el('select', {id: id, value: codes[i] || '', required: true,
                onChange: function (ev) { update(item.key, i, ev.target.value); }},
                el('option', {value: ''}, 'Select passport country'),
                config.countries.map(function (country) {return el('option', {value: country.value, key: country.value}, country.label);})
              )
            );
          }),
          totalPages > 1 ? el('div', {'className':'travel-booking-traveller-pager'},
            el('button', {type:'button',disabled:page===0,onClick:function(){changePage(page-1);}}, 'Previous 20'),
            el('span', null, ' Page '+(page+1)+' of '+totalPages+' '),
            el('button', {type:'button',disabled:page>=totalPages-1,onClick:function(){changePage(page+1);}}, 'Next 20')
          ) : null,
          count > 0 ? el('label', {'className':'travel-booking-confirmation'},
            el('input', {type:'checkbox',checked:confirmed[item.key] === true,
              disabled:!complete,
              onChange:function (event) {
                var checked = !!event.target.checked;
                setConfirmed(function (old) {
                  var next = Object.assign({}, old);
                  next[item.key] = checked;
                  return next;
                });
              }}),
            'I reviewed the passport nationalities of all ' + count + ' travellers in this booking.'
          ) : null,
          count > 0 && !complete ? el('p', {role:'status'}, 'Complete all traveller nationalities before confirming.') : null
        );
      })
    );
  }
  wp.plugins.registerPlugin('travel-booking-traveller-nationality', {
    render: function () {return el(wc.blocksCheckout.ExperimentalOrderMeta, null, el(Picker));},
    scope: 'woocommerce-checkout'
  });
})();
