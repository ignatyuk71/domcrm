<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\EmailValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamUsernameTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        return $owner;
    }

    private function updateUser(User $user, array $data = [])
    {
        return $this->from('/settings/team')->patch('/settings/team/'.$user->id, [
            'role' => $user->role, 'is_active' => $user->is_active, '_team_form' => 'user'.$user->id, ...$data,
        ]);
    }

    public function test_owner_can_assign_a_username_without_changing_the_password(): void
    {
        $this->owner();
        $user = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $password = $user->password;
        $this->updateUser($user, ['username' => ' Міша '])->assertSessionHasNoErrors();
        $this->assertSame('міша', $user->fresh()->username);
        $this->assertSame($password, $user->fresh()->password);
    }

    public function test_owner_can_create_a_user_with_a_username(): void
    {
        $this->owner();
        // Відома коректна адреса: перевірка логіна не залежить від зовнішнього DNS.
        $this->mock(EmailValidator::class)->shouldReceive('isValid')->once()
            ->with('username.test@gmail.com', \Mockery::type(EmailValidation::class))->andReturnTrue();
        $this->post('/settings/team', [
            'name' => 'Менеджер', 'email' => 'username.test@gmail.com', 'username' => ' Anna_2 ',
            'role' => User::ROLE_OPERATOR, 'password' => 'secure-password', 'password_confirmation' => 'secure-password',
        ])->assertSessionHasNoErrors()->assertRedirect('/settings/team');
        $this->assertDatabaseHas('users', ['email' => 'username.test@gmail.com', 'username' => 'anna_2', 'is_active' => true]);
    }

    public function test_duplicate_username_is_rejected_without_losing_the_row_draft(): void
    {
        $this->owner();
        User::factory()->create(['username' => 'міша']);
        $user = User::factory()->create(['username' => 'anna', 'role' => User::ROLE_OPERATOR]);
        $this->updateUser($user, ['username' => ' МІША ', 'role' => User::ROLE_PACKER, 'is_active' => false])
            ->assertSessionHasErrors('username', null, 'user'.$user->id)
            ->assertSessionHasInput('username', 'МІША')->assertSessionHasInput('role', User::ROLE_PACKER);
        $this->assertSame('anna', $user->fresh()->username);
        $this->assertTrue($user->fresh()->is_active);
        $this->get('/settings/team')->assertOk()->assertSee('Цей логін уже зайнятий.')
            ->assertSee('data-flash-toast', false)->assertSee('value="МІША"', false);
    }

    public function test_unchanged_username_is_allowed_and_omitting_it_does_not_erase_it(): void
    {
        $this->owner();
        $user = User::factory()->create(['username' => 'міша']);
        $this->updateUser($user, ['username' => 'МІША'])->assertSessionHasNoErrors();
        $this->updateUser($user)->assertSessionHasNoErrors();
        $this->assertSame('міша', $user->fresh()->username);
        $this->updateUser($user, ['username' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->username);
    }

    public function test_email_addresses_spaces_and_oversized_usernames_are_not_allowed(): void
    {
        $this->owner();
        $user = User::factory()->create();
        foreach (['user@example.com', 'два слова', str_repeat('a', 65), '<script>', ['міша']] as $username) {
            $this->updateUser($user, ['username' => $username])->assertSessionHasErrors('username', null, 'user'.$user->id);
            $this->assertNull($user->fresh()->username);
        }
    }

    public function test_staff_cannot_assign_usernames_or_create_users(): void
    {
        $target = User::factory()->create();
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->updateUser($target, ['username' => 'міша'])->assertForbidden();
            $this->post('/settings/team', ['username' => 'міша'])->assertForbidden();
        }
        $this->assertNull($target->fresh()->username);
    }

    public function test_self_protection_does_not_flash_passwords_on_error(): void
    {
        $owner = $this->owner();
        $this->updateUser($owner, ['username' => 'міша', 'is_active' => false,
            'password' => 'secret-password', 'password_confirmation' => 'secret-password'])
            ->assertSessionHasErrors('team', null, 'user'.$owner->id)
            ->assertSessionHasInput('username', 'міша');
        $this->assertArrayNotHasKey('password', session('_old_input'));
        $this->assertArrayNotHasKey('password_confirmation', session('_old_input'));
        $this->assertNull($owner->fresh()->username);
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_database_enforces_unique_normalized_usernames(): void
    {
        User::factory()->create(['username' => 'міша']);
        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['username' => 'МІША']);
    }
}
