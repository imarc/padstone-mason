(function() {
  if (typeof Craft === 'undefined') {
    return;
  }

  const pendingFiles = new WeakMap();
  const REOPEN_KEY = 'padstone-mason-reopen';
  const PREVIEW_WIDTH_KEY = 'padstone-mason-preview-drawer-width';
  const MIN_DRAWER_WIDTH = 280;
  const MAX_DRAWER_WIDTH_RATIO = 0.95;

  function rootFrom(element) {
    return element.closest('[data-assistant-root]');
  }

  function requestData(root) {
    return {
      scope: root.getAttribute('data-scope') || 'all',
      entryId: root.getAttribute('data-entry-id') || undefined,
      siteId: root.getAttribute('data-site-id') || undefined
    };
  }

  function pageDraftId() {
    try {
      return new URLSearchParams(window.location.search).get('draftId') || '';
    } catch (e) {
      return '';
    }
  }

  function normalizeDraftId(value) {
    if (value === null || value === undefined || value === '' || value === false) {
      return '';
    }
    return String(value);
  }

  function setBoundDraftId(root, draftId) {
    const value = normalizeDraftId(draftId);
    if (value) {
      root.setAttribute('data-bound-draft-id', value);
    } else {
      root.removeAttribute('data-bound-draft-id');
    }
  }

  function boundDraftIdFrom(root, data) {
    if (data && Object.prototype.hasOwnProperty.call(data, 'draftId')) {
      return normalizeDraftId(data.draftId);
    }
    return root.getAttribute('data-bound-draft-id') || '';
  }

  function syncEditorToBoundDraft(root, data) {
    if (!root || root.getAttribute('data-scope') !== 'entry') {
      return false;
    }
    data = data || {};
    if (Object.prototype.hasOwnProperty.call(data, 'draftId')) {
      setBoundDraftId(root, data.draftId);
    }
    if (Object.prototype.hasOwnProperty.call(data, 'editUrl')) {
      if (data.editUrl) {
        root.setAttribute('data-edit-url', data.editUrl);
      } else {
        root.removeAttribute('data-edit-url');
      }
    }

    const editUrl = root.getAttribute('data-edit-url') || '';
    if (!editUrl) {
      return false;
    }

    const bound = boundDraftIdFrom(root, data);
    if (bound === pageDraftId()) {
      return false;
    }

    const payload = {
      conversationId: data.conversationId || root.getAttribute('data-conversation-id') || '',
      entryId: data.entryId || root.getAttribute('data-entry-id') || '',
      draftId: bound || null,
      siteId: data.siteId || root.getAttribute('data-site-id') || ''
    };
    try {
      sessionStorage.setItem(REOPEN_KEY, JSON.stringify(payload));
    } catch (e) {
      // Ignore storage failures and still navigate.
    }
    window.location.assign(editUrl);
    return true;
  }

  function openMasonSlideout(params) {
    if (typeof Craft.CpScreenSlideout === 'undefined') {
      return null;
    }
    const slideout = new Craft.CpScreenSlideout('padstone-mason/chat/panel', {
      containerElement: 'div',
      params: params
    });
    slideout.on('load', function() {
      const root = slideout.$content && slideout.$content.find('[data-assistant-root]').get(0);
      if (!root) {
        return;
      }
      syncEditorToBoundDraft(root, {
        conversationId: root.getAttribute('data-conversation-id') || '',
        entryId: root.getAttribute('data-entry-id') || '',
        draftId: root.getAttribute('data-bound-draft-id') || '',
        siteId: root.getAttribute('data-site-id') || '',
        editUrl: root.getAttribute('data-edit-url') || ''
      });
    });
    return slideout;
  }

  function readReopenPayload() {
    let raw;
    try {
      raw = sessionStorage.getItem(REOPEN_KEY);
    } catch (e) {
      return null;
    }
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw);
    } catch (e) {
      clearReopen();
      return null;
    }
  }

  function clearReopen() {
    try {
      sessionStorage.removeItem(REOPEN_KEY);
    } catch (e) {
      // Ignore.
    }
  }

  function editorMatchesReopen(payload) {
    const bound = normalizeDraftId(payload.draftId);
    if (bound === pageDraftId()) {
      return true;
    }
    const button = document.querySelector('[data-padstone-mason]');
    if (!button) {
      return false;
    }
    if (bound && bound === normalizeDraftId(button.getAttribute('data-draft-id'))) {
      return true;
    }
    if (!bound && !pageDraftId() && !normalizeDraftId(button.getAttribute('data-draft-id'))) {
      return String(payload.entryId) === String(button.getAttribute('data-entry-id'));
    }

    return false;
  }

  function consumeReopen(attempt) {
    attempt = attempt || 0;
    const payload = readReopenPayload();
    if (!payload) {
      return;
    }
    if (!payload.entryId || !payload.siteId) {
      clearReopen();
      return;
    }

    const button = document.querySelector('[data-padstone-mason]');
    if (!button || typeof Craft.CpScreenSlideout === 'undefined' || !editorMatchesReopen(payload)) {
      // Entry chrome and draft URLs can land after this asset runs.
      if (attempt < 50) {
        window.setTimeout(function() {
          consumeReopen(attempt + 1);
        }, 50);
        return;
      }
      clearReopen();
      return;
    }

    clearReopen();
    openMasonSlideout({
      entryId: button.getAttribute('data-entry-id') || payload.entryId,
      draftId: button.getAttribute('data-draft-id') || payload.draftId || null,
      siteId: button.getAttribute('data-site-id') || payload.siteId,
      conversationId: payload.conversationId || null
    });
  }

  function scheduleConsumeReopen() {
    const start = function() {
      consumeReopen(0);
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }

  function filesFor(root) {
    if (!pendingFiles.has(root)) {
      pendingFiles.set(root, []);
    }
    return pendingFiles.get(root);
  }

  function renderConversations(root, conversations, selectedId) {
    const list = root.querySelector('[data-assistant-conversations]');
    if (!list) {
      return;
    }
    list.replaceChildren();
    (conversations || []).forEach(function(item) {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.assistantSelect = '';
      button.dataset.conversationId = String(item.id);
      button.textContent = item.title || 'New chat';
      if (String(item.id) === String(selectedId)) {
        button.setAttribute('aria-current', 'true');
      }
      li.appendChild(button);
      list.appendChild(li);
    });
    const remove = root.querySelector('[data-assistant-delete]');
    if (remove) {
      remove.hidden = !selectedId;
    }
  }

  function assetFigures(assets) {
    const wrap = document.createElement('div');
    wrap.className = 'padstone-mason__message-assets';
    (assets || []).forEach(function(asset) {
      const figure = document.createElement('figure');
      figure.className = 'padstone-mason__thumb';
      if (asset.thumbUrl) {
        const img = document.createElement('img');
        img.src = asset.thumbUrl;
        img.alt = asset.title || asset.filename || '';
        img.width = 64;
        img.height = 64;
        figure.appendChild(img);
      }
      const caption = document.createElement('figcaption');
      caption.textContent = '#' + asset.id + ' ' + (asset.filename || '');
      figure.appendChild(caption);
      wrap.appendChild(figure);
    });
    return wrap;
  }

  function fillMessage(article, message) {
    article.replaceChildren();
    if (message.html) {
      const body = document.createElement('div');
      body.innerHTML = message.html;
      while (body.firstChild) {
        article.appendChild(body.firstChild);
      }
    } else if (message.content) {
      article.textContent = message.content;
    }
    if (message.assets && message.assets.length) {
      article.appendChild(assetFigures(message.assets));
    }
    if (message.role === 'assistant' && message.tools && message.tools.length) {
      const footer = document.createElement('footer');
      footer.className = 'padstone-mason__message-tools';
      footer.textContent = 'Tools used: ' + message.tools.join(', ');
      article.appendChild(footer);
    }
  }

  function replaceTranscript(root, messages) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    transcript.replaceChildren();
    (messages || []).forEach(function(message) {
      const article = document.createElement('article');
      article.className = 'padstone-mason__message padstone-mason__message--' + message.role;
      article.dataset.role = message.role;
      fillMessage(article, message);
      transcript.appendChild(article);
    });
    transcript.scrollTop = transcript.scrollHeight;
  }

  function setConversationId(root, id) {
    const value = id ? String(id) : '';
    root.setAttribute('data-conversation-id', value);
    const hidden = root.querySelector('input[name="conversationId"]');
    if (hidden) {
      hidden.value = value;
    }
    root.querySelectorAll('[data-assistant-select]').forEach(function(button) {
      if (value && button.getAttribute('data-conversation-id') === value) {
        button.setAttribute('aria-current', 'true');
      } else {
        button.removeAttribute('aria-current');
      }
    });
    const remove = root.querySelector('[data-assistant-delete]');
    if (remove) {
      remove.hidden = !value;
    }
    const subjectWrap = root.querySelector('[data-assistant-subject-wrap]');
    if (subjectWrap) {
      subjectWrap.hidden = !value;
    }
  }

  function clearAttachments(root) {
    pendingFiles.set(root, []);
    const input = root.querySelector('[data-assistant-file]');
    if (input) {
      input.value = '';
    }
    renderAttachments(root);
  }

  function renderAttachments(root) {
    const strip = root.querySelector('[data-assistant-attachments]');
    if (!strip) {
      return;
    }
    const files = filesFor(root);
    strip.replaceChildren();
    if (!files.length) {
      strip.hidden = true;
      return;
    }
    strip.hidden = false;
    files.forEach(function(file, index) {
      const chip = document.createElement('div');
      chip.className = 'padstone-mason__attachment';
      const name = document.createElement('span');
      name.textContent = file.name;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'padstone-mason__attachment-remove';
      remove.dataset.assistantRemoveFile = String(index);
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.textContent = '×';
      chip.append(name, remove);
      strip.appendChild(chip);
    });
  }

  function setSubjectHeader(root, title) {
    const wrap = root.querySelector('[data-assistant-subject-wrap]');
    if (!wrap) {
      return;
    }
    wrap.hidden = false;
    const heading = wrap.querySelector('[data-assistant-subject]');
    if (heading) {
      heading.textContent = title || 'New chat';
    }
  }

  function subjectModal(root) {
    return root.querySelector('[data-assistant-subject-modal]');
  }

  function openSubjectModal(root) {
    const modal = subjectModal(root);
    if (!modal) {
      return;
    }
    const heading = root.querySelector('[data-assistant-subject]');
    const input = modal.querySelector('[data-assistant-subject-input]');
    const error = modal.querySelector('[data-assistant-subject-error]');
    if (error) {
      error.hidden = true;
      error.textContent = '';
    }
    if (input) {
      input.value = heading ? heading.textContent : '';
    }
    modal.hidden = false;
    const edit = root.querySelector('[data-assistant-edit-subject]');
    if (edit) {
      edit.setAttribute('aria-expanded', 'true');
    }
    if (input) {
      input.focus();
      input.select();
    }
  }

  function closeSubjectModal(root) {
    const modal = subjectModal(root);
    if (!modal) {
      return;
    }
    modal.hidden = true;
    const error = modal.querySelector('[data-assistant-subject-error]');
    if (error) {
      error.hidden = true;
      error.textContent = '';
    }
    const edit = root.querySelector('[data-assistant-edit-subject]');
    if (edit) {
      edit.setAttribute('aria-expanded', 'false');
      edit.focus();
    }
  }

  function saveSubject(root) {
    const modal = subjectModal(root);
    const input = modal && modal.querySelector('[data-assistant-subject-input]');
    const conversationId = root.getAttribute('data-conversation-id');
    if (!modal || !input || !conversationId) {
      return;
    }
    const subject = input.value;
    const error = modal.querySelector('[data-assistant-subject-error]');
    Craft.sendActionRequest('POST', 'padstone-mason/chat/rename-subject', {
      data: Object.assign({ conversationId: conversationId, subject: subject }, requestData(root))
    }).then(function(response) {
      const data = response.data;
      if (!data.ok) {
        if (error) {
          error.textContent = data.error || 'The subject could not be saved.';
          error.hidden = false;
        }
        return;
      }
      setSubjectHeader(root, data.title);
      if (data.conversations) {
        renderConversations(root, data.conversations, conversationId);
      }
      closeSubjectModal(root);
    }).catch(function() {
      if (error) {
        error.textContent = 'The subject could not be saved.';
        error.hidden = false;
      }
    });
  }

  function setBoundBadge(root, data) {
    const badge = root.querySelector('[data-assistant-bound]');
    if (!badge) {
      return;
    }

    const title = data && Object.prototype.hasOwnProperty.call(data, 'boundTitle')
      ? (data.boundTitle || '')
      : (root.getAttribute('data-bound-title') || '');
    const isDraft = data && Object.prototype.hasOwnProperty.call(data, 'boundIsDraft')
      ? !!data.boundIsDraft
      : root.getAttribute('data-bound-draft') === '1';
    const editUrl = data && Object.prototype.hasOwnProperty.call(data, 'editUrl')
      ? (data.editUrl || '')
      : (root.getAttribute('data-edit-url') || '');

    const label = badge.querySelector('.padstone-mason__bound-label');
    let titleNode = badge.querySelector('[data-assistant-bound-title]');
    const state = badge.querySelector('[data-assistant-bound-state]');

    if (title) {
      badge.removeAttribute('data-empty');
      root.setAttribute('data-bound-title', title);
      if (isDraft) {
        root.setAttribute('data-bound-draft', '1');
      } else {
        root.removeAttribute('data-bound-draft');
      }
      if (label) {
        label.textContent = 'Bound page';
      }
      if (titleNode) {
        if (editUrl && titleNode.tagName !== 'A') {
          const link = document.createElement('a');
          link.className = titleNode.className;
          link.dataset.assistantBoundTitle = '';
          titleNode.replaceWith(link);
          titleNode = link;
        } else if (!editUrl && titleNode.tagName === 'A') {
          const span = document.createElement('span');
          span.className = titleNode.className;
          span.dataset.assistantBoundTitle = '';
          titleNode.replaceWith(span);
          titleNode = span;
        }
        titleNode.hidden = false;
        titleNode.textContent = title;
        if (titleNode.tagName === 'A') {
          titleNode.href = editUrl;
        }
      }
      if (state) {
        state.hidden = !isDraft;
      }
      return;
    }

    badge.setAttribute('data-empty', 'true');
    root.removeAttribute('data-bound-title');
    root.removeAttribute('data-bound-draft');
    if (label) {
      label.textContent = 'No page bound';
    }
    if (titleNode) {
      titleNode.hidden = true;
      titleNode.textContent = '';
      if (titleNode.tagName === 'A') {
        titleNode.removeAttribute('href');
      }
    }
    if (state) {
      state.hidden = true;
    }
  }

  function applyConversation(root, data) {
    const id = data.conversationId || '';
    if (data.conversations) {
      renderConversations(root, data.conversations, id);
    }
    setConversationId(root, id);
    if (data.messages) {
      replaceTranscript(root, data.messages);
    }
    if (data.editUrl) {
      root.setAttribute('data-edit-url', data.editUrl);
    } else if (Object.prototype.hasOwnProperty.call(data, 'editUrl')) {
      root.removeAttribute('data-edit-url');
    }
    if (Object.prototype.hasOwnProperty.call(data, 'draftId')) {
      setBoundDraftId(root, data.draftId);
    }
    if (data.title) {
      setSubjectHeader(root, data.title);
    }
    clearAttachments(root);
    showPreview(root, data);
    setBoundBadge(root, data);
    syncEditorToBoundDraft(root, data);
  }

  function setPending(root, pending) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    const existing = transcript.querySelector('[data-assistant-pending]');
    if (existing) {
      existing.remove();
    }
    const submit = root.querySelector('[data-assistant-send]');
    if (submit) {
      submit.disabled = pending;
    }
    const attach = root.querySelector('[data-assistant-attach]');
    if (attach) {
      attach.disabled = pending;
    }
    if (!pending) {
      return;
    }

    const status = document.createElement('p');
    status.className = 'padstone-mason__pending';
    status.dataset.assistantPending = '';
    status.setAttribute('role', 'status');

    const dots = document.createElement('span');
    dots.className = 'padstone-mason__pending-dots';
    dots.setAttribute('aria-hidden', 'true');
    for (let i = 0; i < 3; i += 1) {
      dots.appendChild(document.createElement('span'));
    }

    const label = document.createElement('span');
    label.className = 'visually-hidden';
    label.textContent = 'Mason is writing';

    status.append(dots, label);
    transcript.appendChild(status);
    transcript.scrollTop = transcript.scrollHeight;
  }

  function appendMessage(root, role, text, html, assets, tools) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    const article = document.createElement('article');
    article.className = 'padstone-mason__message padstone-mason__message--' + role;
    article.dataset.role = role;
    fillMessage(article, {
      role: role,
      content: text,
      html: html,
      assets: assets || [],
      tools: tools || []
    });
    transcript.appendChild(article);
    transcript.scrollTop = transcript.scrollHeight;
  }

  function showReply(root, data) {
    if (data.messages && data.messages.length) {
      replaceTranscript(root, data.messages);
      return;
    }
    appendMessage(root, 'assistant', data.reply || 'The change was not saved.', data.replyHtml, [], data.tools || []);
  }

  function syncPreviewButton(root) {
    const button = root.querySelector('[data-assistant-preview-toggle]');
    if (!button) {
      return;
    }
    button.hidden = !root.getAttribute('data-preview-url');
  }

  function drawerMaxWidth() {
    return Math.floor(window.innerWidth * MAX_DRAWER_WIDTH_RATIO);
  }

  function defaultDrawerWidth() {
    return Math.min(760, Math.floor(window.innerWidth * 0.48));
  }

  function readDrawerWidth() {
    try {
      const raw = localStorage.getItem(PREVIEW_WIDTH_KEY);
      const value = raw ? parseInt(raw, 10) : NaN;
      if (Number.isFinite(value) && value >= MIN_DRAWER_WIDTH) {
        return Math.min(value, drawerMaxWidth());
      }
    } catch (e) {
      // Ignore storage failures.
    }

    return null;
  }

  function saveDrawerWidth(px) {
    try {
      localStorage.setItem(PREVIEW_WIDTH_KEY, String(Math.round(px)));
    } catch (e) {
      // Ignore storage failures.
    }
  }

  function drawerResizable() {
    return window.matchMedia('(min-width: 701px)').matches;
  }

  function applyDrawerWidth(panel, widthPx) {
    if (!panel || !widthPx || !drawerResizable()) {
      return;
    }
    panel.style.width = widthPx + 'px';
    panel.style.maxWidth = 'none';
  }

  function syncDrawerWidth(panel) {
    if (!panel) {
      return;
    }
    if (!drawerResizable()) {
      panel.style.width = '';
      panel.style.maxWidth = '';
      return;
    }
    applyDrawerWidth(panel, readDrawerWidth() || defaultDrawerWidth());
  }

  function initDrawerResize(panel, handle) {
    let dragging = false;

    function widthFromPointer(clientX) {
      return Math.min(drawerMaxWidth(), Math.max(MIN_DRAWER_WIDTH, window.innerWidth - clientX));
    }

    handle.addEventListener('pointerdown', function(event) {
      if (event.button !== 0 || !drawerResizable()) {
        return;
      }
      dragging = true;
      handle.setPointerCapture(event.pointerId);
      document.body.classList.add('padstone-mason__drawer-resizing');
      applyDrawerWidth(panel, widthFromPointer(event.clientX));
      event.preventDefault();
    });

    handle.addEventListener('pointermove', function(event) {
      if (!dragging) {
        return;
      }
      applyDrawerWidth(panel, widthFromPointer(event.clientX));
    });

    function endDrag(event) {
      if (!dragging) {
        return;
      }
      dragging = false;
      document.body.classList.remove('padstone-mason__drawer-resizing');
      try {
        handle.releasePointerCapture(event.pointerId);
      } catch (e) {
        // Ignore release failures.
      }
      saveDrawerWidth(panel.getBoundingClientRect().width);
    }

    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);

    handle.addEventListener('keydown', function(event) {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        return;
      }
      event.preventDefault();
      const step = event.shiftKey ? 48 : 16;
      const current = panel.getBoundingClientRect().width;
      const delta = event.key === 'ArrowLeft' ? step : -step;
      const next = Math.min(drawerMaxWidth(), Math.max(MIN_DRAWER_WIDTH, current + delta));
      applyDrawerWidth(panel, next);
      saveDrawerWidth(next);
    });
  }

  function drawer() {
    let element = document.querySelector('[data-assistant-drawer]');
    if (element) {
      syncDrawerWidth(element.querySelector('.padstone-mason__drawer-panel'));
      return element;
    }

    element = document.createElement('div');
    element.className = 'padstone-mason__drawer';
    element.dataset.assistantDrawer = '';
    element.hidden = true;

    const backdrop = document.createElement('div');
    backdrop.className = 'padstone-mason__drawer-backdrop';
    backdrop.dataset.assistantDrawerClose = '';

    const panel = document.createElement('aside');
    panel.className = 'padstone-mason__drawer-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Preview');

    const resize = document.createElement('div');
    resize.className = 'padstone-mason__drawer-resize';
    resize.dataset.assistantDrawerResize = '';
    resize.setAttribute('role', 'separator');
    resize.setAttribute('aria-orientation', 'vertical');
    resize.setAttribute('aria-label', 'Resize preview panel');
    resize.tabIndex = 0;

    const header = document.createElement('div');
    header.className = 'padstone-mason__drawer-header';
    const heading = document.createElement('h2');
    heading.textContent = 'Preview';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn';
    close.dataset.assistantDrawerClose = '';
    close.textContent = 'Close';
    header.append(heading, close);

    const frame = document.createElement('iframe');
    frame.title = 'Draft preview';
    frame.dataset.assistantPreviewFrame = '';

    const links = document.createElement('div');
    links.className = 'padstone-mason__drawer-links';
    const open = document.createElement('a');
    open.target = '_blank';
    open.rel = 'noopener';
    open.dataset.assistantPreviewLink = '';
    open.textContent = 'Open preview';
    const edit = document.createElement('a');
    edit.dataset.assistantEditLink = '';
    edit.textContent = 'Edit draft';
    links.append(open, edit);

    panel.append(resize, header, frame, links);
    element.append(backdrop, panel);
    document.body.appendChild(element);
    syncDrawerWidth(panel);
    initDrawerResize(panel, resize);

    return element;
  }

  function closeDrawer(root) {
    const element = document.querySelector('[data-assistant-drawer]');
    if (element) {
      element.hidden = true;
    }
    document.querySelectorAll('[data-assistant-preview-toggle]').forEach(function(button) {
      button.setAttribute('aria-expanded', 'false');
    });
    if (root) {
      const toggle = root.querySelector('[data-assistant-preview-toggle]');
      if (toggle) {
        toggle.focus();
      }
    }
  }

  function openDrawer(root) {
    const url = root.getAttribute('data-preview-url');
    if (!url) {
      return;
    }

    const element = drawer();
    const frame = element.querySelector('[data-assistant-preview-frame]');
    if (frame.getAttribute('src') !== url) {
      frame.src = url;
    }

    const open = element.querySelector('[data-assistant-preview-link]');
    open.href = url;

    const edit = element.querySelector('[data-assistant-edit-link]');
    const editUrl = root.getAttribute('data-edit-url') || '';
    edit.hidden = !editUrl;
    if (editUrl) {
      edit.href = editUrl;
    }

    element.hidden = false;
    const toggle = root.querySelector('[data-assistant-preview-toggle]');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
    }
    element.querySelector('[data-assistant-drawer-close].btn').focus();
  }

  function showPreview(root, data) {
    if (data.previewUrl) {
      root.setAttribute('data-preview-url', data.previewUrl);
    } else if (data.previewAvailable === false) {
      root.removeAttribute('data-preview-url');
      closeDrawer(root);
    }
    if (data.editUrl) {
      root.setAttribute('data-edit-url', data.editUrl);
    } else if (Object.prototype.hasOwnProperty.call(data, 'editUrl')) {
      root.removeAttribute('data-edit-url');
    }
    syncPreviewButton(root);
    if (Object.prototype.hasOwnProperty.call(data, 'boundTitle') || Object.prototype.hasOwnProperty.call(data, 'boundIsDraft')) {
      setBoundBadge(root, data);
    }
    const element = document.querySelector('[data-assistant-drawer]');
    if (element && !element.hidden && root.getAttribute('data-preview-url')) {
      openDrawer(root);
    }
  }

  function buildSendPayload(root, message, files) {
    const base = Object.assign({
      conversationId: root.getAttribute('data-conversation-id') || '',
      message: message
    }, requestData(root));

    if (!files.length) {
      return { data: base };
    }

    const formData = new FormData();
    Object.keys(base).forEach(function(key) {
      if (base[key] !== undefined && base[key] !== null) {
        formData.append(key, base[key]);
      }
    });
    files.forEach(function(file) {
      formData.append('images[]', file, file.name);
    });

    return { data: formData };
  }

  document.addEventListener('click', function(event) {
    const button = event.target.closest('[data-padstone-mason]');
    if (!button || typeof Craft.CpScreenSlideout === 'undefined') {
      return;
    }
    event.preventDefault();
    // containerElement must not be "form": that adds a Save button which POSTs to /admin with no action.
    openMasonSlideout({
      entryId: button.getAttribute('data-entry-id'),
      draftId: button.getAttribute('data-draft-id') || null,
      siteId: button.getAttribute('data-site-id')
    });
  });

  scheduleConsumeReopen();

  function sendMessage(root) {
    const form = root.querySelector('[data-assistant-form]');
    if (!form) {
      return;
    }
    const field = form.querySelector('textarea');
    const message = field ? field.value.trim() : '';
    const files = filesFor(root).slice();
    if (!message && !files.length) {
      return;
    }
    if (root.querySelector('[data-assistant-pending]')) {
      return;
    }
    appendMessage(root, 'user', message || ('Attached ' + files.length + (files.length === 1 ? ' image.' : ' images.')), null, files.map(function(file) {
      return { id: '', title: file.name, filename: file.name, thumbUrl: null };
    }));
    if (field) {
      field.value = '';
    }
    clearAttachments(root);
    setPending(root, true);
    Craft.sendActionRequest('POST', 'padstone-mason/chat/send', buildSendPayload(root, message, files)).then(function(response) {
      setPending(root, false);
      const data = response.data;
      if (data.conversationId) {
        if (data.conversations) {
          renderConversations(root, data.conversations, data.conversationId);
        }
        setConversationId(root, data.conversationId);
      }
      showReply(root, data);
      showPreview(root, data);
      setBoundBadge(root, data);
      syncEditorToBoundDraft(root, data);
    }).catch(function() {
      setPending(root, false);
      appendMessage(root, 'assistant', 'The change was not saved.');
    });
  }

  document.addEventListener('keydown', function(event) {
    const field = event.target.closest && event.target.closest('[data-assistant-form] textarea');
    if (!field || event.key !== 'Enter' || event.isComposing) {
      return;
    }
    if (event.shiftKey) {
      return;
    }
    if (event.metaKey) {
      event.preventDefault();
      const start = field.selectionStart;
      const end = field.selectionEnd;
      field.setRangeText('\n', start, end, 'end');
      return;
    }
    event.preventDefault();
    sendMessage(rootFrom(field));
  });

  document.addEventListener('change', function(event) {
    const input = event.target.closest('[data-assistant-file]');
    if (!input) {
      return;
    }
    const root = rootFrom(input);
    const files = filesFor(root);
    Array.prototype.forEach.call(input.files || [], function(file) {
      if (!file.type || file.type.indexOf('image/') !== 0) {
        return;
      }
      files.push(file);
    });
    input.value = '';
    renderAttachments(root);
  });

  document.addEventListener('click', function(event) {
    const send = event.target.closest('[data-assistant-send]');
    if (send) {
      event.preventDefault();
      sendMessage(rootFrom(send));
      return;
    }

    const attach = event.target.closest('[data-assistant-attach]');
    if (attach) {
      event.preventDefault();
      const root = rootFrom(attach);
      const input = root.querySelector('[data-assistant-file]');
      if (input) {
        input.click();
      }
      return;
    }

    const removeFile = event.target.closest('[data-assistant-remove-file]');
    if (removeFile) {
      event.preventDefault();
      const root = rootFrom(removeFile);
      const index = parseInt(removeFile.getAttribute('data-assistant-remove-file'), 10);
      const files = filesFor(root);
      if (!Number.isNaN(index)) {
        files.splice(index, 1);
        renderAttachments(root);
      }
      return;
    }

    const create = event.target.closest('[data-assistant-new]');
    if (create) {
      event.preventDefault();
      const root = rootFrom(create);
      Craft.sendActionRequest('POST', 'padstone-mason/chat/create', {
        data: requestData(root)
      }).then(function(response) {
        const data = response.data;
        if (!data.ok && data.reply) {
          appendMessage(root, 'assistant', data.reply);
          return;
        }
        applyConversation(root, data);
      }).catch(function() {
        appendMessage(root, 'assistant', 'The change was not saved.');
      });
      return;
    }

    const select = event.target.closest('[data-assistant-select]');
    if (select) {
      event.preventDefault();
      const root = rootFrom(select);
      const id = select.getAttribute('data-conversation-id');
      if (!id || id === root.getAttribute('data-conversation-id')) {
        return;
      }
      Craft.sendActionRequest('GET', 'padstone-mason/chat/conversation', {
        params: { conversationId: id }
      }).then(function(response) {
        applyConversation(root, response.data);
      }).catch(function() {
        appendMessage(root, 'assistant', 'That conversation is not available.');
      });
      return;
    }

    const editSubject = event.target.closest('[data-assistant-edit-subject]');
    if (editSubject) {
      event.preventDefault();
      openSubjectModal(rootFrom(editSubject));
      return;
    }

    const subjectSave = event.target.closest('[data-assistant-subject-save]');
    if (subjectSave) {
      event.preventDefault();
      saveSubject(rootFrom(subjectSave));
      return;
    }

    const subjectCancel = event.target.closest('[data-assistant-subject-cancel]');
    if (subjectCancel) {
      event.preventDefault();
      closeSubjectModal(rootFrom(subjectCancel));
      return;
    }

    const remove = event.target.closest('[data-assistant-delete]');
    if (remove) {
      event.preventDefault();
      const root = rootFrom(remove);
      const id = root.getAttribute('data-conversation-id');
      if (!id || !window.confirm('Delete this chat? The draft stays in place.')) {
        return;
      }
      Craft.sendActionRequest('POST', 'padstone-mason/chat/delete', {
        data: Object.assign({ conversationId: id }, requestData(root))
      }).then(function(response) {
        applyConversation(root, response.data);
      }).catch(function() {
        appendMessage(root, 'assistant', 'That conversation is not available.');
      });
    }
  });

  document.addEventListener('click', function(event) {
    const button = event.target.closest('[data-assistant-publish]');
    if (!button) {
      return;
    }
    event.preventDefault();
    const root = rootFrom(button);
    Craft.sendActionRequest('POST', 'padstone-mason/chat/publish', {
      data: {
        conversationId: root.getAttribute('data-conversation-id')
      }
    }).then(function(response) {
      appendMessage(root, 'assistant', response.data.reply || 'The change was not saved.');
      showPreview(root, response.data);
      setBoundBadge(root, response.data);
      syncEditorToBoundDraft(root, response.data);
    }).catch(function() {
      appendMessage(root, 'assistant', 'The change was not saved.');
    });
  });

  document.addEventListener('click', function(event) {
    const toggle = event.target.closest('[data-assistant-preview-toggle]');
    if (toggle) {
      event.preventDefault();
      const root = rootFrom(toggle);
      const element = document.querySelector('[data-assistant-drawer]');
      if (element && !element.hidden) {
        closeDrawer(root);
      } else {
        openDrawer(root);
      }
      return;
    }

    if (event.target.closest('[data-assistant-drawer-close]')) {
      closeDrawer();
    }
  });

  document.addEventListener('keydown', function(event) {
    const subjectInput = event.target.closest && event.target.closest('[data-assistant-subject-input]');
    if (
      subjectInput
      && event.key === 'Enter'
      && !event.isComposing
      && !event.shiftKey
    ) {
      event.preventDefault();
      saveSubject(rootFrom(subjectInput));
      return;
    }

    if (event.key !== 'Escape') {
      return;
    }
    const subjectFrom = event.target.closest && event.target.closest('[data-assistant-subject-modal]');
    if (subjectFrom && !subjectFrom.hidden) {
      closeSubjectModal(rootFrom(subjectFrom));
      return;
    }
    const element = document.querySelector('[data-assistant-drawer]');
    if (element && !element.hidden) {
      closeDrawer();
    }
  });
})();
