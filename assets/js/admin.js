/**
 * Contrôleur d'interface — WooCommerce Search Intelligence by SOYOO
 */

jQuery(document).ready(function($) {
  'use strict';

  var config = window.wooSearchAdmin || {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: '',
    i18n: {}
  };

  /**
   * Système Toast Notification
   */
  function showToast(message, isError) {
    $('.woo-toast').remove();
    var $toast = $('<div>', {
      class: 'woo-toast' + (isError ? ' is-error' : ''),
      text: message
    }).appendTo('body');

    setTimeout(function() {
      $toast.fadeOut(300, function() {
        $(this).remove();
      });
    }, 3200);
  }

  function escapeHtml(str) {
    return $('<div>').text(str || '').html();
  }

  /* ==========================================================================
     1. RÉGLAGES & NOTIFICATIONS
     ========================================================================== */

  // Toggle affichage des réglages d'alertes
  $('#woo_enable_alerts').on('change', function() {
    if ($(this).is(':checked')) {
      $('.woo-alerts-options').slideDown(200);
    } else {
      $('.woo-alerts-options').slideUp(200);
    }
  });

  // Toggle mode seuil vs hebdomadaire
  $('input[name="alert_mode"]').on('change', function() {
    if ($(this).val() === 'threshold') {
      $('#woo_threshold_group').slideDown(200);
    } else {
      $('#woo_threshold_group').slideUp(200);
    }
  });

  // Sauvegarde des réglages
  $('#woo-settings-form').on('submit', function(e) {
    e.preventDefault();
    var $btn = $('#woo-save-settings-btn');
    var $status = $('#woo-save-status');

    $btn.prop('disabled', true);
    $status.css('color', '#64748b').text(config.i18n.saving || 'Enregistrement...');

    var formData = $(this).serializeArray();
    formData.push({ name: 'action', value: 'woo_search_save_settings' });
    formData.push({ name: 'nonce', value: config.nonce });

    $.post(config.ajaxUrl, formData, function(res) {
      $btn.prop('disabled', false);
      if (res.success) {
        $status.css('color', '#059669').text(res.data.message || 'Enregistré !');
        showToast(res.data.message || 'Paramètres enregistrés.');
        setTimeout(function() {
          $status.text('');
        }, 3000);
      } else {
        $status.css('color', '#dc2626').text(res.data.message || 'Erreur');
        showToast(res.data.message || 'Erreur', true);
      }
    }).fail(function() {
      $btn.prop('disabled', false);
      $status.css('color', '#dc2626').text('Erreur réseau');
      showToast('Erreur de connexion', true);
    });
  });

  // Envoi d'un e-mail de test
  $('#woo-btn-test-email').on('click', function() {
    var $btn = $(this);
    var $status = $('#woo-test-email-status');
    var email = $('#woo_alert_email').val();

    $btn.prop('disabled', true);
    $status.css('color', '#64748b').text('Envoi en cours...');

    $.post(config.ajaxUrl, {
      action: 'woo_search_test_email',
      email: email,
      nonce: config.nonce
    }, function(res) {
      $btn.prop('disabled', false);
      if (res.success) {
        $status.css('color', '#059669').text('✓ E-mail envoyé avec succès !');
        showToast('E-mail de test envoyé !');
      } else {
        $status.css('color', '#dc2626').text('✗ ' + (res.data.message || 'Échec'));
        showToast(res.data.message || 'Échec d\'envoi', true);
      }
    });
  });

  // Purge du cache Transients
  $('#woo-btn-clear-cache').on('click', function() {
    var $btn = $(this);
    $btn.prop('disabled', true).text('Purge en cours...');

    $.post(config.ajaxUrl, {
      action: 'woo_search_clear_cache',
      nonce: config.nonce
    }, function(res) {
      $btn.prop('disabled', false).text('🧹 Vider le cache de recherche');
      if (res.success) {
        showToast(res.data.message || 'Cache vidé avec succès.');
      }
    });
  });

  /* ==========================================================================
     2. MIGRATION BATCHÉE DEPUIS SEARCH ANALYTICS FOR WP
     ========================================================================== */

  $('#woo-btn-start-import').on('click', function() {
    var $btn = $(this);
    var $container = $('#woo-import-progress-container');
    var $fill = $('#woo-import-progress-fill');
    var $text = $('#woo-import-progress-text');
    var shouldReset = $('#woo-import-reset-check').is(':checked') ? 1 : 0;

    $btn.prop('disabled', true).text('Migration en cours...');
    $container.slideDown(200);
    $fill.css('width', '2%');
    $text.text('Démarrage de la migration...');

    function runBatch(offset, isFirst) {
      $.post(config.ajaxUrl, {
        action: 'woo_search_import_batch',
        offset: offset,
        batch_size: 250,
        reset: isFirst && shouldReset ? 1 : 0,
        nonce: config.nonce
      }, function(res) {
        if (!res.success) {
          $btn.prop('disabled', false).text('Reprendre la migration');
          showToast(res.data.message || 'Erreur pendant l\'import', true);
          return;
        }

        var data = res.data;
        $fill.css('width', data.percent + '%');
        $text.text(data.percent + '% (' + data.new_offset + ' / ' + data.total + ')');

        if (!data.is_finished && data.new_offset < data.total) {
          runBatch(data.new_offset, false);
        } else {
          $fill.css('width', '100%');
          $text.text('100% (Terminé !)');
          $btn.text('✓ Données synchronisées');
          showToast(data.message || 'Migration terminée !');
          setTimeout(function() {
            location.reload();
          }, 1400);
        }
      }).fail(function() {
        $btn.prop('disabled', false).text('Reprendre la migration');
        showToast('Erreur réseau lors du lot d\'importation.', true);
      });
    }

    runBatch(0, true);
  });

  /* ==========================================================================
     3. SYNONYMES : FILTRAGE, AJOUT, ÉDITION INLINE, SUPPRESSION
     ========================================================================== */

  function filterSynonyms() {
    var query = ($('#woo-synonyms-filter-input').val() || '').toLowerCase().trim();
    var type = $('#woo-synonyms-filter-type').val();
    var visible = 0;

    $('#woo-synonyms-table tbody tr:not(.woo-empty-row)').each(function() {
      var from = ($(this).attr('data-from') || '').toLowerCase();
      var to = ($(this).attr('data-to') || '').toLowerCase();
      var rType = $(this).attr('data-type') || 'expand';

      var matchQuery = !query || from.indexOf(query) !== -1 || to.indexOf(query) !== -1;
      var matchType = !type || rType === type;

      if (matchQuery && matchType) {
        $(this).show();
        visible++;
      } else {
        $(this).hide();
      }
    });

    $('#woo-synonyms-counter strong').text(visible);
  }

  $('#woo-synonyms-filter-input').on('input', filterSynonyms);
  $('#woo-synonyms-filter-type').on('change', filterSynonyms);

  // Ajout rapide d'un synonyme
  $('#woo-add-synonym-form').on('submit', function(e) {
    e.preventDefault();
    var from = $('#woo_from_term').val().trim();
    var to = $('#woo_to_term').val().trim();
    var type = $('#woo_rule_type').val();
    var $btn = $('#woo-btn-add-synonym');

    if (!from || !to) return;

    $btn.prop('disabled', true);

    $.post(config.ajaxUrl, {
      action: 'woo_search_add_synonym',
      from: from,
      to: to,
      type: type,
      nonce: config.nonce
    }, function(res) {
      $btn.prop('disabled', false);
      if (res.success) {
        showToast(res.data.message || 'Synonyme ajouté !');
        setTimeout(function() {
          location.reload();
        }, 800);
      } else {
        showToast(res.data.message || 'Erreur', true);
      }
    });
  });

  // Édition en ligne d'un synonyme (Inline Edit)
  $(document).on('click', '.woo-edit-synonym-btn', function() {
    var $row = $(this).closest('tr');
    if ($row.hasClass('is-editing')) return;

    var index = $row.attr('data-index');
    var from = $row.attr('data-from') || '';
    var to = $row.attr('data-to') || '';
    var type = $row.attr('data-type') || 'expand';

    $row.addClass('is-editing').data('orig-html', $row.html());

    $row.html([
      '<td><input type="text" class="woo-edit-from regular-text" value="' + escapeHtml(from) + '" style="width:100%; height:32px;"></td>',
      '<td><input type="text" class="woo-edit-to regular-text" value="' + escapeHtml(to) + '" style="width:100%; height:32px;"></td>',
      '<td><select class="woo-edit-type" style="height:32px; width:100%;"><option value="replace"' + (type === 'replace' ? ' selected' : '') + '>Remplacement</option><option value="expand"' + (type === 'expand' ? ' selected' : '') + '>Expansion</option></select></td>',
      '<td style="text-align:right; white-space:nowrap;">',
      '  <button type="button" class="woo-btn woo-btn-primary woo-save-edit-btn" data-index="' + index + '" style="padding:4px 10px; font-size:12px; margin-right:4px;">Enregistrer</button>',
      '  <button type="button" class="woo-btn woo-btn-secondary woo-cancel-edit-btn" style="padding:4px 10px; font-size:12px;">Annuler</button>',
      '</td>'
    ].join(''));
  });

  $(document).on('click', '.woo-cancel-edit-btn', function() {
    var $row = $(this).closest('tr');
    $row.removeClass('is-editing').html($row.data('orig-html'));
  });

  $(document).on('click', '.woo-save-edit-btn', function() {
    var $row = $(this).closest('tr');
    var index = $(this).data('index');
    var from = $row.find('.woo-edit-from').val().trim();
    var to = $row.find('.woo-edit-to').val().trim();
    var type = $row.find('.woo-edit-type').val();

    if (!from || !to) return;

    $.post(config.ajaxUrl, {
      action: 'woo_search_edit_synonym',
      index: index,
      from: from,
      to: to,
      type: type,
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        showToast(res.data.message || 'Modifié');
        setTimeout(function() {
          location.reload();
        }, 700);
      } else {
        showToast(res.data.message || 'Erreur', true);
      }
    });
  });

  // Suppression d'un synonyme
  $(document).on('click', '.woo-delete-synonym-btn', function() {
    if (!confirm(config.i18n.confirmDeleteSyn || 'Supprimer ce synonyme ?')) return;

    var $row = $(this).closest('tr');
    var index = $(this).data('index');

    $.post(config.ajaxUrl, {
      action: 'woo_search_delete_synonym',
      index: index,
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        $row.fadeOut(250, function() {
          $(this).remove();
          filterSynonyms();
        });
        showToast(res.data.message || 'Supprimé');
      } else {
        showToast(res.data.message || 'Erreur', true);
      }
    });
  });

  // Installation de tous les packs recommandés
  $('#woo-btn-install-recommended').on('click', function() {
    var $btn = $(this);
    $btn.prop('disabled', true).text('Installation...');

    $.post(config.ajaxUrl, {
      action: 'woo_search_install_recommended_synonyms',
      nonce: config.nonce
    }, function(res) {
      $btn.prop('disabled', false).text('⚡ Installer tous les synonymes suggérés');
      if (res.success) {
        showToast(res.data.message || 'Synonymes installés !');
        setTimeout(function() {
          location.reload();
        }, 1100);
      }
    });
  });

  // Ajout individuel d'un terme recommandé
  $(document).on('click', '.woo-add-single-rec-btn', function() {
    var $btn = $(this);
    var from = $btn.data('from');
    var to = $btn.data('to');
    var type = $btn.data('type') || 'expand';

    $btn.prop('disabled', true).text('...');

    $.post(config.ajaxUrl, {
      action: 'woo_search_add_synonym',
      from: from,
      to: to,
      type: type,
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        $btn.replaceWith('<span class="woo-status-active">✓ Actif</span>');
        showToast('Synonyme « ' + from + ' » associé à « ' + to + ' »');
      }
    });
  });

  /* ==========================================================================
     4. ANALYTICS & STATISTIQUES (AVEC MASQUAGE DIRECT)
     ========================================================================== */

  // Purge des logs anciens (> 90j)
  $('#woo-btn-clear-logs').on('click', function() {
    if (!confirm(config.i18n.confirmClearLogs || 'Purger les statistiques anciennes ?')) return;

    $.post(config.ajaxUrl, {
      action: 'woo_search_clear_logs',
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        showToast(res.data.message || 'Logs purgés avec succès.');
        setTimeout(function() {
          location.reload();
        }, 1000);
      }
    });
  });

  // Masquer/Ignorer un terme directement depuis l'onglet Analytics ou 0 Résultat
  $(document).on('click', '.woo-ignore-term-btn', function() {
    var $btn = $(this);
    var term = $btn.data('term');
    var $row = $btn.closest('tr');

    if (!confirm(config.i18n.confirmIgnore || 'Ignorer définitivement ce terme ?')) return;

    $btn.prop('disabled', true);

    $.post(config.ajaxUrl, {
      action: 'woo_search_ignore_term',
      term: term,
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        $row.fadeOut(300, function() {
          $(this).remove();
        });
        showToast(res.data.message || 'Terme ignoré.');
      } else {
        $btn.prop('disabled', false);
        showToast(res.data.message || 'Erreur', true);
      }
    });
  });

  /* ==========================================================================
     5. 0 RÉSULTAT & BLACKLIST UNIVERSELLE
     ========================================================================== */

  // Association rapide en 1 clic
  $(document).on('click', '.woo-quick-zero-btn', function() {
    var $btn = $(this);
    var from = $btn.data('from');
    var to = $btn.data('to');

    $btn.prop('disabled', true).text('Association...');

    $.post(config.ajaxUrl, {
      action: 'woo_search_add_synonym',
      from: from,
      to: to,
      type: 'expand',
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        $btn.replaceWith('<span class="woo-status-active">✓ Associé</span>');
        showToast('Synonyme « ' + from + ' » associé à « ' + to + ' » !');
      } else {
        $btn.prop('disabled', false).text('⚡ Associer 1 clic');
        showToast(res.data.message || 'Erreur', true);
      }
    });
  });

  // Réactiver un terme ignoré
  $(document).on('click', '.woo-unignore-btn', function() {
    var $btn = $(this);
    var term = $btn.data('term');
    var $pill = $btn.closest('.woo-ignored-pill');

    $.post(config.ajaxUrl, {
      action: 'woo_search_unignore_term',
      term: term,
      nonce: config.nonce
    }, function(res) {
      if (res.success) {
        $pill.fadeOut(200, function() {
          $(this).remove();
        });
        showToast(res.data.message || 'Terme réactivé.');
      }
    });
  });

  // Sélection groupée
  function updateBulkBar() {
    var checkedCount = $('.woo-zero-cb:checked').length;
    var $bulkBar = $('#woo-bulk-bar');
    var $bulkCount = $('#woo-bulk-count');

    if (checkedCount > 0) {
      $bulkCount.text(checkedCount + ' terme(s) sélectionné(s)');
      $bulkBar.slideDown(150);
    } else {
      $bulkBar.slideUp(150);
    }
  }

  $('#woo-select-all-zero').on('change', function() {
    var isChecked = $(this).is(':checked');
    $('.woo-zero-cb').prop('checked', isChecked);
    $('.woo-zero-row').toggleClass('is-selected', isChecked);
    updateBulkBar();
  });

  $(document).on('change', '.woo-zero-cb', function() {
    $(this).closest('.woo-zero-row').toggleClass('is-selected', $(this).is(':checked'));
    updateBulkBar();
  });

  // Ignorer en masse les termes sélectionnés
  $('#woo-bulk-ignore-btn').on('click', function() {
    var selected = [];
    $('.woo-zero-cb:checked').each(function() {
      var val = $(this).val();
      if (val) selected.push(val);
    });

    if (selected.length === 0) return;

    if (!confirm(config.i18n.confirmBulk || 'Ignorer la sélection ?')) return;

    var $btn = $(this);
    $btn.prop('disabled', true).text('Action en cours...');

    $.post(config.ajaxUrl, {
      action: 'woo_search_bulk_ignore_terms',
      terms: selected,
      nonce: config.nonce
    }, function(res) {
      $btn.prop('disabled', false).text('🚫 Ignorer la sélection');
      if (res.success) {
        $('.woo-zero-cb:checked').closest('.woo-zero-row').fadeOut(300, function() {
          $(this).remove();
        });
        $('#woo-bulk-bar').hide();
        $('#woo-select-all-zero').prop('checked', false);
        showToast(res.data.message || 'Termes ignorés avec succès.');
        setTimeout(function() {
          location.reload();
        }, 1200);
      } else {
        showToast(res.data.message || 'Erreur', true);
      }
    });
  });
});
