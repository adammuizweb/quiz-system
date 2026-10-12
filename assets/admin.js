(function(){
  'use strict';

  /* ===== Confirm action dialog ===== */
  function confirmAction(el){
    var msg = el.getAttribute('data-confirm');
    if (!msg) return;
    var variant = el.getAttribute('data-confirm-variant') || 'warning';
    var opts = {
      message: msg.replace(/\\n/g, '\n'),
      variant: variant
    };
    var t = el.getAttribute('data-confirm-title');
    var ok = el.getAttribute('data-confirm-ok');
    var cc = el.getAttribute('data-confirm-cancel');
    if (t) opts.title = t;
    if (ok) opts.confirmText = ok;
    if (cc) opts.cancelText = cc;
    (variant === 'danger' ? NewNotifConfirm.danger : NewNotifConfirm.warning)(opts)
      .then(function(ok){
        if (!ok) return;
        if (el.tagName === 'A'){
          var href = el.getAttribute('href');
          if (href && href !== '#') window.location.href = href;
        }else if (el.form){
          el.form.submit();
        }
      });
  }

  document.addEventListener('click', function(e){
    var el = e.target.closest('[data-confirm]');
    if (!el) return;
    e.preventDefault();
    confirmAction(el);
  });

  /* ===== Conditional settings fields ===== */
  document.querySelectorAll('#qz-settings-form [data-qz-controls]').forEach(function(control){
    var target = document.getElementById(control.getAttribute('data-qz-controls'));
    if (!target) return;
    function syncConditionalField(){
      target.hidden = control.value !== control.getAttribute('data-qz-show-value');
    }
    control.addEventListener('change', syncConditionalField);
    syncConditionalField();
  });

  document.querySelectorAll('#qz-settings-form .qz-check-item input[type="checkbox"]').forEach(function(input){
    function syncCheckItem(){
      var item = input.closest('.qz-check-item');
      if (item) item.classList.toggle('active', input.checked);
    }
    input.addEventListener('change', syncCheckItem);
    syncCheckItem();
  });

  (function wireSettingsSummary(){
    var form = document.getElementById('qz-settings-form');
    if (!form) return;
    var verification = document.querySelector('[data-qz-settings-summary="verification"]');
    var start = document.querySelector('[data-qz-settings-summary="start"]');
    var number = document.querySelector('[data-qz-settings-summary="number"]');
    var globalTitleSummary = document.querySelector('[data-qz-settings-summary="global-title"]');
    var email = form.querySelector('[name="quiz_otp_email_enabled"]');
    var whatsapp = form.querySelector('[name="quiz_otp_whatsapp_enabled"]');
    var mode = form.querySelector('[name="quiz_start_mode"]');
    var limit = form.querySelector('[name="quiz_start_limit"]');
    var format = form.querySelector('[name="quiz_test_number_format"]');
    var globalTitle = form.querySelector('[name="quiz_global_title"]');
    var modeLabels = {
      all: 'All active quizzes',
      selected: 'Selected quizzes',
      primary: 'Primary Quiz only'
    };

    function previewNumber(pattern){
      return String(pattern || '')
        .replace(/\{year\}/g, String(new Date().getFullYear()))
        .replace(/\{quiz_id(?::([1-9]|1[0-2]))?\}/g, function(match, width){
          return String(8).padStart(Number(width || 0), '0');
        })
        .replace(/\{attempt_id(?::([1-9]|1[0-2]))?\}/g, function(match, width){
          return String(123).padStart(Number(width || 0), '0');
        });
    }

    function syncSettingsSummary(){
      if (verification) {
        var methods = [];
        if (email && email.checked) methods.push('Email');
        if (whatsapp && whatsapp.checked) methods.push('WhatsApp');
        verification.textContent = methods.length ? methods.join(' + ') : 'Code from administrator';
      }
      if (start && mode && limit) {
        start.textContent = (modeLabels[mode.value] || 'Invalid configuration') + ', up to ' + (limit.value || '0') + ' quizzes';
      }
      if (number && format) number.textContent = previewNumber(format.value);
      if (globalTitleSummary && globalTitle) globalTitleSummary.textContent = globalTitle.value.trim() || 'Quiz';
    }

    [email, whatsapp, mode, limit, format, globalTitle].forEach(function(control){
      if (!control) return;
      control.addEventListener('input', syncSettingsSummary);
      control.addEventListener('change', syncSettingsSummary);
    });
    syncSettingsSummary();
  }());

  /* ===== Shared copy controls ===== */
  document.querySelectorAll('[data-qz-copy-target]').forEach(function(button){
    var target = document.getElementById(button.getAttribute('data-qz-copy-target'));
    if (!target || button.disabled) return;
    var originalLabel = button.textContent;
    var status = document.createElement('span');
    status.className = 'visually-hidden';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    button.insertAdjacentElement('afterend', status);
    button.addEventListener('click', function(){
      var value = 'value' in target ? target.value : target.textContent;
      value = String(value || '').trim();
      if (!value || value === '______') return;
      var fallback = function(){
        var field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', '');
        field.className = 'qz-copy-fallback';
        (document.getElementById('adam-main') || document.body).appendChild(field);
        field.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (error) {}
        field.remove();
        return copied ? Promise.resolve() : Promise.reject(new Error('Copy failed'));
      };
      var copy;
      try {
        copy = navigator.clipboard && navigator.clipboard.writeText
          ? navigator.clipboard.writeText(value).catch(fallback)
          : fallback();
      } catch (error) {
        copy = fallback();
      }
      copy.then(function(){
        var message = button.getAttribute('data-qz-copy-label') || 'Copied';
        window.clearTimeout(button._qzCopyTimer);
        button.textContent = message;
        status.textContent = message;
        button._qzCopyTimer = window.setTimeout(function(){ button.textContent = originalLabel; }, 1200);
      }).catch(function(){
        var message = 'Copy failed';
        window.clearTimeout(button._qzCopyTimer);
        button.textContent = message;
        status.textContent = message + '. Select the text and copy it manually.';
        button._qzCopyTimer = window.setTimeout(function(){ button.textContent = originalLabel; }, 1800);
      });
    });
  });

  /* ===== Quiz settings sections ===== */
  document.addEventListener('click', function(e){
    var toggle = e.target.closest('.qz-settings-form .settings-section-toggle');
    if (!toggle) return;
    var section = toggle.closest('.settings-section[data-open]');
    if (!section) return;
    var body = section.querySelector('.settings-section-body');
    if (!body) return;
    var open = section.getAttribute('data-open') === '1';
    section.setAttribute('data-open', open ? '0' : '1');
    body.hidden = open;
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
  });

  /* ===== Accessible row-action disclosures ===== */
  var actionToggleSelector = '.qz-action-menu__toggle, [data-qz-action-toggle]';
  var actionPanelSelector = '.qz-action-menu__panel, [data-qz-action-panel]';
  var actionItemSelector = '.qz-action-menu__item, [data-qz-action-item]';
  var actionMenuSelector = '.qz-action-menu, [data-qz-action-menu]';

  function actionPanel(toggle){
    var id = toggle.getAttribute('aria-controls');
    return id ? document.getElementById(id) : null;
  }

  function actionLayer(){
    var layer = document.getElementById('qz-action-layer');
    if (layer) return layer;
    var main = document.getElementById('adam-main');
    if (!main) return null;
    layer = document.createElement('div');
    layer.id = 'qz-action-layer';
    layer.className = 'qz-action-layer';
    main.appendChild(layer);
    return layer;
  }

  function positionActionPanel(toggle, panel){
    var rect = toggle.getBoundingClientRect();
    var gutter = 8;
    var gap = 6;
    panel.style.width = '';
    var width = Math.min(Math.max(panel.offsetWidth, rect.width), window.innerWidth - (gutter * 2));
    panel.style.width = width + 'px';
    panel.style.left = Math.max(gutter, Math.min(rect.right - width, window.innerWidth - width - gutter)) + 'px';

    var height = panel.offsetHeight;
    var top = rect.bottom + gap;
    if (top + height > window.innerHeight - gutter && rect.top - height - gap >= gutter) {
      top = rect.top - height - gap;
    }
    panel.style.top = Math.max(gutter, top) + 'px';
  }

  function setActionMenu(toggle, open, restoreFocus){
    var panel = actionPanel(toggle);
    if (!panel) return;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      var layer = actionLayer();
      if (!layer) return;
      if (!panel._qzHome) panel._qzHome = panel.parentNode;
      panel.style.visibility = 'hidden';
      layer.appendChild(panel);
      panel.hidden = false;
      positionActionPanel(toggle, panel);
      panel.style.visibility = 'visible';
    } else {
      panel.hidden = true;
      panel.style.visibility = '';
      if (panel._qzHome) panel._qzHome.appendChild(panel);
    }
    if (!open && restoreFocus && typeof toggle.focus === 'function') toggle.focus();
  }

  function closeActionMenus(except){
    document.querySelectorAll('.qz-action-menu__toggle[aria-expanded="true"], [data-qz-action-toggle][aria-expanded="true"]').forEach(function(toggle){
      if (toggle !== except) setActionMenu(toggle, false, false);
    });
  }

  document.addEventListener('click', function(e){
    var toggle = e.target.closest(actionToggleSelector);
    if (toggle) {
      var opening = toggle.getAttribute('aria-expanded') !== 'true';
      closeActionMenus(toggle);
      setActionMenu(toggle, opening, false);
      if (opening && e.detail === 0) {
        var openedPanel = actionPanel(toggle);
        var firstAction = openedPanel && openedPanel.querySelector('a[href], button:not([disabled])');
        if (firstAction) firstAction.focus();
      }
      return;
    }
    if (e.target.closest('.newnotif-confirm')) return;
    var panel = e.target.closest(actionPanelSelector);
    if (panel) {
      var owner = document.getElementById(panel.getAttribute('aria-labelledby') || '');
      if (owner && e.target.closest(actionItemSelector)) setActionMenu(owner, false, false);
      return;
    }
    if (!e.target.closest(actionMenuSelector)) closeActionMenus(null);
  });

  document.addEventListener('keydown', function(e){
    if (e.defaultPrevented) return;
    var toggle = e.target.closest(actionToggleSelector);
    if (e.key === 'ArrowDown' && toggle) {
      e.preventDefault();
      closeActionMenus(toggle);
      setActionMenu(toggle, true, false);
      var panel = actionPanel(toggle);
      var first = panel && panel.querySelector('a[href], button:not([disabled])');
      if (first) first.focus();
      return;
    }
    if (e.key !== 'Escape') return;
    var openPanel = e.target.closest(actionPanelSelector);
    var menu = e.target.closest(actionMenuSelector);
    if (!openPanel && !menu) return;
    toggle = openPanel
      ? document.getElementById(openPanel.getAttribute('aria-labelledby') || '')
      : menu.querySelector(actionToggleSelector);
    if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
      e.preventDefault();
      setActionMenu(toggle, false, true);
    }
  });

  window.addEventListener('resize', function(){ closeActionMenus(null); });
  window.addEventListener('scroll', function(){ closeActionMenus(null); }, true);

  /* ===== Current-page bulk quiz selection ===== */
  document.querySelectorAll('[data-bulk-form]').forEach(function(form){
    var englishUi = !!form.closest('.qz-shell');
    var all = form.querySelector('[data-bulk-all]');
    var items = Array.prototype.slice.call(form.querySelectorAll('[data-bulk-item]'));
    var count = form.querySelector('[data-bulk-count]');
    var action = form.querySelector('[data-bulk-action]');
    var submit = form.querySelector('[data-bulk-submit]');

    function syncBulk(){
      var selected = items.filter(function(item){ return item.checked; });
      if (all) {
        all.checked = items.length > 0 && selected.length === items.length;
        all.indeterminate = selected.length > 0 && selected.length < items.length;
      }
      if (count) count.textContent = selected.length + (englishUi ? ' selected' : ' dipilih');
      if (submit) submit.disabled = selected.length === 0 || !action || action.value === '';
      items.forEach(function(item){
        var row = item.closest('.qz-row, .qz-select-card');
        if (row) row.classList.toggle('is-selected', item.checked);
      });
    }

    if (all) all.addEventListener('change', function(){
      items.forEach(function(item){ item.checked = all.checked; });
      syncBulk();
    });
    items.forEach(function(item){ item.addEventListener('change', syncBulk); });
    if (action) action.addEventListener('change', syncBulk);

    form.addEventListener('submit', function(e){
      e.preventDefault();
      var selected = items.filter(function(item){ return item.checked; });
      if (selected.length === 0 || !action || !/^[01]$/.test(action.value)) return;
      var label = action.value === '1'
        ? (englishUi ? 'activate' : 'mengaktifkan')
        : (englishUi ? 'deactivate' : 'menonaktifkan');
      NewNotifConfirm.warning({
        message: englishUi
          ? 'Are you sure you want to ' + label + ' ' + selected.length + ' selected quizzes?'
          : 'Yakin ingin ' + label + ' ' + selected.length + ' quiz terpilih?',
        variant: 'warning'
      }).then(function(ok){ if (ok) form.submit(); });
    });

    syncBulk();
  });

  /* ===== Current-page question bulk actions ===== */
  document.querySelectorAll('[data-question-bulk-form]').forEach(function(form){
    var all = form.querySelector('[data-question-bulk-all]');
    var items = Array.prototype.slice.call(form.elements).filter(function(control){
      return control.matches && control.matches('[data-question-bulk-item]');
    });
    var count = form.querySelector('[data-question-bulk-count]');
    var action = form.querySelector('[data-question-bulk-action]');
    var submit = form.querySelector('[data-question-bulk-submit]');

    function syncQuestionBulk(){
      var selected = items.filter(function(item){ return item.checked; });
      if (all) {
        all.checked = items.length > 0 && selected.length === items.length;
        all.indeterminate = selected.length > 0 && selected.length < items.length;
        all.disabled = items.length === 0;
      }
      if (count) count.textContent = selected.length + ' dipilih';
      if (submit) submit.disabled = selected.length === 0 || !action || action.value === '';
      items.forEach(function(item){
        var row = item.closest('tr');
        if (row) row.classList.toggle('is-selected', item.checked);
      });
    }

    if (all) all.addEventListener('change', function(){
      items.forEach(function(item){ item.checked = all.checked; });
      syncQuestionBulk();
    });
    items.forEach(function(item){ item.addEventListener('change', syncQuestionBulk); });
    if (action) action.addEventListener('change', syncQuestionBulk);

    form.addEventListener('submit', function(event){
      event.preventDefault();
      var selected = items.filter(function(item){ return item.checked; });
      var labels = { activate:'mengaktifkan', deactivate:'menonaktifkan', delete:'menghapus' };
      var verb = action ? labels[action.value] : '';
      if (!selected.length || !verb) return;
      var options = {
        message: 'Yakin ingin ' + verb + ' ' + selected.length + ' soal terpilih?',
        variant: action.value === 'delete' ? 'danger' : 'warning'
      };
      (action.value === 'delete' ? NewNotifConfirm.danger : NewNotifConfirm.warning)(options)
        .then(function(ok){ if (ok) form.submit(); });
    });

    syncQuestionBulk();
  });

  /* ===== Category to passage filter cascade ===== */
  document.querySelectorAll('[data-question-category-filter]').forEach(function(category){
    var form = category.closest('form');
    var passage = form ? form.querySelector('[data-question-passage-filter]') : null;
    var endpoint = passage ? passage.getAttribute('data-passages-url') : '';
    var quizId = passage ? passage.getAttribute('data-quiz-id') : '';
    if (!passage || !endpoint || !quizId) return;
    var requestNumber = 0;
    function syncPassageFilter(){
      var categoryId = String(category.value || '');
      var currentRequest = ++requestNumber;
      var url = new URL(endpoint, window.location.href);
      url.searchParams.set('quiz_id', quizId);
      if (categoryId) url.searchParams.set('category_id', categoryId);
      else url.searchParams.delete('category_id');
      passage.disabled = true;
      passage.setAttribute('aria-busy', 'true');
      passage.replaceChildren(new Option('Memuat passage...', ''));
      fetch(url.toString(), {
        credentials:'same-origin',
        headers:{ 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' }
      }).then(function(response){
        return response.text().then(function(text){
          var data = null;
          try { data = JSON.parse(text); } catch (error) {}
          if (!response.ok || !data || data.ok !== true || !Array.isArray(data.passages)) {
            throw new Error(data && data.error ? data.error : 'Request gagal.');
          }
          return data.passages;
        });
      }).then(function(passages){
        if (currentRequest !== requestNumber) return;
        var placeholder = categoryId ? 'Semua passage kategori ini' : 'Semua passage tanpa kategori';
        var options = [new Option(placeholder, '')];
        passages.forEach(function(item){ options.push(new Option(String(item.title || ('Passage #' + item.id)), String(item.id))); });
        passage.replaceChildren.apply(passage, options);
        passage.disabled = false;
        passage.removeAttribute('aria-busy');
      }).catch(function(){
        if (currentRequest !== requestNumber) return;
        passage.replaceChildren(new Option('Gagal memuat passage', ''));
        passage.disabled = false;
        passage.removeAttribute('aria-busy');
      });
    }
    category.addEventListener('change', syncPassageFilter);
  });

  /* ===== Persisted list column preferences ===== */
  function wireColumnPicker(picker, tableSelector, controlSelector, fallbackStorage){
    var scope = picker.closest('[data-column-scope]') || document;
    var table = scope.querySelector(tableSelector);
    if (!table) return;
    var controls = Array.prototype.slice.call(picker.querySelectorAll(controlSelector));
    var storageKey = picker.getAttribute('data-storage-key') || fallbackStorage;
    var state = {};
    controls.forEach(function(control){ state[control.getAttribute(controlSelector.slice(1, -1))] = control.checked; });
    try {
      var stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
      controls.forEach(function(control){
        var column = control.getAttribute(controlSelector.slice(1, -1));
        if (typeof stored[column] === 'boolean') state[column] = stored[column];
      });
    } catch (error) {}

    function syncColumns(persist){
      controls.forEach(function(control){
        var column = control.getAttribute(controlSelector.slice(1, -1));
        control.checked = state[column] === true;
        table.querySelectorAll('[data-column="' + column + '"]').forEach(function(cell){
          cell.hidden = !state[column];
        });
      });
      if (persist) {
        try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (error) {}
      }
    }

    controls.forEach(function(control){
      control.addEventListener('change', function(){
        state[control.getAttribute(controlSelector.slice(1, -1))] = control.checked;
        syncColumns(true);
      });
    });
    syncColumns(false);
  }
  document.querySelectorAll('[data-question-column-picker]').forEach(function(picker){
    wireColumnPicker(picker, '[data-question-column-table]', '[data-question-column]', 'quiz-question-columns-v1');
  });
  document.querySelectorAll('[data-student-column-picker]').forEach(function(picker){
    wireColumnPicker(picker, '[data-student-column-table]', '[data-student-column]', 'quiz-student-columns-v1');
  });
  document.querySelectorAll('[data-attempt-column-picker]').forEach(function(picker){
    wireColumnPicker(picker, '[data-attempt-column-table]', '[data-attempt-column]', 'quiz-attempt-columns-v1');
    var scope = picker.closest('[data-column-scope]') || document;
    var exportForm = scope.querySelector('[data-attempt-export-form]');
    if (!exportForm) return;
    var controls = Array.prototype.slice.call(picker.querySelectorAll('[data-attempt-column]'));
    function syncAttemptExportColumns(){
      exportForm.querySelectorAll('[data-attempt-export-column]').forEach(function(input){ input.remove(); });
      ['student', 'status'].concat(controls.filter(function(control){
        return control.checked;
      }).map(function(control){
        return control.getAttribute('data-attempt-column');
      })).forEach(function(column){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'columns[]';
        input.value = column;
        input.setAttribute('data-attempt-export-column', '');
        exportForm.appendChild(input);
      });
    }
    controls.forEach(function(control){ control.addEventListener('change', syncAttemptExportColumns); });
    syncAttemptExportColumns();
  });

  /* ===== Aggregate legacy question editor ===== */
  function renderQuestionMarkdown(source, target){
    if (!target) return;
    var markdown = String(source || '');
    if (!window.marked || !window.DOMPurify) {
      target.textContent = markdown;
      return;
    }
    var html = '';
    try { html = window.marked.parse(markdown, { mangle:false, headerIds:false }); }
    catch (error) { html = '<pre>Markdown error</pre>'; }
    target.innerHTML = window.DOMPurify.sanitize(html, { USE_PROFILES:{ html:true } });
    if (typeof window.renderMathInElement === 'function') {
      try {
        window.renderMathInElement(target, {
          delimiters:[
            { left:'$$', right:'$$', display:true },
            { left:'$', right:'$', display:false },
            { left:'\\(', right:'\\)', display:false },
            { left:'\\[', right:'\\]', display:true }
          ],
          throwOnError:false,
          strict:'ignore',
          ignoredTags:['script','noscript','style','textarea','pre','code']
        });
      } catch (error) {}
    }
  }

  function injectQuestionModalHtml(container, html){
    var parsed = new DOMParser().parseFromString(html, 'text/html');
    var fragment = document.createDocumentFragment();
    Array.prototype.slice.call(parsed.body.childNodes).forEach(function(node){
      if (node.nodeName && node.nodeName.toLowerCase() === 'script') return;
      fragment.appendChild(document.importNode(node, true));
    });
    container.replaceChildren(fragment);
    parsed.querySelectorAll('script').forEach(function(script){
      if (script.src) {
        if (document.querySelector('script[src="' + script.src + '"]')) return;
        var external = document.createElement('script');
        external.src = script.src;
        external.async = false;
        document.head.appendChild(external);
      } else {
        var inline = document.createElement('script');
        inline.text = script.textContent;
        document.body.appendChild(inline);
      }
    });
  }

  function openQuestionSelector(url, eventName, messageType){
    return new Promise(function(resolve){
      var backdrop = document.createElement('div');
      backdrop.className = 'qz-selector-backdrop';
      var dialog = document.createElement('div');
      dialog.className = 'qz-selector-dialog';
      dialog.innerHTML = '<button type="button" class="qz-selector-close" aria-label="Tutup">&times;</button><div class="qz-selector-content">Memuat...</div>';
      backdrop.appendChild(dialog);
      (document.getElementById('adam-main') || document.body).appendChild(backdrop);
      var finished = false;
      function cleanup(){
        document.removeEventListener(eventName, onEvent);
        window.removeEventListener('message', onMessage);
        document.removeEventListener('keydown', onKey);
      }
      function finish(value){
        if (finished) return;
        finished = true;
        cleanup();
        backdrop.remove();
        resolve(value || null);
      }
      function onEvent(event){ finish(event && event.detail ? event.detail : null); }
      function onMessage(event){
        if (event && event.data && (event.data.type === messageType || event.data.type === eventName)) finish(event.data.detail || null);
      }
      function onKey(event){ if (event.key === 'Escape') finish(null); }
      dialog.querySelector('.qz-selector-close').addEventListener('click', function(){ finish(null); });
      backdrop.addEventListener('click', function(event){ if (event.target === backdrop) finish(null); });
      document.addEventListener(eventName, onEvent);
      window.addEventListener('message', onMessage);
      document.addEventListener('keydown', onKey);
      fetch(url, { credentials:'include' })
        .then(function(response){ if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
        .then(function(html){ injectQuestionModalHtml(dialog.querySelector('.qz-selector-content'), html); })
        .catch(function(){ dialog.querySelector('.qz-selector-content').textContent = 'Gagal memuat selector.'; });
    });
  }

  function postQuestionEditorJson(url, payload){
    return fetch(url, {
      method:'POST',
      credentials:'same-origin',
      headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' },
      body:JSON.stringify(payload)
    }).then(function(response){
      return response.text().then(function(text){
        var data = null;
        try { data = JSON.parse(text); } catch (error) {}
        if (!response.ok || !data || data.ok !== true) throw new Error(data && data.error ? data.error : 'Request gagal.');
        return data;
      });
    });
  }

  document.querySelectorAll('[data-question-aggregate-editor]').forEach(function(editor){
    var quizId = Number(editor.getAttribute('data-quiz-id'));
    var questionId = Number(editor.getAttribute('data-question-id'));
    var questionEditUrl = editor.getAttribute('data-question-edit-url') || '';
    var csrf = editor.getAttribute('data-csrf') || '';
    var canEditQuestion = editor.getAttribute('data-can-edit') === '1';
    var mainForm = editor.querySelector('[data-question-main-form]');
    var mainSource = editor.querySelector('[data-markdown-source]');
    var mainPreview = editor.querySelector('.qz-editor-section [data-markdown-preview]');
    var questionType = editor.querySelector('select[name="question_type"]');
    var questionActive = editor.querySelector('select[name="is_active"]');
    var questionSaveStatus = editor.querySelector('[data-question-save-status]');
    var questionSaveRetry = editor.querySelector('[data-question-save-retry]');
    var answerAdd = editor.querySelector('[data-answer-add]');
    var createNote = editor.querySelector('[data-question-create-note]');
    var questionSaveTimer = null;
    var questionSaveRevision = 0;
    var questionSaveInFlight = false;
    function setQuestionSaveState(state, message){
      editor.setAttribute('data-question-save-state', state);
      if (questionSaveStatus) {
        questionSaveStatus.setAttribute('data-state', state);
        questionSaveStatus.textContent = message;
      }
      if (questionSaveRetry) questionSaveRetry.hidden = state !== 'error';
    }
    function scheduleQuestionAutosave(){
      if (!canEditQuestion) return;
      questionSaveRevision++;
      clearTimeout(questionSaveTimer);
      setQuestionSaveState('dirty', 'Menunggu autosave soal...');
      questionSaveTimer = setTimeout(saveQuestionNow, 650);
    }
    function saveQuestionNow(){
      if (!canEditQuestion || !mainForm) return;
      if (questionSaveInFlight) {
        clearTimeout(questionSaveTimer);
        questionSaveTimer = setTimeout(saveQuestionNow, 250);
        return;
      }
      var questionText = String(mainSource ? mainSource.value : '').trim();
      if (!questionText) {
        setQuestionSaveState('error', 'Soal wajib diisi sebelum dapat disimpan.');
        return;
      }
      var passageId = passage && passage.value !== '' ? Number(passage.value) : null;
      var passageSequence = passageId && passageSeq && passageSeq.value !== '' ? Number(passageSeq.value) : null;
      var categoryId = !passageId && category && category.value !== '' ? Number(category.value) : null;
      var submittedRevision = questionSaveRevision;
      questionSaveInFlight = true;
      setQuestionSaveState('saving', 'Menyimpan perubahan soal...');
      postQuestionEditorJson(editor.getAttribute('data-question-save-url'), {
        csrf_token:csrf,
        quiz_id:quizId,
        id:questionId,
        question_text:questionText,
        question_type:questionType ? questionType.value : 'single_choice',
        is_active:Number(questionActive ? questionActive.value : 1),
        image_media_id:imageId && imageId.value !== '' ? Number(imageId.value) : null,
        file_id:fileId && fileId.value !== '' ? Number(fileId.value) : null,
        youtube_url:youtubeUrl ? youtubeUrl.value.trim() : '',
        passage_id:passageId,
        passage_seq:passageSequence,
        category_id:categoryId
      }).then(function(data){
        if (questionId <= 0) {
          var createdId = Number(data.id || 0);
          if (createdId <= 0) throw new Error('ID soal baru tidak diterima.');
          questionId = createdId;
          editor.setAttribute('data-question-id', String(createdId));
          editor.setAttribute('data-question-is-new', '0');
          if (answerAdd) answerAdd.disabled = false;
          if (createNote) createNote.hidden = true;
          var editorTitle = editor.querySelector('[data-question-editor-title]');
          if (editorTitle) editorTitle.textContent = 'Edit Soal';
          if (questionEditUrl && window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState({}, '', questionEditUrl.replace('__QUESTION_ID__', encodeURIComponent(String(createdId))));
          }
        }
        questionSaveInFlight = false;
        if (questionSaveRevision === submittedRevision) setQuestionSaveState('saved', 'Semua perubahan soal tersimpan.');
        else {
          setQuestionSaveState('dirty', 'Ada perubahan soal baru yang belum tersimpan.');
          clearTimeout(questionSaveTimer);
          questionSaveTimer = setTimeout(saveQuestionNow, 250);
        }
      }).catch(function(error){
        questionSaveInFlight = false;
        setQuestionSaveState('error', error.message);
      });
    }
    if (mainSource && mainPreview) {
      renderQuestionMarkdown(mainSource.value, mainPreview);
      mainSource.addEventListener('input', function(){ renderQuestionMarkdown(mainSource.value, mainPreview); scheduleQuestionAutosave(); });
    }

    var passage = editor.querySelector('[data-question-passage]');
    var passageSeq = editor.querySelector('[data-question-passage-seq]');
    var category = editor.querySelector('[data-question-category]');
    var editableContext = !!(passage && !passage.disabled);
    function syncQuestionContext(){
      if (!editableContext) return;
      var hasPassage = passage.value !== '';
      if (passageSeq) passageSeq.disabled = !hasPassage;
      if (category) {
        category.disabled = hasPassage;
        if (hasPassage) category.value = '';
      }
    }
    if (passage) {
      passage.addEventListener('change', syncQuestionContext);
      syncQuestionContext();
    }

    var imageId = editor.querySelector('[data-question-image-id]');
    var imagePreview = editor.querySelector('[data-question-image-preview]');
    var imageEmpty = editor.querySelector('[data-question-image-empty]');
    var imageLabel = editor.querySelector('[data-question-image-label]');
    var attachmentState = editor.querySelector('[data-question-attachment-state]');
    var youtubeUrl = editor.querySelector('input[name="youtube_url"]');
    function syncQuestionAttachmentState(){
      var count = (imageId && Number(imageId.value) > 0 ? 1 : 0)
        + (fileId && Number(fileId.value) > 0 ? 1 : 0)
        + (youtubeUrl && youtubeUrl.value.trim() !== '' ? 1 : 0);
      if (attachmentState) attachmentState.textContent = count > 0 ? count + ' terisi' : 'Belum ada';
    }
    function setQuestionImage(id, url){
      if (imageId) imageId.value = id ? String(id) : '';
      if (imageLabel) imageLabel.textContent = id ? String(id) : '-';
      if (imagePreview) {
        if (url) { imagePreview.src = url; imagePreview.hidden = false; }
        else { imagePreview.removeAttribute('src'); imagePreview.hidden = true; }
      }
      if (imageEmpty) imageEmpty.hidden = !!url;
      syncQuestionAttachmentState();
      scheduleQuestionAutosave();
    }
    editor.querySelector('[data-question-pick-image]')?.addEventListener('click', function(){
      openQuestionSelector(editor.getAttribute('data-media-url'), 'media:insert', 'media:insert').then(function(detail){
        if (!detail) return;
        var media = detail.media && typeof detail.media === 'object' ? detail.media : detail;
        var id = Number(media.id || 0);
        if (id > 0) setQuestionImage(id, String(media.url || ''));
      });
    });
    editor.querySelector('[data-question-clear-image]')?.addEventListener('click', function(){ setQuestionImage(null, ''); });

    var fileId = editor.querySelector('[data-question-file-id]');
    var fileLabel = editor.querySelector('[data-question-file-label]');
    var fileIdLabel = editor.querySelector('[data-question-file-id-label]');
    function setQuestionFile(id, label){
      if (fileId) fileId.value = id ? String(id) : '';
      if (fileIdLabel) fileIdLabel.textContent = id ? String(id) : '-';
      if (fileLabel) fileLabel.textContent = id ? (label || ('File #' + id)) : 'Belum ada file';
      syncQuestionAttachmentState();
      scheduleQuestionAutosave();
    }
    editor.querySelector('[data-question-pick-file]')?.addEventListener('click', function(){
      openQuestionSelector(editor.getAttribute('data-file-url'), 'file:insert', 'file:insert').then(function(detail){
        if (!detail) return;
        var file = detail.file && typeof detail.file === 'object' ? detail.file : detail;
        var id = Number(file.id || 0);
        var label = file.filename || file.title || file.name || file.original_name || '';
        if (id > 0) setQuestionFile(id, String(label));
      });
    });
    editor.querySelector('[data-question-clear-file]')?.addEventListener('click', function(){ setQuestionFile(null, ''); });
    if (youtubeUrl) youtubeUrl.addEventListener('input', function(){ syncQuestionAttachmentState(); scheduleQuestionAutosave(); });
    syncQuestionAttachmentState();
    if (questionType) questionType.addEventListener('change', scheduleQuestionAutosave);
    if (questionActive) questionActive.addEventListener('change', scheduleQuestionAutosave);
    if (passage) passage.addEventListener('change', scheduleQuestionAutosave);
    if (passageSeq) passageSeq.addEventListener('input', scheduleQuestionAutosave);
    if (category) category.addEventListener('change', scheduleQuestionAutosave);
    if (mainForm) mainForm.addEventListener('submit', function(event){ event.preventDefault(); clearTimeout(questionSaveTimer); saveQuestionNow(); });
    questionSaveRetry?.addEventListener('click', function(){ clearTimeout(questionSaveTimer); saveQuestionNow(); });
    if (canEditQuestion) {
      if (questionId > 0) setQuestionSaveState('saved', 'Semua perubahan soal tersimpan.');
      else setQuestionSaveState('new', 'Mulai tulis soal untuk membuat record baru.');
    }

    var answerList = editor.querySelector('[data-answer-list]');
    function syncAnswerEmpty(){
      if (!answerList || answerList.querySelector('[data-answer-row]') || editor.querySelector('[data-answer-empty]')) return;
      var empty = document.createElement('div');
      empty.className = 'empty';
      empty.setAttribute('data-answer-empty', '');
      empty.textContent = 'Belum ada jawaban untuk soal ini.';
      answerList.insertAdjacentElement('afterend', empty);
    }
    function wireAnswerRow(row){
      var text = row.querySelector('[data-answer-text]');
      var preview = row.querySelector('[data-markdown-preview]');
      var scoreInput = row.querySelector('[data-answer-score]');
      var activeSelect = row.querySelector('[data-answer-active]');
      var retryButton = row.querySelector('[data-answer-save]');
      var deleteButton = row.querySelector('[data-answer-delete]');
      var status = row.querySelector('[data-answer-status]');
      var revision = 0;
      var saveInFlight = false;
      var saveTimer = null;
      function setAnswerSaveState(state, message){
        row.setAttribute('data-save-state', state);
        if (status) {
          status.setAttribute('data-state', state);
          status.textContent = message;
        }
        if (retryButton) retryButton.hidden = state !== 'error';
        if (deleteButton) deleteButton.disabled = saveInFlight;
      }
      function scheduleAnswerAutosave(){
        if (!canEditQuestion) return;
        revision++;
        clearTimeout(saveTimer);
        if (!String(text ? text.value : '').trim()) {
          setAnswerSaveState('dirty', 'Isi teks jawaban untuk memulai autosave.');
          return;
        }
        setAnswerSaveState('dirty', 'Menunggu autosave jawaban...');
        saveTimer = setTimeout(saveAnswerNow, 650);
      }
      function saveAnswerNow(){
        if (!canEditQuestion) return;
        clearTimeout(saveTimer);
        if (questionId <= 0) {
          setAnswerSaveState('dirty', 'Simpan isi soal terlebih dahulu.');
          return;
        }
        if (saveInFlight) {
          saveTimer = setTimeout(saveAnswerNow, 250);
          return;
        }
        var answerText = String(text ? text.value : '').trim();
        var score = Number(scoreInput ? scoreInput.value : 0);
        if (!answerText) {
          setAnswerSaveState('dirty', 'Isi teks jawaban untuk memulai autosave.');
          return;
        }
        if (!Number.isFinite(score) || score < 0 || score > 100) {
          setAnswerSaveState('error', 'Skor jawaban harus antara 0 sampai 100.');
          return;
        }
        var submittedRevision = revision;
        saveInFlight = true;
        setAnswerSaveState('saving', 'Menyimpan jawaban otomatis...');
        postQuestionEditorJson(editor.getAttribute('data-answer-save-url'), {
          csrf_token:csrf,
          quiz_id:quizId,
          question_id:questionId,
          id:Number(row.getAttribute('data-answer-id') || 0),
          answer_text:answerText,
          score:score,
          is_active:Number(activeSelect ? activeSelect.value : 0)
        }).then(function(data){
          row.setAttribute('data-answer-id', String(data.id));
          saveInFlight = false;
          if (revision === submittedRevision) setAnswerSaveState('saved', 'Jawaban tersimpan otomatis.');
          else {
            setAnswerSaveState('dirty', 'Ada perubahan jawaban baru yang belum tersimpan.');
            saveTimer = setTimeout(saveAnswerNow, 250);
          }
          editor.querySelector('[data-answer-empty]')?.remove();
        }).catch(function(error){
          saveInFlight = false;
          setAnswerSaveState('error', error.message);
        });
      }
      if (text && preview) {
        renderQuestionMarkdown(text.value, preview);
        text.addEventListener('input', function(){ renderQuestionMarkdown(text.value, preview); });
      }
      if (text) text.addEventListener('input', scheduleAnswerAutosave);
      if (scoreInput) scoreInput.addEventListener('input', scheduleAnswerAutosave);
      if (activeSelect) activeSelect.addEventListener('change', scheduleAnswerAutosave);
      if (canEditQuestion) {
        if (Number(row.getAttribute('data-answer-id') || 0) > 0) setAnswerSaveState('saved', 'Jawaban tersimpan otomatis.');
        else setAnswerSaveState('dirty', 'Isi teks jawaban untuk memulai autosave.');
      } else {
        setAnswerSaveState('readonly', 'Mode baca saja.');
      }
      retryButton?.addEventListener('click', saveAnswerNow);
      deleteButton?.addEventListener('click', function(){
        clearTimeout(saveTimer);
        var id = Number(row.getAttribute('data-answer-id') || 0);
        if (id <= 0) { row.remove(); syncAnswerEmpty(); return; }
        NewNotifConfirm.danger({ message:'Hapus jawaban ini?', variant:'danger' }).then(function(ok){
          if (!ok) return;
          postQuestionEditorJson(editor.getAttribute('data-answer-delete-url'), {
            csrf_token:csrf, quiz_id:quizId, question_id:questionId, id:id
          }).then(function(){ row.remove(); syncAnswerEmpty(); }).catch(function(error){
            var status = row.querySelector('[data-answer-status]');
            if (status) status.textContent = error.message;
          });
        });
      });
    }
    if (answerList) answerList.querySelectorAll('[data-answer-row]').forEach(wireAnswerRow);
    answerAdd?.addEventListener('click', function(){
      if (questionId <= 0) return;
      var template = editor.querySelector('[data-answer-template]');
      if (!template || !answerList) return;
      var row = template.content.firstElementChild.cloneNode(true);
      answerList.appendChild(row);
      editor.querySelector('[data-answer-empty]')?.remove();
      wireAnswerRow(row);
      row.querySelector('[data-answer-text]')?.focus();
    });
    window.addEventListener('beforeunload', function(event){
      var questionState = editor.getAttribute('data-question-save-state');
      var questionPending = questionState === 'dirty' || questionState === 'saving' || questionState === 'error';
      var answerPending = editor.querySelector('[data-answer-row][data-save-state="dirty"], [data-answer-row][data-save-state="saving"], [data-answer-row][data-save-state="error"]');
      if (!questionPending && !answerPending) return;
      event.preventDefault();
      event.returnValue = '';
    });
  });

  /* ===== Submitted response correction ===== */
  var correctionModalSelector = '#qz-response-correction-modal';

  function correctionPayload(){
    var script = document.getElementById('qz-response-correction-data');
    if (!script) return null;
    if (script._qzCorrectionPayload) return script._qzCorrectionPayload;
    try {
      var parsed = JSON.parse(script.textContent || '');
      if (!parsed || typeof parsed !== 'object' || !parsed.questions || typeof parsed.questions !== 'object') return null;
      script._qzCorrectionPayload = parsed;
      return parsed;
    } catch (error) {
      return null;
    }
  }

  function correctionMessage(modal, message){
    var error = modal.querySelector('[data-qz-correction-message]');
    if (!error) return;
    error.textContent = message || '';
    error.hidden = !message;
    if (message) error.setAttribute('tabindex', '-1');
    else error.removeAttribute('tabindex');
  }

  function correctionElement(tag, className, text){
    var element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = String(text);
    return element;
  }

  function renderCorrectionKey(container, question){
    container.replaceChildren();
    if (question.question_type === 'text' || question.question_type === 'number') {
      container.appendChild(correctionElement(
        'p',
        'qz-correction-key__note',
        'No objective answer key is stored for this response type. Its computed score remains zero.'
      ));
      return;
    }
    var list = correctionElement('ul', 'qz-correction-key__list');
    (Array.isArray(question.options) ? question.options : []).forEach(function(option){
      var item = correctionElement('li', 'qz-correction-key__item' + (Number(option.score) > 0 ? ' is-positive' : ''));
      var label = correctionElement('span', 'qz-correction-key__label', option.label || ('Option #' + option.id));
      var score = Number(option.score) || 0;
      var badge = correctionElement(
        'strong',
        'qz-correction-key__score',
        score > 0 ? 'Answer key · +' + score + (score === 1 ? ' point' : ' points') : '0 points'
      );
      item.appendChild(label);
      item.appendChild(badge);
      list.appendChild(item);
    });
    if (!list.children.length) list.appendChild(correctionElement('li', 'qz-correction-key__note', 'No snapshotted options are available.'));
    container.appendChild(list);
  }

  function renderCorrectionControls(fieldset, question){
    var legend = correctionElement('legend', '', 'Corrected response');
    fieldset.replaceChildren(legend);
    var current = question.current_response || {};
    var type = question.question_type;
    if (type === 'single_choice' || type === 'yesno' || type === 'multiple_choice') {
      var options = correctionElement('div', 'qz-correction-options');
      (Array.isArray(question.options) ? question.options : []).forEach(function(option, index){
        var label = correctionElement('label', 'qz-correction-option');
        var input = document.createElement('input');
        input.type = type === 'multiple_choice' ? 'checkbox' : 'radio';
        input.name = type === 'multiple_choice' ? 'selected_answer_ids' : 'selected_answer_id';
        input.value = String(option.id);
        if (type === 'multiple_choice') {
          input.checked = Array.isArray(current.selected_answer_ids)
            && current.selected_answer_ids.map(Number).indexOf(Number(option.id)) !== -1;
        } else {
          input.checked = Number(current.selected_answer_id) === Number(option.id);
        }
        if (index === 0 && type !== 'multiple_choice') input.required = true;
        var copy = correctionElement('span', 'qz-correction-option__copy');
        copy.appendChild(correctionElement('strong', '', option.label || ('Option #' + option.id)));
        copy.appendChild(correctionElement('small', '', (Number(option.score) || 0) + ' points'));
        label.appendChild(input);
        label.appendChild(copy);
        options.appendChild(label);
      });
      fieldset.appendChild(options);
      return;
    }
    var input;
    if (type === 'text') {
      input = document.createElement('textarea');
      input.rows = 4;
    } else {
      input = document.createElement('input');
      input.type = 'number';
      input.step = 'any';
      input.inputMode = 'decimal';
    }
    input.className = 'adam-input qz-correction-text';
    input.name = 'answer_text';
    input.value = current.answer_text == null ? '' : String(current.answer_text);
    if (type === 'text') input.maxLength = 20000;
    fieldset.appendChild(input);
  }

  function unlockCorrectionScroll(modal){
    if (!modal || !modal._qzScrollState) return;
    document.documentElement.style.overflow = modal._qzScrollState.html;
    document.body.style.overflow = modal._qzScrollState.body;
    modal._qzScrollState = null;
    if (modal._qzRemovalObserver) modal._qzRemovalObserver.disconnect();
    modal._qzRemovalObserver = null;
  }

  function closeCorrectionModal(modal, restoreFocus){
    if (!modal || modal.getAttribute('aria-hidden') === 'true') return;
    if (modal.getAttribute('aria-busy') === 'true') return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.hidden = true;
    unlockCorrectionScroll(modal);
    var trigger = modal._qzCorrectionTrigger;
    modal._qzCorrectionTrigger = null;
    modal._qzCorrectionQuestion = null;
    if (restoreFocus && trigger && trigger.isConnected && typeof trigger.focus === 'function') trigger.focus();
  }

  function openCorrectionModal(button){
    var modal = document.querySelector(correctionModalSelector);
    var payload = correctionPayload();
    var questionId = String(button.getAttribute('data-question-id') || '');
    var question = payload && payload.questions ? payload.questions[questionId] : null;
    if (!modal || !question) return;
    if (question.correction_eligible !== true) {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
        window.NewNotifToast.show({type:'warning', title:'Correction unavailable', message:'This historical Attempt does not have an answer-key snapshot captured before submission.'});
      }
      return;
    }

    var form = modal.querySelector('[data-qz-correction-form]');
    var questionBox = modal.querySelector('[data-qz-correction-question]');
    var currentBox = modal.querySelector('[data-qz-correction-current]');
    var keyBox = modal.querySelector('[data-qz-correction-key]');
    var controls = modal.querySelector('[data-qz-correction-controls]');
    var reason = form && form.elements.reason;
    if (!form || !questionBox || !currentBox || !keyBox || !controls || !reason) return;

    questionBox.textContent = String(question.question_text || '');
    currentBox.textContent = String((question.current_response || {}).answer_label || '—');
    renderCorrectionKey(keyBox, question);
    renderCorrectionControls(controls, question);
    reason.value = '';
    reason.removeAttribute('aria-invalid');
    correctionMessage(modal, '');
    var status = modal.querySelector('[data-qz-correction-status]');
    if (status) status.textContent = '';

    modal._qzCorrectionTrigger = button;
    modal._qzCorrectionQuestion = question;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    modal._qzScrollState = {
      html: document.documentElement.style.overflow,
      body: document.body.style.overflow
    };
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    if (window.MutationObserver) {
      modal._qzRemovalObserver = new MutationObserver(function(){
        if (!modal.isConnected) unlockCorrectionScroll(modal);
      });
      modal._qzRemovalObserver.observe(document.documentElement, { childList:true, subtree:true });
    }
    var dialog = modal.querySelector('[data-qz-correction-dialog]');
    if (dialog) dialog.focus();
  }

  function setCorrectionLoading(modal, loading){
    var form = modal.querySelector('[data-qz-correction-form]');
    modal.setAttribute('aria-busy', loading ? 'true' : 'false');
    modal.classList.toggle('is-loading', loading);
    if (!form) return;
    form.querySelectorAll('button, input, textarea').forEach(function(control){
      if (loading) {
        control._qzWasDisabled = control.disabled;
        control.disabled = true;
      } else {
        control.disabled = !!control._qzWasDisabled;
        delete control._qzWasDisabled;
      }
    });
    var status = modal.querySelector('[data-qz-correction-status]');
    if (status) status.textContent = loading ? 'Applying correction…' : '';
  }

  function correctionErrorMessage(status, data){
    var supplied = data && (data.message || (typeof data.error === 'string' ? data.error : data.error && data.error.message));
    if (supplied) return String(supplied);
    if (status === 400) return 'Check the corrected response and reason, then try again.';
    if (status === 409) return 'This response changed after the dialog was opened. Refresh the page before correcting it.';
    if (status === 419) return 'Your session expired. Refresh the page and try again.';
    if (status >= 500) return 'The correction could not be saved because of a server error.';
    return 'The correction could not be saved.';
  }

  function appendCorrectionHistory(row, history, answeredAt){
    if (!row || !history) return;
    var details = row.querySelector('[data-qz-response-history]');
    var list = details && details.querySelector('[data-qz-history-list]');
    if (!details || !list) return;
    var entry = correctionElement('div', 'qz-response-revision');
    entry.setAttribute('data-qz-history-entry', '');
    var head = correctionElement('div', 'qz-response-revision__head');
    head.appendChild(correctionElement('strong', '', history.change_source === 'admin' ? 'Before administrative correction' : 'Before participant change'));
    head.appendChild(correctionElement('span', '', 'Version ' + (Number(history.revision_number || 0) + 1)));
    entry.appendChild(head);
    entry.appendChild(correctionElement('div', 'qz-response-revision__answer', history.answer_label || '—'));
    var meta = correctionElement('div', 'qz-response-revision__meta');
    meta.appendChild(correctionElement('span', '', 'Score ' + Number(history.computed_score || 0)));
    meta.appendChild(correctionElement('span', '', 'Changed at ' + String(history.created_at || '-')));
    meta.appendChild(correctionElement('span', '', 'Answered ' + String(answeredAt || '-')));
    if (history.changed_by_label) meta.appendChild(correctionElement('span', '', 'By ' + history.changed_by_label));
    entry.appendChild(meta);
    if (history.change_source === 'admin' && history.change_reason) {
      var reason = correctionElement('div', 'qz-response-revision__reason');
      reason.appendChild(correctionElement('strong', '', 'Reason: '));
      reason.appendChild(document.createTextNode(String(history.change_reason)));
      entry.appendChild(reason);
    }
    list.appendChild(entry);
    details.hidden = false;
  }

  function updateCorrectionView(payload, question, submitted, data){
    var row = document.querySelector('[data-qz-response-row][data-question-id="' + String(question.question_id) + '"]');
    if (row) {
      var answer = row.querySelector('[data-qz-response-answer]');
      var score = row.querySelector('[data-qz-response-score]');
      if (answer) answer.textContent = String(data.answer_label || '—');
      if (score) score.textContent = String(Number(data.computed_score || 0));
      appendCorrectionHistory(row, data.history, (question.current_response || {}).answered_at);
      var summary = row.querySelector('[data-qz-history-summary]');
      if (summary) summary.textContent = 'Prior saved versions (' + Number(data.revision_count || 0) + ')';
    }
    var total = document.querySelector('[data-qz-total-score]');
    if (total) total.textContent = String(Number(data.total_score || 0));
    var answered = document.querySelector('[data-qz-answered-count]');
    if (answered) {
      var totalQuestions = Number(answered.getAttribute('data-total-questions') || payload.total_questions || 0);
      answered.textContent = Number(data.answered_count || 0) + '/' + totalQuestions;
    }

    question.attempt_answer_id = Number(data.attempt_answer_id || 0);
    question.revision_count = Number(data.revision_count || 0);
    question.current_response = question.current_response || {};
    question.current_response.answer_label = String(data.answer_label || '—');
    question.current_response.computed_score = Number(data.computed_score || 0);
    if (question.question_type === 'multiple_choice') {
      question.current_response.selected_answer_ids = submitted.selected_answer_ids.slice();
      question.current_response.selected_answer_id = null;
      question.current_response.answer_text = null;
    } else if (question.question_type === 'single_choice' || question.question_type === 'yesno') {
      question.current_response.selected_answer_id = submitted.selected_answer_id;
      question.current_response.selected_answer_ids = [];
      question.current_response.answer_text = null;
    } else {
      question.current_response.answer_text = submitted.answer_text;
      question.current_response.selected_answer_id = null;
      question.current_response.selected_answer_ids = [];
    }
  }

  function submitCorrection(form){
    var modal = form.closest(correctionModalSelector);
    var payload = correctionPayload();
    var question = modal && modal._qzCorrectionQuestion;
    if (!modal || !payload || !question || modal.getAttribute('aria-busy') === 'true') return;
    var reason = String(form.elements.reason ? form.elements.reason.value : '').trim();
    if (reason.length < 5 || reason.length > 500) {
      form.elements.reason.setAttribute('aria-invalid', 'true');
      correctionMessage(modal, 'Enter a correction reason between 5 and 500 characters.');
      form.elements.reason.focus();
      return;
    }
    form.elements.reason.removeAttribute('aria-invalid');
    var request = {
      csrf_token: String(form.elements.csrf_token ? form.elements.csrf_token.value : ''),
      attempt_id: Number(payload.attempt_id || 0),
      question_id: Number(question.question_id || 0),
      attempt_answer_id: Number(question.attempt_answer_id || 0),
      expected_revision_count: Number(question.revision_count || 0),
      reason: reason
    };
    if (question.question_type === 'multiple_choice') {
      request.selected_answer_ids = Array.prototype.slice.call(form.querySelectorAll('input[name="selected_answer_ids"]:checked'))
        .map(function(input){ return Number(input.value); });
    } else if (question.question_type === 'single_choice' || question.question_type === 'yesno') {
      var selected = form.querySelector('input[name="selected_answer_id"]:checked');
      if (!selected) {
        correctionMessage(modal, 'Select a corrected response.');
        var firstOption = form.querySelector('input[name="selected_answer_id"]');
        if (firstOption) firstOption.focus();
        return;
      }
      request.selected_answer_id = Number(selected.value);
    } else {
      request.answer_text = String(form.elements.answer_text ? form.elements.answer_text.value : '').trim();
    }
    correctionMessage(modal, '');

    var confirmation = window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function'
      ? window.NewNotifConfirm.warning({
          title: 'Apply administrative correction?',
          message: 'The submitted response will change. Its prior value and this reason will be retained in the audit history.',
          confirmText: 'Apply correction',
          cancelText: 'Cancel',
          variant: 'warning'
        })
      : Promise.resolve(true);
    Promise.resolve(confirmation).then(function(confirmed){
      if (!confirmed || !modal.isConnected) return;
      setCorrectionLoading(modal, true);
      return fetch(form.getAttribute('data-endpoint') || '', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(request)
      }).then(function(response){
        return response.text().then(function(text){
          var result = null;
          try { result = JSON.parse(text); } catch (error) {}
          if (!response.ok || !result || result.ok !== true || !result.data) {
            var failure = new Error(correctionErrorMessage(response.status, result));
            failure.status = response.status;
            throw failure;
          }
          return result.data;
        });
      }).then(function(data){
        if (!data) return;
        updateCorrectionView(payload, question, request, data);
        setCorrectionLoading(modal, false);
        closeCorrectionModal(modal, true);
        var toastMessage = data.changed === false ? 'The submitted response already matches this value.' : 'Response correction saved.';
        if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
          window.NewNotifToast.show({type:'success', title:data.changed === false ? 'No change needed' : 'Correction saved', message:toastMessage});
        } else if (typeof window.showToast === 'function') {
          window.showToast(toastMessage, 'success');
        }
      }).catch(function(error){
        if (!modal.isConnected) return;
        setCorrectionLoading(modal, false);
        correctionMessage(modal, error && error.message ? error.message : 'The correction could not be saved.');
        var errorBox = modal.querySelector('[data-qz-correction-message]');
        if (errorBox) errorBox.focus();
      });
    }).catch(function(){
      correctionMessage(modal, 'The confirmation dialog could not be opened.');
    });
  }

  document.addEventListener('click', function(event){
    var open = event.target.closest('[data-qz-correction-open]');
    if (open) {
      event.preventDefault();
      openCorrectionModal(open);
      return;
    }
    var close = event.target.closest(correctionModalSelector + ' [data-qz-correction-close]');
    if (close) {
      event.preventDefault();
      closeCorrectionModal(close.closest(correctionModalSelector), true);
      return;
    }
    if (event.target.matches(correctionModalSelector)) closeCorrectionModal(event.target, true);
  });

  document.addEventListener('submit', function(event){
    var form = event.target.closest('[data-qz-correction-form]');
    if (!form) return;
    event.preventDefault();
    submitCorrection(form);
  });

  document.addEventListener('keydown', function(event){
    var modal = document.querySelector(correctionModalSelector + '.is-open');
    if (!modal || document.querySelector('.newnotif-confirm.is-open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeCorrectionModal(modal, true);
      return;
    }
    if (event.key !== 'Tab') return;
    var focusable = Array.prototype.slice.call(modal.querySelectorAll(
      'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function(element){ return element.offsetParent !== null; });
    if (!focusable.length) {
      event.preventDefault();
      modal.querySelector('[data-qz-correction-dialog]').focus();
      return;
    }
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  });

  /* ===== Quiz Detail Modal ===== */
  var baseUrl = window.ADMIN_PATH || '/adiwira';

  function escHtml(s){
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  var quizDetailTrigger = null;

  function openQuizDetail(btn){
    var d;
    try {
      d = JSON.parse(btn.getAttribute('data-quiz'));
    } catch (error) {
      return;
    }
    var modal = document.getElementById('quizDetailModal');
    if (!modal) return;

    document.getElementById('qdm-title').textContent = d.title;

    var statusBadge = d.is_active
      ? '<span class="badge badge--ok">Active</span>'
      : '<span class="badge badge--muted">Inactive</span>';
    if (d.is_primary) statusBadge += ' <span class="badge badge--primary">PRIMARY QUIZ</span>';

    var badHtml = '';
    if (d.badzero > 0) {
      badHtml = '<div class="qdm-warn"><b>&#9888;</b> ' + d.badzero + ' choice Questions have no Active Answers.</div>';
    }

    var schedHtml = '';
    if (d.start_at || d.end_at) {
      schedHtml = '<div class="qdm-field"><span class="qdm-label">Schedule</span><span>' +
        (d.start_at ? escHtml(d.start_at) : '&mdash;') + ' &rarr; ' + (d.end_at ? escHtml(d.end_at) : '&mdash;') +
        '</span></div>';
    }

    document.getElementById('qdm-body').innerHTML =
      '<div class="qdm-grid">' +
        '<div class="qdm-section">' +
          '<div class="qdm-field"><span class="qdm-label">ID</span><span>#' + d.id + '</span></div>' +
          '<div class="qdm-field"><span class="qdm-label">Status</span><span class="qdm-badge-stack">' + statusBadge + '</span></div>' +
          '<div class="qdm-field"><span class="qdm-label">Owner</span><span>' + escHtml(d.owner) + '</span></div>' +
          '<div class="qdm-field"><span class="qdm-label">Visual theme</span><span>' + escHtml(d.visual_theme) + ' <small class="qdm-source">' + escHtml(d.visual_theme_source) + '</small></span></div>' +
          (d.public_url ? '<div class="qdm-field"><span class="qdm-label">Public URL</span><span><a href="' + escHtml(d.public_url) + '" target="_blank" rel="noopener">' + escHtml(d.public_url) + '</a></span></div>' : '') +
          (d.description ? '<div class="qdm-field qdm-desc"><span class="qdm-label">Description</span><span>' + escHtml(d.description) + '</span></div>' : '') +
          schedHtml +
          '<div class="qdm-field"><span class="qdm-label">Created</span><span>' + escHtml(d.created_at) + '</span></div>' +
        '</div>' +
        '<div class="qdm-section">' +
          '<div class="qdm-subh">Statistics</div>' +
          '<div class="qdm-field"><span class="qdm-label">Questions</span><span><b>' + d.questions + '</b></span></div>' +
          '<div class="qdm-field"><span class="qdm-label">Answers</span><span><b>' + d.answers + '</b></span></div>' +
          '<div class="qdm-field"><span class="qdm-label">Per Attempt</span><span>' + (d.per_attempt > 0 ? d.per_attempt : 'All') + '</span></div>' +
          '<div class="qdm-field"><span class="qdm-label">Duration</span><span>' + (d.duration ? d.duration + ' minutes' : '&mdash;') + '</span></div>' +
        '</div>' +
      '</div>' +
      badHtml;

    var csrfEl = document.querySelector('input[name="csrf_token"], input[name="_csrf"]');
    var csrf = csrfEl ? csrfEl.value : '';

    var primaryAction = '';
    if (d.can_set_primary) {
      primaryAction = d.is_primary
        ? '<span class="btn-soft disabled" aria-disabled="true">Primary Quiz</span>'
        : '<form method="post" action="' + baseUrl + '/?page=admin/quiz/quizzes/set_primary" class="qdm-inline-form">' +
            '<input type="hidden" name="id" value="' + d.id + '">' +
            '<input type="hidden" name="csrf_token" value="' + escHtml(csrf) + '">' +
            '<button type="submit" class="btn-soft" data-confirm="Set this as the global Primary Quiz? It will replace the current Primary Quiz.">Set as Primary</button>' +
          '</form>';
    }
    var collaboratorsLink = d.can_manage_collaborators
      ? '<a class="btn-soft" href="' + baseUrl + '/?page=admin/quiz/quizzes/collaborators&quiz_id=' + d.id + '">Collaborators</a>'
      : '';
    var publicLink = d.public_url
      ? '<a class="btn-soft" href="' + escHtml(d.public_url) + '" target="_blank" rel="noopener">Open Registration</a>'
      : '';
    var quizManageActions = '';
    if (d.can_manage_quizzes) {
      quizManageActions = '<a class="btn-soft" href="' + baseUrl + '/?page=admin/quiz/quizzes/edit&id=' + d.id + '">Edit settings</a>' +
        '<form method="post" action="' + baseUrl + '/?page=admin/quiz/quizzes/duplicate" class="qdm-inline-form">' +
          '<input type="hidden" name="id" value="' + d.id + '">' +
          '<input type="hidden" name="csrf_token" value="' + escHtml(csrf) + '">' +
          '<button type="submit" class="btn-soft" data-confirm="Duplicate this Quiz? All Categories, Passages, Questions, and Answers will be copied. Attempts are not included.">Duplicate</button>' +
        '</form>';
    }
    if (d.can_delete) {
      quizManageActions += '<form method="post" action="' + baseUrl + '/?page=admin/quiz/quizzes/delete" class="qdm-inline-form">' +
          '<input type="hidden" name="id" value="' + d.id + '">' +
          '<input type="hidden" name="csrf_token" value="' + escHtml(csrf) + '">' +
          '<button type="submit" class="btn-danger" data-confirm="Delete this Quiz? Its Questions and Answers will also be deleted." data-confirm-variant="danger">Delete</button>' +
        '</form>';
    }

    var contentActions =
      '<a class="adam-button qdm-action-primary" href="' + baseUrl + '/?page=admin/quiz/master&quiz_id=' + d.id + '">Master Builder</a>' +
      '<a class="btn-soft" href="' + baseUrl + '/?page=admin/quiz/questions&quiz_id=' + d.id + '">Question List</a>' +
      '<a class="btn-soft" href="' + baseUrl + '/?page=admin/quiz/dump&quiz_id=' + d.id + '">Quality Check</a>';
    var accessActions = publicLink + collaboratorsLink + primaryAction;
    var actionGroup = function(label, description, actions, modifier){
      if (!actions) return '';
      return '<section class="qdm-action-group ' + modifier + '">' +
        '<div class="qdm-action-group__head"><strong>' + label + '</strong><span>' + description + '</span></div>' +
        '<div class="qdm-action-list">' + actions + '</div>' +
      '</section>';
    };

    document.getElementById('qdm-foot').innerHTML =
      '<div class="qdm-actions">' +
        actionGroup('Content', 'Build and review Quiz material.', contentActions, 'qdm-action-group--content') +
        actionGroup('Access', 'Open participant and ownership controls.', accessActions, 'qdm-action-group--access') +
        actionGroup('Quiz management', 'Change, copy, or remove this Quiz.', quizManageActions, 'qdm-action-group--manage') +
      '</div>';

    quizDetailTrigger = btn;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    var closeButton = modal.querySelector('.modal-close');
    if (closeButton) closeButton.focus();
  }

  function closeQuizDetail(){
    var modal = document.getElementById('quizDetailModal');
    if (modal) {
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
    }
    if (quizDetailTrigger && typeof quizDetailTrigger.focus === 'function') quizDetailTrigger.focus();
    quizDetailTrigger = null;
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.qz-detail-btn[data-quiz]');
    if (btn) {
      e.preventDefault();
      openQuizDetail(btn);
      return;
    }
    var closeBtn = e.target.closest('#quizDetailModal .modal-close');
    if (closeBtn) {
      closeQuizDetail();
      return;
    }
  });

  document.addEventListener('keydown', function(e){
    var modal = document.getElementById('quizDetailModal');
    if (!modal || !modal.classList.contains('active')) return;
    if (e.target.closest('.newnotif-confirm')) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      closeQuizDetail();
      return;
    }
    if (e.key !== 'Tab') return;
    var focusable = Array.prototype.slice.call(modal.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function(element){ return element.offsetParent !== null; });
    if (focusable.length === 0) {
      e.preventDefault();
      modal.focus();
      return;
    }
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });

  // Close modal on backdrop click
  document.addEventListener('click', function(e){
    if (e.target.id === 'quizDetailModal') closeQuizDetail();
  });
})();
