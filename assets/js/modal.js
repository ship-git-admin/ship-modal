(function () {
  'use strict';

  var config = window.ShipModalConfig || {};
  var activeModal = null;
  var previousFocus = null;
  var pendingModals = [];
  var gtagScriptRequested = false;
  var gtagConfiguredIds = {};
  var backgroundInertState = [];

  function localDateKey() {
    var date = new Date();
    var month = String(date.getMonth() + 1);
    var day = String(date.getDate());
    return date.getFullYear() + '-' + (month.length < 2 ? '0' + month : month) + '-' + (day.length < 2 ? '0' + day : day);
  }

  function isWithinSchedule(modal) {
    if (!modal || modal.dataset.preview === '1') return true;
    var now = Date.now();
    var start = parseInt(modal.dataset.scheduleStart || '0', 10);
    var end = parseInt(modal.dataset.scheduleEnd || '0', 10);
    return (!start || now >= start) && (!end || now <= end);
  }

  function setTriggerVisibility(modal, visible) {
    document.querySelectorAll('[data-ship-modal-target]').forEach(function (trigger) {
      if (trigger.dataset.shipModalTarget === modal.id) trigger.hidden = !visible;
    });
  }

  function setTriggerExpanded(modal, expanded) {
    document.querySelectorAll('[data-ship-modal-target]').forEach(function (trigger) {
      if (trigger.dataset.shipModalTarget === modal.id) trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
  }

  function setBackgroundInert(modal) {
    restoreBackgroundInert();
    if (!modal || !document.body) return;

    // ショートコードで本文内に置かれる場合もあるため、モーダルを含む
    // body直下の祖先だけは対象外にする。その他の背景要素は支援技術と
    // キーボードから一時的に隠し、モーダル内のフォーカストラップを補完する。
    var modalRoot = modal;
    while (modalRoot.parentElement && modalRoot.parentElement !== document.body) {
      modalRoot = modalRoot.parentElement;
    }
    Array.prototype.forEach.call(document.body.children, function (element) {
      if (element === modalRoot || /^(SCRIPT|STYLE|LINK|META|NOSCRIPT|TEMPLATE)$/i.test(element.tagName)) return;
      var supportsInert = 'inert' in element;
      backgroundInertState.push({
        element: element,
        hadInert: element.hasAttribute('inert'),
        inertValue: supportsInert ? !!element.inert : element.hasAttribute('inert'),
        hadAriaHidden: element.hasAttribute('aria-hidden'),
        ariaHiddenValue: element.getAttribute('aria-hidden'),
        supportsInert: supportsInert
      });
      if (supportsInert) element.inert = true;
      else element.setAttribute('inert', '');
      element.setAttribute('aria-hidden', 'true');
    });
  }

  function restoreBackgroundInert() {
    backgroundInertState.forEach(function (state) {
      var element = state.element;
      if (!element || !document.documentElement.contains(element)) return;
      if (state.supportsInert) {
        element.inert = state.inertValue;
      } else if (state.hadInert) {
        element.setAttribute('inert', '');
      } else {
        element.removeAttribute('inert');
      }
      if (state.hadAriaHidden) element.setAttribute('aria-hidden', state.ariaHiddenValue);
      else element.removeAttribute('aria-hidden');
    });
    backgroundInertState = [];
  }

  function storageKey(modal) {
    return 'ship-modal-' + (modal.dataset.postId || modal.id);
  }

  function canShow(modal) {
    if (modal && modal.dataset.preview === '1') return true;
    if (!isWithinSchedule(modal)) return false;
    var frequency = modal.dataset.frequency || 'session';
    if (frequency === 'always') return true;
    var store = frequency === 'session' ? window.sessionStorage : window.localStorage;
    try {
      var value = store.getItem(storageKey(modal));
      if (!value) return true;
      if (frequency === 'day') return value !== localDateKey();
      return false;
    } catch (e) {
      return true;
    }
  }

  function markShown(modal) {
    if (modal && modal.dataset.preview === '1') return;
    var frequency = modal.dataset.frequency || 'session';
    if (frequency === 'always') return;
    var store = frequency === 'session' ? window.sessionStorage : window.localStorage;
    try {
      store.setItem(storageKey(modal), frequency === 'day' ? localDateKey() : '1');
    } catch (e) { /* storage disabled */ }
    if (modal.dataset.trigger === 'manual' && !canShow(modal)) setTriggerVisibility(modal, false);
  }

  function randomEventId() {
    // 1つのUIイベントを再送する場合も同じIDを使えるよう、trackServer内で1回だけ生成する。
    // cryptoが使えない古いブラウザでは、時刻＋乱数のフォールバックを使う。
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        var values = new Uint32Array(4);
        window.crypto.getRandomValues(values);
        return Array.prototype.map.call(values, function (value) { return value.toString(16); }).join('-');
      }
    } catch (e) { /* fall through to the non-cryptographic fallback */ }
    return String(Date.now()) + '-' + String(Math.random()).slice(2);
  }

  function hydrateImages(root) {
    if (!root) return;
    root.querySelectorAll('[data-ship-modal-srcset]').forEach(function (source) {
      if (!source.getAttribute('srcset')) source.setAttribute('srcset', source.getAttribute('data-ship-modal-srcset'));
      source.removeAttribute('data-ship-modal-srcset');
    });
    root.querySelectorAll('img[data-ship-modal-src]').forEach(function (image) {
      var src = image.getAttribute('data-ship-modal-src');
      var srcset = image.getAttribute('data-ship-modal-srcset');
      var sizes = image.getAttribute('data-ship-modal-sizes');
      if (src) image.setAttribute('src', src);
      if (srcset) image.setAttribute('srcset', srcset);
      if (sizes) image.setAttribute('sizes', sizes);
      image.removeAttribute('data-ship-modal-src');
      image.removeAttribute('data-ship-modal-srcset');
      image.removeAttribute('data-ship-modal-sizes');
      image.setAttribute('loading', 'eager');
    });
  }

  function trackServer(modal, event) {
    if (!modal || !config.ajaxUrl || (!modal.dataset.eventToken && !config.nonce)) return;
    var eventId = randomEventId();
    var payload = [
      'action=ship_modal_event',
      'token=' + encodeURIComponent(modal.dataset.eventToken || ''),
      'modal_id=' + encodeURIComponent(modal.dataset.postId || ''),
      'event=' + encodeURIComponent(event),
      'event_id=' + encodeURIComponent(eventId)
    ];
    if (config.nonce) payload.push('nonce=' + encodeURIComponent(config.nonce));
    payload = payload.join('&');
    if (navigator.sendBeacon) {
      try {
        var beaconBody = window.Blob ? new Blob([payload], { type: 'application/x-www-form-urlencoded;charset=UTF-8' }) : payload;
        if (navigator.sendBeacon(config.ajaxUrl, beaconBody)) return;
      } catch (e) { /* fall through to fetch/XHR */ }
    }
    if (window.fetch) {
      try {
        window.fetch(config.ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: payload,
          credentials: 'same-origin',
          keepalive: true
        });
        return;
      } catch (e) { /* fall through to XHR */ }
    }
    try {
      var request = new XMLHttpRequest();
      request.open('POST', config.ajaxUrl, true);
      request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      request.send(payload);
    } catch (e) { /* analytics must never block the UI */ }
  }

  function currentPage(modal) {
    var pages = modal ? modal.querySelector('.ship-modal__pages') : null;
    var current = pages ? pages.querySelector('.ship-modal__page.is-active') : null;
    var index = current ? parseInt(current.dataset.shipModalPagePanel, 10) : 0;
    var count = pages ? parseInt(pages.dataset.shipModalPageCount || '0', 10) : 0;
    return { index: isNaN(index) ? 0 : index, count: isNaN(count) ? 0 : count };
  }

  function analyticsPayload(modal, eventName, details) {
    if (!modal) return;
    var page = currentPage(modal);
    var payload = {
      event: eventName,
      ship_modal_id: modal.dataset.postId || '',
      ship_modal_title: modal.dataset.modalTitle || '',
      ship_modal_content_type: modal.dataset.contentType || '',
      ship_modal_design: modal.dataset.design || '',
      ship_modal_trigger: modal.dataset.trigger || '',
      ship_modal_frequency: modal.dataset.frequency || '',
      ship_modal_page: page.index + 1,
      ship_modal_page_count: page.count
    };
    Object.keys(details || {}).forEach(function (key) { payload[key] = details[key]; });
    return payload;
  }

  function analyticsUrl(url) {
    var value = String(url || '');
    if (!value) return '';
    if (!/^https?:\/\//i.test(value)) {
      var scheme = value.match(/^([a-z][a-z0-9+.-]*:)/i);
      return scheme ? scheme[1] : value.replace(/[?#].*$/, '');
    }
    try {
      var anchor = document.createElement('a');
      anchor.href = value;
      return anchor.protocol + '//' + anchor.host + anchor.pathname;
    } catch (e) {
      return value.replace(/[?#].*$/, '');
    }
  }

  function pushDataLayer(payload) {
    if (!payload) return;
    if (!window.dataLayer) window.dataLayer = [];
    if (typeof window.dataLayer.push !== 'function') return;
    try {
      window.dataLayer.push(payload);
    } catch (e) { /* third-party GTM code must never block the UI */ }
  }

  function ensureGtag() {
    if (config.ga4Enabled === false) return false;
    var measurementId = String(config.ga4MeasurementId || '').trim();
    var hadGtag = typeof window.gtag === 'function';
    if (!hadGtag && !measurementId) return false;
    if (!hadGtag) {
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
    }
    if (measurementId && !gtagConfiguredIds[measurementId]) {
      try {
        window.gtag('config', measurementId, { send_page_view: false });
        gtagConfiguredIds[measurementId] = true;
      } catch (e) { /* analytics must never block the UI */ }
    }
    if (!hadGtag && measurementId && !gtagScriptRequested) {
      gtagScriptRequested = true;
      if (!document.getElementById('ship-modal-ga4-script')) {
        var script = document.createElement('script');
        script.id = 'ship-modal-ga4-script';
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(measurementId);
        (document.head || document.documentElement).appendChild(script);
      }
    }
    return typeof window.gtag === 'function';
  }

  function pushGtag(payload) {
    if (!payload || !ensureGtag()) return false;
    var parameters = {};
    Object.keys(payload).forEach(function (key) {
      if (key !== 'event') parameters[key] = payload[key];
    });
    var measurementId = String(config.ga4MeasurementId || '').trim();
    if (measurementId) parameters.send_to = measurementId;
    try {
      window.gtag('event', payload.event, parameters);
      return true;
    } catch (e) {
      return false;
    }
  }

  function sendAnalytics(modal, eventName, details) {
    var payload = analyticsPayload(modal, eventName, details);
    if (!payload) return;
    var transport = config.ga4Transport || 'auto';
    if (transport !== 'datalayer' && pushGtag(payload)) return;
    pushDataLayer(payload);
  }

  function track(modal, event, details) {
    if (modal && modal.dataset.preview === '1') return;
    try { trackServer(modal, event); } catch (e) { /* analytics must never block the UI */ }
    var eventName = event === 'impression' ? 'ship_modal_impression' : event === 'click' ? 'ship_modal_click' : event === 'close' ? 'ship_modal_close' : 'ship_modal_page_view';
    sendAnalytics(modal, eventName, details);
  }

  function focusable(modal) {
    var candidates = modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    return Array.prototype.filter.call(candidates, function (element) {
      return !element.closest('[hidden]') && element.getClientRects().length > 0;
    });
  }

  function openModal(modal) {
    if (!modal || modal.classList.contains('is-open') || modal.classList.contains('is-closing') || !canShow(modal)) return;
    if (activeModal === modal) return;
    if (activeModal && activeModal !== modal) {
      if (pendingModals.indexOf(modal) === -1) pendingModals.push(modal);
      return;
    }
    previousFocus = document.activeElement;
    activeModal = modal;
    modal.hidden = false;
    setTriggerExpanded(modal, true);
    document.body.classList.add('ship-modal-open');
    showPage(modal, 0, false);
    var content = modal.querySelector('.ship-modal__content');
    var pages = modal.querySelector('.ship-modal__pages');
    hydrateImages(pages ? pages.querySelector('.ship-modal__page.is-active') : content);
    if (content) content.scrollTop = 0;
    window.requestAnimationFrame(function () {
      if (activeModal !== modal || modal.hidden || modal.classList.contains('is-closing')) return;
      modal.classList.add('is-open');
      var close = modal.querySelector('.ship-modal__close');
      var elements = focusable(modal);
      var dialog = modal.querySelector('.ship-modal__dialog');
      if (close) close.focus();
      else if (elements.length) elements[0].focus();
      else if (dialog) dialog.focus();
      setBackgroundInert(modal);
    });
    markShown(modal);
    track(modal, 'impression');
    if (modal.querySelector('.ship-modal__pages')) track(modal, 'page_view', { ship_modal_action: 'page_view' });
  }

  function closeModal(modal, trackClose) {
    if (!modal || modal.hidden || modal.classList.contains('is-closing')) return;
    modal.classList.add('is-closing');
    modal.classList.remove('is-open');
    setTriggerExpanded(modal, false);
    if (trackClose !== false) track(modal, 'close');
    window.setTimeout(function () {
      modal.hidden = true;
      modal.classList.remove('is-open');
      modal.classList.remove('is-closing');
      if (activeModal === modal) activeModal = null;
      restoreBackgroundInert();
      if (previousFocus && previousFocus.focus && document.documentElement.contains(previousFocus)) previousFocus.focus();
      previousFocus = null;
      while (pendingModals.length && !canShow(pendingModals[0])) pendingModals.shift();
      if (pendingModals.length) openModal(pendingModals.shift());
      else document.body.classList.remove('ship-modal-open');
    }, 220);
  }

  function showPage(modal, index, trackChange) {
    var container = modal.querySelector('.ship-modal__pages');
    if (!container) return;
    var panels = Array.prototype.slice.call(container.querySelectorAll('[data-ship-modal-page-panel]'));
    if (!panels.length) return;
    var currentPanel = container.querySelector('.ship-modal__page.is-active');
    var previousIndex = currentPanel ? parseInt(currentPanel.dataset.shipModalPagePanel, 10) : 0;
    var nextIndex = Math.max(0, Math.min(panels.length - 1, index));
    panels.forEach(function (panel, panelIndex) {
      var active = panelIndex === nextIndex;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    container.querySelectorAll('[data-ship-modal-page]').forEach(function (button) {
      var active = parseInt(button.dataset.shipModalPage, 10) === nextIndex;
      button.classList.toggle('is-active', active);
      if (active) button.setAttribute('aria-current', 'true');
      else button.removeAttribute('aria-current');
    });
    var previous = container.querySelector('[data-ship-modal-page-prev]');
    var next = container.querySelector('[data-ship-modal-page-next]');
    if (previous) previous.disabled = nextIndex === 0;
    if (next) next.disabled = nextIndex === panels.length - 1;
    var status = container.querySelector('[data-ship-modal-page-status]');
    if (status) status.textContent = (nextIndex + 1) + ' / ' + panels.length + 'ページ';
    hydrateImages(panels[nextIndex]);
    if (trackChange !== false && nextIndex !== previousIndex) track(modal, 'page_view', { ship_modal_action: 'page_view' });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-ship-modal-target]');
    if (trigger) {
      event.preventDefault();
      openModal(document.getElementById(trigger.dataset.shipModalTarget));
      return;
    }
    var close = event.target.closest('[data-ship-modal-close]');
    if (close) {
      var modal = close.closest('.ship-modal');
      if (modal && (close.classList.contains('ship-modal__backdrop') ? modal.dataset.closeOverlay === '1' : true)) closeModal(modal);
      return;
    }
    var link = event.target.closest('.ship-modal a');
    if (link) {
      var modal = link.closest('.ship-modal');
      var action = link.dataset.shipModalAction || 'link';
      track(modal, 'click', {
        ship_modal_action: action,
        ship_modal_label: link.dataset.shipModalLabel || (link.textContent || '').trim().slice(0, 80),
        ship_modal_url: analyticsUrl(link.href || '')
      });
    }
  });

  document.addEventListener('click', function (event) {
    var pageButton = event.target.closest('[data-ship-modal-page], [data-ship-modal-page-prev], [data-ship-modal-page-next]');
    if (!pageButton) return;
    event.preventDefault();
    var modal = pageButton.closest('.ship-modal');
    var container = pageButton.closest('.ship-modal__pages');
    if (!modal || !container) return;
    var current = container.querySelector('.ship-modal__page.is-active');
    var currentIndex = current ? parseInt(current.dataset.shipModalPagePanel, 10) : 0;
    if (pageButton.hasAttribute('data-ship-modal-page-prev')) currentIndex--;
    else if (pageButton.hasAttribute('data-ship-modal-page-next')) currentIndex++;
    else currentIndex = parseInt(pageButton.dataset.shipModalPage, 10);
    showPage(modal, currentIndex);
  });

  document.addEventListener('keydown', function (event) {
    if (!activeModal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(activeModal);
    }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      var editingTarget = event.target && event.target.closest ? event.target.closest('input, textarea, select, [contenteditable="true"]') : null;
      if (editingTarget) return;
      var pages = activeModal.querySelector('.ship-modal__pages');
      if (pages) {
        event.preventDefault();
        var current = pages.querySelector('.ship-modal__page.is-active');
        var currentIndex = current ? parseInt(current.dataset.shipModalPagePanel, 10) : 0;
        showPage(activeModal, currentIndex + (event.key === 'ArrowRight' ? 1 : -1));
      }
    }
    if (event.key === 'Tab') {
      var elements = focusable(activeModal);
      if (!elements.length) {
        event.preventDefault();
        var dialog = activeModal.querySelector('.ship-modal__dialog');
        if (dialog) dialog.focus();
        return;
      }
      var first = elements[0];
      var last = elements[elements.length - 1];
      if (!activeModal.contains(document.activeElement)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
        return;
      }
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  function bindScrollTrigger(modal) {
    var threshold = Math.max(10, Math.min(95, parseInt(modal.dataset.scrollThreshold || '50', 10)));
    var fired = false;
    function evaluate() {
      if (fired) return;
      var documentHeight = Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0);
      var scrollable = documentHeight - window.innerHeight;
      var progress = scrollable > 0 ? (window.scrollY / scrollable) * 100 : 100;
      if (progress < threshold) return;
      fired = true;
      window.removeEventListener('scroll', evaluate);
      openModal(modal);
    }
    window.addEventListener('scroll', evaluate, { passive: true });
    evaluate();
  }

  function bindExitIntentTrigger(modal) {
    var hasFinePointer = window.matchMedia && (window.matchMedia('(pointer: fine)').matches || window.matchMedia('(hover: hover)').matches);
    if (!hasFinePointer || window.innerWidth < 768) return;
    var fired = false;
    function evaluate(event) {
      if (fired || event.clientY > 10 || event.relatedTarget) return;
      fired = true;
      document.removeEventListener('mouseout', evaluate);
      openModal(modal);
    }
    document.addEventListener('mouseout', evaluate);
  }

  function runAt(timestamp, callback) {
    var remaining = timestamp - Date.now();
    if (remaining <= 0) {
      callback();
      return;
    }
    window.setTimeout(function () { runAt(timestamp, callback); }, Math.min(remaining, 2147483647));
  }

  function initializeModal(modal) {
    if (modal.dataset.shipModalInitialized === '1') return;
    modal.dataset.shipModalInitialized = '1';
    setTriggerVisibility(modal, isWithinSchedule(modal) && canShow(modal));
    var initializeTrigger = function () {
      if (!canShow(modal)) {
        setTriggerVisibility(modal, false);
        return;
      }
      setTriggerVisibility(modal, true);
      var trigger = modal.dataset.trigger || 'auto';
      if (trigger === 'auto') {
        var delay = Math.max(0, parseInt(modal.dataset.delay || '0', 10)) * 1000;
        window.setTimeout(function () { openModal(modal); }, delay);
      } else if (trigger === 'scroll') {
        bindScrollTrigger(modal);
      } else if (trigger === 'exit_intent') {
        bindExitIntentTrigger(modal);
      }
    };
    var start = parseInt(modal.dataset.scheduleStart || '0', 10);
    if (start && start > Date.now()) runAt(start, initializeTrigger);
    else initializeTrigger();
    var end = parseInt(modal.dataset.scheduleEnd || '0', 10);
    if (end && end > Date.now()) {
      runAt(end + 1, function () {
        setTriggerVisibility(modal, false);
        pendingModals = pendingModals.filter(function (pending) { return pending !== modal; });
        if (activeModal === modal) closeModal(modal, false);
      });
    }
  }

  function initialize() {
    document.querySelectorAll('.ship-modal').forEach(function (modal) {
      initializeModal(modal);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();
