(function () {
  'use strict';
  var config = window.etgTravellerBlocks;
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
      state[item.key] = Array.from({length: count}, function () { return config.preferred || ''; });
    });
    return state;
  }
  function Picker(props) {
    var items = readItems(props.cart);
    var itemSignature = items.map(function (i) { return i.key + ':' + i.extensions[namespace].travellers; }).join('|');
    var pair = useState(function () { return initialState(items); });
    var values = pair[0], setValues = pair[1];
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
    }, [itemSignature]);
    useEffect(function () {
      var store = wp.data.dispatch(CheckoutStoreKey);
      if (store && store.setExtensionData) store.setExtensionData(namespace, { travellers: values });
    }, [values]);
    if (!items.length) return null;
    function update(key, index, code) {
      setValues(function (prev) {
        var next = Object.assign({}, prev);
        next[key] = (next[key] || []).slice();
        next[key][index] = code;
        return next;
      });
    }
    return el('fieldset', {'className': 'etg-block-travellers'},
      el('legend', null, 'Traveller passport nationalities'),
      items.map(function (item) {
        var count = Math.min(Number(config.max) || 999, Number(item.extensions[namespace].travellers) || 0);
        var codes = values[item.key] || [];
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
        return el('div', {'className': 'etg-block-travellers__line', key: item.key},
          el('strong', null, item.name),
          count < 1 ? el('p', {role:'alert'}, 'Traveller count is not yet verified for this booking. Choose a valid booking configuration before checkout.') : null,
          count > batch ? el('p', {'aria-live':'polite'}, 'Travellers ' + (start + 1) + '–' + (start + visible) + ' of ' + count) : null,
          Array.from({length: Math.max(0, visible)}, function (_, index) {
            var i = start + index;
            var id = 'etg-passport-' + item.key.replace(/[^a-z0-9_-]/gi, '') + '-' + i;
            return el('label', {key: i, htmlFor: id, 'className': 'etg-block-travellers__field'},
              el('span', null, 'Traveller ' + (i + 1)),
              el('select', {id: id, value: codes[i] || '', required: true,
                onChange: function (ev) { update(item.key, i, ev.target.value); }},
                el('option', {value: ''}, 'Select passport country'),
                config.countries.map(function (country) {return el('option', {value: country.value, key: country.value}, country.label);})
              )
            );
          }),
          totalPages > 1 ? el('div', {'className':'etg-traveller-pager'},
            el('button', {type:'button',disabled:page===0,onClick:function(){changePage(page-1);}}, 'Previous 20'),
            el('span', null, ' Page '+(page+1)+' of '+totalPages+' '),
            el('button', {type:'button',disabled:page>=totalPages-1,onClick:function(){changePage(page+1);}}, 'Next 20')
          ) : null
        );
      })
    );
  }
  wp.plugins.registerPlugin('etg-traveller-nationality', {
    render: function () {return el(wc.blocksCheckout.ExperimentalOrderMeta, null, el(Picker));},
    scope: 'woocommerce-checkout'
  });
})();
