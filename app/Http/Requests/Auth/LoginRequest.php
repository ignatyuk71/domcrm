<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private ?string $resolvedThrottleKey = null;

    protected function prepareForValidation(): void
    {
        // Сумісність із уже відкритими формами, які ще надсилають поле email.
        $login = $this->input('login', $this->input('email'));
        $this->merge(['login' => is_string($login) ? Str::lower(trim($login)) : $login]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            $this->loginColumn() => $this->string('login')->toString(),
            'password' => $this->string('password')->toString(),
        ];

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_active')) {
            $credentials['is_active'] = true;
        }

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Неправильний логін, email або пароль.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Забагато спроб входу. Спробуйте через {$seconds} с.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        if ($this->resolvedThrottleKey !== null) {
            return $this->resolvedThrottleKey;
        }

        // Обидва способи входу використовують спільний ліміт для акаунта й IP.
        $login = $this->string('login')->toString();
        $id = User::query()->where($this->loginColumn(), $login)->value('id');
        $identity = $id === null ? $this->loginColumn().':'.$login : 'user:'.$id;

        return $this->resolvedThrottleKey = 'login:'.hash('sha256', $identity.'|'.$this->ip());
    }

    private function loginColumn(): string
    {
        // Символ @ заборонено в логінах, тож email не може вказати на інший акаунт.
        return str_contains($this->string('login')->toString(), '@') ? 'email' : 'username';
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Введіть логін або email.',
            'login.string' => 'Введіть коректний логін або email.',
            'login.max' => 'Логін або email не може містити більше 255 символів.',
            'password.required' => 'Введіть пароль.',
        ];
    }
}
