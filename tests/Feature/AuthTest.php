<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_niu_returns_needs_activation_for_new_user(): void
    {
        User::create([
            'niu' => '11111',
            'name' => 'Fajar Pratama',
            'pin_hash' => null,
            'role' => 'STUDENT',
            'practicum_group' => 'B2',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/check-niu', ['niu' => '11111'])
            ->assertOk()
            ->assertJson([
                'status' => 'needs_activation',
                'user' => [
                    'niu' => '11111',
                    'name' => 'Fajar Pratama',
                ],
            ]);
    }

    public function test_check_niu_returns_not_found_for_unknown_niu(): void
    {
        $this->postJson('/api/auth/check-niu', ['niu' => '99999'])
            ->assertNotFound()
            ->assertJson(['status' => 'not_found']);
    }

    public function test_check_niu_returns_ready_to_login_for_active_user(): void
    {
        User::create([
            'niu' => '22222',
            'name' => 'Adib Muzakki',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/check-niu', ['niu' => '22222'])
            ->assertOk()
            ->assertJson([
                'status' => 'ready_to_login',
                'user' => ['niu' => '22222'],
            ]);
    }

    public function test_user_can_activate_pin_and_login(): void
    {
        $user = User::create([
            'niu' => '33333',
            'name' => 'New Student',
            'pin_hash' => null,
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/activate', [
            'niu' => '33333',
            'pin' => '654321',
            'pin_confirmation' => '654321',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'niu', 'is_admin', 'is_pj']]);

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue(Hash::check('654321', $user->fresh()->pin_hash));
    }

    public function test_activation_rejected_for_already_active_account(): void
    {
        User::create([
            'niu' => '33333',
            'name' => 'Active Student',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/activate', [
            'niu' => '33333',
            'pin' => '654321',
            'pin_confirmation' => '654321',
        ])->assertStatus(422);
    }

    public function test_user_can_login_with_valid_pin(): void
    {
        $user = User::create([
            'niu' => '44444',
            'name' => 'Active Student',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'niu' => '44444',
            'pin' => '123456',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['niu', 'is_admin', 'is_pj']]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_pin(): void
    {
        User::create([
            'niu' => '44444',
            'name' => 'Active Student',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'niu' => '44444',
            'pin' => '999999',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertGuest();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::create([
            'niu' => '55555',
            'name' => 'Me Student',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.niu', '55555');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_ends_session(): void
    {
        $user = User::create([
            'niu' => '66666',
            'name' => 'Logout Student',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Anda telah keluar.']);

        $this->assertGuest();
    }
}
