<section class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-white border-bottom border-light p-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-primary bg-opacity-10 p-2 text-primary">
                <i class="bi bi-person-badge-fill fs-4"></i>
            </div>
            <div>
                <h5 class="fw-bold text-dark mb-1">{{ __('Інформація профілю') }}</h5>
                <p class="text-muted small mb-0">
                    {{ __('Оновіть дані свого облікового запису та електронну пошту.') }}
                </p>
            </div>
        </div>
    </div>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <div class="card-body p-4">
        <form method="post" action="{{ route('profile.update') }}">
            @csrf
            @method('patch')

            <div class="mb-3">
                <label for="name" class="form-label fw-medium text-secondary small">
                    {{ __('Ім\'я') }}
                </label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    class="form-control {{ $errors->get('name') ? 'is-invalid' : '' }}" 
                    value="{{ old('name', $user->name) }}" 
                    required 
                    autofocus 
                    autocomplete="name"
                >
                @foreach ($errors->get('name') as $message)
                    <div class="invalid-feedback d-block">
                        {{ $message }}
                    </div>
                @endforeach
            </div>

            <div class="mb-3">
                <label for="email" class="form-label fw-medium text-secondary small">
                    {{ __('Email') }}
                </label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    class="form-control {{ $errors->get('email') ? 'is-invalid' : '' }}" 
                    value="{{ old('email', $user->email) }}" 
                    required 
                    autocomplete="username"
                >
                @foreach ($errors->get('email') as $message)
                    <div class="invalid-feedback d-block">
                        {{ $message }}
                    </div>
                @endforeach

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="alert alert-warning mt-3 mb-0 d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <div>
                            <span class="small">{{ __('Ваша електронна адреса не підтверджена.') }}</span>
                            <button form="send-verification" class="btn btn-link p-0 align-baseline text-decoration-none fw-bold small">
                                {{ __('Натисніть тут, щоб надіслати лист повторно.') }}
                            </button>
                        </div>
                    </div>

                    @if (session('status') === 'verification-link-sent')
                        <div class="alert alert-success mt-2 small" role="alert">
                            <i class="bi bi-check-circle me-1"></i>
                            {{ __('Нове посилання для підтвердження надіслано на вашу електронну адресу.') }}
                        </div>
                    @endif
                @endif
            </div>

            <div class="d-flex align-items-center gap-3 mt-4">
                <button type="submit" class="btn btn-dark px-4 fw-medium">
                    {{ __('Зберегти') }}
                </button>

                @if (session('status') === 'profile-updated')
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