<?php

namespace Tests\Feature;

use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => '']);
    }

    public function test_guest_is_blocked_from_ai_chat_bootstrap(): void
    {
        $this->getJson(route('ai-chat.bootstrap'))->assertUnauthorized();
    }

    public function test_all_roles_can_bootstrap_floating_ai_chat(): void
    {
        $users = [
            'admin' => User::factory()->admin()->create(),
            'owner' => User::factory()->owner()->create(),
            'staff' => User::factory()->staff()->create(),
            'customer' => User::factory()->customer()->create(),
        ];

        foreach ($users as $role => $user) {
            $this->actingAs($user)
                ->getJson(route('ai-chat.bootstrap'))
                ->assertOk()
                ->assertJsonPath('bootstrap.role_key', $role)
                ->assertJsonPath('bootstrap.action_mode', 'draft_only');
        }
    }

    public function test_customer_private_operational_question_is_refused_without_leaking_farm_data(): void
    {
        $owner = User::factory()->owner()->create();
        $customer = User::factory()->customer()->create();
        $farm = $this->createFarm($owner, 'Private Layer Farm');
        $this->createItem($farm, 'PRIVATE-ITEM', 55);

        $response = $this->ask($customer, 'Show me the private device inventory for Private Layer Farm.');

        $response->assertOk();
        $assistant = $this->assistantMessage($response->json());
        $this->assertSame('refusal', $assistant['intent']);
        $this->assertStringContainsString('cannot access private farm', $assistant['content']);
        $this->assertStringNotContainsString('Private Layer Farm', $assistant['content']);
        $this->assertStringNotContainsString('PRIVATE-ITEM', $assistant['content']);
    }

    public function test_owner_only_receives_owned_inventory_scope(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();

        $ownedFarm = $this->createFarm($owner, 'Owned AI Farm');
        $otherFarm = $this->createFarm($otherOwner, 'Hidden AI Farm');
        $this->createItem($ownedFarm, 'OWNED-AI', 40);
        $this->createItem($otherFarm, 'HIDDEN-AI', 90);

        $response = $this->ask($owner, 'Summarize my inventory.');

        $response->assertOk();
        $assistant = $this->assistantMessage($response->json());
        $this->assertStringContainsString('Scoped stock: 40 eggs', $assistant['content']);
        $this->assertStringNotContainsString('Hidden AI Farm', $assistant['content']);
        $this->assertSame(1, $assistant['sources'][0] ? 1 : 0);
    }

    public function test_staff_only_receives_assigned_farm_scope(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $assignedFarm = $this->createFarm($owner, 'Assigned AI Farm');
        $foreignFarm = $this->createFarm($owner, 'Foreign AI Farm');

        DB::table('farm_staff_assignments')->insert([
            'farm_id' => $assignedFarm->id,
            'user_id' => $staff->id,
            'created_at' => now(),
        ]);

        $this->createItem($assignedFarm, 'STAFF-AI', 35);
        $this->createItem($foreignFarm, 'FOREIGN-AI', 70);

        $response = $this->ask($staff, 'Show low-stock risks for my farms.');

        $response->assertOk();
        $assistant = $this->assistantMessage($response->json());
        $this->assertStringContainsString('Scoped stock: 35 eggs', $assistant['content']);
        $this->assertStringNotContainsString('Foreign AI Farm', $assistant['content']);
    }

    public function test_admin_receives_all_inventory_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();

        $this->createItem($this->createFarm($owner, 'Admin Visible One'), 'ADMIN-AI-1', 30);
        $this->createItem($this->createFarm($otherOwner, 'Admin Visible Two'), 'ADMIN-AI-2', 45);

        $response = $this->ask($admin, 'Summarize system farm and inventory health.');

        $response->assertOk();
        $assistant = $this->assistantMessage($response->json());
        $this->assertStringContainsString('Scoped stock: 75 eggs', $assistant['content']);
    }

    public function test_action_requests_create_drafts_without_mutating_operational_records(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Draft Farm');
        $this->createItem($farm, 'DRAFT-AI', 60);
        $beforeMovements = StockMovement::query()->count();

        $response = $this->ask($owner, 'Record 10 eggs out from DRAFT-AI today.');

        $response->assertOk();
        $assistant = $this->assistantMessage($response->json());
        $this->assertSame('action_draft', $assistant['intent']);
        $this->assertStringContainsString('No database record was changed', $assistant['content']);
        $this->assertDatabaseCount('ai_chat_action_drafts', 1);
        $this->assertSame($beforeMovements, StockMovement::query()->count());
    }

    public function test_openai_failure_returns_graceful_fallback_response(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500),
        ]);

        $customer = User::factory()->customer()->create();
        $response = $this->ask($customer, 'Show egg price ranges by size.');

        $response->assertOk();
        $assistant = $this->assistantMessage($response->json());
        $this->assertSame('fallback', $assistant['status']);
        $this->assertStringContainsString('AI service is currently unavailable', $assistant['content']);
    }

    private function ask(User $user, string $message)
    {
        $sessionId = $this->actingAs($user)
            ->postJson(route('ai-chat.sessions.store'))
            ->assertCreated()
            ->json('session.id');

        return $this->actingAs($user)
            ->postJson(route('ai-chat.sessions.messages.store', ['session' => $sessionId]), [
                'message' => $message,
            ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function assistantMessage(array $payload): array
    {
        $messages = collect($payload['messages'] ?? []);

        return $messages->firstWhere('sender', 'assistant') ?? [];
    }

    private function createFarm(User $owner, string $farmName): Farm
    {
        return Farm::query()->create([
            'farm_name' => $farmName,
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);
    }

    private function createItem(Farm $farm, string $itemCode, int $stock): EggItem
    {
        $item = EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => $itemCode,
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Large',
            'unit_cost' => 7.50,
            'selling_price' => 9.00,
            'reorder_level' => 45,
            'current_stock' => $stock,
        ]);

        StockMovement::query()->create([
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => $stock,
            'stock_before' => 0,
            'stock_after' => $stock,
            'unit_cost' => 7.50,
            'reference_no' => 'AI-OPEN-' . $itemCode,
            'movement_date' => now()->toDateString(),
        ]);

        return $item;
    }
}
