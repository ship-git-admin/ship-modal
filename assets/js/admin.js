(function ($) {
  'use strict';

  var adminConfig = window.ShipModalAdminConfig || {};
  var targetSearchRequest = null;
  var targetSearchTimer = null;

  function currentScope() {
    return $('input[name="ship_modal_scope"]:checked').val() || 'front';
  }

  function refreshTriggerHelp(trigger) {
    $('.ship-modal-trigger-help__item').removeClass('is-active').filter('[data-trigger="' + trigger + '"]').addClass('is-active');
  }

  function refreshRows() {
    var $typeField = $('#ship-modal-content_type');
    var imageOnlyMode = $typeField.is('input[type="hidden"]');
    var type = $typeField.val() || (imageOnlyMode ? 'image' : '');
    $('.ship-modal-legacy-html-row').toggle(type === 'html');
    $('.ship-modal-copy-row').toggle(type === 'hybrid' || type === 'text');
    $('.ship-modal-single-image-row').toggle(imageOnlyMode || type === 'image' || type === 'hybrid');
    $('.ship-modal-single-image-alt-row').toggle(imageOnlyMode || type === 'image' || type === 'hybrid');
    $('.ship-modal-hybrid-image-row').toggle(type === 'hybrid');
    $('.ship-modal-buttons-row').toggle(type === 'hybrid' || type === 'text');
    $('.ship-modal-pages-row').toggle(type === 'pager');
    $('.ship-modal-target-picker').toggle(currentScope() === 'selected');
    $('#ship-modal-body').removeAttr('maxlength');
    var trigger = $('#ship-modal-trigger').val();
    refreshTriggerHelp(trigger);
    $('.ship-modal-delay-row').toggle(trigger === 'auto');
    $('.ship-modal-scroll-row').toggle(trigger === 'scroll');
    $('.ship-modal-trigger-text-row').toggle(trigger === 'manual');
    $('.ship-modal-trigger-style-row').toggle(trigger === 'manual');
  }

  function updateCounter(field) {
    var $field = $(field);
    var max = parseInt($field.attr('maxlength'), 10);
    if (!max) return;
    var value = String($field.val() || '').replace(/<[^>]*>/g, '');
    var $counter = $field.siblings('.ship-modal-char-count');
    if (!$counter.length) {
      $counter = $('<span class="ship-modal-char-count"></span>');
      $field.after($counter);
    }
    $counter.text(value.length + ' / ' + max + '文字');
    $counter.toggleClass('is-over', value.length > max);
  }

  function toChars(value) {
    var chars = [];
    var text = String(value || '');
    for (var index = 0; index < text.length; index++) {
      var code = text.charCodeAt(index);
      if (code >= 0xD800 && code <= 0xDBFF && index + 1 < text.length) {
        chars.push(text.slice(index, index + 2));
        index++;
      } else {
        chars.push(text.charAt(index));
      }
    }
    return chars;
  }

  function buttonLabelLines(value) {
    return String(value || '').split(/<br\s*\/?>/i).map(function (line) {
      return line.replace(/<[^>]*>/g, '');
    });
  }

  function updateButtonLabelMeta(field) {
    var $field = $(field);
    var maxLines = parseInt($field.attr('data-max-lines'), 10) || 2;
    var maxChars = parseInt($field.attr('data-max-chars-per-line'), 10) || 16;
    var lines = buttonLabelLines($field.val());
    var lengths = lines.map(function (line) { return toChars(line).length; });
    var maxLineLength = lengths.length ? Math.max.apply(Math, lengths) : 0;
    var total = lengths.reduce(function (sum, length) { return sum + length; }, 0);
    var over = lines.length > maxLines || maxLineLength > maxChars;
    var $meta = $field.siblings('.ship-modal-button-label-meta');
    if (!$meta.length) {
      $meta = $('<span class="ship-modal-button-label-meta" aria-live="polite"></span>');
      $field.after($meta);
    }
    $meta.text('上限 ' + maxChars + '文字/行・' + maxLines + '行（現在 ' + maxLineLength + '文字/行・' + lines.length + '行／合計' + total + '文字）');
    $meta.toggleClass('is-over', over);
  }

  function normalizeButtonLabelField(field) {
    var $field = $(field);
    var maxLines = parseInt($field.attr('data-max-lines'), 10) || 2;
    var maxChars = parseInt($field.attr('data-max-chars-per-line'), 10) || 16;
    var lines = buttonLabelLines($field.val()).slice(0, maxLines).map(function (line) {
      return toChars(line).slice(0, maxChars).join('');
    });
    var normalized = lines.join('<br>');
    if (normalized !== String($field.val() || '')) {
      $field.val(normalized);
    }
    updateButtonLabelMeta($field);
  }

  function refreshButtonActions(container) {
    $(container).find('.ship-modal-button-action').each(function () {
      var isClose = $(this).val() === 'close';
      var $field = $(this).closest('.ship-modal-button-field');
      $field.find('.ship-modal-button-url').prop('disabled', isClose);
      $field.find('input[type="checkbox"][name$="[new_tab]"]').prop('disabled', isClose);
    });
  }

  function updateTargetCount() {
    var count = $('#ship-modal-target-selected .ship-modal-target-chip').length;
    $('.ship-modal-target-count').text(count ? count + '件選択中' : '未選択');
  }

  function refreshStatsExportLink() {
    $('.ship-modal-stats-export-link').each(function () {
      var $link = $(this);
      var baseUrl = String($link.data('baseUrl') || '');
      var from = $('#' + $link.data('fromId')).val() || '';
      var to = $('#' + $link.data('toId')).val() || '';
      if (!baseUrl) return;
      var separator = baseUrl.indexOf('?') >= 0 ? '&' : '?';
      var params = [];
      if (from) params.push('from=' + encodeURIComponent(from));
      if (to) params.push('to=' + encodeURIComponent(to));
      $link.attr('href', baseUrl + (params.length ? separator + params.join('&') : ''));
    });
  }

  function targetIsSelected(id) {
    return $('#ship-modal-target-selected .ship-modal-target-chip[data-target-id="' + id + '"]').length > 0;
  }

  function showTargetMessage(message) {
    $('#ship-modal-target-results').empty().append($('<p class="description"></p>').text(message));
  }

  function addTarget(id, label) {
    if (!id || targetIsSelected(id)) return;
    var $chip = $('<span class="ship-modal-target-chip"></span>').attr('data-target-id', id);
    $('<input type="hidden" name="ship_modal_target_ids[]">').val(id).appendTo($chip);
    $('<span></span>').text(label).appendTo($chip);
    $('<button type="button" class="ship-modal-target-remove" aria-label="選択を解除">×</button>').appendTo($chip);
    $('#ship-modal-target-selected').append($chip);
    updateTargetCount();
  }

  function searchTargets() {
    window.clearTimeout(targetSearchTimer);
    targetSearchTimer = null;
    if (targetSearchRequest) {
      targetSearchRequest.abort();
      targetSearchRequest = null;
    }
    var query = $.trim($('#ship-modal-target-search').val() || '');
    var postType = $('#ship-modal-target-post-type').val() || '';
    if (query.length === 1) {
      showTargetMessage('ページ名・記事タイトルを2文字以上入力してください。');
      return;
    }
    if (!adminConfig.ajaxUrl || !adminConfig.targetSearchNonce) {
      showTargetMessage('検索設定を読み込めませんでした。ページを再読み込みしてください。');
      return;
    }
    showTargetMessage(query ? '検索中…' : '最近の公開ページを読み込み中…');
    var request = $.post(adminConfig.ajaxUrl, { action: 'ship_modal_search_targets', nonce: adminConfig.targetSearchNonce, modal_post_id: adminConfig.postId || '', q: query, post_type: postType });
    targetSearchRequest = request;
    request
      .done(function (response) {
        if (targetSearchRequest !== request) return;
        var $results = $('#ship-modal-target-results').empty();
        if (!response || !response.success || !response.data || !response.data.length) {
          showTargetMessage(query ? '該当する公開ページがありません。' : '公開ページがありません。');
          return;
        }
        response.data.forEach(function (item) {
          var id = String(item.id);
          var label = '[' + item.type + '] ' + item.title;
          var $row = $('<div class="ship-modal-target-result"></div>');
          $('<span></span>').text(label).appendTo($row);
          var $button = $('<button type="button" class="button button-small">＋追加</button>').data({ targetId: id, targetLabel: label });
          if (targetIsSelected(id)) $button.prop('disabled', true).text('選択済み');
          $row.append($button).appendTo($results);
        });
      })
      .fail(function (xhr, status) {
        if (status !== 'abort' && targetSearchRequest === request) showTargetMessage('検索に失敗しました。もう一度お試しください。');
      })
      .always(function () {
        if (targetSearchRequest === request) targetSearchRequest = null;
      });
  }

  $(function () {
    refreshRows();
    $('[maxlength]').each(function () { updateCounter(this); });
    $('.ship-modal-button-label').each(function () { updateButtonLabelMeta(this); });
    refreshButtonActions(document);
    $(document).on('input', '[maxlength]', function () { updateCounter(this); });
    $(document).on('input', '.ship-modal-button-label', function () { updateButtonLabelMeta(this); });
    $(document).on('blur', '.ship-modal-button-label', function () { normalizeButtonLabelField(this); });
    $(document).on('change', '.ship-modal-button-action', function () { refreshButtonActions($(this).closest('.ship-modal-button-field')); });
    $('#ship-modal-content_type, #ship-modal-trigger, #ship-modal-target-post-type').on('change', refreshRows);
    $('input[name="ship_modal_scope"]').on('change', function () {
      refreshRows();
      if (currentScope() === 'selected') searchTargets();
    });
    updateTargetCount();
    refreshStatsExportLink();
    $(document)
      .off('change.shipModalStats', '.ship-modal-stats-export-form input[type="date"]')
      .on('change.shipModalStats', '.ship-modal-stats-export-form input[type="date"]', refreshStatsExportLink);
    $(document)
      .off('click.shipModalStatsReset', '.ship-modal-stats-reset-button')
      .on('click.shipModalStatsReset', '.ship-modal-stats-reset-button', function () {
        if (!window.confirm('このモーダルの計測データをすべてリセットします。よろしいですか？')) return;
        var $button = $(this);
        var $form = $('<form method="post"></form>').attr('action', String($button.data('actionUrl') || ''));
        $('<input type="hidden" name="action" value="ship_modal_reset_stats">').appendTo($form);
        $('<input type="hidden" name="post_id">').val(String($button.data('postId') || '')).appendTo($form);
        $('<input type="hidden" name="_wpnonce">').val(String($button.data('nonce') || '')).appendTo($form);
        $form.appendTo(document.body);
        $form.get(0).submit();
      });
    if (currentScope() === 'selected') searchTargets();
    $('#ship-modal-target-search').on('input', function () {
      window.clearTimeout(targetSearchTimer);
      targetSearchTimer = window.setTimeout(searchTargets, 250);
    });
    $('#ship-modal-target-post-type').on('change', searchTargets);
    $(document).on('click', '.ship-modal-target-result .button', function () {
      addTarget(String($(this).data('targetId')), String($(this).data('targetLabel')));
      $(this).prop('disabled', true).text('選択済み');
    });
    $(document).on('click', '.ship-modal-target-remove', function () {
      var $chip = $(this).closest('.ship-modal-target-chip');
      var removedId = String($chip.attr('data-target-id') || '');
      $chip.remove();
      updateTargetCount();
      $('#ship-modal-target-results .button').each(function () {
        if (String($(this).data('targetId')) === removedId) $(this).prop('disabled', false).text('＋追加');
      });
    });
    $('#ship-modal-target-clear').on('click', function (event) {
      event.preventDefault();
      $('#ship-modal-target-selected').empty();
      updateTargetCount();
      $('#ship-modal-target-results .button').prop('disabled', false).text('＋追加');
    });
    var frame;
    function selectImage(targetId, previewId) {
      if (!window.wp || typeof window.wp.media !== 'function') {
        window.alert('メディアライブラリを読み込めませんでした。ページを再読み込みしてください。');
        return;
      }
      var currentFrame = frame;
      if (currentFrame) {
        // wp.media のフレームは Backbone.Events なので、jQuery のような
        // イベント名前空間（select.shipModal）は使えない。
        currentFrame.off('select');
      }
      currentFrame = window.wp.media({ title: 'モーダル画像を選択', button: { text: 'この画像を使用' }, multiple: false, library: { type: 'image' } });
      frame = currentFrame;
      currentFrame.on('select', function () {
        var selected = currentFrame.state().get('selection').first();
        if (!selected) return;
        var attachment = selected.toJSON();
        if (!attachment.id || !attachment.url) return;
        $('#' + targetId).val(attachment.id).trigger('change');
        $('#' + previewId).empty().append(
          $('<img alt="" style="max-width:100%;height:auto;">').attr('src', attachment.url)
        );
        if (targetId === 'ship-modal-image-id') {
          var imageWidth = parseInt(attachment.width, 10);
          if (!imageWidth && attachment.sizes && attachment.sizes.full) {
            imageWidth = parseInt(attachment.sizes.full.width, 10);
          }
          if (imageWidth > 0 && $('#ship-modal-max_width').length) {
            $('#ship-modal-max_width').val(Math.max(280, Math.min(1200, imageWidth))).trigger('input').trigger('change');
          }
        }
        if (targetId === 'ship-modal-image-id' && !$.trim($('#ship-modal-image-alt').val() || '') && attachment.alt) {
          $('#ship-modal-image-alt').val(attachment.alt);
        }
      });
      currentFrame.open();
    }
    $(document).off('click.shipModalImage', '#ship-modal-select-image').on('click.shipModalImage', '#ship-modal-select-image', function (event) {
      event.preventDefault();
      event.stopPropagation();
      selectImage('ship-modal-image-id', 'ship-modal-image-preview');
    });
    $(document).off('click.shipModalImageRemove', '#ship-modal-remove-image').on('click.shipModalImageRemove', '#ship-modal-remove-image', function (event) {
      event.preventDefault();
      event.stopPropagation();
      $('#ship-modal-image-id').val('');
      $('#ship-modal-image-preview').empty();
    });
    $(document).off('click.shipModalImageMobile', '#ship-modal-select-image-mobile').on('click.shipModalImageMobile', '#ship-modal-select-image-mobile', function (event) {
      event.preventDefault();
      event.stopPropagation();
      selectImage('ship-modal-image-id-mobile', 'ship-modal-image-preview-mobile');
    });
    $(document).off('click.shipModalImageMobileRemove', '#ship-modal-remove-image-mobile').on('click.shipModalImageMobileRemove', '#ship-modal-remove-image-mobile', function (event) {
      event.preventDefault();
      event.stopPropagation();
      $('#ship-modal-image-id-mobile').val('');
      $('#ship-modal-image-preview-mobile').empty();
    });
    $(document).on('click', '.ship-modal-page-select-image', function (event) {
      event.preventDefault();
      selectImage($(this).data('target-id'), $(this).data('target-preview'));
    });
    $(document).on('click', '.ship-modal-page-remove-image', function (event) {
      event.preventDefault();
      $('#' + $(this).data('target-id')).val('');
      $('#' + $(this).data('target-preview')).empty();
    });
    $(document).on('click', '.ship-modal-remove-page', function (event) {
      event.preventDefault();
      var rows = $('.ship-modal-page-row');
      if (rows.length > 1) {
        $(this).closest('.ship-modal-page-row').remove();
      } else {
        var $row = $(this).closest('.ship-modal-page-row');
        $row.find('input[type="hidden"], input[type="text"], input[type="url"], textarea').val('');
        $row.find('input[type="checkbox"]').prop('checked', false);
        $row.find('select').prop('selectedIndex', 0).trigger('change');
        $row.find('.ship-modal-page-preview').empty();
      }
      $('.ship-modal-page-row').each(function (index) {
        $(this).find('.ship-modal-page-row__header strong').first().text('ページ ' + (index + 1));
      });
    });
    $('#ship-modal-add-page').on('click', function (event) {
      event.preventDefault();
      var index = -1;
      $('.ship-modal-page-row').each(function () {
        var rowIndex = parseInt($(this).attr('data-page-index'), 10);
        if (!isNaN(rowIndex)) index = Math.max(index, rowIndex);
      });
      index++;
      var pageNumber = $('.ship-modal-page-row').length + 1;
      var template = $('#ship-modal-page-template').html().replace(/__INDEX__/g, String(index)).replace(/__NUMBER__/g, String(pageNumber));
      $('#ship-modal-pages').append(template);
    });
  });
})(jQuery);
