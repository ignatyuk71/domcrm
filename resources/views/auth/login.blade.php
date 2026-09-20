<x-guest-layout>
    @php
        $loginValue = old('login', old('email'));
        $loginValue = is_string($loginValue) ? $loginValue : '';
    @endphp
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h4 class="card-title text-center mb-4 fw-bold">Вхід</h4>

            <x-flash-toast />

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <!-- Єдине поле для обох способів входу. -->
                <div class="mb-3">
                    <label for="login" class="form-label">Логін або email</label>
                    <input id="login" class="form-control @error('login') is-invalid @enderror" type="text" name="login" value="{{ $loginValue }}" maxlength="255" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" aria-invalid="{{ $errors->has('login') ? 'true' : 'false' }}" @error('login') aria-describedby="login-error" @enderror />
                    @error('login')
                        <div id="login-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Пароль -->
                <div class="mb-3">
                    <label for="password" class="form-label">Пароль</label>
                    <input id="password" class="form-control @error('password') is-invalid @enderror" type="password" name="password" required autocomplete="current-password" />
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Запам’ятати вхід -->
                <div class="mb-3 form-check">
                    <input id="remember_me" type="checkbox" class="form-check-input" name="remember" @checked(old('remember'))>
                    <label for="remember_me" class="form-check-label text-muted small">Запам’ятати мене</label>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    @if (Route::has('password.request'))
                        <a class="text-decoration-none small" href="{{ route('password.request') }}">
                            Забули пароль?
                        </a>
                    @endif

                    <button type="submit" class="btn btn-primary">
                        Увійти
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-guest-layout>
