<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'label' => 'Office',
            'recipient_name' => 'Ada Obi',
            'phone' => '0801 234 5678',
            'street' => '5 Broad Street',
            'city' => 'Lagos Island',
            'state' => 'Lagos',
        ];
    }

    public function test_first_address_becomes_default_and_phone_is_cleaned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/addresses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.phone', '08012345678');
    }

    public function test_new_default_replaces_old_default(): void
    {
        $user = User::factory()->create();
        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/addresses', $this->payload(['label' => 'Home', 'is_default' => true]))
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');

        $this->assertDatabaseHas('addresses', ['id' => $first, 'is_default' => false]);
        $this->assertDatabaseHas('addresses', ['id' => $second, 'is_default' => true]);
    }

    public function test_validation(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/addresses', $this->payload(['phone' => 'abc', 'street' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'street']);
    }

    public function test_cannot_see_edit_or_delete_another_users_address(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $address = $this->makeAddress($owner);

        $this->actingAs($intruder, 'sanctum')->getJson('/api/v1/addresses')->assertJsonCount(0, 'data');
        $this->actingAs($intruder, 'sanctum')->patchJson("/api/v1/addresses/{$address->id}", ['city' => 'Hacked'])->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/addresses/{$address->id}")->assertNotFound();
    }

    public function test_update_and_switching_default(): void
    {
        $user = User::factory()->create();
        $a = $this->makeAddress($user, ['is_default' => true]);
        $b = $this->makeAddress($user, ['is_default' => false, 'label' => 'Work']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/addresses/{$b->id}", ['city' => 'Ikoyi', 'is_default' => true])
            ->assertOk()->assertJsonPath('data.city', 'Ikoyi')->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', ['id' => $a->id, 'is_default' => false]);

        // Trying to un-default the current default does nothing: there must always be one.
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/addresses/{$b->id}", ['is_default' => false])
            ->assertOk()->assertJsonPath('data.is_default', true);
    }

    public function test_deleting_the_default_promotes_another(): void
    {
        $user = User::factory()->create();
        $a = $this->makeAddress($user, ['is_default' => false]);
        $b = $this->makeAddress($user, ['is_default' => true]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/addresses/{$b->id}")->assertNoContent();

        $this->assertDatabaseHas('addresses', ['id' => $a->id, 'is_default' => true]);
    }

    public function test_address_limit(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->makeAddress($user, ['is_default' => $i === 0]);
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/addresses', $this->payload())->assertUnprocessable();
    }
}
