<section class="card border-danger border-opacity-25 shadow-sm rounded-4 overflow-hidden">
    <div class="card-body p-4">
        <div class="d-flex align-items-start gap-3">
            <div class="rounded-circle bg-danger bg-opacity-10 p-2 text-danger flex-shrink-0">
                <i class="bi bi-exclamation-triangle-fill fs-4"></i>
            </div>
            
            <div class="w-100">
                <header>
                    <h5 class="fw-bold text-dark mb-1">{{ __('Видалити акаунт') }}</h5>
                    <p class="text-muted small mb-3">
                        {{ __('Після видалення вашого облікового запису всі його ресурси та дані будуть безповоротно втрачені. Будь ласка, завантажте будь-які дані, які ви хочете зберегти, перед видаленням.') }}
                    </p>
                </header>

                <button type="button" class="btn btn-danger px-4 fw-medium" data-bs-toggle="modal" data-bs-target="#confirmDeletionModal">
                    {{ __('Видалити акаунт') }}
                </button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmDeletionModal" tabindex="-1" aria-labelledby="confirmDeletionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <form method="post" action="{{ route('profile.destroy') }}" class="p-2">
                    @csrf
                    @method('delete')

                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark" id="confirmDeletionModalLabel">
                            {{ __('Ви впевнені?') }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body pt-2">
                        <p class="text-muted small mb-4">
                            {{ __('Після видалення облікового запису всі дані зникнуть назавжди. Введіть пароль для підтвердження цієї дії.') }}
                        </p>

                        <div class="mb-3">
                            <label for="password" class="form-label visually-hidden">{{ __('Password') }}</label>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control {{ $errors->userDeletion->get('password') ? 'is-invalid' : '' }}" 
                                placeholder="{{ __('Ваш пароль') }}"
                            >
                            @foreach ($errors->userDeletion->get('password') as $message)
                                <div class="invalid-feedback text-start">
                                    {{ $message }}
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light border fw-medium" data-bs-dismiss="modal">
                            {{ __('Скасувати') }}
                        </button>
                        <button type="submit" class="btn btn-danger fw-bold">
                            {{ __('Видалити акаунт') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

@if($errors->userDeletion->isNotEmpty())
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var myModal = new bootstrap.Modal(document.getElementById('confirmDeletionModal'));
            myModal.show();
        });
    </script>
@endif