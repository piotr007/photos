<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_password_reset_request_page_renders(): void
    {
        $this->get('/admin/password-reset/request')->assertOk();
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_authenticated_user_can_open_profile_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk();
    }

    public function test_email_authentication_flag_toggles_in_database(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasEmailAuthentication());

        $user->toggleEmailAuthentication(true);

        $this->assertTrue($user->fresh()->hasEmailAuthentication());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'has_email_authentication' => true,
        ]);
    }

    public function test_app_authentication_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->create();

        $user->saveAppAuthenticationSecret('SECRET123');

        $this->assertSame('SECRET123', $user->fresh()->getAppAuthenticationSecret());
        $this->assertNotSame(
            'SECRET123',
            $user->fresh()->getRawOriginal('app_authentication_secret'),
        );
    }
}
