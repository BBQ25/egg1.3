@auth
  @if (\Illuminate\Support\Facades\Route::has('ai-chat.bootstrap'))
    <div
      id="aiChatWidget"
      class="ai-chat-widget app-dusk-optout"
      data-bootstrap-url="{{ route('ai-chat.bootstrap') }}"
      data-sessions-url="{{ route('ai-chat.sessions.index') }}"
      data-session-store-url="{{ route('ai-chat.sessions.store') }}"
      data-session-messages-url-template="{{ route('ai-chat.sessions.messages.index', ['session' => '__SESSION__']) }}"
      data-session-message-store-url-template="{{ route('ai-chat.sessions.messages.store', ['session' => '__SESSION__']) }}"
      data-csrf-token="{{ csrf_token() }}"
      hidden
    >
      <button type="button" class="ai-chat-launcher" aria-expanded="false" aria-controls="aiChatPanel">
        <i class="fa-solid fa-comments" aria-hidden="true"></i>
        <span>AI</span>
      </button>

      <section id="aiChatPanel" class="ai-chat-panel" role="dialog" aria-label="PoultryPulse AI assistant" hidden>
        <header class="ai-chat-header">
          <div>
            <strong>PoultryPulse AI</strong>
            <span data-ai-chat-role>Loading scope...</span>
          </div>
          <button type="button" class="ai-chat-icon-btn" data-ai-chat-close aria-label="Close AI assistant">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </header>

        <div class="ai-chat-body">
          <aside class="ai-chat-sessions" aria-label="AI chat sessions">
            <button type="button" class="ai-chat-new" data-ai-chat-new>
              <i class="fa-solid fa-plus" aria-hidden="true"></i>
              <span>New</span>
            </button>
            <div class="ai-chat-session-list" data-ai-chat-sessions></div>
          </aside>

          <main class="ai-chat-conversation">
            <div class="ai-chat-capabilities" data-ai-chat-capabilities></div>
            <div class="ai-chat-quick-prompts" data-ai-chat-prompts></div>
            <div class="ai-chat-messages" data-ai-chat-messages aria-live="polite"></div>
          </main>
        </div>

        <form class="ai-chat-composer" data-ai-chat-form>
          <label class="visually-hidden" for="aiChatInput">Message PoultryPulse AI</label>
          <textarea id="aiChatInput" data-ai-chat-input rows="2" maxlength="2000" placeholder="Ask about allowed farm, inventory, batch, price, or forecast data..."></textarea>
          <button type="submit" class="ai-chat-send" aria-label="Send message">
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
          </button>
        </form>
      </section>
    </div>
  @endif
@endauth
