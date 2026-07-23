/**
 * WooCommerce AI Shopping Assistant widgets:
 * floating | panel | search | button
 */
(function () {
  'use strict';

  if (typeof wcaiWidget === 'undefined') {
    return;
  }

  var cfg = wcaiWidget;
  var SESSION_KEY = 'wcai_session_token';
  var lastQueryId = 0;

  function getSessionToken() {
    try {
      return sessionStorage.getItem(SESSION_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function setSessionToken(token) {
    if (!token) return;
    try {
      sessionStorage.setItem(SESSION_KEY, token);
    } catch (e) {}
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function mount(root) {
    if (!root || root.getAttribute('data-wcai-mounted') === '1') {
      return;
    }
    root.setAttribute('data-wcai-mounted', '1');

    var mode = root.getAttribute('data-wcai-mode') || 'floating';
    var customLabel = root.getAttribute('data-wcai-label') || '';

    if (cfg.accent) {
      root.style.setProperty('--wcai-accent', cfg.accent);
    }

    if (mode === 'search') {
      mountSearch(root, customLabel);
      return;
    }
    if (mode === 'button') {
      mountButton(root, customLabel);
      return;
    }

    // floating | panel (embedded)
    mountChat(root, mode === 'panel' || mode === 'embedded', mode === 'floating');
  }

  function createPanel(embedded, withClose) {
    var voiceSupported = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    var panel = document.createElement('div');
    panel.className = 'wcai-panel' + (embedded ? ' wcai-panel--embedded' : ' wcai-panel--overlay');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', cfg.i18n.title);
    if (!embedded) {
      panel.hidden = true;
    }

    panel.innerHTML =
      '<div class="wcai-panel__header">' +
      '<strong class="wcai-panel__title">' +
      escapeHtml(cfg.i18n.title) +
      '</strong>' +
      (withClose
        ? '<button type="button" class="wcai-panel__close" aria-label="' +
          escapeHtml(cfg.i18n.closeLabel) +
          '">&times;</button>'
        : '') +
      '</div>' +
      '<div class="wcai-panel__messages" aria-live="polite"></div>' +
      '<form class="wcai-panel__form">' +
      '<input type="text" class="wcai-panel__input" maxlength="500" placeholder="' +
      escapeHtml(cfg.i18n.placeholder) +
      '" autocomplete="off" />' +
      (voiceSupported
        ? '<button type="button" class="wcai-panel__mic" aria-label="' +
          escapeHtml(cfg.i18n.voice) +
          '">🎤</button>'
        : '') +
      '<button type="submit" class="wcai-panel__send">' +
      escapeHtml(cfg.i18n.send) +
      '</button>' +
      '</form>' +
      (cfg.hideBranding
        ? ''
        : '<div class="wcai-panel__brand">' + escapeHtml(cfg.i18n.poweredBy) + '</div>');

    return panel;
  }

  function wireChat(panel, options) {
    options = options || {};
    var busy = false;
    var messagesEl = panel.querySelector('.wcai-panel__messages');
    var form = panel.querySelector('.wcai-panel__form');
    var input = panel.querySelector('.wcai-panel__input');
    var micBtn = panel.querySelector('.wcai-panel__mic');
    var closeBtn = panel.querySelector('.wcai-panel__close');

    appendBot(cfg.i18n.empty);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var q = (input.value || '').trim();
      if (!q) return;
      input.value = '';
      appendUser(q);
      ask(q);
    });

    if (micBtn) {
      setupVoice(micBtn, input, function (transcript) {
        if (busy || !transcript) return;
        appendUser(transcript);
        ask(transcript);
      });
    }

    if (closeBtn && options.onClose) {
      closeBtn.addEventListener('click', options.onClose);
    }

    function ask(query) {
      busy = true;
      var thinking = appendBot(cfg.i18n.thinking, true);
      fetch(cfg.restUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce,
        },
        body: JSON.stringify({
          query: query,
          session_token: getSessionToken(),
        }),
      })
        .then(function (res) {
          return res.json().then(function (body) {
            return { ok: res.ok, body: body };
          });
        })
        .then(function (result) {
          thinking.remove();
          if (!result.ok) {
            var msg =
              (result.body &&
                (result.body.message || (result.body.data && result.body.data.message))) ||
              cfg.i18n.error;
            appendBot(msg);
            return;
          }
          if (result.body.session_token) setSessionToken(result.body.session_token);
          if (result.body.query_id) lastQueryId = result.body.query_id;
          renderResponse(result.body);
        })
        .catch(function () {
          thinking.remove();
          appendBot(cfg.i18n.error);
        })
        .finally(function () {
          busy = false;
        });
    }

    function renderResponse(data) {
      if (data && data.reply_text) appendBot(data.reply_text);
      var products = (data && data.products) || [];
      if (products.length) {
        var wrap = document.createElement('div');
        wrap.className = 'wcai-msg wcai-msg--bot wcai-msg--cards';
        products.forEach(function (p) {
          wrap.appendChild(productCard(p));
        });
        messagesEl.appendChild(wrap);
        messagesEl.scrollTop = messagesEl.scrollHeight;
      }
      if (data && data.clarifying_question) appendBot(data.clarifying_question);
    }

    function productCard(p) {
      var a = document.createElement('a');
      a.className = 'wcai-card';
      a.href = p.url || '#';
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.addEventListener('click', function () {
        trackClick(p.id);
      });

      var img = document.createElement('img');
      img.className = 'wcai-card__img';
      img.src = p.image || '';
      img.alt = p.title || '';
      img.loading = 'lazy';

      var body = document.createElement('div');
      body.className = 'wcai-card__body';
      var title = document.createElement('div');
      title.className = 'wcai-card__title';
      title.textContent = p.title || '';
      var price = document.createElement('div');
      price.className = 'wcai-card__price';
      if (p.price_html) price.innerHTML = p.price_html;
      else if (typeof p.price === 'number')
        price.textContent = cfg.currency + Number(p.price).toFixed(2);
      var reason = document.createElement('div');
      reason.className = 'wcai-card__reason';
      reason.textContent = p.reason || '';
      body.appendChild(title);
      body.appendChild(price);
      if (p.reason) body.appendChild(reason);
      a.appendChild(img);
      a.appendChild(body);
      return a;
    }

    function trackClick(productId) {
      if (!cfg.clickUrl || !productId) return;
      fetch(cfg.clickUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce,
        },
        body: JSON.stringify({
          product_id: productId,
          query_id: lastQueryId || 0,
          session_token: getSessionToken(),
        }),
      }).catch(function () {});
    }

    function appendUser(text) {
      var el = document.createElement('div');
      el.className = 'wcai-msg wcai-msg--user';
      el.textContent = text;
      messagesEl.appendChild(el);
      messagesEl.scrollTop = messagesEl.scrollHeight;
      return el;
    }

    function appendBot(text, isThinking) {
      var el = document.createElement('div');
      el.className = 'wcai-msg wcai-msg--bot' + (isThinking ? ' wcai-msg--thinking' : '');
      el.textContent = text;
      messagesEl.appendChild(el);
      messagesEl.scrollTop = messagesEl.scrollHeight;
      return el;
    }

    return {
      ask: ask,
      focus: function () {
        input.focus();
      },
      prefillAndAsk: function (q) {
        if (!q) return;
        appendUser(q);
        ask(q);
      },
      input: input,
    };
  }

  function mountChat(root, embedded, withLauncher) {
    var open = embedded;
    var panel = createPanel(embedded, !embedded || withLauncher);
    root.appendChild(panel);

    var launcher = null;
    if (withLauncher) {
      launcher = document.createElement('button');
      launcher.type = 'button';
      launcher.className = 'wcai-launcher';
      launcher.setAttribute('aria-label', cfg.i18n.openLabel);
      launcher.innerHTML = '<span class="wcai-launcher__icon" aria-hidden="true">AI</span>';
      root.appendChild(launcher);
      launcher.addEventListener('click', function () {
        setOpen(!open);
      });
    }

    var api = wireChat(panel, {
      onClose: function () {
        setOpen(false);
      },
    });

    function setOpen(next) {
      open = next;
      panel.hidden = !open;
      if (launcher) {
        launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      if (open) api.focus();
    }
  }

  function ensureOverlayHost() {
    var host = document.getElementById('wcai-overlay-host');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'wcai-overlay-host';
    host.className = 'wcai-root';
    document.body.appendChild(host);
    return host;
  }

  function openOverlayChat(initialQuery) {
    var host = ensureOverlayHost();
    var existing = host.querySelector('.wcai-panel');
    var backdrop = host.querySelector('.wcai-backdrop');

    if (!existing) {
      backdrop = document.createElement('div');
      backdrop.className = 'wcai-backdrop';
      host.appendChild(backdrop);

      existing = createPanel(false, true);
      existing.classList.add('wcai-panel--modal');
      host.appendChild(existing);

      var api = wireChat(existing, {
        onClose: function () {
          existing.hidden = true;
          backdrop.hidden = true;
        },
      });
      host._wcaiApi = api;

      backdrop.addEventListener('click', function () {
        existing.hidden = true;
        backdrop.hidden = true;
      });
    }

    existing.hidden = false;
    if (backdrop) backdrop.hidden = false;

    var api = host._wcaiApi;
    if (api) {
      api.focus();
      if (initialQuery) {
        api.prefillAndAsk(initialQuery);
      }
    }
  }

  function mountSearch(root, customLabel) {
    var wrap = document.createElement('form');
    wrap.className = 'wcai-searchbar';
    wrap.setAttribute('role', 'search');
    wrap.innerHTML =
      '<input type="search" class="wcai-searchbar__input" maxlength="500" placeholder="' +
      escapeHtml(cfg.i18n.searchPlaceholder) +
      '" autocomplete="off" />' +
      '<button type="submit" class="wcai-searchbar__btn">' +
      escapeHtml(customLabel || cfg.i18n.search) +
      '</button>';
    root.appendChild(wrap);

    wrap.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = wrap.querySelector('.wcai-searchbar__input');
      var q = (input.value || '').trim();
      if (!q) return;
      openOverlayChat(q);
      input.value = '';
    });
  }

  function mountButton(root, customLabel) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'wcai-cta';
    btn.textContent = customLabel || cfg.i18n.askAi;
    root.appendChild(btn);
    btn.addEventListener('click', function () {
      openOverlayChat('');
    });
  }

  function setupVoice(micBtn, input, onResult) {
    var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Recognition) return;
    var rec = new Recognition();
    rec.interimResults = false;
    rec.continuous = false;
    var listening = false;

    micBtn.addEventListener('click', function () {
      if (listening) {
        rec.stop();
        return;
      }
      listening = true;
      micBtn.classList.add('is-listening');
      try {
        rec.start();
      } catch (e) {
        listening = false;
        micBtn.classList.remove('is-listening');
      }
    });

    rec.onresult = function (event) {
      var transcript =
        event.results && event.results[0] && event.results[0][0]
          ? event.results[0][0].transcript || ''
          : '';
      input.value = transcript;
      onResult(transcript.trim());
    };
    rec.onend = function () {
      listening = false;
      micBtn.classList.remove('is-listening');
    };
    rec.onerror = function () {
      listening = false;
      micBtn.classList.remove('is-listening');
    };
  }

  function boot() {
    var nodes = document.querySelectorAll(
      '#wcai-assistant-root, .wcai-root[data-wcai-mode], .wcai-embed-root'
    );
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n.getAttribute('data-wcai-mode') && n.classList.contains('wcai-embed-root')) {
        n.setAttribute('data-wcai-mode', 'panel');
      }
      mount(n);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
