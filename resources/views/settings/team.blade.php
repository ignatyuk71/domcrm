<x-app-layout>
    @php
        $available = empty($migrationPending);
        $members = $available ? $users : collect();
        $activeCount = $members->where('is_active', true)->count();
        $blockedCount = $members->count() - $activeCount;
        $editingUser = $members->first(fn ($member) => old('_team_form') === 'user'.$member->id);
        $formKey = $editingUser ? 'user'.$editingUser->id : 'createUser';
        $formErrors = $errors->getBag($formKey);
        $restoreForm = $formErrors->any();
        $fieldValue = fn ($key, $default = '') => $restoreForm && array_key_exists($key, session('_old_input', []))
            ? (is_scalar(old($key)) ? old($key) : '') : $default;
        $memberData = $members->map(fn ($member) => [
            'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
            'username' => $member->username, 'role' => $member->roleKey(), 'is_active' => $member->is_active,
            'update_url' => route('settings.team.update', $member),
            'delete_url' => auth()->id() === $member->id ? null : route('settings.team.destroy', $member),
        ])->values();
        $roleIcons = ['owner' => 'bi-shield-check', 'operator' => 'bi-headset', 'packer' => 'bi-box-seam'];
    @endphp

    <div class="team-page" data-team-page data-members="{{ $memberData->toJson() }}" data-restore="{{ $restoreForm ? $formKey : '' }}" data-create-url="{{ route('settings.team.store') }}">
        <div class="team-breadcrumb"><span>Налаштування</span><i class="bi bi-chevron-right" aria-hidden="true"></i><span>Команда та доступи</span></div>
        <header class="team-heading">
            <div><h1>Команда<span class="team-heading-dot">.</span></h1><p>Люди, які допомагають вашому бізнесу працювати.</p></div>
            @if($available)
                <button type="button" class="btn team-primary" data-team-create><i class="bi bi-plus-lg" aria-hidden="true"></i>Додати користувача</button>
            @endif
        </header>

        <div class="team-stats" aria-label="Статистика команди">
            <div class="team-stat"><span class="team-stat-icon"><i class="bi bi-people" aria-hidden="true"></i></span><div><span class="team-stat-label">Усього в команді</span><strong>{{ $members->count() }}</strong></div></div>
            <div class="team-stat"><span class="team-stat-icon is-green"><i class="bi bi-person-check" aria-hidden="true"></i></span><div><span class="team-stat-label">Мають доступ</span><strong>{{ $activeCount }}</strong></div><span class="team-stat-note"><span class="team-dot"></span>Активні</span></div>
            <div class="team-stat"><span class="team-stat-icon is-neutral"><i class="bi bi-person-lock" aria-hidden="true"></i></span><div><span class="team-stat-label">Доступ вимкнено</span><strong>{{ $blockedCount }}</strong></div></div>
        </div>

        @if(!$available)
            <section class="team-empty"><i class="bi bi-tools" aria-hidden="true"></i><h2>Керування командою тимчасово недоступне</h2><p>Зверніться до адміністратора, щоб завершити оновлення.</p></section>
        @else
            <section class="team-members" aria-labelledby="team-members-title">
                <div class="team-section-heading"><h2 id="team-members-title">Учасники команди <span>{{ $members->count() }}</span></h2><span class="team-private-note"><i class="bi bi-shield-lock" aria-hidden="true"></i>Приватний доступ</span></div>
                <div class="team-toolbar">
                    <div class="team-tabs" role="group" aria-label="Фільтр за статусом">
                        <button type="button" class="is-selected" data-team-status="all" aria-pressed="true">Усі <span>{{ $members->count() }}</span></button>
                        <button type="button" data-team-status="active" aria-pressed="false">Активні <span>{{ $activeCount }}</span></button>
                        <button type="button" data-team-status="blocked" aria-pressed="false">Заблоковані <span>{{ $blockedCount }}</span></button>
                    </div>
                    <div class="team-search-controls">
                        <label class="team-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-team-search placeholder="Ім’я, email або логін" aria-label="Пошук користувачів"></label>
                        <select class="form-select team-role-filter" data-team-role aria-label="Фільтр за роллю"><option value="">Усі ролі</option>@foreach($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    </div>
                </div>
                <div class="team-table-wrap">
                    <table class="team-table">
                        <thead><tr><th scope="col">Користувач</th><th scope="col">Логін</th><th scope="col">Роль</th><th scope="col">Статус</th><th scope="col"><span class="visually-hidden">Дії</span></th></tr></thead>
                        <tbody>
                            @foreach($members as $member)
                                @php
                                    $initials = collect(preg_split('/\s+/', trim($member->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
                                    $role = $member->roleKey();
                                    $isSelf = auth()->id() === $member->id;
                                @endphp
                                <tr data-team-row data-id="{{ $member->id }}" data-role="{{ $role }}" data-status="{{ $member->is_active ? 'active' : 'blocked' }}" data-search="{{ $member->name.' '.$member->email.' '.$member->username }}">
                                    <td class="team-identity"><span class="team-avatar team-role-{{ $role }}">{{ $initials ?: 'U' }}</span><div><div class="team-person-name">{{ $member->name }}@if($isSelf)<span class="team-self">Ви</span>@endif</div><span class="team-email">{{ $member->email }}</span></div></td>
                                    <td data-label="Логін">@if($member->username)<span class="team-login">{{ $member->username }}</span>@else<span class="team-muted">Не задано</span>@endif</td>
                                    <td data-label="Роль"><span class="team-role team-role-{{ $role }}"><i class="bi {{ $roleIcons[$role] ?? 'bi-person' }}" aria-hidden="true"></i>{{ $roleOptions[$role] ?? $role }}</span></td>
                                    <td><span class="team-status {{ $member->is_active ? '' : 'is-blocked' }}"><span class="team-dot"></span>{{ $member->is_active ? 'Активний' : 'Заблокований' }}</span></td>
                                    <td class="team-actions"><button type="button" class="btn team-edit" data-team-edit="{{ $member->id }}" aria-label="Редагувати: {{ $member->name }}"><i class="bi bi-pencil" aria-hidden="true"></i><span>Редагувати</span></button>@unless($isSelf)<button type="button" class="btn team-delete" data-team-delete="{{ $member->id }}" aria-label="Видалити: {{ $member->name }}"><i class="bi bi-trash3" aria-hidden="true"></i></button>@endunless</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="team-empty" data-team-empty @if($members->isNotEmpty()) hidden @endif><i class="bi bi-search" aria-hidden="true"></i><h3>Користувачів не знайдено</h3><p>Змініть пошук або фільтри, щоб побачити учасників.</p><button type="button" class="btn team-edit" data-team-reset>Скинути фільтри</button></div>
                <footer class="team-list-footer"><span data-team-count aria-live="polite">Показано {{ $members->count() }} із {{ $members->count() }}</span><span><i class="bi bi-lock" aria-hidden="true"></i>Доступи змінює лише власник</span></footer>
            </section>

            <section class="team-roles-section" aria-labelledby="team-roles-title">
                <h2 id="team-roles-title">Кожному — своя роль</h2>
                <div class="team-role-guides">
                    <div><span class="team-guide-icon team-role-owner"><i class="bi bi-shield-check" aria-hidden="true"></i></span><h3>Власник</h3><p>Повний доступ до CRM, команди, фінансів та налаштувань.</p></div>
                    <div><span class="team-guide-icon team-role-operator"><i class="bi bi-headset" aria-hidden="true"></i></span><h3>Оператор</h3><p>Робота із замовленнями, клієнтами та повідомленнями.</p></div>
                    <div><span class="team-guide-icon team-role-packer"><i class="bi bi-box-seam" aria-hidden="true"></i></span><h3>Пакувальник</h3><p>Доступ до пакування та підготовки замовлень до відправлення.</p></div>
                </div>
            </section>

            @include('settings.team.editor')
            <div class="modal fade team-confirm" id="team-delete-dialog" tabindex="-1" aria-labelledby="team-delete-title" aria-describedby="team-delete-description">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title fs-5" id="team-delete-title">Видалити користувача?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button></div>
                    <div class="modal-body"><p id="team-delete-description">Обліковий запис <strong data-team-delete-name></strong> буде видалено. Відновити його неможливо.</p><p class="team-muted mb-0">Щоб тимчасово обмежити вхід, змініть статус на «Заблокований» у редагуванні.</p></div>
                    <form method="POST" data-team-delete-form class="modal-footer">@csrf @method('DELETE')<button type="button" class="btn team-edit" data-bs-dismiss="modal">Скасувати</button><button type="submit" class="btn btn-danger">Видалити користувача</button></form>
                </div></div>
            </div>
        @endif
    </div>
    <x-flash-toast />
</x-app-layout>
