(function() {
  if (typeof Craft === 'undefined') {
    return;
  }

  const pendingFiles = new WeakMap();

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
    } else {
      root.removeAttribute('data-edit-url');
    }
    clearAttachments(root);
    showPreview(root, data);
    setBoundBadge(root, data);
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

  function appendMessage(root, role, text, html, assets) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    const article = document.createElement('article');
    article.className = 'padstone-mason__message padstone-mason__message--' + role;
    article.dataset.role = role;
    fillMessage(article, {
      content: text,
      html: html,
      assets: assets || []
    });
    transcript.appendChild(article);
    transcript.scrollTop = transcript.scrollHeight;
  }

  function showReply(root, data) {
    if (data.messages && data.messages.length) {
      replaceTranscript(root, data.messages);
      return;
    }
    appendMessage(root, 'assistant', data.reply || 'The change was not saved.', data.replyHtml);
  }

  function syncPreviewButton(root) {
    const button = root.querySelector('[data-assistant-preview-toggle]');
    if (!button) {
      return;
    }
    button.hidden = !root.getAttribute('data-preview-url');
  }

  function drawer() {
    let element = document.querySelector('[data-assistant-drawer]');
    if (element) {
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

    panel.append(header, frame, links);
    element.append(backdrop, panel);
    document.body.appendChild(element);

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
    new Craft.CpScreenSlideout('padstone-mason/chat/panel', {
      containerElement: 'div',
      params: {
        entryId: button.getAttribute('data-entry-id'),
        draftId: button.getAttribute('data-draft-id') || null,
        siteId: button.getAttribute('data-site-id')
      }
    });
  });

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
    if (event.key !== 'Escape') {
      return;
    }
    const element = document.querySelector('[data-assistant-drawer]');
    if (element && !element.hidden) {
      closeDrawer();
    }
  });
})();
