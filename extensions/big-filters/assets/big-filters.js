/* ==========================================================================
   Big Collection Filters - 100% Theme Match (Ecomus & Unified Filtration)
   ========================================================================== */
(function () {
  'use strict';

  if (window.__bigFiltersLoaded) return;
  window.__bigFiltersLoaded = true;

  var configEl = document.querySelector('[data-big-filters-config]');
  if (!configEl) {
    console.warn('[big-filters] Config element not found; app embed only runs on collection pages.');
    return;
  }
  var config = JSON.parse(configEl.textContent);

  var PREFIX = 'bf_';
  var SORTS = [
    ['created-descending', 'Date, new to old'],
    ['created-ascending', 'Date, old to new'],
    ['price-ascending', 'Price, low to high'],
    ['price-descending', 'Price, high to low'],
    ['title-ascending', 'Alphabetically, A-Z'],
    ['title-descending', 'Alphabetically, Z-A'],
  ];
  var FACET_PREVIEW = 8;

  var COLOR_MAP = {
    black: '#000000', white: '#ffffff', red: '#e53935', blue: '#1e88e5',
    navy: '#0d233a', green: '#43a047', yellow: '#fdd835', orange: '#fb8c00',
    purple: '#8e24aa', pink: '#d81b60', brown: '#6d4c41', grey: '#9e9e9e',
    gray: '#9e9e9e', beige: '#f5f5dc', cream: '#fffdd0', tan: '#d2b48c',
    gold: '#ffd700', silver: '#c0c0c0', teal: '#00897b', olive: '#689f38',
    maroon: '#800000', coral: '#ff7043', turquoise: '#26c6da', lavender: '#b39ddb',
    charcoal: '#37474f', burgundy: '#780016', khaki: '#c3b091', mint: '#a7ffeb'
  };

  var state = readState();
  var lastData = null;
  var inflight = null;
  var expanded = {};
  var collapsed = {};
  var els = {};
  var isStandalone = false;
  var currentCols = 4;

  /* ---------- DOM & String Helpers ---------- */

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) return;
      if (key === 'class') el.className = value;
      else if (key === 'text') el.textContent = value;
      else if (key.slice(0, 2) === 'on') el.addEventListener(key.slice(2), value);
      else if (value === true) el.setAttribute(key, '');
      else el.setAttribute(key, value);
    });
    [].concat(children || []).forEach(function (child) {
      if (child === null || child === undefined || child === false) return;
      el.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return el;
  }

  function formatMoney(cents) {
    var format = config.moneyFormat || '${{amount}}';
    function fmt(number, precision, thousands, decimal) {
      var parts = (number / 100).toFixed(precision).split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
      return parts[0] + (parts[1] ? decimal + parts[1] : '');
    }
    var match = format.match(/\{\{\s*(\w+)\s*\}\}/);
    var value;
    switch (match ? match[1] : 'amount') {
      case 'amount_no_decimals': value = fmt(cents, 0, ',', '.'); break;
      case 'amount_with_comma_separator': value = fmt(cents, 2, '.', ','); break;
      case 'amount_no_decimals_with_comma_separator': value = fmt(cents, 0, '.', ','); break;
      case 'amount_with_apostrophe_separator': value = fmt(cents, 2, "'", '.'); break;
      case 'amount_no_decimals_with_space_separator': value = fmt(cents, 0, ' ', ','); break;
      case 'amount_with_space_separator': value = fmt(cents, 2, ' ', ','); break;
      case 'amount_with_period_and_space_separator': value = fmt(cents, 2, ' ', '.'); break;
      default: value = fmt(cents, 2, ',', '.');
    }
    var tmp = document.createElement('textarea');
    tmp.innerHTML = format.replace(/<[^>]*>/g, '');
    return tmp.value.replace(/\{\{\s*\w+\s*\}\}/, value);
  }

  function imageUrl(url, width) {
    if (!url) return null;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + 'width=' + width;
  }

  function productUrl(product) {
    var root = (config.rootUrl || '/').replace(/\/$/, '');
    return root + '/collections/' + encodeURIComponent(config.collection) + '/products/' + encodeURIComponent(product.handle);
  }

  function resolveColorHex(val) {
    var key = String(val).trim().toLowerCase();
    if (COLOR_MAP[key]) return COLOR_MAP[key];
    if (/^#[0-9a-f]{3,8}$/i.test(key)) return key;
    return '#e5e5e5';
  }

  function isLightColor(hex) {
    if (!hex || hex.charAt(0) !== '#') return false;
    var c = hex.substring(1);
    if (c.length === 3) c = c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
    var rgb = parseInt(c, 16);
    var r = (rgb >> 16) & 0xff;
    var g = (rgb >> 8) & 0xff;
    var b = (rgb >> 0) & 0xff;
    var luma = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    return luma > 180;
  }

  /* ---------- URL State Management ---------- */

  function emptyState() {
    return { vendor: [], type: [], tag: [], options: {}, available: null, min: '', max: '', sort: 'created-descending', page: 1 };
  }

  function readState() {
    var params = new URLSearchParams(window.location.search);
    var s = emptyState();
    params.forEach(function (value, key) {
      if (key.indexOf(PREFIX) !== 0) return;
      var name = key.slice(PREFIX.length);
      if (name === 'vendor' || name === 'type' || name === 'tag') s[name].push(value);
      else if (name.indexOf('opt_') === 0) (s.options[name.slice(4)] = s.options[name.slice(4)] || []).push(value);
      else if (name === 'available') s.available = value === '1' ? true : value === '0' ? false : null;
      else if (name === 'min' || name === 'max') s[name] = value;
      else if (name === 'sort') s.sort = value;
      else if (name === 'page') s.page = Math.max(1, parseInt(value, 10) || 1);
    });
    return s;
  }

  function writeState() {
    var params = new URLSearchParams(window.location.search);
    Array.from(params.keys()).forEach(function (key) {
      if (key.indexOf(PREFIX) === 0) params.delete(key);
    });
    ['vendor', 'type', 'tag'].forEach(function (name) {
      state[name].forEach(function (v) { params.append(PREFIX + name, v); });
    });
    Object.keys(state.options).forEach(function (name) {
      state.options[name].forEach(function (v) { params.append(PREFIX + 'opt_' + name, v); });
    });
    if (state.available !== null) params.set(PREFIX + 'available', state.available ? '1' : '0');
    if (state.min !== '') params.set(PREFIX + 'min', state.min);
    if (state.max !== '') params.set(PREFIX + 'max', state.max);
    if (state.sort !== 'created-descending') params.set(PREFIX + 'sort', state.sort);
    if (state.page > 1) params.set(PREFIX + 'page', state.page);
    var qs = params.toString();
    history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
  }

  function activeCount() {
    var n = state.vendor.length + state.type.length + state.tag.length;
    Object.keys(state.options).forEach(function (k) { n += state.options[k].length; });
    if (state.available !== null) n++;
    if (state.min !== '' || state.max !== '') n++;
    return n;
  }

  function toggleValue(list, value) {
    var i = list.indexOf(value);
    if (i === -1) list.push(value);
    else list.splice(i, 1);
  }

  /* ---------- Data Fetching ---------- */

  function requestResults() {
    var filters = { vendor: state.vendor, type: state.type, tag: state.tag, options: state.options };
    if (state.available !== null) filters.available = state.available;
    if (state.min !== '' || state.max !== '') {
      filters.price = {};
      if (state.min !== '') filters.price.min = Number(state.min);
      if (state.max !== '') filters.price.max = Number(state.max);
    }

    var params = new URLSearchParams({
      collection: config.collection,
      f: JSON.stringify(filters),
      sort: state.sort,
      page: String(state.page),
      per_page: String(config.perPage || 24),
    });

    if (inflight) inflight.abort();
    inflight = window.AbortController ? new AbortController() : null;

    return fetch(config.endpoint + '?' + params.toString(), {
      headers: { Accept: 'application/json' },
      signal: inflight ? inflight.signal : undefined,
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    });
  }

  function fetchResults() {
    if (els.gridContainer) els.gridContainer.classList.add('bf-loading');
    if (els.root) els.root.classList.add('bf-loading');

    return requestResults()
      .then(function (data) {
        lastData = data;
        render(data);
      })
      .catch(function (err) {
        if (err.name === 'AbortError') return;
        if (els.grid) {
          els.grid.innerHTML = '<div class="hdt-empty-message"><p>Products could not be loaded. Please refresh the page.</p></div>';
        }
        console.error('[big-filters]', err);
      })
      .finally(function () {
        if (els.gridContainer) els.gridContainer.classList.remove('bf-loading');
        if (els.root) els.root.classList.remove('bf-loading');
      });
  }

  function update(resetPage) {
    if (resetPage) state.page = 1;
    writeState();
    fetchResults();
  }

  /* ---------- SVG Icons (Exact Ecomus Theme Icons) ---------- */

  function chevronSvg() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'hdt-facet-title_icon');
    svg.setAttribute('width', '11');
    svg.setAttribute('height', '7');
    svg.setAttribute('viewBox', '0 0 11 7');
    svg.setAttribute('fill', 'currentColor');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M10.8284 1.41421L5.41421 6.82843L0 1.41421L1.41421 0L5.41421 4L9.41421 0L10.8284 1.41421Z');
    svg.appendChild(path);
    return svg;
  }

  function checkmarkSvg(color) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '10');
    svg.setAttribute('height', '8');
    svg.setAttribute('viewBox', '0 0 10 8');
    svg.setAttribute('fill', 'none');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M1 4L3.8 7L9 1');
    path.setAttribute('stroke', color || '#ffffff');
    path.setAttribute('stroke-width', '1.8');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);
    return svg;
  }

  function filterIconSvg() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '12');
    svg.setAttribute('viewBox', '0 0 20 12');
    svg.setAttribute('fill', 'none');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M0 1C0 0.734784 0.105357 0.48043 0.292893 0.292893C0.48043 0.105357 0.734784 0 1 0H19C19.2652 0 19.5196 0.105357 19.7071 0.292893C19.8946 0.48043 20 0.734784 20 1C20 1.26522 19.8946 1.51957 19.7071 1.70711C19.5196 1.89464 19.2652 2 19 2H1C0.734784 2 0.48043 1.89464 0.292893 1.70711C0.105357 1.51957 0 1.26522 0 1ZM3 6C3 5.73478 3.10536 5.48043 3.29289 5.29289C3.48043 5.10536 3.73478 5 4 5H16C16.2652 5 16.5196 5.10536 16.7071 5.29289C16.8946 5.48043 17 5.73478 17 6C17 6.26522 16.8946 6.51957 16.7071 6.70711C16.5196 6.89464 16.2652 7 16 7H4C3.73478 7 3.48043 6.89464 3.29289 6.70711C3.10536 6.51957 3 6.26522 3 6ZM8 10C7.73478 10 7.48043 10.1054 7.29289 10.2929C7.10536 10.4804 7 10.7348 7 11C7 11.2652 7.10536 11.5196 7.29289 11.7071C7.48043 11.8946 7.73478 12 8 12H12C12.2652 12 12.5196 11.8946 12.7071 11.7071C12.8946 11.5196 13 11.2652 13 11C13 10.7348 12.8946 10.4804 12.7071 10.2929C12.5196 10.1054 12.2652 10 12 10H8Z');
    path.setAttribute('fill', 'currentColor');
    svg.appendChild(path);
    return svg;
  }

  function removeCrossSvg() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '8');
    svg.setAttribute('height', '8');
    svg.setAttribute('viewBox', '0 0 8 8');
    svg.setAttribute('fill', 'none');
    svg.style.flexShrink = '0';
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M1 1L7 7M7 1L1 7');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '1.5');
    path.setAttribute('stroke-linecap', 'round');
    svg.appendChild(path);
    return svg;
  }

  function arrowLeftSvg() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '7');
    svg.setAttribute('height', '11');
    svg.setAttribute('viewBox', '0 0 7 11');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M6.02344 0L0.523438 5.5L6.02344 11L6.99969 10.0237L2.47594 5.5L6.99969 0.97625L6.02344 0Z');
    path.setAttribute('fill', 'currentColor');
    svg.appendChild(path);
    return svg;
  }

  function arrowRightSvg() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '7');
    svg.setAttribute('height', '11');
    svg.setAttribute('viewBox', '0 0 7 11');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M0.976562 0L6.47656 5.5L0.976562 11L0.000312424 10.0237L4.52406 5.5L0.000312424 0.97625L0.976562 0Z');
    path.setAttribute('fill', 'currentColor');
    svg.appendChild(path);
    return svg;
  }

  /* ---------- Filter UI Builders (100% Ecomus Markup) ---------- */

  function buildFacetGroup(title, activeCount, bodyContent) {
    var isOpen = collapsed[title] === undefined ? true : !collapsed[title];
    var countBadge = activeCount > 0
      ? h('span', { class: 'hdt-facet-badge-count', text: ' (' + activeCount + ')' })
      : null;

    var summary = h('summary', { class: 'hdt-filter-group-summary' }, [
      h('div', { class: 'hdt-facet-title hdt-text-lg lg:hdt-text-2xl hdt-font-normal hdt-s-text hdt-flex' }, [
        h('span', { class: 'hdt-inline-flex hdt-facet-title_group' }, [
          h('span', { text: title }),
          countBadge,
        ]),
        chevronSvg(),
      ]),
    ]);

    var details = h('details', {
      class: 'hdt-filter-group',
      open: isOpen,
      ontoggle: function (e) {
        collapsed[title] = !e.currentTarget.open;
      },
    }, [
      summary,
      h('div', { class: 'hdt-filter-group__display' }, bodyContent),
    ]);

    return h('div', { class: 'hdt-contents' }, details);
  }

  function buildCheckboxList(key, title, values, selected, onToggle, isColor) {
    if (!values.length && !selected.length) return null;

    var map = {};
    values.forEach(function (v) { map[v.value] = v.count; });
    selected.forEach(function (v) {
      if (!(v in map)) values = values.concat([{ value: v, count: 0 }]);
    });

    var isColorOption = isColor || /colou?r/i.test(title);
    var showAll = expanded[key] || values.length <= FACET_PREVIEW + 2;
    var visible = showAll ? values : values.slice(0, FACET_PREVIEW);

    var items = visible.map(function (v, idx) {
      var isChecked = selected.indexOf(v.value) !== -1;
      var isDisabled = v.count === 0 && !isChecked;
      var inputId = 'bf-' + key.replace(/[^a-z0-9]/gi, '-') + '-' + idx;

      var input = h('input', {
        type: 'checkbox',
        id: inputId,
        class: 'sr-only',
        checked: isChecked,
        disabled: isDisabled,
        onchange: function () {
          onToggle(v.value);
          update(true);
        },
      });

      var boxEl;
      if (isColorOption) {
        var hex = resolveColorHex(v.value);
        var checkColor = isLightColor(hex) ? '#000000' : '#ffffff';
        boxEl = h('span', {
          class: 'hdt-facets-checkbox-color',
          style: 'background-color: ' + hex + ';',
        }, [
          h('span', { class: 'hdt-facets-color-icon' }, checkmarkSvg(checkColor)),
        ]);
      } else {
        boxEl = h('span', { class: 'hdt-facets-checkbox' }, checkmarkSvg('#ffffff'));
      }

      var label = h('label', { for: inputId }, [
        boxEl,
        h('span', { class: 'hdt-facets-label' }, [
          h('span', { text: v.value }),
          h('span', { class: 'hdt-item-count', text: '(' + v.count.toLocaleString() + ')' }),
        ]),
      ]);

      return h('li', { class: 'hdt-filter-group__list-item' + (isDisabled ? ' hdt-disabled' : '') }, [
        input,
        label,
      ]);
    });

    var listEl = h('ul', {
      class: 'hdt-filter-group__list' + (isColorOption ? ' hdt-filter-group__list--color' : ''),
    }, items);

    var moreBtn = values.length > visible.length || expanded[key]
      ? h('button', {
          type: 'button',
          class: 'hdt-filter-show-more',
          text: showAll && expanded[key] ? 'Show less' : 'Show all ' + values.length,
          onclick: function () {
            expanded[key] = !expanded[key];
            if (lastData) render(lastData);
          },
        })
      : null;

    return buildFacetGroup(title, selected.length, [listEl, moreBtn]);
  }

  function buildPriceGroup(range) {
    var minVal = state.min !== '' ? Number(state.min) : 0;
    var maxVal = state.max !== '' ? Number(state.max) : (range.max || 100);
    var fullMax = range.max || 100;

    var timer;
    function triggerUpdate() {
      clearTimeout(timer);
      timer = setTimeout(function () { update(true); }, 500);
    }

    function updateTrack(minV, maxV) {
      var minP = Math.max(0, Math.min(100, (minV / fullMax) * 100));
      var maxP = Math.max(0, Math.min(100, 100 - (maxV / fullMax) * 100));
      progressEl.style.setProperty('--min-progress', minP + '%');
      progressEl.style.setProperty('--max-progress', maxP + '%');
    }

    var sliderMin = h('input', {
      type: 'range',
      min: '0',
      max: String(fullMax),
      step: '1',
      value: String(minVal),
      'aria-label': 'Minimum price',
      oninput: function (e) {
        var v = Math.min(Number(e.target.value), Number(sliderMax.value));
        sliderMin.value = String(v);
        inputMin.value = String(v);
        state.min = v > 0 ? String(v) : '';
        updateTrack(v, Number(sliderMax.value));
        triggerUpdate();
      },
    });

    var sliderMax = h('input', {
      type: 'range',
      min: '0',
      max: String(fullMax),
      step: '1',
      value: String(maxVal),
      'aria-label': 'Maximum price',
      oninput: function (e) {
        var v = Math.max(Number(e.target.value), Number(sliderMin.value));
        sliderMax.value = String(v);
        inputMax.value = String(v);
        state.max = v < fullMax ? String(v) : '';
        updateTrack(Number(sliderMin.value), v);
        triggerUpdate();
      },
    });

    var inputMin = h('input', {
      type: 'number',
      min: '0',
      max: String(fullMax),
      step: '1',
      value: state.min,
      placeholder: '0',
      'aria-label': 'Minimum price',
      oninput: function (e) {
        var v = Math.max(0, Math.min(Number(e.target.value || 0), fullMax));
        state.min = v > 0 ? String(v) : '';
        sliderMin.value = String(v);
        updateTrack(v, Number(sliderMax.value));
        triggerUpdate();
      },
    });

    var inputMax = h('input', {
      type: 'number',
      min: '0',
      max: String(fullMax),
      step: '1',
      value: state.max,
      placeholder: String(fullMax),
      'aria-label': 'Maximum price',
      oninput: function (e) {
        var v = Math.max(0, Math.min(Number(e.target.value || fullMax), fullMax));
        state.max = v < fullMax ? String(v) : '';
        sliderMax.value = String(v);
        updateTrack(Number(sliderMin.value), v);
        triggerUpdate();
      },
    });

    var progressEl = h('div', { class: 'hdt-price-range' }, [
      h('div', { class: 'hdt-filter-group__range-slider' }, [
        h('div', { class: 'hdt-filter-group__range-progress' }),
        h('div', { class: 'hdt-filter-group__range-price' }, [sliderMin, sliderMax]),
      ]),
      h('div', { class: 'hdt-filter-group__input-price-wrap' }, [
        h('div', { class: 'hdt-filter-group__input-price' }, [
          h('span', { text: 'Price:' }),
          h('div', { class: 'hdt-filter-group__price-range-from' }, [
            h('span', { class: 'hdt-filter-group__price-currency', text: '$' }),
            inputMin,
          ]),
          h('span', { class: 'hdt-price-separator', text: '–' }),
          h('div', { class: 'hdt-filter-group__price-range-to' }, [
            h('span', { class: 'hdt-filter-group__price-currency', text: '$' }),
            inputMax,
          ]),
        ]),
      ]),
    ]);

    updateTrack(minVal, maxVal);

    var hasActivePrice = state.min !== '' || state.max !== '';
    return buildFacetGroup('Price', hasActivePrice ? 1 : 0, progressEl);
  }

  function buildAvailabilityGroup(counts) {
    var options = [
      { label: 'In stock', value: true, count: counts.in_stock },
      { label: 'Out of stock', value: false, count: counts.out_of_stock },
    ];

    var items = options.map(function (o, idx) {
      var isChecked = state.available === o.value;
      var isDisabled = o.count === 0 && !isChecked;
      var inputId = 'bf-avail-' + idx;

      var input = h('input', {
        type: 'checkbox',
        id: inputId,
        class: 'sr-only',
        checked: isChecked,
        disabled: isDisabled,
        onchange: function () {
          state.available = isChecked ? null : o.value;
          update(true);
        },
      });

      var label = h('label', { for: inputId }, [
        h('span', { class: 'hdt-facets-checkbox' }, checkmarkSvg('#ffffff')),
        h('span', { class: 'hdt-facets-label' }, [
          h('span', { text: o.label }),
          h('span', { class: 'hdt-item-count', text: '(' + o.count.toLocaleString() + ')' }),
        ]),
      ]);

      return h('li', { class: 'hdt-filter-group__list-item' + (isDisabled ? ' hdt-disabled' : '') }, [
        input,
        label,
      ]);
    });

    var listEl = h('ul', { class: 'hdt-filter-group__list' }, items);
    return buildFacetGroup('Availability', state.available !== null ? 1 : 0, listEl);
  }

  /* ---------- Product Card Builder (100% Ecomus Card Match) ---------- */

  function buildProductCard(p) {
    var onSale = p.compare_at_price && p.compare_at_price > p.price_min;
    var priceText = p.price_min !== p.price_max
      ? 'From ' + formatMoney(p.price_min)
      : formatMoney(p.price_min);
    var compareText = p.compare_at_price ? formatMoney(p.compare_at_price) : '';
    var url = productUrl(p);

    // Responsive images matching Ecomus theme
    var imgEl = p.image
      ? h('img', {
          class: 'hdt-card-product__media-image',
          src: imageUrl(p.image, 400),
          srcset: [300, 400, 600, 800].map(function (w) { return imageUrl(p.image, w) + ' ' + w + 'w'; }).join(', '),
          sizes: '(min-width: 1150px) 25vw, (min-width: 768px) 33vw, 50vw',
          alt: p.image_alt || p.title || '',
          loading: 'lazy',
          width: '400',
          height: '400',
        })
      : h('div', { class: 'hdt-card-product__media-placeholder' });

    var badges = [];
    if (onSale) {
      badges.push(h('span', { class: 'hdt-badge hdt-badge-sale hdt-badge__on-sale', text: 'Sale' }));
    }
    if (!p.available) {
      badges.push(h('span', { class: 'hdt-badge hdt-badge-soldout hdt-badge__sold-out', text: 'Sold out' }));
    }

    var mediaLink = h('a', {
      href: url,
      class: 'hdt-card-product__media-link',
      'data-pr-href': '',
    }, [imgEl, badges.length ? h('div', { class: 'hdt-product-badges' }, badges) : null]);

    var mediaContainer = h('div', { class: 'hdt-card-product__media' }, mediaLink);

    var priceList;
    if (onSale) {
      priceList = h('div', { class: 'hdt-price__list' }, [
        h('span', { class: 'hdt-compare-at-price' }, [h('span', { class: 'hdt-money', text: compareText })]),
        h('span', { class: 'hdt-price hdt-price-sale' }, [h('span', { class: 'hdt-money', text: priceText })]),
      ]);
    } else {
      priceList = h('div', { class: 'hdt-price__list' }, [
        h('span', { class: 'hdt-price' }, [h('span', { class: 'hdt-money', text: priceText })]),
      ]);
    }

    var infoChildren = [];
    if (config.showVendor && p.vendor) {
      infoChildren.push(h('div', { class: 'hdt-card-product__vendor', text: p.vendor }));
    }
    infoChildren.push(h('a', {
      href: url,
      class: 'hdt-card-product__title',
      'data-pr-url': '',
      text: p.title,
    }));
    infoChildren.push(h('div', { class: 'hdt-price-wrapp' }, priceList));

    var infoContainer = h('div', { class: 'hdt-card-product__info' }, infoChildren);

    var wrapper = h('div', { class: 'hdt-card-product__wrapper' }, [mediaContainer, infoContainer]);

    return h('div', {
      class: 'hdt-card-product hdt-pr-style1' + (!p.available ? ' hdt-pr-sold_out' : ''),
    }, wrapper);
  }

  /* ---------- Active Filter Chips & Pagination ---------- */

  function renderActiveChips(targetEl, total) {
    if (!targetEl) return;
    var chips = [];

    chips.push(h('div', { class: 'hdt-filters_count', text: total.toLocaleString() + (total === 1 ? ' product found' : ' products found') }));

    function addChip(label, onRemove) {
      chips.push(h('div', { class: 'hdt-filters_count' }, [
        h('a', {
          class: 'hdt-active-filters__remove',
          href: 'javascript:;',
          onclick: function (e) { e.preventDefault(); onRemove(); update(true); },
        }, [
          h('span', { text: label }),
          removeCrossSvg(),
        ]),
      ]));
    }

    ['vendor', 'type', 'tag'].forEach(function (name) {
      state[name].forEach(function (v) {
        addChip(v, function () { toggleValue(state[name], v); });
      });
    });

    Object.keys(state.options).forEach(function (name) {
      state.options[name].forEach(function (v) {
        addChip(name + ': ' + v, function () {
          toggleValue(state.options[name], v);
          if (!state.options[name].length) delete state.options[name];
        });
      });
    });

    if (state.available !== null) {
      addChip(state.available ? 'In stock' : 'Out of stock', function () { state.available = null; });
    }
    if (state.min !== '' || state.max !== '') {
      addChip('Price: $' + (state.min || '0') + ' – $' + (state.max || '∞'), function () {
        state.min = '';
        state.max = '';
      });
    }

    if (activeCount() > 0) {
      chips.push(h('button', {
        type: 'button',
        class: 'hdt-active-filters__clear-all',
        text: 'Clear all',
        onclick: function () {
          var s = state.sort;
          state = emptyState();
          state.sort = s;
          update(true);
        },
      }));
    }

    targetEl.replaceChildren.apply(targetEl, chips);
  }

  function renderPagination(targetEl, page, pages) {
    if (!targetEl) return;
    if (pages <= 1) {
      targetEl.replaceChildren();
      return;
    }

    function goTo(n) {
      return function (e) {
        e.preventDefault();
        state.page = n;
        update(false);
        var scrollTarget = els.gridContainer || els.root || document.body;
        scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
      };
    }

    var listItems = [];

    // Prev arrow
    if (page > 1) {
      listItems.push(h('li', null, [
        h('a', {
          class: 'hdt-pagination__item hdt-pagination__item-arrow',
          href: 'javascript:;',
          'aria-label': 'Previous page',
          onclick: goTo(page - 1),
        }, arrowLeftSvg()),
      ]));
    }

    var start = Math.max(1, page - 2);
    var end = Math.min(pages, page + 2);
    if (start > 1) {
      listItems.push(h('li', null, h('a', { class: 'hdt-pagination__item', href: 'javascript:;', text: '1', onclick: goTo(1) })));
      if (start > 2) listItems.push(h('li', null, h('span', { class: 'hdt-pagination__ellipsis', text: '…' })));
    }

    for (var i = start; i <= end; i++) {
      if (i === page) {
        listItems.push(h('li', null, h('span', { class: 'hdt-pagination__item hdt-pagination__item--current', text: String(i) })));
      } else {
        listItems.push(h('li', null, h('a', { class: 'hdt-pagination__item', href: 'javascript:;', text: String(i), onclick: goTo(i) })));
      }
    }

    if (end < pages) {
      if (end < pages - 1) listItems.push(h('li', null, h('span', { class: 'hdt-pagination__ellipsis', text: '…' })));
      listItems.push(h('li', null, h('a', { class: 'hdt-pagination__item', href: 'javascript:;', text: String(pages), onclick: goTo(pages) })));
    }

    // Next arrow
    if (page < pages) {
      listItems.push(h('li', null, [
        h('a', {
          class: 'hdt-pagination__item hdt-pagination__item-arrow',
          href: 'javascript:;',
          'aria-label': 'Next page',
          onclick: goTo(page + 1),
        }, arrowRightSvg()),
      ]));
    }

    var nav = h('nav', { class: 'hdt-pagination', role: 'navigation', 'aria-label': 'Pagination' }, [
      h('ul', { class: 'hdt-pagination__list' }, listItems),
    ]);

    targetEl.replaceChildren(nav);
  }

  /* ---------- Filter Rendering Pipeline ---------- */

  function renderSidebar(facets) {
    if (!els.sidebar) return;

    var groups = [
      buildAvailabilityGroup(facets.available),
      buildPriceGroup(facets.price),
      buildCheckboxList('vendor', 'Brand', facets.vendor, state.vendor, function (v) { toggleValue(state.vendor, v); }),
      buildCheckboxList('type', 'Product Type', facets.type, state.type, function (v) { toggleValue(state.type, v); }),
    ];

    facets.options.forEach(function (option) {
      var selected = state.options[option.name] || [];
      groups.push(buildCheckboxList('opt:' + option.name, option.name, option.values, selected, function (v) {
        var list = (state.options[option.name] = state.options[option.name] || []);
        toggleValue(list, v);
        if (!list.length) delete state.options[option.name];
      }));
    });

    Object.keys(state.options).forEach(function (name) {
      if (facets.options.some(function (o) { return o.name === name; })) return;
      groups.push(buildCheckboxList('opt:' + name, name, [], state.options[name], function (v) {
        toggleValue(state.options[name], v);
        if (!state.options[name].length) delete state.options[name];
      }));
    });

    groups.push(buildCheckboxList('tag', 'Tags', facets.tag, state.tag, function (v) { toggleValue(state.tag, v); }));

    var validGroups = groups.filter(Boolean);
    els.sidebar.replaceChildren.apply(els.sidebar, validGroups);

    // Also populate mobile drawer body if present
    if (els.mobileDrawerBody) {
      var drawerGroups = [
        buildAvailabilityGroup(facets.available),
        buildPriceGroup(facets.price),
        buildCheckboxList('vendor_d', 'Brand', facets.vendor, state.vendor, function (v) { toggleValue(state.vendor, v); }),
        buildCheckboxList('type_d', 'Product Type', facets.type, state.type, function (v) { toggleValue(state.type, v); }),
      ];
      facets.options.forEach(function (option) {
        var selected = state.options[option.name] || [];
        drawerGroups.push(buildCheckboxList('opt_d:' + option.name, option.name, option.values, selected, function (v) {
          var list = (state.options[option.name] = state.options[option.name] || []);
          toggleValue(list, v);
          if (!list.length) delete state.options[option.name];
        }));
      });
      drawerGroups.push(buildCheckboxList('tag_d', 'Tags', facets.tag, state.tag, function (v) { toggleValue(state.tag, v); }));
      els.mobileDrawerBody.replaceChildren.apply(els.mobileDrawerBody, drawerGroups.filter(Boolean));
    }
  }

  function render(data) {
    if (!data) return;

    renderSidebar(data.facets);
    renderActiveChips(els.activeFilters, data.total);

    // Update filter toggle count in toolbar
    if (els.filterBtnCount) {
      var cnt = activeCount();
      els.filterBtnCount.textContent = cnt ? ' (' + cnt + ')' : '';
      els.filterBtnCount.style.display = cnt ? 'inline' : 'none';
    }

    // Render product cards
    if (els.grid) {
      if (!data.products.length) {
        els.grid.replaceChildren(h('div', { class: 'hdt-empty-message' }, [
          h('p', { text: 'No products match these filters. ' }),
          h('button', {
            type: 'button',
            text: 'Clear all filters',
            onclick: function () {
              var s = state.sort;
              state = emptyState();
              state.sort = s;
              update(true);
            },
          }),
        ]));
      } else {
        els.grid.replaceChildren.apply(els.grid, data.products.map(buildProductCard));
      }
    }

    renderPagination(els.pagination, data.page, data.pages);
  }

  /* ---------- Layout & Grid Switching ---------- */

  function switchColumns(num) {
    currentCols = num;
    var grid = els.grid;
    if (!grid) return;

    grid.classList.remove('lg:hdt-grid-cols-2', 'lg:hdt-grid-cols-3', 'lg:hdt-grid-cols-4');
    grid.classList.add('lg:hdt-grid-cols-' + num);

    if (els.layoutButtons) {
      els.layoutButtons.forEach(function (btn) {
        btn.classList.toggle('is-active', Number(btn.getAttribute('value')) === num);
      });
    }
  }

  /* ---------- Sorting Dropdown Builder ---------- */

  function sortChevronSvg() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'hdt-sort-chevron-icon');
    svg.setAttribute('width', '10');
    svg.setAttribute('height', '8');
    svg.setAttribute('viewBox', '0 0 19 12');
    svg.setAttribute('fill', 'none');
    var polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    polyline.setAttribute('fill', 'none');
    polyline.setAttribute('stroke', 'currentColor');
    polyline.setAttribute('points', '17 2 9.5 10 2 2');
    polyline.setAttribute('stroke-width', '2');
    polyline.setAttribute('stroke-linecap', 'square');
    svg.appendChild(polyline);
    return svg;
  }

  function buildSortPopover() {
    var currentSortItem = SORTS.find(function (s) { return s[0] === state.sort; }) || SORTS[0];

    var labelSpan = h('span', { class: 'hdt-sort-current-text', text: currentSortItem[1] });

    var toggleBtn = h('button', {
      type: 'button',
      class: 'hdt-sort-toggle-btn',
      'aria-expanded': 'false',
      onclick: function (e) {
        e.stopPropagation();
        var open = popoverContainer.classList.toggle('hdt-sort-popover-open');
        toggleBtn.setAttribute('aria-expanded', String(open));
        menuEl.hidden = !open;
      },
    }, [
      labelSpan,
      sortChevronSvg(),
    ]);

    var menuItems = SORTS.map(function (s) {
      return h('li', {
        class: 'hdt-sort-dropdown-item' + (s[0] === state.sort ? ' is-selected' : ''),
        text: s[1],
        onclick: function (e) {
          e.stopPropagation();
          state.sort = s[0];
          labelSpan.textContent = s[1];
          popoverContainer.classList.remove('hdt-sort-popover-open');
          toggleBtn.setAttribute('aria-expanded', 'false');
          menuEl.hidden = true;
          update(true);
        },
      });
    });

    var menuEl = h('ul', { class: 'hdt-sort-dropdown-menu', hidden: true }, menuItems);

    var popoverContainer = h('div', { class: 'hdt-popover__sorting' }, [toggleBtn, menuEl]);

    document.addEventListener('click', function () {
      if (popoverContainer.classList.contains('hdt-sort-popover-open')) {
        popoverContainer.classList.remove('hdt-sort-popover-open');
        toggleBtn.setAttribute('aria-expanded', 'false');
        menuEl.hidden = true;
      }
    });

    return popoverContainer;
  }

  /* ---------- Mobile Drawer Builder ---------- */

  function buildMobileDrawer() {
    var overlay = h('div', {
      class: 'bf-mobile-drawer-overlay',
      onclick: closeMobileDrawer,
    });

    var title = h('h6', { class: 'bf-drawer-title', text: 'Filters' });
    var closeBtn = h('button', {
      type: 'button',
      class: 'bf-drawer-close',
      'aria-label': 'Close filters',
      text: '×',
      onclick: closeMobileDrawer,
    });

    var header = h('div', { class: 'bf-drawer-header' }, [title, closeBtn]);
    els.mobileDrawerBody = h('div', { class: 'bf-drawer-body' });

    var applyBtn = h('button', {
      type: 'button',
      class: 'bf-drawer-apply-btn',
      text: 'Apply Filters',
      onclick: closeMobileDrawer,
    });

    var clearBtn = h('button', {
      type: 'button',
      class: 'bf-drawer-clear-btn',
      text: 'Clear All',
      onclick: function () {
        var s = state.sort;
        state = emptyState();
        state.sort = s;
        update(true);
        closeMobileDrawer();
      },
    });

    var footer = h('div', { class: 'bf-drawer-footer' }, [clearBtn, applyBtn]);

    var drawer = h('div', { class: 'bf-mobile-drawer' }, [header, els.mobileDrawerBody, footer]);

    var wrapper = h('div', { class: 'bf-mobile-drawer-container' }, [overlay, drawer]);
    document.body.appendChild(wrapper);
  }

  function openMobileDrawer() {
    document.body.classList.add('bf-mobile-drawer-open');
  }

  function closeMobileDrawer() {
    document.body.classList.remove('bf-mobile-drawer-open');
  }

  /* ---------- Mounting & DOM Setup ---------- */

  function setupNativeEcomus() {
    var nativeSidebarForm = document.querySelector('#hdt-facet-filters-form-sidebar, .hdt-shop-sidebar form, .hdt-filter');
    var nativeGrid = document.querySelector('.hdt-collection-products, [id^="products-template"], .collection-product-list, #product-grid');

    if (!nativeGrid || !nativeSidebarForm) return false;

    console.info('[big-filters] Enhancing existing Ecomus theme DOM in-place.');

    // Sidebar
    els.sidebar = nativeSidebarForm;
    // Intercept native submit so facets.min.js doesn't fetch Shopify's empty results
    nativeSidebarForm.addEventListener('submit', function (e) {
      e.preventDefault();
      e.stopPropagation();
    }, true);

    // Product grid — ensure it has the expected class for our CSS to apply
    els.grid = nativeGrid;
    if (!nativeGrid.classList.contains('hdt-collection-products')) {
      nativeGrid.classList.add('hdt-collection-products');
    }
    els.gridContainer = nativeGrid.closest('.hdt-shop-content') || nativeGrid.parentNode || nativeGrid;

    // Active filters
    els.activeFilters = document.querySelector('.hdt-active-filters');
    if (!els.activeFilters) {
      els.activeFilters = h('div', { class: 'hdt-active-filters hdt-flex' });
      nativeGrid.parentNode.insertBefore(els.activeFilters, nativeGrid);
    }

    // Pagination
    els.pagination = document.querySelector('.hdt-pagination-wrapp');
    if (!els.pagination) {
      els.pagination = h('div', { class: 'hdt-pagination-wrapp' });
      nativeGrid.parentNode.appendChild(els.pagination);
    }

    // Toolbar elements
    var filterBtn = document.querySelector('.hdt-filter_btn');
    if (filterBtn) {
      filterBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openMobileDrawer();
      });
      els.filterBtnCount = filterBtn.querySelector('.hdt-filter-btn-count');
      if (!els.filterBtnCount) {
        els.filterBtnCount = h('span', { class: 'hdt-filter-btn-count' });
        filterBtn.appendChild(els.filterBtnCount);
      }
    }

    // Layout switcher
    var layoutSwitch = document.querySelector('.hdt-view-layout-switch');
    if (layoutSwitch) {
      els.layoutButtons = Array.from(layoutSwitch.querySelectorAll('button[value]'));
      els.layoutButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var num = Number(btn.getAttribute('value'));
          if (num >= 2 && num <= 4) switchColumns(num);
        });
      });
    }

    // Sort control — replace or create
    var sortWrapper = document.querySelector('.hdt-control-sorting');
    if (sortWrapper) {
      sortWrapper.replaceChildren(buildSortPopover());
    } else {
      // Look for any existing sort container in the toolbar area
      var toolbar = document.querySelector('.hdt-shop-control');
      if (toolbar) {
        var sortCol = h('div', { class: 'hdt-col hdt-control-sorting' }, buildSortPopover());
        toolbar.appendChild(sortCol);
      }
    }

    buildMobileDrawer();
    return true;
  }

  function setupStandaloneEcomus(mountEl) {
    console.info('[big-filters] Building full Ecomus theme layout structure.');

    // 1. Toolbar
    var filterBtn = h('button', {
      type: 'button',
      class: 'hdt-filter_btn',
      'aria-label': 'Filter products',
      onclick: openMobileDrawer,
    }, [
      filterIconSvg(),
      h('span', { text: 'Filters' }),
      (els.filterBtnCount = h('span', { class: 'hdt-filter-btn-count', style: 'display:none;' })),
    ]);

    var btnCol2 = h('button', {
      type: 'button', class: 'hdt-btn-layout-col hdt_btn_layout2', value: '2',
      'aria-label': '2 columns', onclick: function () { switchColumns(2); },
    }, h('svg', { width: '16', height: '16', viewBox: '0 0 16 16', fill: 'currentColor' }, [
      h('path', { d: 'M1 1h6v14H1V1zm8 0h6v14H9V1z' }),
    ]));

    var btnCol3 = h('button', {
      type: 'button', class: 'hdt-btn-layout-col hdt_btn_layout3', value: '3',
      'aria-label': '3 columns', onclick: function () { switchColumns(3); },
    }, h('svg', { width: '16', height: '16', viewBox: '0 0 16 16', fill: 'currentColor' }, [
      h('path', { d: 'M1 1h4v14H1V1zm5 0h4v14H6V1zm5 0h4v14h-4V1z' }),
    ]));

    var btnCol4 = h('button', {
      type: 'button', class: 'hdt-btn-layout-col hdt_btn_layout4 is-active', value: '4',
      'aria-label': '4 columns', onclick: function () { switchColumns(4); },
    }, h('svg', { width: '16', height: '16', viewBox: '0 0 16 16', fill: 'currentColor' }, [
      h('path', { d: 'M1 1h2.5v14H1V1zm4 0h2.5v14H5V1zm4 0h2.5v14H9V1zm4 0H15v14h-2V1z' }),
    ]));

    els.layoutButtons = [btnCol2, btnCol3, btnCol4];

    var toolbar = h('div', { class: 'hdt-shop-control' }, [
      h('div', { class: 'hdt-col hdt-control-filter' }, filterBtn),
      h('div', { class: 'hdt-col hdt-control-layout' }, [
        h('div', { class: 'hdt-view-layout-switch' }, els.layoutButtons),
      ]),
      h('div', { class: 'hdt-col hdt-control-sorting' }, buildSortPopover()),
    ]);

    // 2. Active filters
    els.activeFilters = h('div', { class: 'hdt-active-filters' });

    // 3. Sidebar
    els.sidebar = h('form', { class: 'hdt-filter', id: 'bf-sidebar-form' });
    var sidebarWrapper = h('div', { class: 'hdt-shop-sidebar hdt-col' }, els.sidebar);

    // 4. Product grid & pagination
    els.grid = h('div', {
      class: 'hdt-collection-products hdt-collection-has-pr1 hdt-pr-border_none hdt-row-grid hdt-grid-cols-2 md:hdt-grid-cols-3 lg:hdt-grid-cols-4',
    });
    els.pagination = h('div', { class: 'hdt-pagination-wrapp' });

    var contentWrapper = h('div', { class: 'hdt-shop-content hdt-col' }, [
      els.activeFilters,
      els.grid,
      els.pagination,
    ]);
    els.gridContainer = contentWrapper;

    // 5. Flex container
    var flexRow = h('div', { class: 'hdt-row-flex' }, [sidebarWrapper, contentWrapper]);

    els.root = h('div', { class: 'bf-ecomus-root hdt-main-collection-content hdt-section-spacing' }, [
      toolbar,
      flexRow,
    ]);

    mountEl.replaceChildren(els.root);
    buildMobileDrawer();
    return true;
  }

  function init() {
    var collectionHandle = config.collection || 'all';
    console.info('[big-filters] Initializing for collection "' + collectionHandle + '" (threshold: ' + (config.threshold || 5000) + ')');

    requestResults()
      .then(function (data) {
        lastData = data;
        var total = data.collection_total || 0;
        console.info('[big-filters] Collection total products: ' + total);

        var nativeFiltersCount = document.querySelectorAll('#hdt-facet-filters-form-sidebar .hdt-filter-group, .hdt-shop-sidebar .hdt-filter-group, #main-collection-filters .facets__wrapper').length;
        var needsBigFilters = (config.threshold <= 0) || (total >= (config.threshold || 5000)) || (nativeFiltersCount === 0);

        console.info('[big-filters] nativeFiltersCount=' + nativeFiltersCount + ' needsBigFilters=' + needsBigFilters + ' threshold=' + config.threshold + ' total=' + total);

        if (!needsBigFilters) {
          console.info('[big-filters] Below threshold and native filters are present.');
          return;
        }

        // Try direct in-place enhancement first
        var success = setupNativeEcomus();
        console.info('[big-filters] setupNativeEcomus result=' + success);

        // If native structure was missing or replaced by user block
        if (!success) {
          var mount = document.querySelector('[data-big-filters-mount]') ||
                      document.querySelector('#ProductGridContainer') ||
                      document.querySelector('main, #MainContent, [role="main"]') ||
                      document.body;
          console.info('[big-filters] Using standalone mode, mount=' + (mount ? mount.tagName + '.' + mount.className : 'body'));
          setupStandaloneEcomus(mount);
        }

        render(data);
      })
      .catch(function (err) {
        console.error('[big-filters] Failed to initialize collection filters:', err);
      });
  }

  // Handle browser back / forward navigation
  window.addEventListener('popstate', function () {
    state = readState();
    fetchResults();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
