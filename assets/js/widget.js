/**
 * WooCommerce AI Shopping Assistant — multi-turn widget (float + embed).
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
    } catch (e) {
      /* ignore */
    }
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
    var embedded = mode === 'embedded';
    var open = embedded;
    var busy = false;

    if (cfg.accent) {
      root.style.setProperty('--wcai-accent', cfg.accent);
    }

    var panel = document.createElement('div');
    panel.className = 'wcai-panel' + (embedded ? ' wcai-panel--embedded' : '');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', cfg.i18n.title);
    if (!embedded) {
      panel.hidden = true;
    }

    var voiceSupported =
      !!(window.SpeechRecognition || window.webkitSpeechRecognition);

    panel.innerHTML =
      '<div class="wcai-panel__header">' +
      '<strong class="wcai-panel__title">' +
      escapeHtml(cfg.i18n.title) +
      '</strong>' +
      (embedded
        ? ''
        : '<button type="button" class="wcai-panel__close" aria-label="' +
          escapeHtml(cfg.i18n.closeLabel) +
          '">&times;</button>') +
      '</div>' +
      '<div class="wcai-panel__messages" aria-live="polite"></div>' +
      '<form class="wcai-panel__form">' +
      '<input type="text" class="wcai-panel__input" maxlength="500" placeholder="' +
      escapeHtml(cfg.i18n.placeholder) +
      '" autocomplete="off" />' +
      (voiceSupported
        ? '<button type="button" class="wcai-panel__mic" aria-label="' +
          escapeHtml(cfg.i18n.voice) +
          '" title="' +
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

    root.appendChild(panel);

    var launcher = null;
    if (!embedded) {
      launcher = document.createElement('button');
      launcher.type = 'button';
      launcher.className = 'wcai-launcher';
      launcher.setAttribute('aria-label', cfg.i18n.openLabel);
      launcher.innerHTML = '<span class="wcai-launcher__icon" aria-hidden="true">AI</span>';
      root.appendChild(launcher);
      launcher.addEventListener('click', function () {
        setOpen(!open);
      });
      var closeBtn = panel.querySelector('.wcai-panel__close');
      if (closeBtn) {
        closeBtn.addEventListener('click', function () {
          setOpen(false);
        });
      }
    }

    var messagesEl = panel.querySelector('.wcai-panel__messages');
    var form = panel.querySelector('.wcai-panel__form');
    var input = panel.querySelector('.wcai-panel__input');
    var micBtn = panel.querySelector('.wcai-panel__mic');

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

    if (micBtn && voiceSupported) {
      setupVoice(micBtn, input, function (transcript) {
        if (busy || !transcript) return;
        appendUser(transcript);
        ask(transcript);
      });
    }

    function setOpen(next) {
      open = next;
      panel.hidden = !open;
      if (launcher) {
        launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        launcher.setAttribute('aria-label', open ? cfg.i18n.closeLabel : cfg.i18n.openLabel);
      }
      if (open) input.focus();
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
          if (result.body.session_token) {
            setSessionToken(result.body.session_token);
          }
          if (result.body.query_id) {
            lastQueryId = result.body.query_id;
          }
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
      var text = (data && data.reply_text) || '';
      if (text) appendBot(text);

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

      if (data && data.clarifying_question) {
        appendBot(data.clarifying_question);
      }
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
      if (p.price_html) {
        price.innerHTML = p.price_html;
      } else if (typeof p.price === 'number') {
        price.textContent = cfg.currency + Number(p.price).toFixed(2);
      }

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
      }).catch(function () {
        /* ignore */
      });
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
  }

  function setupVoice(micBtn, input, onResult) {
    var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
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
      micBtn.setAttribute('aria-label', cfg.i18n.listening);
      try {
        rec.start();
      } catch (e) {
        listening = false;
        micBtn.classList.remove('is-listening');
      }
    });

    rec.onresult = function (event) {
      var transcript = '';
      if (event.results && event.results[0] && event.results[0][0]) {
        transcript = event.results[0][0].transcript || '';
      }
      input.value = transcript;
      onResult(transcript.trim());
    };

    rec.onend = function () {
      listening = false;
      micBtn.classList.remove('is-listening');
      micBtn.setAttribute('aria-label', cfg.i18n.voice);
    };

    rec.onerror = function () {
      listening = false;
      micBtn.classList.remove('is-listening');
    };
  }

  function boot() {
    var floating = document.getElementById('wcai-assistant-root');
    if (floating) mount(floating);

    var embeds = document.querySelectorAll('.wcai-embed-root');
    for (var i = 0; i < embeds.length; i++) {
      mount(embeds[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
