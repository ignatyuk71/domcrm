<section class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-white border-bottom border-light p-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-primary bg-opacity-10 p-2 text-primary">
                <i class="bi bi-shield-lock-fill fs-4"></i>
            </div>
            <div>
                <h5 class="fw-bold text-dark mb-1">{{ __('Оновлення пароля') }}</h5>
                <p class="text-muted small mb-0">
                    {{ __('Використовуйте довгий та складний пароль для безпеки вашого акаунту.') }}
                </p>
            </div>
        </div>
    </div>

    <div class="card-body p-4">
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            @method('put')

            <div class="mb-3">
                <label for="update_password_current_password" class="form-label fw-medium text-secondary small">
                    {{ __('Поточний пароль') }}
                </label>
                <input 
                    type="password" 
                    id="update_password_current_password" 
                    name="current_password" 
                    class="form-control {{ $errors->updatePassword->get('current_password') ? 'is-invalid' : '' }}" 
                    autocomplete="current-password"
                >
                @foreach ($errors->updatePassword->get('current_password') as $message)
                    <div class="invalid-feedback d-block">
                        {{ $message }}
                    </div>
                @endforeach
            </div>

            <div class="mb-3">
                <label for="update_password_password" class="form-label fw-medium text-secondary small">
                    {{ __('Новий пароль') }}
                </label>
                <input 
                    type="password" 
                    id="update_password_password" 
                    name="password" 
                    class="form-control {{ $errors->updatePassword->get('password') ? 'is-invalid' : '' }}" 
                    autocomplete="new-password"
                >
                @foreach ($errors->updatePassword->get('password') as $message)
                    <div class="invalid-feedback d-block">
                        {{ $message }}
                    </div>
                @endforeach
            </div>

            <div class="mb-4">
                <label for="update_password_password_confirmation" class="form-label fw-medium text-secondary small">
                    {{ __('Підтвердження пароля') }}
                </label>
                <input 
                    type="password" 
                    id="update_password_password_confirmation" 
                    name="password_confirmation" 
                    class="form-control {{ $errors->updatePassword->get('password_confirmation') ? 'is-invalid' : '' }}" 
                    autocomplete="new-password"
                >
                @foreach ($errors->updatePassword->get('password_confirmation') as $message)
                    <div class="invalid-feedback d-block">
                        {{ $message }}
                    </div>
                @endforeach
            </div>

            <div class="d-flex align-items-center gap-3">
                <button type="submit" class="btn btn-dark px-4 fw-medium">
                    {{ __('Зберегти') }}
                </button>

                @if (session('status') === 'password-updated')
                    <p
                        x-data="{ show: true }"
                        x-show="show"
                        x-transition
                        x-init="setTimeout(() => show = false, 2000)"
                        class="text-success small mb-0 fw-bold"
                    >
                        <i class="bi bi-check-circle me-1"></i> {{ __('Збережено.') }}
                    </p>
                @endif
            </div>
        </form>
    </div>
</section>