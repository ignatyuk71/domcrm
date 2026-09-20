<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_accepts_text_and_preserves_the_identifier_after_an_error(): void
    {
        $this->get('/login')->assertOk()->assertSee('Логін або email')->assertSee('name="login"', false);
        $this->from('/login')->post('/login', ['login' => 'Міша', 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors('login')->assertSessionHasInput('login', 'Міша');
        $this->get('/login')->assertSee('value="Міша"', false)->assertSee('data-flash-toast', false);
        $this->assertGuest();
    }

    public function test_username_and_email_authenticate_the_same_user_without_case_sensitivity(): void
    {
        $user = User::factory()->create(['username' => 'Міша', 'email' => 'misha@example.com']);
        $this->assertSame('міша', $user->username);

        foreach (['Міша', 'МІША', ' міша ', ' MISHA@EXAMPLE.COM '] as $login) {
            $this->post('/login', ['login' => $login, 'password' => 'password'])
                ->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    public function test_email_login_still_works_for_accounts_without_a_username_and_remembers_them(): void
    {
        $user = User::factory()->create(['username' => null]);
        $this->post('/login', ['login' => $user->email, 'password' => 'password', 'remember' => 'on'])
            ->assertSessionHasNoErrors()->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_display_name_is_not_automatically_a_login(): void
    {
        User::factory()->create(['name' => 'Міша', 'username' => null]);
        $this->post('/login', ['login' => 'Міша', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_blocked_accounts_cannot_sign_in_through_either_identifier(): void
    {
        $user = User::factory()->create(['username' => 'blocked', 'is_active' => false]);
        foreach ([$user->username, $user->email] as $login) {
            $this->post('/login', ['login' => $login, 'password' => 'password'])
                ->assertSessionHasErrors(['login' => 'Неправильний логін, email або пароль.']);
            $this->assertGuest();
        }
    }

    public function test_unknown_username_and_wrong_password_have_the_same_error(): void
    {
        User::factory()->create(['username' => 'known']);
        foreach (['known', 'unknown'] as $login) {
            $this->post('/login', ['login' => $login, 'password' => 'wrong'])
                ->assertSessionHasErrors(['login' => 'Неправильний логін, email або пароль.']);
            $this->assertGuest();
        }
    }

    public function test_switching_between_username_and_email_does_not_bypass_the_attempt_limit(): void
    {
        $user = User::factory()->create(['username' => 'Міша']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['login' => $attempt % 2 ? $user->email : 'МІША', 'password' => 'wrong'])
                ->assertSessionHasErrors(['login' => 'Неправильний логін, email або пароль.']);
        }

        $this->post('/login', ['login' => 'міша', 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertStringContainsString('Забагато спроб', session('errors')->first('login'));
        $this->assertGuest();
    }

    public function test_invalid_identifiers_are_rejected_before_authentication(): void
    {
        foreach ([null, ['міша'], str_repeat('a', 256)] as $login) {
            $this->post('/login', ['login' => $login, 'password' => 'password'])
                ->assertSessionHasErrors('login');
            $this->assertGuest();
            $this->get('/login')->assertOk();
        }
    }
}
