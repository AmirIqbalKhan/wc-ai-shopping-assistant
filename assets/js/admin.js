/**
 * Admin hub: provider cascading, test connection, reindex polling.
 */
(function () {
  'use strict';
  if (typeof wcaiAdmin === 'undefined') return;

  var bar = document.getElementById('wcai-reindex-bar');
  var label = document.getElementById('wcai-reindex-label');
  var statusVal = document.getElementById('wcai-status-reindex');
  var statusMeta = document.getElementById('wcai-status-reindex-meta');
  var pollTimer = null;

  function applyState(data) {
    var total = data.total || 0;
    var done = data.done || 0;
    var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    var status = data.status || 'idle';
    if (bar) bar.style.width = pct + '%';
    if (label) {
      label.textContent =
        'Progress: ' + done + ' / ' + total + ' (' + status + ')';
    }
    if (statusVal) statusVal.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    if (statusMeta) statusMeta.textContent = done + ' / ' + total;
  }

  function poll() {
    fetch(wcaiAdmin.statusUrl, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': wcaiAdmin.nonce },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        applyState(data);
        if (data.status === 'running' || data.status === 'queued') {
          pollTimer = setTimeout(poll, 2500);
        }
      })
      .catch(function () {});
  }

  if (bar && label) {
    if (wcaiAdmin.reindexState) applyState(wcaiAdmin.reindexState);
    poll();
  }

  // After redirect with queued flag, keep polling.
  if (window.location.search.indexOf('wcai_reindex=queued') !== -1 && !pollTimer) {
    poll();
  }

  var providers = wcaiAdmin.providers || {};
  var providerEl = document.getElementById('wcai_provider');
  var baseEl = document.getElementById('wcai_api_base');
  var keyHint = document.getElementById('wcai_key_hint');
  var chatSelect = document.getElementById('wcai_chat_model_select');
  var chatInput = document.getElementById('wcai_chat_model');
  var embSelect = document.getElementById('wcai_embedding_model_select');
  var embInput = document.getElementById('wcai_embedding_model');

  function fillSelect(select, map, current, allowCustom) {
    if (!select) return;
    select.innerHTML = '';
    var ids = Object.keys(map || {});
    ids.forEach(function (id) {
      var opt = document.createElement('option');
      opt.value = id;
      opt.textContent = map[id] + ' (' + id + ')';
      select.appendChild(opt);
    });
    if (allowCustom) {
      var custom = document.createElement('option');
      custom.value = '__custom__';
      custom.textContent = '— Custom / other —';
      select.appendChild(custom);
    }
    if (current && map && map[current]) {
      select.value = current;
    } else if (current) {
      select.value = '__custom__';
    } else if (ids.length) {
      select.value = ids[0];
    }
  }

  function applyProvider(updateDefaults) {
    if (!providerEl || !chatSelect || !chatInput) return;
    var id = providerEl.value;
    var meta = providers[id] || {};
    if (keyHint) {
      keyHint.textContent = meta.key_hint || '';
    }
    if (updateDefaults && baseEl && meta.api_base) {
      baseEl.value = meta.api_base;
    }
    fillSelect(chatSelect, meta.chat_models || {}, chatInput.value, true);
    if (updateDefaults && meta.default_chat && (!chatInput.value || chatSelect.value === '__custom__')) {
      chatInput.value = meta.default_chat;
      chatSelect.value = meta.default_chat;
    }
    if (chatSelect.value && chatSelect.value !== '__custom__') {
      chatInput.value = chatSelect.value;
    }

    fillSelect(embSelect, meta.embedding_models || {}, embInput ? embInput.value : '', true);
    if (embInput) {
      if (updateDefaults && meta.default_embedding) {
        embInput.value = meta.default_embedding;
        if (meta.embedding_models && meta.embedding_models[meta.default_embedding]) {
          embSelect.value = meta.default_embedding;
        }
      }
      if (embSelect.value && embSelect.value !== '__custom__') {
        embInput.value = embSelect.value;
      }
      embSelect.disabled = !meta.embedding_models || !Object.keys(meta.embedding_models).length;
    }
  }

  if (providerEl && chatSelect && chatInput) {
    chatSelect.addEventListener('change', function () {
      if (chatSelect.value !== '__custom__') {
        chatInput.value = chatSelect.value;
      }
    });

    if (embSelect && embInput) {
      embSelect.addEventListener('change', function () {
        if (embSelect.value !== '__custom__') {
          embInput.value = embSelect.value;
        }
      });
    }

    providerEl.addEventListener('change', function () {
      applyProvider(true);
    });

    applyProvider(false);
  }

  var testBtn = document.getElementById('wcai-test-connection');
  var testOut = document.getElementById('wcai-test-result');
  if (testBtn && wcaiAdmin.testUrl) {
    testBtn.addEventListener('click', function () {
      testBtn.disabled = true;
      if (testOut) {
        testOut.className = 'wcai-callout is-busy';
        testOut.textContent = (wcaiAdmin.i18n && wcaiAdmin.i18n.testing) || 'Testing…';
      }
      fetch(wcaiAdmin.testUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-WP-Nonce': wcaiAdmin.nonce,
          'Content-Type': 'application/json',
        },
        body: '{}',
      })
        .then(function (r) {
          return r.json().then(function (body) {
            return { ok: r.ok, body: body };
          });
        })
        .then(function (res) {
          if (!testOut) return;
          if (res.ok && res.body && res.body.ok) {
            testOut.className = 'wcai-callout is-ok';
            testOut.textContent =
              ((wcaiAdmin.i18n && wcaiAdmin.i18n.testOk) || 'Connection OK') +
              ' — ' +
              (res.body.provider || '') +
              ' @ ' +
              (res.body.api_base || '');
          } else {
            var msg =
              (res.body && (res.body.message || (res.body.data && res.body.data.message))) ||
              ((wcaiAdmin.i18n && wcaiAdmin.i18n.testFail) || 'Connection failed');
            testOut.className = 'wcai-callout is-err';
            testOut.textContent = msg;
          }
        })
        .catch(function () {
          if (testOut) {
            testOut.className = 'wcai-callout is-err';
            testOut.textContent = (wcaiAdmin.i18n && wcaiAdmin.i18n.testFail) || 'Connection failed';
          }
        })
        .finally(function () {
          testBtn.disabled = false;
        });
    });
  }
})();
