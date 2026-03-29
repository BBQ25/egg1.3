<?php

namespace Tests\Feature;

use App\Contracts\DeployTriggerRunner;
use Tests\TestCase;

class GithubDeployWebhookTest extends TestCase
{
    public function test_disabled_webhook_returns_not_found(): void
    {
        config()->set('deploy.github.enabled', false);

        $response = $this->postJson(route('ops.deploy.github'), []);

        $response->assertNotFound();
    }

    public function test_ping_event_with_valid_signature_returns_pong(): void
    {
        config()->set('deploy.github.enabled', true);
        config()->set('deploy.github.secret', 'top-secret');

        $payload = json_encode(['zen' => 'Deploys should be boring.'], JSON_THROW_ON_ERROR);

        $response = $this->deliverPayload($payload, $this->signatureHeaders('top-secret', $payload, 'ping'));

        $response->assertOk();
        $response->assertJson([
            'message' => 'pong',
        ]);
    }

    public function test_push_to_main_for_expected_repository_starts_deploy(): void
    {
        $fakeRunner = new class() implements DeployTriggerRunner
        {
            public int $calls = 0;

            public function queue(string $triggerFile, array $payload): array
            {
                $this->calls++;

                return [
                    'ok' => true,
                    'queued_at' => '2026-03-29T00:00:00+08:00',
                    'trigger_file' => $triggerFile,
                ];
            }
        };

        $this->app->instance(DeployTriggerRunner::class, $fakeRunner);

        config()->set('deploy.github.enabled', true);
        config()->set('deploy.github.secret', 'top-secret');
        config()->set('deploy.github.repository', 'BBQ25/egg1.3');
        config()->set('deploy.github.branch', 'main');
        config()->set('deploy.github.trigger_file', storage_path('app/deploy/github-webhook-trigger.json'));

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'after' => 'beda3e50b42215389c882d99b20652e9fbffc4f6',
            'repository' => [
                'full_name' => 'BBQ25/egg1.3',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->deliverPayload($payload, $this->signatureHeaders('top-secret', $payload));

        $response->assertAccepted();
        $response->assertJson([
            'message' => 'deploy queued',
            'queued_at' => '2026-03-29T00:00:00+08:00',
        ]);
        $this->assertSame(1, $fakeRunner->calls);
    }

    public function test_push_to_other_branch_is_ignored(): void
    {
        $fakeRunner = new class() implements DeployTriggerRunner
        {
            public int $calls = 0;

            public function queue(string $triggerFile, array $payload): array
            {
                $this->calls++;

                return [
                    'ok' => true,
                    'queued_at' => '2026-03-29T00:00:00+08:00',
                ];
            }
        };

        $this->app->instance(DeployTriggerRunner::class, $fakeRunner);

        config()->set('deploy.github.enabled', true);
        config()->set('deploy.github.secret', 'top-secret');
        config()->set('deploy.github.repository', 'BBQ25/egg1.3');
        config()->set('deploy.github.branch', 'main');

        $payload = json_encode([
            'ref' => 'refs/heads/feature/test',
            'repository' => [
                'full_name' => 'BBQ25/egg1.3',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->deliverPayload($payload, $this->signatureHeaders('top-secret', $payload));

        $response->assertStatus(202);
        $response->assertJson([
            'message' => 'ignored branch',
        ]);
        $this->assertSame(0, $fakeRunner->calls);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $fakeRunner = new class() implements DeployTriggerRunner
        {
            public int $calls = 0;

            public function queue(string $triggerFile, array $payload): array
            {
                $this->calls++;

                return [
                    'ok' => true,
                    'queued_at' => '2026-03-29T00:00:00+08:00',
                ];
            }
        };

        $this->app->instance(DeployTriggerRunner::class, $fakeRunner);

        config()->set('deploy.github.enabled', true);
        config()->set('deploy.github.secret', 'top-secret');

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => [
                'full_name' => 'BBQ25/egg1.3',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->deliverPayload($payload, [
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256=wrong',
        ]);

        $response->assertUnauthorized();
        $response->assertJson([
            'message' => 'Invalid signature.',
        ]);
        $this->assertSame(0, $fakeRunner->calls);
    }

    /**
     * @return array<string, string>
     */
    private function signatureHeaders(string $secret, string $payload, string $event = 'push'): array
    {
        return [
            'X-GitHub-Event' => $event,
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $payload, $secret),
        ];
    }

    private function deliverPayload(string $payload, array $headers)
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $normalizedName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$normalizedName] = $value;
        }

        return $this->call(
            'POST',
            route('ops.deploy.github'),
            [],
            [],
            [],
            $server,
            $payload
        );
    }
}
