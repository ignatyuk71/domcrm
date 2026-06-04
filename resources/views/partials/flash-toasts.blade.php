@php
    $toastMessages = [];

    if (session('success')) {
        $toastMessages[] = ['type' => 'success', 'title' => 'Готово', 'message' => session('success')];
    }

    if (session('error')) {
        $toastMessages[] = ['type' => 'danger', 'title' => 'Помилка', 'message' => session('error')];
    }

    if ($errors->any()) {
        foreach ($errors->all() as $error) {
            $toastMessages[] = ['type' => 'danger', 'title' => 'Помилка', 'message' => $error];
        }
    }
@endphp

@if(count($toastMessages) > 0)
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;">
        @foreach($toastMessages as $toast)
            <div class="toast app-flash-toast border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true"
                 data-bs-delay="{{ $toast['type'] === 'danger' ? 7000 : 4500 }}">
                <div class="toast-header border-0 text-white {{ $toast['type'] === 'danger' ? 'bg-danger' : 'bg-success' }}">
                    <i class="bi {{ $toast['type'] === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill' }} me-2"></i>
                    <strong class="me-auto">{{ $toast['title'] }}</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">{{ $toast['message'] }}</div>
            </div>
        @endforeach
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof bootstrap === 'undefined') return;
            document.querySelectorAll('.app-flash-toast').forEach(function (el) {
                new bootstrap.Toast(el).show();
            });
        });
    </script>
@endif
