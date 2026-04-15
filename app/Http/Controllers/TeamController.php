<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TeamController extends Controller
{
    public function index()
    {
        if (!$this->canManageUsers()) {
            return view('settings.team', [
                'users' => collect(),
                'roleOptions' => User::roleOptions(),
                'migrationPending' => true,
            ]);
        }

        $users = User::query()
            ->orderByRaw("role = 'owner' DESC")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'created_at']);

        return view('settings.team', [
            'users' => $users,
            'roleOptions' => User::roleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->canManageUsers()) {
            return back()->withErrors([
                'team' => 'Спочатку застосуйте міграції для модуля керування командою.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,dns', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in(array_keys(User::roleOptions()))],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'is_active' => true,
            'password' => Hash::make($data['password']),
        ]);

        return redirect()
            ->route('settings.team.index')
            ->with('success', 'Користувача додано.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if (!$this->canManageUsers()) {
            return back()->withErrors([
                'team' => 'Спочатку застосуйте міграції для модуля керування командою.',
            ]);
        }

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(User::roleOptions()))],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $currentUser = $request->user();
        $isSelf = $currentUser?->id === $user->id;
        $ownerCount = User::query()->where('role', User::ROLE_OWNER)->count();

        // Забороняємо знімати права власника в останнього owner
        if ($user->role === User::ROLE_OWNER && $data['role'] !== User::ROLE_OWNER && $ownerCount <= 1) {
            return back()->withErrors([
                'team' => 'У системі має залишитись щонайменше один власник.',
            ]);
        }

        // Забороняємо деактивацію власного акаунта
        if ($isSelf && !$data['is_active']) {
            return back()->withErrors([
                'team' => 'Неможливо деактивувати власний акаунт.',
            ]);
        }

        $payload = [
            'role' => $data['role'],
            'is_active' => (bool) $data['is_active'],
        ];

        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        return redirect()
            ->route('settings.team.index')
            ->with('success', 'Користувача оновлено.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if (!$this->canManageUsers()) {
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
            && Schema::hasColumn('users', 'is_active');
    }
}
