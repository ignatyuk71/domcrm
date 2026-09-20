<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTeamUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TeamController extends Controller
{
    public function index()
    {
        if (! $this->canManageUsers()) {
            return view('settings.team', [
                'users' => collect(),
                'roleOptions' => User::roleOptions(),
                'migrationPending' => true,
            ]);
        }

        $users = User::query()
            ->orderByRaw("role = 'owner' DESC")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'username', 'role', 'is_active', 'created_at']);

        return view('settings.team', [
            'users' => $users,
            'roleOptions' => User::roleOptions(),
        ]);
    }

    public function store(SaveTeamUserRequest $request): RedirectResponse
    {
        if (! $this->canManageUsers()) {
            return back()->withErrors([
                'team' => 'Спочатку застосуйте міграції для модуля керування командою.',
            ]);
        }

        $data = $request->validated();

        User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'username' => $data['username'] ?? null,
            'role' => $data['role'],
            'is_active' => true,
            'password' => Hash::make($data['password']),
        ]);

        return redirect()
            ->route('settings.team.index')
            ->with('success', 'Користувача додано.');
    }

    public function update(SaveTeamUserRequest $request, User $user): RedirectResponse
    {
        if (! $this->canManageUsers()) {
            return back()->withErrors([
                'team' => 'Спочатку застосуйте міграції для модуля керування командою.',
            ]);
        }

        $data = $request->validated();

        $currentUser = $request->user();
        $isSelf = $currentUser?->id === $user->id;
        $ownerCount = User::query()->where('role', User::ROLE_OWNER)->count();

        // Забороняємо знімати права власника в останнього owner
        if ($user->role === User::ROLE_OWNER && $data['role'] !== User::ROLE_OWNER && $ownerCount <= 1) {
            return back()->withErrors([
                'team' => 'У системі має залишитись щонайменше один власник.',
            ], 'user'.$user->id)->withInput($request->except(['password', 'password_confirmation']));
        }

        // Забороняємо деактивацію власного акаунта
        if ($isSelf && ! $data['is_active']) {
            return back()->withErrors([
                'team' => 'Неможливо деактивувати власний акаунт.',
            ], 'user'.$user->id)->withInput($request->except(['password', 'password_confirmation']));
        }

        $payload = [
            'role' => $data['role'],
            'is_active' => (bool) $data['is_active'],
        ];

        // Старі форми без поля логіна не повинні стирати вже заданий логін.
        if (array_key_exists('username', $data)) {
            $payload['username'] = $data['username'];
        }

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        return redirect()
            ->route('settings.team.index')
            ->with('success', 'Користувача оновлено.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if (! $this->canManageUsers()) {
            return back()->withErrors([
                'team' => 'Спочатку застосуйте міграції для модуля керування командою.',
            ]);
        }

        $currentUser = $request->user();
        $isSelf = $currentUser?->id === $user->id;

        if ($isSelf) {
            return back()->withErrors([
                'team' => 'Неможливо видалити власний акаунт.',
            ]);
        }

        $ownerCount = User::query()->where('role', User::ROLE_OWNER)->count();

        if ($user->role === User::ROLE_OWNER && $ownerCount <= 1) {
            return back()->withErrors([
                'team' => 'Не можна видалити останнього власника CRM.',
            ]);
        }

        $user->delete();

        return redirect()
            ->route('settings.team.index')
            ->with('success', 'Користувача видалено.');
    }

    private function canManageUsers(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasColumn('users', 'role')
            && Schema::hasColumn('users', 'username')
            && Schema::hasColumn('users', 'is_active');
    }
}
