/**
 * Contrôleur d'administration — Woo Search Intelligence by SOYOO
 *
 * Toutes les requêtes passent par post() : nonce, gestion des erreurs réseau
 * et des sessions expirées (403 / -1), messages serveur affichés en toast.
 */
jQuery(function ($) {
  'use strict';

  var config = window.wooSearchAdmin || { ajaxUrl: window.ajaxurl || '/wp-admin/admin-ajax.php', nonce: '', i18n: {} };
  var i18n = config.i18n || {};

  /* ------------------------------------------------------------------
   * Utilitaires
   * ---------------------------------------------------------------- */

  function toast(message, isError) {
    $('.woo-toast').remove();
    var $t = $('<div>', { 'class': 'woo-toast' + (isError ? ' is-error' : ''), role: 'status', text: message }).appendTo('body');
    setTimeout(function () { $t.fadeOut(300, function () { $t.remove(); }); }, isError ? 5000 : 3200);
  }

  function messageOf(res, fallback) {
    return res && res.data && res.data.message ? res.data.message : (fallback || i18n.error || 'Erreur');
  }

  /**
   * POST admin-ajax avec nonce. Retourne une promesse résolue avec res.data
   * en cas de succès, rejetée avec un message lisible sinon.
   */
  function post(action, data) {
    var payload = $.extend({}, data || {}, { action: 'woo_search_' + action, nonce: config.nonce });
    var d = $.Deferred();

    $.ajax({ url: config.ajaxUrl, method: 'POST', data: payload, dataType: 'json', timeout: 120000 })
      .done(function (res) {
        if (res && res.success) {
          d.resolve(res.data || {});
        } else {
          d.reject(messageOf(res));
        }
      })
      .fail(function (xhr) {
        var res = xhr.responseJSON;
        if (xhr.status === 403 || xhr.responseText === '-1' || xhr.responseText === '0') {
          d.reject(res && res.data && res.data.message ? res.data.message : (i18n.sessionExpired || 'Session expirée.'));
        } else {
          d.reject(messageOf(res));
        }
      });

    return d.promise();
  }

  function busy($btn, state) {
    $btn.prop('disabled', state).toggleClass('is-busy', state);
  }

  function status($el, text, type) {
    $el.removeClass('is-good is-bad').addClass(type === 'ok' ? 'is-good' : (type === 'err' ? 'is-bad' : '')).text(text || '');
  }

  function reloadSoon(delay) {
    setTimeout(function () { window.location.reload(); }, delay || 900);
  }

  function setProgress(prefix, percent, label) {
    var p = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
    $('#' + prefix + '-fill').css('width', p + '%');
    $('#' + prefix + '-text').text(label || p + '%');
  }

  /* ------------------------------------------------------------------
   * 1. Paramètres
   * ---------------------------------------------------------------- */

  $('#woo_enable_alerts').on('change', function () {
    $('.woo-alerts-options')[this.checked ? 'slideDown' : 'slideUp'](200);
  });

  $('input[name="alert_mode"]').on('change', function () {
    $('#woo_threshold_group')[this.value === 'threshold' ? 'slideDown' : 'slideUp'](200);
  });

  $('#woo-settings-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#woo-save-settings-btn');
    var $status = $('#woo-save-status');
    var data = {};

    $.each($(this).serializeArray(), function (_, field) { data[field.name] = field.value; });

    busy($btn, true);
    status($status, i18n.saving || '…');

    post('save_settings', data)
      .done(function (res) {
        status($status, res.message, 'ok');
        toast(res.message);
        setTimeout(function () { status($status, ''); }, 3000);
      })
      .fail(function (msg) {
        status($status, msg, 'err');
        toast(msg, true);
      })
      .always(function () { busy($btn, false); });
  });

  $('#woo-btn-test-email').on('click', function () {
    var $btn = $(this);
    var $status = $('#woo-test-email-status');

    busy($btn, true);
    status($status, '…');

    post('test_email', { email: $('#woo_alert_email').val() })
      .done(function (res) { status($status, res.message, 'ok'); })
      .fail(function (msg) { status($status, msg, 'err'); })
      .always(function () { busy($btn, false); });
  });

  $('#woo-btn-clear-cache').on('click', function () {
    var $btn = $(this);
    busy($btn, true);
    post('clear_cache')
      .done(function (res) { toast(res.message); })
      .fail(function (msg) { toast(msg, true); })
      .always(function () { busy($btn, false); });
  });

  /* ------------------------------------------------------------------
   * 2. Reconstruction de l'index (lots séquentiels avec curseur)
   * ---------------------------------------------------------------- */

  $('#woo-btn-rebuild-index').on('click', function () {
    if (!window.confirm(i18n.confirmRebuild || 'Reconstruire l\'index ?')) {
      return;
    }

    var $btn = $(this);
    busy($btn, true);
    $('#woo-index-progress').show();
    setProgress('woo-index-progress', 0);

    (function step(cursor) {
      post('index_batch', { cursor: cursor })
        .done(function (res) {
          setProgress('woo-index-progress', res.percent, (res.percent || 0) + '% (' + (res.processed || 0) + ' / ' + (res.total || 0) + ')');
          if (res.done) {
            toast('✓ ' + (res.total || 0));
            reloadSoon();
          } else {
            step(res.cursor);
          }
        })
        .fail(function (msg) {
          toast(msg, true);
          busy($btn, false);
        });
    })(0);
  });

  /* ------------------------------------------------------------------
   * 3. Migration Search Analytics for WP
   * ---------------------------------------------------------------- */

  $('#woo-btn-start-import').on('click', function () {
    var $btn = $(this);
    var reset = $('#woo-import-reset-check').is(':checked') ? 1 : 0;

    busy($btn, true);
    $('#woo-import-progress-container').show();
    setProgress('woo-import-progress', 0);

    (function step(cursor, done, first) {
      post('import_batch', { cursor: cursor, done: done, reset: first ? reset : 0 })
        .done(function (res) {
          setProgress('woo-import-progress', res.percent, (res.percent || 0) + '% (' + (res.done || 0) + ' / ' + (res.total || 0) + ')');
          if (res.is_finished) {
            toast(res.message || '✓');
            reloadSoon(1200);
          } else {
            step(res.cursor, res.done, false);
          }
        })
        .fail(function (msg) {
          toast(msg, true);
          busy($btn, false);
        });
    })(0, 0, true);
  });

  /* ------------------------------------------------------------------
   * 4. Synonymes
   * ---------------------------------------------------------------- */

  function filterSynonyms() {
    var q = ($('#woo-synonyms-filter-input').val() || '').toLowerCase().trim();
    var type = $('#woo-synonyms-filter-type').val() || '';
    var visible = 0;

    $('#woo-synonyms-table tbody tr[data-id]').each(function () {
      var $row = $(this);
      var text = (String($row.data('from')) + ' ' + String($row.data('to'))).toLowerCase();
      var show = (!q || text.indexOf(q) !== -1) && (!type || $row.data('type') === type);
      $row.toggle(show);
      if (show) {
        visible++;
      }
    });

    $('#woo-synonyms-counter strong').text(visible);
  }

  $('#woo-synonyms-filter-input').on('input', filterSynonyms);
  $('#woo-synonyms-filter-type').on('change', filterSynonyms);

  $('#woo-add-synonym-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#woo-btn-add-synonym');
    busy($btn, true);

    post('add_synonym', {
      from: $('#woo_from_term').val(),
      to: $('#woo_to_term').val(),
      type: $('#woo_rule_type').val()
    })
      .done(function (res) {
        toast(res.message);
        // Nettoie le préremplissage ?from=&to= pour éviter une double soumission au rechargement.
        var url = new URL(window.location.href);
        url.searchParams.delete('from');
        url.searchParams.delete('to');
        setTimeout(function () { window.location.href = url.toString(); }, 700);
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $('#woo-synonyms-table').on('click', '.woo-edit-synonym-btn', function () {
    var $row = $(this).closest('tr');
    if ($row.hasClass('is-editing')) {
      return;
    }

    $row.addClass('is-editing').data('html', $row.html());

    var type = $row.data('type');
    var $from = $('<input>', { type: 'text', 'class': 'woo-inline-input js-from', value: String($row.data('from')) });
    var $to = $('<input>', { type: 'text', 'class': 'woo-inline-input js-to', value: String($row.data('to')) });
    var $type = $('<select>', { 'class': 'js-type' })
      .append($('<option>', { value: 'expand', text: i18n.expand || 'Expansion', selected: type === 'expand' }))
      .append($('<option>', { value: 'replace', text: i18n.replace || 'Remplacement', selected: type === 'replace' }));
    var $actions = $('<td>', { 'class': 'woo-col-actions' })
      .append($('<button>', { type: 'button', 'class': 'woo-btn woo-btn-primary woo-btn-xs js-save', text: i18n.save || 'OK' }))
      .append(' ')
      .append($('<button>', { type: 'button', 'class': 'woo-btn woo-btn-secondary woo-btn-xs js-cancel', text: i18n.cancel || 'Annuler' }));

    $row.empty()
      .append($('<td>').append($from))
      .append($('<td>').append($to))
      .append($('<td>').append($type))
      .append($actions);

    $from.trigger('focus');
  });

  $('#woo-synonyms-table').on('click', '.js-cancel', function () {
    var $row = $(this).closest('tr');
    $row.html($row.data('html')).removeClass('is-editing');
  });

  $('#woo-synonyms-table').on('keydown', '.woo-inline-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $(this).closest('tr').find('.js-save').trigger('click');
    } else if (e.key === 'Escape') {
      $(this).closest('tr').find('.js-cancel').trigger('click');
    }
  });

  $('#woo-synonyms-table').on('click', '.js-save', function () {
    var $btn = $(this);
    var $row = $btn.closest('tr');
    busy($btn, true);

    post('edit_synonym', {
      id: $row.data('id'),
      from: $row.find('.js-from').val(),
      to: $row.find('.js-to').val(),
      type: $row.find('.js-type').val()
    })
      .done(function (res) {
        toast(res.message);
        reloadSoon(600);
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $('#woo-synonyms-table').on('click', '.woo-delete-synonym-btn', function () {
    if (!window.confirm(i18n.confirmDeleteSyn || 'Supprimer ?')) {
      return;
    }

    var $btn = $(this);
    var $row = $btn.closest('tr');
    busy($btn, true);

    post('delete_synonym', { id: $row.data('id') })
      .done(function (res) {
        toast(res.message);
        $row.fadeOut(200, function () {
          $row.remove();
          filterSynonyms();
        });
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $('#woo-btn-install-recommended').on('click', function () {
    var $btn = $(this);
    busy($btn, true);
    post('install_recommended_synonyms')
      .done(function (res) {
        toast(res.message);
        reloadSoon();
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $(document).on('click', '.woo-add-single-rec-btn', function () {
    var $btn = $(this);
    busy($btn, true);
    post('add_synonym', { from: $btn.data('from'), to: $btn.data('to'), type: $btn.data('type') })
      .done(function (res) {
        toast(res.message);
        reloadSoon(600);
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  /* ------------------------------------------------------------------
   * 5. Statistiques & 0 résultat
   * ---------------------------------------------------------------- */

  $(document).on('click', '.woo-ignore-term-btn', function () {
    if (!window.confirm(i18n.confirmIgnore || 'Ignorer ?')) {
      return;
    }

    var $btn = $(this);
    busy($btn, true);

    post('ignore_term', { term: String($btn.data('term')) })
      .done(function (res) {
        toast(res.message);
        $btn.closest('tr').fadeOut(200, function () { $(this).remove(); updateBulkBar(); });
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $(document).on('click', '.woo-unignore-btn', function () {
    var $btn = $(this);
    busy($btn, true);

    post('unignore_term', { term: String($btn.data('term')) })
      .done(function (res) {
        toast(res.message);
        $btn.closest('.woo-ignored-pill').fadeOut(200, function () { $(this).remove(); });
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $(document).on('click', '.woo-quick-zero-btn', function () {
    var $btn = $(this);
    busy($btn, true);

    post('add_synonym', { from: String($btn.data('from')), to: String($btn.data('to')), type: 'expand' })
      .done(function (res) {
        toast(res.message);
        reloadSoon(800);
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  $('#woo-btn-clear-logs').on('click', function () {
    if (!window.confirm(i18n.confirmClearLogs || 'Purger ?')) {
      return;
    }

    var $btn = $(this);
    busy($btn, true);
    post('clear_logs')
      .done(function (res) {
        toast(res.message);
        reloadSoon();
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });

  function updateBulkBar() {
    var n = $('.woo-zero-cb:checked').length;
    $('#woo-bulk-count').text(n + ' / ' + $('.woo-zero-cb').length);
    $('#woo-bulk-bar').toggle(n > 0);
    $('#woo-select-all-zero').prop('checked', n > 0 && n === $('.woo-zero-cb').length);
  }

  $('#woo-select-all-zero').on('change', function () {
    $('.woo-zero-cb').prop('checked', this.checked);
    updateBulkBar();
  });

  $(document).on('change', '.woo-zero-cb', updateBulkBar);

  $('#woo-bulk-ignore-btn').on('click', function () {
    var terms = $('.woo-zero-cb:checked').map(function () { return this.value; }).get();
    if (!terms.length || !window.confirm(i18n.confirmBulk || 'Ignorer la sélection ?')) {
      return;
    }

    var $btn = $(this);
    busy($btn, true);
    post('bulk_ignore_terms', { terms: terms })
      .done(function (res) {
        toast(res.message);
        reloadSoon(700);
      })
      .fail(function (msg) {
        toast(msg, true);
        busy($btn, false);
      });
  });
});
