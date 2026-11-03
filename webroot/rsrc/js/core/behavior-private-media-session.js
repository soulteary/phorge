/**
 * @provides javelin-behavior-private-media-session
 * @requires javelin-behavior
 */
JX.behavior('private-media-session', function(config) {
  'use strict';
  // A single page-wide flight. Quicksand reinitialization cannot start a loop.
  if (window.__phorgePrivateMediaFlight) { return; }
  var candidates = [], targets = [], known = new WeakMap();
  function path(value) {
    try {
      var uri = new URL(value, window.location.href);
      return uri.origin === config.files && /^\/file\/(data|download|xform)\//.test(uri.pathname) &&
        !uri.search && !uri.hash && uri.pathname.length <= config.max_path_length ? uri.pathname : null;
    } catch (error) { return null; }
  }
  function collect() {
    if (state.stage === 'failed') { return; }
    // Quicksand may discard entire pages. Do not keep their DOM subtrees alive
    // through this document-wide observer and its retry list.
    known = new WeakMap();
    candidates = candidates.filter(function(item) {
      if (!item.node.isConnected) { return false; }
      var current = item.attribute === 'background' ? item.node.style.backgroundImage :
        item.node.getAttribute(item.attribute);
      if (current !== item.value) { return false; }
      var seen = known.get(item.node) || {};
      seen[item.attribute] = item.value; known.set(item.node, seen);
      return true;
    });
    targets = candidates.map(function(item) { return item.target; });
    var nodes = document.querySelectorAll('img[src],audio[src],video[src],source[src],a[href],[style]');
    function add(node, attribute, value, target) {
      var seen = known.get(node) || {};
      if (seen[attribute] === value) { return; }
      seen[attribute] = value; known.set(node, seen);
      candidates.push({node:node, attribute:attribute, value:value, target:target}); targets.push(target);
    }
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i], attribute = node.tagName === 'A' ? 'href' : 'src';
      var value = node.getAttribute(attribute), target = value && path(value);
      if (target) { add(node, attribute, value, target); }
      var background = node.style && node.style.backgroundImage;
      if (background) {
        var pattern = /url\(\s*['"]?([^'"\)]+)['"]?\s*\)/g, match;
        while ((match = pattern.exec(background))) {
          target = path(match[1]);
          if (target) { add(node, 'background', background, target); break; }
        }
      }
    }
    start();
  }
  var state = {stage:'waiting', retries:0};
  window.__phorgePrivateMediaFlight = state;
  var frame, nonce, timeout, exchangeTarget;
  function start() {
    if (state.stage !== 'waiting' || !targets.length) { return; }
    state.stage = 'loading';
    exchangeTarget = targets[0];
    var bytes = crypto.getRandomValues(new Uint8Array(32));
    nonce = Array.from(bytes, function(x) { return x.toString(16).padStart(2, '0'); }).join('');
    frame = document.createElement('iframe');
  frame.hidden = true; frame.title = 'Private media bridge'; frame.setAttribute('aria-hidden', 'true');
    timeout = window.setTimeout(fail, 20000);
    frame.addEventListener('load', function() {
      if (state.stage !== 'loading') { return; }
      state.stage = 'preparing';
      frame.contentWindow.postMessage({type:'media-init', nonce:nonce, path:exchangeTarget}, config.files);
    });
    frame.src = config.files + '/media/session/bridge/'; document.body.appendChild(frame);
  }
  function finish() {
    window.clearTimeout(timeout); window.removeEventListener('message', receive);
    if (frame) { frame.remove(); frame = null; }
  }
  function fail(afterReady) {
    if ((state.stage === 'ready' && afterReady !== true) || state.stage === 'failed') { return; }
    state.stage = 'failed'; finish();
    observer.disconnect(); candidates = []; targets = []; known = new WeakMap();
    var status = document.createElement('div'); status.setAttribute('role', 'status');
    status.textContent = config.failure; document.body.appendChild(status);
  }
  function ready() {
    state.stage = 'ready'; finish();
    // One retry only, with the same immutable resource URI, never a bearer URL.
    if (state.retries++) { return; }
    for (var index = 0; index < candidates.length; index++) {
      var item = candidates[index];
      if (!item.node.isConnected || item.attribute === 'href') { continue; }
      if (item.attribute === 'background' ? item.node.style.backgroundImage !== item.value :
          item.node.getAttribute(item.attribute) !== item.value) { continue; }
      if (item.attribute === 'background') {
        item.node.style.backgroundImage = 'none'; item.node.offsetWidth;
        item.node.style.backgroundImage = item.value;
      } else {
        item.node.setAttribute(item.attribute, item.value);
        var media = item.node.tagName === 'SOURCE' ? item.node.parentNode : item.node;
        if (media && (media.tagName === 'VIDEO' || media.tagName === 'AUDIO')) { media.load(); }
      }
    }
  }
  async function receive(event) {
    if (!frame || event.source !== frame.contentWindow || event.origin !== config.files) { return; }
    var data = event.data;
    if (!data || typeof data !== 'object' || data.nonce !== nonce) { return; }
    try {
      if (state.stage === 'preparing' && data.type === 'media-prepared' &&
          Object.keys(data).sort().join(',') === 'nonce,transaction,type' && /^[a-f0-9]{64}$/.test(data.transaction)) {
        state.stage = 'issuing';
        var body = new URLSearchParams({payload:JSON.stringify({transaction:data.transaction, client_nonce:nonce, path:exchangeTarget})});
        body.set(config.csrf_name, config.csrf);
        var response = await fetch('/auth/media/issue/', {method:'POST', credentials:'same-origin', cache:'no-store', redirect:'error', body:body});
        if (state.stage !== 'issuing') { return; }
        if (!response.ok) { throw Error('media unavailable'); }
        var issued = await response.json();
        if (state.stage !== 'issuing') { return; }
        if (typeof issued.code !== 'string' || !/^[a-f0-9]{64}$/.test(issued.code)) { throw Error('media unavailable'); }
        state.stage = 'redeeming';
        frame.contentWindow.postMessage({type:'media-code', nonce:nonce, code:issued.code}, config.files);
      } else if (state.stage === 'redeeming' && data.type === 'media-ready' && Object.keys(data).sort().join(',') === 'nonce,type') {
        ready();
      } else if (data.type === 'media-failed' && Object.keys(data).sort().join(',') === 'nonce,type') { fail(); }
    } catch (error) { fail(); }
  }
  window.addEventListener('message', receive);
  // Observe the stable document: Quicksand and asynchronously inserted media
  // share the same exchange, including pages which initially have no media.
  var observer = new MutationObserver(collect);
  observer.observe(document.documentElement, {subtree:true, childList:true,
    attributes:true, attributeFilter:['src', 'href', 'style']});
  document.addEventListener('error', function(event) {
    if (state.stage !== 'ready') { return; }
    var node = event.target;
    var value = node && node.getAttribute && node.getAttribute('src');
    // A long-lived page must not loop on an expired credential or new denial.
    // Request genuine login/reload; do not mint repeated exchanges on errors.
    if (value && path(value)) { fail(true); }
  }, true);
  collect();
});
