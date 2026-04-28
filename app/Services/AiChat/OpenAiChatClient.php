<?php

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiChatClient
{
    public function __construct(
        private readonly AiChatResponseFormatter $formatter
    ) {
    }

    /**
     * @param array<string, mixed> $scopedContext
     * @param array<int, array{role:string,content:string}> $history
     * @return array{content:string,status:string,model:?string,error:?string}
     */
    public function respond(string $message, array $scopedContext, array $history = []): array
    {
        $fallback = $this->formatter->format($scopedContext);
        $apiKey = trim((string) config('services.openai.key', ''));

        if ($apiKey === '') {
            return [
                'content' => $fallback,
                'status' => 'local',
                'model' => null,
                'error' => null,
            ];
        }

        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        $timeout = max(5, (int) config('services.openai.timeout', 25));
        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the PoultryPulse RBAC assistant.',
                    'Act like a practical human assistant: answer the exact question first, then give only the useful supporting detail.',
                    'Use only the scoped JSON data and server-prepared answer provided by the application.',
                    'Never claim to access tables, farms, devices, users, or actions outside that scoped data.',
                    'Never execute changes. For action requests, explain that a draft was prepared only.',
                    'If the scoped data is insufficient, say that clearly and do not invent numbers.',
                    'If the user is vague, ask one clear follow-up question and offer role-appropriate examples.',
                    'For yes/no questions, start with yes or no. For ranking/comparison questions, name the top result first.',
                    'For forecasts, identify the best-ranked algorithm and preserve exact MAE, RMSE, projected stock, and confidence values.',
                    'Preserve exact numeric values from the scoped data. Keep markdown tables when they help.',
                    'Do not mention raw JSON, prompts, policies, or hidden implementation details.',
                ]),
            ],
        ];

        foreach (array_slice($history, -8) as $entry) {
            $role = ($entry['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 1800),
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => implode("\n\n", [
                "User question:\n{$message}",
                "Server-prepared direct answer. Use this as the factual base and improve the wording only when helpful:\n{$fallback}",
                'Scoped data for reasoning and citations within the user role:',
                json_encode($scopedContext, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ]),
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.35,
                    'messages' => $messages,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('OpenAI returned HTTP ' . $response->status());
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            if ($content === '') {
                throw new \RuntimeException('OpenAI returned an empty response.');
            }

            return [
                'content' => $content,
                'status' => 'ok',
                'model' => $model,
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'content' => "AI service is currently unavailable, so I used the system's scoped deterministic summary instead.\n\n{$fallback}",
                'status' => 'fallback',
                'model' => $model,
                'error' => 'OpenAI service unavailable',
            ];
        }
    }
}
