<x-app-layout>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">📋 Шаблони повідомлень</h1>
        <a href="{{ route('templates.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Додати шаблон
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">
        @forelse($templates as $template)
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm {{ $template->is_active ? '' : 'border-secondary bg-light text-muted' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title text-truncate" title="{{ $template->title }}">
                                {{ $template->title }}
                            </h5>
                            @if(!$template->is_active)
                                <span class="badge bg-secondary">Архів</span>
                            @endif
                        </div>

                        <p class="card-text small bg-light p-2 rounded text-break" style="font-family: monospace;">
                            {{ \Illuminate\Support\Str::limit($template->content, 150) }}
                        </p>

                        <textarea id="tpl-content-{{ $template->id }}" class="d-none">{{ $template->content }}</textarea>
                    </div>

                    <div class="card-footer bg-transparent border-top-0 pt-0 pb-3">
                        <div class="d-grid gap-2">
                            <button onclick="copyTemplate({{ $template->id }})" class="btn btn-outline-primary fw-bold">
                                <i class="bi bi-clipboard"></i> Копіювати
                            </button>

                            <div class="d-flex gap-2">
                                <a href="{{ route('templates.edit', $template) }}" class="btn btn-sm btn-light flex-grow-1 border">
                                    <i class="bi bi-pencil"></i> Редагувати
                                </a>

                                <form action="{{ route('templates.destroy', $template) }}" method="POST" onsubmit="return confirm('Видалити шаблон?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-light border text-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-5">
                <p class="text-muted fs-5">Шаблонів поки немає.</p>
                <a href="{{ route('templates.create') }}" class="btn btn-outline-primary">Створити перший</a>
            </div>
        @endforelse
    </div>
</div>

<script>
    async function copyTemplate(id) {
        let text = document.getElementById('tpl-content-' + id).value;

        const regex = /\{\{(.*?)\}\}/g;
        const matches = [...text.matchAll(regex)];

        if (matches.length > 0) {
            let uniqueVars = new Set(matches.map(m => m[0]));

            for (let variable of uniqueVars) {
                let varName = variable.replace(/[\{\}]/g, '').trim();
                let userValue = prompt(`Введіть значення для "${varName}":`);

                if (userValue === null) return;

                text = text.split(variable).join(userValue);
            }
        }

        try {
            await navigator.clipboard.writeText(text);
            alert('✅ Скопійовано в буфер обміну!');
        } catch (err) {
            console.error('Помилка:', err);
            alert('❌ Помилка копіювання. Дозвольте доступ до буферу обміну.');
        }
    }
</script>
</x-app-layout>
