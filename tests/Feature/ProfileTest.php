<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully')
            ->assertJsonPath('user.name', 'Test User')
            ->assertJsonPath('user.email', 'test@example.com');
        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

        $response->assertOk();
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_guests_cannot_update_a_profile(): void
    {
        $this->putJson('/api/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])->assertUnauthorized();
    }
}
