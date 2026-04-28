<?php

namespace App\Http\Controllers;

use App\Models\AiChatActionDraft;
use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use App\Models\User;
use App\Services\AiChat\AiChatDataService;
use App\Services\AiChat\OpenAiChatClient;
use App\Support\AppTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    public function __construct(
        private readonly AiChatDataService $dataService,
        private readonly OpenAiChatClient $openAi
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'bootstrap' => $this->dataService->bootstrap($user),
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = AiChatSession::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (AiChatSession $session): array => $this->serializeSession($session))
            ->all();

        return response()->json([
            'ok' => true,
            'sessions' => $sessions,
        ]);
    }

    public function storeSession(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        $session = AiChatSession::query()->create([
            'user_id' => (int) $user->id,
            'title' => trim((string) ($validated['title'] ?? '')) !== ''
                ? trim((string) $validated['title'])
                : 'New chat',
            'role_key' => $this->dataService->roleKey($user),
            'last_message_at' => AppTimezone::now(),
        ]);

        return response()->json([
            'ok' => true,
            'session' => $this->serializeSession($session),
        ], 201);
    }

    public function messages(Request $request, AiChatSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $messages = $session->messages()
            ->with('actionDrafts')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AiChatMessage $message): array => $this->serializeMessage($message))
            ->all();

        return response()->json([
            'ok' => true,
            'session' => $this->serializeSession($session),
            'messages' => $messages,
        ]);
    }

    public function storeMessage(Request $request, AiChatSession $session): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeSession($request, $session);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $messageText = trim((string) $validated['message']);
        $roleKey = $this->dataService->roleKey($user);

        $userMessage = $session->messages()->create([
            'user_id' => (int) $user->id,
            'sender' => 'user',
            'role_key' => $roleKey,
            'intent' => null,
            'status' => 'ok',
            'content' => $messageText,
        ]);

        $scopedContext = $this->dataService->build($user, $messageText);
        $assistantResponse = $this->openAi->respond($messageText, $scopedContext);

        $assistantMessage = $session->messages()->create([
            'user_id' => (int) $user->id,
            'sender' => 'assistant',
            'role_key' => $roleKey,
            'intent' => (string) ($scopedContext['intent'] ?? 'overview'),
            'status' => $assistantResponse['status'],
            'content' => $assistantResponse['content'],
            'scoped_data_json' => $this->safeJsonArray($scopedContext),
            'sources_json' => $this->safeJsonArray($scopedContext['sources'] ?? []),
        ]);

        $draft = null;
        if (is_array($scopedContext['action_draft'] ?? null)) {
            $draft = AiChatActionDraft::query()->create([
                'session_id' => (int) $session->id,
                'message_id' => (int) $assistantMessage->id,
                'user_id' => (int) $user->id,
                'action_type' => (string) data_get($scopedContext, 'action_draft.action_type', 'general_request'),
                'summary' => (string) data_get($scopedContext, 'action_draft.summary', 'Draft-only request.'),
                'target_route' => data_get($scopedContext, 'action_draft.target_route'),
                'payload_json' => $this->safeJsonArray(data_get($scopedContext, 'action_draft.payload_json', [])),
                'status' => 'draft',
            ]);
            $assistantMessage->setRelation('actionDrafts', collect([$draft]));
        }

        $session->forceFill([
            'title' => $session->title === 'New chat' ? $this->titleFromMessage($messageText) : $session->title,
            'role_key' => $roleKey,
            'last_intent' => (string) ($scopedContext['intent'] ?? 'overview'),
            'last_message_at' => AppTimezone::now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'session' => $this->serializeSession($session->refresh()),
            'messages' => [
                $this->serializeMessage($userMessage),
                $this->serializeMessage($assistantMessage),
            ],
            'draft' => $draft ? $this->serializeDraft($draft) : null,
        ]);
    }

    private function authorizeSession(Request $request, AiChatSession $session): void
    {
        abort_unless((int) $session->user_id === (int) $request->user()?->id, 403);
    }

    private function titleFromMessage(string $message): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $message));

        return $title === '' ? 'New chat' : Str::limit($title, 72, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(AiChatSession $session): array
    {
        return [
            'id' => (int) $session->id,
            'title' => (string) $session->title,
            'role_key' => (string) $session->role_key,
            'last_intent' => $session->last_intent,
            'last_message_at' => $session->last_message_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(AiChatMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'sender' => (string) $message->sender,
            'role_key' => (string) $message->role_key,
            'intent' => $message->intent,
            'status' => (string) $message->status,
            'content' => (string) $message->content,
            'sources' => $message->sources_json ?? [],
            'created_at' => $message->created_at?->toIso8601String(),
            'drafts' => $message->relationLoaded('actionDrafts')
                ? $message->actionDrafts->map(fn (AiChatActionDraft $draft): array => $this->serializeDraft($draft))->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDraft(AiChatActionDraft $draft): array
    {
        $routeName = $draft->target_route;

        return [
            'id' => (int) $draft->id,
            'action_type' => (string) $draft->action_type,
            'summary' => (string) $draft->summary,
            'target_route' => $routeName,
            'target_url' => $routeName && Route::has($routeName) ? route($routeName) : null,
            'payload' => $draft->payload_json ?? [],
            'status' => (string) $draft->status,
            'created_at' => $draft->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param mixed $value
     * @return array<mixed>
     */
    private function safeJsonArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
