(function() {
  if (typeof Craft === 'undefined') {
    return;
  }

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

  function replaceTranscript(root, messages) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    transcript.replaceChildren();
    (messages || []).forEach(function(message) {
      const article = document.createElement('article');
      article.className = 'padstone-mason__message padstone-mason__message--' + message.role;
      article.dataset.role = message.role;
      article.innerHTML = message.html || '';
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
    showPreview(root, data);
  }

  function setPending(root, pending) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    const existing = transcript.querySelector('[data-assistant-pending]');
    if (existing) {
      existing.remove();
    }
    const submit = root.querySelector('[data-assistant-form] button[type="submit"]');
    if (submit) {
      submit.disabled = pending;
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

  function appendMessage(root, role, text, html) {
    const transcript = root.querySelector('[data-assistant-transcript]');
    const article = document.createElement('article');
    article.className = 'padstone-mason__message padstone-mason__message--' + role;
    article.dataset.role = role;
    if (html) {
      article.innerHTML = html;
    } else {
      article.textContent = text;
    }
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
    }
    syncPreviewButton(root);
    const element = document.querySelector('[data-assistant-drawer]');
    if (element && !element.hidden && root.getAttribute('data-preview-url')) {
      openDrawer(root);
    }
  }

  document.addEventListener('click', function(event) {
    const button = event.target.closest('[data-padstone-mason]');
    if (!button || typeof Craft.CpScreenSlideout === 'undefined') {
      return;
    }
    event.preventDefault();
    new Craft.CpScreenSlideout('padstone-mason/chat/panel', {
      params: {
        entryId: button.getAttribute('data-entry-id'),
        draftId: button.getAttribute('data-draft-id') || null,
        siteId: button.getAttribute('data-site-id')
      }
    });
  });

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
    if (typeof field.form.requestSubmit === 'function') {
      field.form.requestSubmit();
    } else {
      field.form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
  });

  document.addEventListener('submit', function(event) {
    const form = event.target.closest('[data-assistant-form]');
    if (!form) {
      return;
    }
    event.preventDefault();
    const root = rootFrom(form);
    const message = form.querySelector('textarea').value.trim();
    if (!message) {
      return;
    }
    if (root.querySelector('[data-assistant-pending]')) {
      return;
    }
    appendMessage(root, 'user', message);
    form.querySelector('textarea').value = '';
    setPending(root, true);
    Craft.sendActionRequest('POST', 'padstone-mason/chat/send', {
      data: Object.assign({
        conversationId: root.getAttribute('data-conversation-id'),
        message: message
      }, requestData(root))
    }).then(function(response) {
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
  });

  document.addEventListener('click', function(event) {
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
