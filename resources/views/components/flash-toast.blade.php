@php
    $messages = collect($errors->getBags())->flatMap(fn ($bag) => $bag->all())->unique()->values()->all();
    $notification = null;
    if (count($messages)) {
        $notification = ['type' => 'error', 'title' => 'Перевірте форму', 'messages' => $messages];
    } elseif (session('success') || session('status')) {
        $notification = ['type' => 'success', 'title' => 'Готово', 'messages' => [session('success') ?: session('status')]];
    }
@endphp

@if($notification)
    <div data-flash-toast data-notification="{{ json_encode($notification, JSON_UNESCAPED_UNICODE) }}"></div>
@endif
