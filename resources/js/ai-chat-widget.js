const widget = document.getElementById('aiChatWidget');

if (widget) {
  const launcher = widget.querySelector('.ai-chat-launcher');
  const panel = widget.querySelector('#aiChatPanel');
  const closeButton = widget.querySelector('[data-ai-chat-close]');
  const newButton = widget.querySelector('[data-ai-chat-new]');
  const roleLabel = widget.querySelector('[data-ai-chat-role]');
  const sessionList = widget.querySelector('[data-ai-chat-sessions]');
  const promptsEl = widget.querySelector('[data-ai-chat-prompts]');
  const capabilitiesEl = widget.querySelector('[data-ai-chat-capabilities]');
  const messagesEl = widget.querySelector('[data-ai-chat-messages]');
  const form = widget.querySelector('[data-ai-chat-form]');
  const input = widget.querySelector('[data-ai-chat-input]');

  const state = {
    bootstrapped: false,
    busy: false,
    bootstrap: null,
    sessions: [],
    currentSession: null,
    messages: [],
  };

  widget.hidden = false;
  bindEvents();

  function bindEvents() {
    launcher?.addEventListener('click', async () => {
      const shouldOpen = panel.hidden;
      setOpen(shouldOpen);

      if (shouldOpen) {
        await ensureReady();
        input?.focus();
      }
    });

    closeButton?.addEventListener('click', () => setOpen(false));

    newButton?.addEventListener('click', async () => {
      if (state.busy) return;
      await createSession();
      input?.focus();
    });

    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const text = String(input?.value || '').trim();
      if (!text || state.busy) return;
      input.value = '';
      await sendMessage(text);
    });

    input?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form?.requestSubmit();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !panel.hidden) {
        setOpen(false);
      }
    });
  }

  function setOpen(open) {
    panel.hidden = !open;
    launcher?.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  async function ensureReady() {
    if (state.bootstrapped) return;
    setBusy(true);
    try {
      const [bootstrapPayload, sessionsPayload] = await Promise.all([
        fetchJson(widget.dataset.bootstrapUrl),
        fetchJson(widget.dataset.sessionsUrl),
      ]);

      state.bootstrap = bootstrapPayload.bootstrap || {};
      state.sessions = sessionsPayload.sessions || [];
      state.bootstrapped = true;
      renderBootstrap();
      renderSessions();

      if (state.sessions.length > 0) {
        await loadSession(state.sessions[0].id);
      } else {
        await createSession();
      }
    } catch (error) {
      renderError(error instanceof Error ? error.message : 'AI assistant failed to load.');
    } finally {
      setBusy(false);
    }
  }

  async function createSession() {
    setBusy(true);
    try {
      const payload = await fetchJson(widget.dataset.sessionStoreUrl, {
        method: 'POST',
        body: JSON.stringify({}),
      });
      upsertSession(payload.session);
      state.currentSession = payload.session;
      state.messages = [];
      renderSessions();
      renderMessages();
    } catch (error) {
      renderError(error instanceof Error ? error.message : 'Could not create AI chat session.');
    } finally {
      setBusy(false);
    }
  }

  async function loadSession(sessionId) {
    setBusy(true);
    try {
      const payload = await fetchJson(sessionUrl('messages', sessionId));
      state.currentSession = payload.session;
      state.messages = payload.messages || [];
      upsertSession(payload.session);
      renderSessions();
      renderMessages();
    } catch (error) {
      renderError(error instanceof Error ? error.message : 'Could not load AI chat messages.');
    } finally {
      setBusy(false);
    }
  }

  async function sendMessage(text) {
    if (!state.currentSession) {
      await createSession();
    }

    if (!state.currentSession) return;

    setBusy(true);
    renderThinking(text);
    try {
      const payload = await fetchJson(sessionUrl('store', state.currentSession.id), {
        method: 'POST',
        body: JSON.stringify({ message: text }),
      });

      state.currentSession = payload.session;
      upsertSession(payload.session);
      state.messages = [...state.messages.filter((message) => !message.optimistic), ...(payload.messages || [])];
      renderSessions();
      renderMessages();
    } catch (error) {
      state.messages = state.messages.filter((message) => !message.optimistic);
      renderMessages();
      renderError(error instanceof Error ? error.message : 'AI assistant could not answer.');
    } finally {
      setBusy(false);
    }
  }

  function sessionUrl(kind, sessionId) {
    const key = kind === 'store' ? 'sessionMessageStoreUrlTemplate' : 'sessionMessagesUrlTemplate';
    return String(widget.dataset[key] || '').replace('__SESSION__', encodeURIComponent(sessionId));
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': widget.dataset.csrfToken || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      ...options,
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.message || `AI request failed (${response.status}).`);
    }

    return payload;
  }

  function renderBootstrap() {
    if (roleLabel) {
      roleLabel.textContent = `${state.bootstrap.role_label || 'Account'} scope | ${state.bootstrap.action_mode || 'draft_only'}`;
    }

    if (capabilitiesEl) {
      const capabilities = state.bootstrap.capabilities || [];
      capabilitiesEl.textContent = capabilities.length
        ? `Allowed: ${capabilities.join(' | ')}`
        : 'Allowed data will follow your account role.';
    }

    if (promptsEl) {
      promptsEl.innerHTML = '';
      (state.bootstrap.quick_prompts || []).forEach((prompt) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ai-chat-prompt';
        button.textContent = prompt;
        button.addEventListener('click', () => sendMessage(prompt));
        promptsEl.appendChild(button);
      });
    }
  }

  function renderSessions() {
    if (!sessionList) return;
    sessionList.innerHTML = '';

    state.sessions.forEach((session) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'ai-chat-session-btn';
      if (state.currentSession && Number(state.currentSession.id) === Number(session.id)) {
        button.classList.add('is-active');
      }
      button.innerHTML = `${escapeHtml(session.title || 'New chat')}<span>${escapeHtml(session.last_intent || 'ready')}</span>`;
      button.addEventListener('click', () => loadSession(session.id));
      sessionList.appendChild(button);
    });
  }

  function renderMessages() {
    if (!messagesEl) return;
    messagesEl.innerHTML = '';

    if (!state.messages.length) {
      const empty = document.createElement('div');
      empty.className = 'ai-chat-empty';
      empty.textContent = 'Ask a question about data allowed by your account role.';
      messagesEl.appendChild(empty);
      return;
    }

    state.messages.forEach((message) => {
      messagesEl.appendChild(messageNode(message));
    });

    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function renderThinking(text) {
    state.messages.push({
      id: `optimistic-user-${Date.now()}`,
      sender: 'user',
      content: text,
      optimistic: true,
      status: 'ok',
    });
    state.messages.push({
      id: `optimistic-assistant-${Date.now()}`,
      sender: 'assistant',
      content: 'Checking your allowed data...',
      optimistic: true,
      status: 'loading',
    });
    renderMessages();
  }

  function messageNode(message) {
    const article = document.createElement('article');
    article.className = `ai-chat-message ai-chat-message--${message.sender === 'user' ? 'user' : 'assistant'}`;

    const meta = document.createElement('div');
    meta.className = 'ai-chat-message-meta';
    meta.textContent = message.sender === 'user'
      ? 'You'
      : `${message.intent || 'assistant'}${message.status && message.status !== 'ok' ? ` | ${message.status}` : ''}`;
    article.appendChild(meta);

    const content = document.createElement('div');
    content.className = 'ai-chat-message-content';
    renderMessageContent(content, message);
    article.appendChild(content);

    if (Array.isArray(message.sources) && message.sources.length) {
      const sources = document.createElement('div');
      sources.className = 'ai-chat-message-sources';
      message.sources.forEach((source) => {
        const chip = document.createElement('span');
        chip.className = 'ai-chat-source';
        chip.textContent = source.label || source.table || 'Scoped source';
        sources.appendChild(chip);
      });
      article.appendChild(sources);
    }

    if (Array.isArray(message.drafts) && message.drafts.length) {
      const drafts = document.createElement('div');
      drafts.className = 'ai-chat-draft';
      message.drafts.forEach((draft) => {
        if (draft.target_url) {
          const link = document.createElement('a');
          link.href = draft.target_url;
          link.textContent = 'Open draft destination';
          drafts.appendChild(link);
        } else {
          const chip = document.createElement('span');
          chip.textContent = 'Draft saved';
          drafts.appendChild(chip);
        }
      });
      article.appendChild(drafts);
    }

    return article;
  }

  function renderMessageContent(container, message) {
    const text = String(message.content || '');

    if (message.sender === 'user' || !text.includes('|')) {
      const paragraph = document.createElement('div');
      paragraph.className = 'ai-chat-message-text';
      paragraph.textContent = text;
      container.appendChild(paragraph);
      return;
    }

    const lines = text.split(/\r?\n/);
    let paragraphLines = [];

    const flushParagraph = () => {
      const paragraphText = paragraphLines.join('\n').trim();
      paragraphLines = [];
      if (!paragraphText) return;

      const paragraph = document.createElement('div');
      paragraph.className = 'ai-chat-message-text';
      paragraph.textContent = paragraphText;
      container.appendChild(paragraph);
    };

    for (let index = 0; index < lines.length; index += 1) {
      const line = lines[index] || '';
      const nextLine = lines[index + 1] || '';

      if (isTableHeader(line, nextLine)) {
        flushParagraph();
        const tableLines = [line, nextLine];
        index += 2;

        while (index < lines.length && isTableRow(lines[index] || '')) {
          tableLines.push(lines[index] || '');
          index += 1;
        }

        index -= 1;
        container.appendChild(tableNode(tableLines));
        continue;
      }

      paragraphLines.push(line);
    }

    flushParagraph();
  }

  function tableNode(lines) {
    const wrapper = document.createElement('div');
    wrapper.className = 'ai-chat-table-wrap';

    const table = document.createElement('table');
    table.className = 'ai-chat-table';

    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    splitTableCells(lines[0]).forEach((cell) => {
      const th = document.createElement('th');
      th.textContent = cell;
      headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    lines.slice(2).forEach((line) => {
      const tr = document.createElement('tr');
      splitTableCells(line).forEach((cell) => {
        const td = document.createElement('td');
        td.textContent = cell;
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);

    wrapper.appendChild(table);
    return wrapper;
  }

  function splitTableCells(line) {
    return String(line)
      .trim()
      .replace(/^\|/, '')
      .replace(/\|$/, '')
      .split('|')
      .map((cell) => cell.trim());
  }

  function isTableHeader(line, nextLine) {
    return isTableRow(line) && /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(nextLine);
  }

  function isTableRow(line) {
    const text = String(line || '').trim();
    return text.startsWith('|') && text.endsWith('|') && text.split('|').length >= 3;
  }

  function renderError(message) {
    if (!messagesEl) return;
    const node = document.createElement('article');
    node.className = 'ai-chat-message ai-chat-message--assistant';
    node.innerHTML = `<div class="ai-chat-message-meta">Error</div><div class="ai-chat-message-content">${escapeHtml(message)}</div>`;
    messagesEl.appendChild(node);
  }

  function upsertSession(session) {
    if (!session) return;
    const index = state.sessions.findIndex((item) => Number(item.id) === Number(session.id));
    if (index >= 0) {
      state.sessions.splice(index, 1, session);
    } else {
      state.sessions.unshift(session);
    }
    state.sessions.sort((left, right) => String(right.last_message_at || '').localeCompare(String(left.last_message_at || '')));
  }

  function setBusy(busy) {
    state.busy = busy;
    [newButton, form?.querySelector('button'), ...Array.from(promptsEl?.querySelectorAll('button') || [])].forEach((button) => {
      if (button) button.disabled = busy;
    });
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
}
