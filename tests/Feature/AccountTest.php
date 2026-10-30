<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_string_and_overlong_passwords_are_rejected_without_hash_errors(): void
    {
        $base = ['name' => 'Test', 'email' => 'length@example.test'];
        foreach ([['invalid'], str_repeat('a', 73), str_repeat('é', 40), "a valid password\0bad"] as $password) {
            $this->postJson('/api/v1/auth/register', $base + ['password' => $password, 'password_confirmation' => $password])->assertUnprocessable();
        }
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/me/password', ['current_password' => ['invalid'], 'password' => 'new password 123!', 'password_confirmation' => 'new password 123!'])->assertUnprocessable();
        config(['mail.default' => 'failover']);
        Notification::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(503);
        Notification::assertNothingSent();
    }

    public function test_registration_login_logout_and_profile_are_session_based(): void
    {
        $this->getJson('/api/v1/auth/session')->assertJsonPath('data.user', null)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/register', ['name' => 'Alice', 'email' => 'ALICE@example.test', 'password' => 'A long password 123!', 'password_confirmation' => 'A long password 123!'])->assertCreated()->assertJsonPath('data.user.email', 'alice@example.test')->assertJsonPath('data.user.role', 'user')->assertJsonMissingPath('data.user.password');
        $user = User::first();
        $this->assertTrue(Hash::check('A long password 123!', $user->password));
        $this->getJson('/api/v1/me/usage')->assertOk()->assertJsonPath('data.limits.max_file_bytes', 25_000_000);
        $this->patchJson('/api/v1/me/profile', ['name' => 'Alice 2', 'email' => 'new@example.test', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->patchJson('/api/v1/me/profile', ['name' => 'Alice 2', 'email' => 'new@example.test', 'current_password' => 'A long password 123!'])->assertOk()->assertJsonPath('data.user.name', 'Alice 2');
        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.user', null);
        $this->patchJson('/api/v1/me/profile', [])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'new@example.test', 'password' => 'A long password 123!'])->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_roles_and_plans_cannot_be_self_assigned_and_login_is_throttled(): void
    {
        $values = ['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'A long password 123!', 'password_confirmation' => 'A long password 123!', 'role' => 'admin', 'plan' => 'premium'];
        $this->postJson('/api/v1/auth/register', $values)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'bad@example.test', 'password' => 'not-correct'])->assertUnprocessable()->assertJsonPath('error.fields.email.0', 'Adresse email ou mot de passe incorrect.');
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'bad@example.test', 'password' => 'not-correct'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old password 123!']);
        $this->actingAs($user)->patchJson('/api/v1/me/password', ['current_password' => 'old password 123!', 'password' => 'new password 456!', 'password_confirmation' => 'new password 456!'])->assertOk();
        $this->assertTrue(Hash::check('new password 456!', $user->fresh()->password));
    }

    public function test_password_reset_is_private_one_time_and_does_not_log_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(503);
        Notification::assertNothingSent();
        config(['mail.default' => 'smtp']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonMissingPath('data.token');
        Notification::assertSentTo($user, AccountResetPassword::class);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'absent@example.test'])->assertOk()->assertJsonPath('data.message', 'Si un compte correspond à cette adresse, un lien vous sera envoyé.');
        $token = Password::createToken($user);
        $values = ['email' => $user->email, 'token' => $token, 'password' => 'reset password 123!', 'password_confirmation' => 'reset password 123!'];
        $this->postJson('/api/v1/auth/reset-password', $values)->assertOk();
        $this->assertTrue(Hash::check('reset password 123!', $user->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', $values)->assertUnprocessable();
        $this->assertGuest();
    }
}
