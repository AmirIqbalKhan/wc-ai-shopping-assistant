/**
 * ShopAsk AI – Shopping Assistant for WooCommerce widgets:
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
  var reduceMotion =
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var MIC_SVG =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z"/></svg>';
  var CLOSE_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.71L12 12.01l-6.3-6.3-1.4 1.42 6.29 6.29-6.3 6.3 1.42 1.4L12 14.42l6.29 6.3 1.4-1.42-6.28-6.29 6.3-6.3z"/></svg>';
  var LAUNCHER_SVG =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5C4 5.12 5.12 4 6.5 4h7C15.88 4 17 5.12 17 6.5V8h.5A2.5 2.5 0 0 1 20 10.5v6A3.5 3.5 0 0 1 16.5 20h-9A3.5 3.5 0 0 1 4 16.5v-10zm4.5 7.25a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5zm3.5 0a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5zm3.5 0a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5z"/></svg>';

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

  function chips() {
    return (cfg.suggestions && cfg.suggestions.length
      ? cfg.suggestions
      : [
          cfg.i18n.chipUnder || 'Under $50',
          cfg.i18n.chipGift || 'Gift ideas',
          cfg.i18n.chipPopular || 'Bestsellers',
          cfg.i18n.chipNew || 'Something new',
        ]
    ).slice(0, 4);
  }

  function showToast(msg) {
    var existing = document.querySelector('.wcai-toast');
    if (existing) existing.remove();
    var t = document.createElement('div');
    t.className = 'wcai-toast';
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () {
      if (t.parentNode) t.parentNode.removeChild(t);
    }, 2200);
  }

  function lockBody(on) {
    document.body.classList.toggle('wcai-lock', !!on);
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

    mountChat(root, mode === 'panel' || mode === 'embedded', mode === 'floating');
  }

  function createPanel(embedded, withClose) {
    var voiceSupported = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    var panel = document.createElement('div');
    panel.className = 'wcai-panel' + (embedded ? ' wcai-panel--embedded' : ' wcai-panel--overlay');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', embedded ? 'false' : 'true');
    panel.setAttribute('aria-label', cfg.i18n.title);
    if (!embedded) {
      panel.hidden = true;
    }

    panel.innerHTML =
      '<div class="wcai-panel__handle" aria-hidden="true"></div>' +
      '<div class="wcai-panel__header">' +
      '<strong class="wcai-panel__title">' +
      escapeHtml(cfg.i18n.title) +
      '</strong>' +
      (withClose
        ? '<button type="button" class="wcai-panel__close" aria-label="' +
          escapeHtml(cfg.i18n.closeLabel) +
          '">' +
          CLOSE_SVG +
          '</button>'
        : '') +
      '</div>' +
      '<div class="wcai-panel__messages" aria-live="polite"></div>' +
      '<form class="wcai-panel__form' +
      (voiceSupported ? '' : ' wcai-panel__form--no-mic') +
      '">' +
      '<input type="text" class="wcai-panel__input" maxlength="500" placeholder="' +
      escapeHtml(cfg.i18n.placeholder) +
      '" aria-label="' +
      escapeHtml(cfg.i18n.placeholder) +
      '" autocomplete="off" />' +
      (voiceSupported
        ? '<button type="button" class="wcai-panel__mic" aria-label="' +
          escapeHtml(cfg.i18n.voice) +
          '">' +
          MIC_SVG +
          '</button>'
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
    var started = false;
    var messagesEl = panel.querySelector('.wcai-panel__messages');
    var form = panel.querySelector('.wcai-panel__form');
    var input = panel.querySelector('.wcai-panel__input');
    var sendBtn = panel.querySelector('.wcai-panel__send');
    var micBtn = panel.querySelector('.wcai-panel__mic');
    var closeBtn = panel.querySelector('.wcai-panel__close');

    renderEmpty();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var q = (input.value || '').trim();
      if (!q) return;
      input.value = '';
      ensureChatStarted();
      appendUser(q);
      ask(q);
    });

    if (micBtn) {
      setupVoice(micBtn, input, function (transcript) {
        if (busy || !transcript) return;
        ensureChatStarted();
        appendUser(transcript);
        ask(transcript);
      });
    }

    if (closeBtn && options.onClose) {
      closeBtn.addEventListener('click', options.onClose);
    }

    function ensureChatStarted() {
      if (started) return;
      started = true;
      var empty = messagesEl.querySelector('.wcai-empty');
      if (empty) empty.remove();
    }

    function renderEmpty() {
      messagesEl.innerHTML = '';
      started = false;
      var wrap = document.createElement('div');
      wrap.className = 'wcai-empty';
      wrap.innerHTML =
        '<h3 class="wcai-empty__title">' +
        escapeHtml(cfg.i18n.title) +
        '</h3>' +
        '<p class="wcai-empty__text">' +
        escapeHtml(cfg.i18n.empty) +
        '</p>' +
        '<div class="wcai-chips"></div>';
      var chipRow = wrap.querySelector('.wcai-chips');
      chips().forEach(function (label) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'wcai-chip';
        b.textContent = label;
        b.addEventListener('click', function () {
          if (busy) return;
          ensureChatStarted();
          appendUser(label);
          ask(label);
        });
        chipRow.appendChild(b);
      });
      messagesEl.appendChild(wrap);
    }

    function setBusy(next) {
      busy = next;
      if (sendBtn) sendBtn.disabled = next;
      if (input) input.disabled = next;
    }

    function ask(query) {
      setBusy(true);
      var thinking = appendThinking();
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
          setBusy(false);
        });
    }

    function renderResponse(data) {
      if (data && data.reply_text) appendBot(data.reply_text);
      var products = (data && data.products) || [];
      if (products.length) {
        var wrap = document.createElement('div');
        wrap.className = 'wcai-msg wcai-msg--bot wcai-msg--cards';
        products.forEach(function (p, i) {
          var card = productCard(p);
          if (!reduceMotion) {
            card.style.animationDelay = i * 0.06 + 's';
          }
          wrap.appendChild(card);
        });
        messagesEl.appendChild(wrap);
        messagesEl.scrollTop = messagesEl.scrollHeight;
      }
      if (data && data.clarifying_question) appendBot(data.clarifying_question);
    }

    function productCard(p) {
      var card = document.createElement('div');
      card.className = 'wcai-card';

      var media = document.createElement('div');
      media.className = 'wcai-card__media';
      var img = document.createElement('img');
      img.className = 'wcai-card__img';
      img.src = p.image || '';
      img.alt = p.title || '';
      img.loading = 'lazy';
      media.appendChild(img);

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

      var actions = document.createElement('div');
      actions.className = 'wcai-card__actions';

      if (p.add_to_cart) {
        var atc = document.createElement('button');
        atc.type = 'button';
        atc.className = 'wcai-card__atc';
        atc.textContent = cfg.i18n.addToCart || 'Add to cart';
        atc.addEventListener('click', function () {
          addToCart(p, atc);
        });
        actions.appendChild(atc);
      }

      var view = document.createElement('a');
      view.className = 'wcai-card__view';
      view.href = p.url || '#';
      view.target = '_blank';
      view.rel = 'noopener noreferrer';
      view.textContent = cfg.i18n.viewProduct || 'View product';
      view.addEventListener('click', function () {
        trackClick(p.id);
      });
      actions.appendChild(view);

      body.appendChild(title);
      body.appendChild(price);
      if (p.reason) body.appendChild(reason);
      body.appendChild(actions);
      card.appendChild(media);
      card.appendChild(body);
      return card;
    }

    function addToCart(p, btn) {
      if (!cfg.ajaxUrl || !p.id) return;
      trackClick(p.id);
      var prev = btn.textContent;
      btn.disabled = true;
      btn.textContent = cfg.i18n.adding || 'Adding…';

      var body = new URLSearchParams();
      body.set('product_id', String(p.id));
      body.set('quantity', '1');
      if (cfg.cartNonce) body.set('security', cfg.cartNonce);

      fetch(cfg.ajaxUrl + '?wc-ajax=add_to_cart', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      })
        .then(function (r) {
          return r.json().catch(function () {
            return {};
          });
        })
        .then(function (data) {
          if (data && data.error) {
            showToast(cfg.i18n.cartError || cfg.i18n.error);
            return;
          }
          showToast(cfg.i18n.added || 'Added to cart');
          if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('added_to_cart', [
              data && data.fragments,
              data && data.cart_hash,
              jQuery(btn),
            ]);
          }
        })
        .catch(function () {
          showToast(cfg.i18n.cartError || cfg.i18n.error);
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = prev;
        });
    }

    function trackClick(productId) {
      if (!cfg.clickUrl || !productId) return;
      var token = getSessionToken();
      if (!lastQueryId || !token) return;
      fetch(cfg.clickUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce,
        },
        body: JSON.stringify({
          product_id: productId,
          query_id: lastQueryId,
          session_token: token,
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

    function appendBot(text) {
      var el = document.createElement('div');
      el.className = 'wcai-msg wcai-msg--bot';
      el.textContent = text;
      messagesEl.appendChild(el);
      messagesEl.scrollTop = messagesEl.scrollHeight;
      return el;
    }

    function appendThinking() {
      var el = document.createElement('div');
      el.className = 'wcai-msg wcai-msg--bot wcai-msg--thinking';
      el.setAttribute('aria-label', cfg.i18n.thinking);
      el.innerHTML =
        '<span class="wcai-skel"></span><span class="wcai-skel"></span><span class="wcai-skel"></span>';
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
        ensureChatStarted();
        appendUser(q);
        ask(q);
      },
      input: input,
      panel: panel,
    };
  }

  function bindA11y(panel, onClose) {
    function onKey(e) {
      if (e.key === 'Escape') {
        onClose();
        return;
      }
      if (e.key !== 'Tab') return;
      var focusables = panel.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      var list = Array.prototype.filter.call(focusables, function (el) {
        return !el.disabled && el.offsetParent !== null;
      });
      if (!list.length) return;
      var first = list[0];
      var last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
    panel.addEventListener('keydown', onKey);
    return function () {
      panel.removeEventListener('keydown', onKey);
    };
  }

  function mountChat(root, embedded, withLauncher) {
    var open = embedded;
    var panel = createPanel(embedded, !embedded || withLauncher);
    root.appendChild(panel);
    var unbindA11y = null;

    var launcher = null;
    if (withLauncher) {
      launcher = document.createElement('button');
      launcher.type = 'button';
      launcher.className = 'wcai-launcher';
      launcher.setAttribute('aria-label', cfg.i18n.openLabel);
      launcher.setAttribute('aria-expanded', 'false');
      launcher.innerHTML = '<span class="wcai-launcher__icon">' + LAUNCHER_SVG + '</span>';
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
      if (!embedded) {
        lockBody(open);
        if (unbindA11y) {
          unbindA11y();
          unbindA11y = null;
        }
        if (open) {
          unbindA11y = bindA11y(panel, function () {
            setOpen(false);
          });
          api.focus();
        }
      } else if (open) {
        api.focus();
      }
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

    function close() {
      if (existing) existing.hidden = true;
      if (backdrop) backdrop.hidden = true;
      lockBody(false);
      if (host._wcaiUnbind) {
        host._wcaiUnbind();
        host._wcaiUnbind = null;
      }
      var returnFocus = host._wcaiReturnFocus;
      if (returnFocus && typeof returnFocus.focus === 'function') {
        try {
          returnFocus.focus();
        } catch (e) {}
      }
    }

    if (!existing) {
      backdrop = document.createElement('div');
      backdrop.className = 'wcai-backdrop';
      host.appendChild(backdrop);

      existing = createPanel(false, true);
      existing.classList.add('wcai-panel--modal');
      host.appendChild(existing);

      var api = wireChat(existing, { onClose: close });
      host._wcaiApi = api;

      backdrop.addEventListener('click', close);
    }

    host._wcaiReturnFocus = document.activeElement;
    existing.hidden = false;
    if (backdrop) backdrop.hidden = false;
    lockBody(true);
    if (host._wcaiUnbind) host._wcaiUnbind();
    host._wcaiUnbind = bindA11y(existing, close);

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
      micBtn.setAttribute('aria-label', cfg.i18n.listening || cfg.i18n.voice);
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
      micBtn.setAttribute('aria-label', cfg.i18n.voice);
    };
    rec.onerror = function () {
      listening = false;
      micBtn.classList.remove('is-listening');
      micBtn.setAttribute('aria-label', cfg.i18n.voice);
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
