<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'new-password1',
            'password_confirmation' => 'new-password1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Password updated successfully');
        $this->assertTrue(Hash::check('new-password1', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password1',
            'password_confirmation' => 'new-password1',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
