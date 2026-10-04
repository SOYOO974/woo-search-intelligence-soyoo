/**
 * Front — Woo Search Intelligence by SOYOO
 *
 * 1. Mesure des clics sur les résultats (CTR, position) et attribution des commandes :
 *    - liens du live search : fragment `#wsi=<uid>.<produit>.<position>` lu à l'arrivée sur la fiche ;
 *    - page de résultats : clics capturés sur les liens produits listés par le serveur.
 * 2. Interface de live search optionnelle (désactivée par défaut, réservée aux thèmes sans liste déroulante).
 *
 * Aucune dépendance (pas de jQuery), chargé en `defer`.
 */
(function () {
  'use strict';

  var cfg = window.wsiFront;
  if (!cfg) {
    return;
  }

  var UID_RE = /^[a-f0-9]{16}$/;

  /* ------------------------------------------------------------------
   * Suivi
   * ---------------------------------------------------------------- */

  function setRefCookie(uid) {
    if (!cfg.conversion || !UID_RE.test(uid)) {
      return;
    }
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = cfg.cookie + '=' + uid + '; path=/; max-age=86400; SameSite=Lax' + secure;
  }

  function sendClick(uid, pid, pos) {
    if (!cfg.tracking || !UID_RE.test(uid) || !(pid > 0)) {
      return;
    }

    setRefCookie(uid);

    var data = new FormData();
    data.append('uid', uid);
    data.append('pid', String(pid));
    data.append('pos', String(pos || 0));

    try {
      if (navigator.sendBeacon && navigator.sendBeacon(cfg.clickUrl, data)) {
        return;
      }
    } catch (e) { /* repli fetch */ }

    if (window.fetch) {
      window.fetch(cfg.clickUrl, { method: 'POST', body: data, keepalive: true, credentials: 'same-origin' }).catch(function () {});
    }
  }

  // Arrivée sur une fiche produit depuis le live search (thème ou interface intégrée).
  (function readFragment() {
    var match = /(?:^#|&)wsi=([a-f0-9]{16})\.(\d+)\.(\d+)/.exec(window.location.hash || '');
    if (!match) {
      return;
    }

    sendClick(match[1], parseInt(match[2], 10), parseInt(match[3], 10));

    if (window.history && window.history.replaceState) {
      window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
    }
  })();

  function urlKey(href) {
    try {
      var u = new URL(href, window.location.href);
      var path = u.pathname.replace(/\/+$/, '');
      try {
        path = decodeURIComponent(path);
      } catch (e) { /* chemin non décodable : conservé tel quel */ }
      return (u.hostname + path).toLowerCase();
    } catch (e) {
      return '';
    }
  }

  // Page de résultats standard : correspondance URL → [produit, position].
  if (cfg.tracking && cfg.page && UID_RE.test(cfg.page.uid) && cfg.page.items) {
    var pageItems = {};
    Object.keys(cfg.page.items).forEach(function (key) {
      pageItems[urlKey('//' + key)] = cfg.page.items[key];
    });

    var seen = {};
    var onPageClick = function (event) {
      if (event.type === 'auxclick' && event.button !== 1) {
        return;
      }
      var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
      if (!link || link.getAttribute('href').charAt(0) === '#') {
        return;
      }
      var item = pageItems[urlKey(link.href)];
      if (!item || seen[item[0]]) {
        return;
      }
      seen[item[0]] = true; // Un clic par produit et par affichage (image + titre = même lien).
      sendClick(cfg.page.uid, item[0], item[1]);
    };

    document.addEventListener('click', onPageClick, true);
    document.addEventListener('auxclick', onPageClick, true);
  }

  /* ------------------------------------------------------------------
   * Interface de live search intégrée (optionnelle)
   * ---------------------------------------------------------------- */

  if (!cfg.ui || !window.fetch) {
    return;
  }

  var i18n = cfg.i18n || {};
  var minChars = Math.max(1, parseInt(cfg.minChars, 10) || 2);
  var selector = (cfg.selector || '').trim() || 'input[name="s"]';
  var memo = {};
  var counter = 0;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null && text !== '') {
      node.textContent = String(text);
    }
    return node;
  }

  function fetchResults(term, signal) {
    if (memo[term]) {
      return Promise.resolve(memo[term]);
    }
    var url = new URL(cfg.searchUrl, window.location.href);
    url.searchParams.set('term', term);

    return window.fetch(url.toString(), { credentials: 'same-origin', signal: signal, headers: { Accept: 'application/json' } })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (json) {
        if (!json || !json.success || !json.data) {
          throw new Error('Invalid payload');
        }
        memo[term] = json.data;
        return json.data;
      });
  }

  function attach(input) {
    if (input.dataset.wsiBound) {
      return;
    }
    input.dataset.wsiBound = '1';

    var id = 'wsi-listbox-' + (++counter);
    var panel = el('div', 'wsi-dropdown');
    panel.id = id;
    panel.setAttribute('role', 'listbox');
    panel.hidden = true;
    document.body.appendChild(panel);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id);
    input.setAttribute('autocomplete', 'off');

    var timer = null;
    var controller = null;
    var active = -1;
    var lastTerm = '';

    function options() {
      return panel.querySelectorAll('[role="option"]');
    }

    function position() {
      var rect = input.getBoundingClientRect();
      var width = Math.max(rect.width, 320);
      var left = rect.left + window.pageXOffset;
      var maxLeft = window.pageXOffset + document.documentElement.clientWidth - width - 8;
      panel.style.top = (rect.bottom + window.pageYOffset + 4) + 'px';
      panel.style.left = Math.max(8, Math.min(left, maxLeft)) + 'px';
      panel.style.width = width + 'px';
    }

    function close() {
      panel.hidden = true;
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function open() {
      position();
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function highlight(index) {
      var list = options();
      if (!list.length) {
        return;
      }
      active = (index + list.length) % list.length;
      for (var i = 0; i < list.length; i++) {
        list[i].classList.toggle('is-active', i === active);
        list[i].setAttribute('aria-selected', i === active ? 'true' : 'false');
      }
      input.setAttribute('aria-activedescendant', list[active].id);
      list[active].scrollIntoView({ block: 'nearest' });
    }

    function render(data) {
      panel.innerHTML = '';
      active = -1;
      var display = data.display || {};
      var optionIndex = 0;

      function option(href, className) {
        var a = el('a', 'wsi-option ' + (className || ''));
        a.href = href;
        a.id = id + '-opt-' + (optionIndex++);
        a.setAttribute('role', 'option');
        a.setAttribute('aria-selected', 'false');
        a.tabIndex = -1;
        return a;
      }

      if (data.notice) {
        panel.appendChild(el('div', 'wsi-notice', data.notice));
      }

      if (data.categories && data.categories.length) {
        panel.appendChild(el('div', 'wsi-heading', i18n.categories));
        data.categories.forEach(function (cat) {
          var a = option(cat.permalink, 'wsi-cat');
          a.appendChild(el('span', 'wsi-cat-name', cat.name));
          a.appendChild(el('span', 'wsi-cat-count', cat.count));
          panel.appendChild(a);
        });
      }

      if (data.products && data.products.length) {
        if (data.categories && data.categories.length) {
          panel.appendChild(el('div', 'wsi-heading', i18n.products));
        }
        data.products.forEach(function (p) {
          var a = option(p.permalink, 'wsi-product' + (p.in_stock ? '' : ' is-out'));
          if (display.images && p.image_url) {
            var img = el('img', 'wsi-thumb');
            img.src = p.image_url;
            img.alt = '';
            img.loading = 'lazy';
            img.width = 48;
            img.height = 48;
            a.appendChild(img);
          }
          var body = el('span', 'wsi-body');
          body.appendChild(el('span', 'wsi-title', p.title));
          var meta = el('span', 'wsi-meta');
          if (display.sku && p.sku) {
            meta.appendChild(el('span', 'wsi-sku' + (p.is_sku_match ? ' is-match' : ''), p.sku));
          }
          if (display.stock && !p.in_stock) {
            meta.appendChild(el('span', 'wsi-stock', i18n.outOfStock));
          }
          if (meta.childNodes.length) {
            body.appendChild(meta);
          }
          a.appendChild(body);
          if (display.prices && p.price_html) {
            var price = el('span', 'wsi-price');
            price.innerHTML = p.price_html; // HTML de prix généré par WooCommerce (serveur de confiance).
            a.appendChild(price);
          }
          panel.appendChild(a);
        });
      } else {
        panel.appendChild(el('div', 'wsi-empty', i18n.noResults));
      }

      if (data.total_found > (data.products ? data.products.length : 0) && data.see_all_url) {
        var all = option(data.see_all_url, 'wsi-all');
        all.textContent = String(i18n.seeAll || '%d').replace('%d', data.total_found);
        panel.appendChild(all);
      }

      open();
    }

    function run() {
      var term = input.value.trim();
      if (term.length < minChars) {
        lastTerm = '';
        close();
        return;
      }
      if (term === lastTerm && !panel.hidden) {
        return;
      }
      lastTerm = term;

      if (controller) {
        controller.abort();
      }
      controller = window.AbortController ? new AbortController() : null;
      panel.classList.add('is-loading');

      fetchResults(term, controller ? controller.signal : undefined)
        .then(function (data) {
          if (input.value.trim() === term) {
            render(data);
          }
        })
        .catch(function () { /* requête annulée ou erreur réseau : on garde l'état courant */ })
        .then(function () { panel.classList.remove('is-loading'); });
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(run, 200);
    });

    input.addEventListener('focus', function () {
      if (input.value.trim().length >= minChars && panel.childNodes.length) {
        open();
      }
    });

    input.addEventListener('keydown', function (e) {
      if (panel.hidden) {
        if (e.key === 'ArrowDown' && input.value.trim().length >= minChars) {
          run();
        }
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(active + 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(active - 1);
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        var target = options()[active];
        if (target) {
          window.location.href = target.href;
        }
      } else if (e.key === 'Escape') {
        close();
      }
    });

    input.addEventListener('blur', function () {
      // Laisse le temps au clic sur une option d'aboutir.
      setTimeout(function () {
        if (!panel.contains(document.activeElement)) {
          close();
        }
      }, 180);
    });

    panel.addEventListener('mousedown', function (e) {
      e.preventDefault(); // Conserve le focus dans le champ.
    });

    var reposition = function () {
      if (!panel.hidden) {
        position();
      }
    };
    window.addEventListener('resize', reposition, { passive: true });
    window.addEventListener('scroll', reposition, { passive: true });
  }

  function bindAll(root) {
    var nodes;
    try {
      nodes = (root || document).querySelectorAll(selector);
    } catch (e) {
      return; // Sélecteur invalide saisi dans les réglages.
    }
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].tagName === 'INPUT') {
        attach(nodes[i]);
      }
    }
  }

  bindAll(document);

  // Champs injectés plus tard (menus mobiles, popups de recherche).
  document.addEventListener('focusin', function (e) {
    var t = e.target;
    if (t && t.tagName === 'INPUT' && !t.dataset.wsiBound) {
      try {
        if (t.matches(selector)) {
          attach(t);
        }
      } catch (err) { /* sélecteur invalide */ }
    }
  });
})();
