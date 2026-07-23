/**
 * Admin: reindex progress + provider/model cascading dropdowns.
 */
(function () {
  'use strict';
  if (typeof wcaiAdmin === 'undefined') return;

  var bar = document.getElementById('wcai-reindex-bar');
  var label = document.getElementById('wcai-reindex-label');
  if (bar && label) {
    function poll() {
      fetch(wcaiAdmin.statusUrl, {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': wcaiAdmin.nonce },
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          var total = data.total || 0;
          var done = data.done || 0;
          var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
          bar.style.width = pct + '%';
          label.textContent =
            'Progress: ' + done + ' / ' + total + ' (' + (data.status || 'idle') + ')';
          if (data.status === 'running') {
            setTimeout(poll, 3000);
          }
        })
        .catch(function () {});
    }
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

  if (!providerEl || !chatSelect || !chatInput) return;

  function fillSelect(select, map, current, allowCustom) {
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

  var testBtn = document.getElementById('wcai-test-connection');
  var testOut = document.getElementById('wcai-test-result');
  if (testBtn && wcaiAdmin.testUrl) {
    testBtn.addEventListener('click', function () {
      if (testOut) testOut.textContent = 'Testing…';
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
            testOut.textContent =
              'OK — ' + (res.body.provider || '') + ' @ ' + (res.body.api_base || '');
          } else {
            var msg =
              (res.body && (res.body.message || (res.body.data && res.body.data.message))) ||
              'Connection failed';
            testOut.textContent = msg;
          }
        })
        .catch(function () {
          if (testOut) testOut.textContent = 'Request failed';
        });
    });
  }
})();
