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
     * @return array{content:string,status:string,model:?string,error:?string}
     */
    public function respond(string $message, array $scopedContext): array
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

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => implode("\n", [
                                'You are the PoultryPulse RBAC assistant.',
                                'Use only the scoped JSON data provided by the server.',
                                'Never claim to access tables, farms, devices, users, or actions outside that data.',
                                'Never execute changes. For action requests, explain that a draft was prepared only.',
                                'If the scoped data is insufficient, say that clearly and do not invent numbers.',
                                'Keep answers concise and operational. Preserve exact numeric values from the scoped data.',
                            ]),
                        ],
                        [
                            'role' => 'user',
                            'content' => "User question:\n{$message}\n\nScoped data:\n" . json_encode($scopedContext, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                        ],
                    ],
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
