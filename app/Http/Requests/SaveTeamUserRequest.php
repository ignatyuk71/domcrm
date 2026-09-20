<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveTeamUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $user = $this->route('user');
        $this->errorBag = $user ? 'user'.$user->id : 'createUser';
        $this->merge(['_team_form' => $this->errorBag]);

        if (is_string($this->input('username'))) {
            $this->merge(['username' => User::normalizeUsername($this->input('username'))]);
        }
        if (! $user && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $uniqueUsername = Rule::unique(User::class, 'username');
        if ($user) {
            $uniqueUsername->ignore($user);
        }

        $rules = [
            'username' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[\p{L}\p{N}][\p{L}\p{N}._-]*\z/u', $uniqueUsername],
            'role' => ['required', Rule::in(array_keys(User::roleOptions()))],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ];

        return $user
            ? [...$rules, 'is_active' => ['required', 'boolean']]
            : [...$rules, 'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc,dns', 'max:255', Rule::unique(User::class, 'email')]];
    }

    public function attributes(): array
    {
        return ['username' => 'логін', 'name' => 'ім’я', 'email' => 'email',
            'role' => 'роль', 'is_active' => 'статус', 'password' => 'пароль'];
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'Цей логін уже зайнятий. Виберіть інший.',
            'username.string' => 'Логін має бути текстом.',
            'username.regex' => 'Логін має починатися з літери або цифри. Дозволено літери, цифри, крапку, дефіс і підкреслення, без пробілів та @.',
            'username.max' => 'Логін не може містити більше 64 символів.',
        ];
    }
}
