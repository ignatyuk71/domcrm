<div class="container-fluid px-4 pb-4" data-ai-channels>
    <div data-page-toast></div>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <h2 class="h6 fw-bold">Канали AI</h2>
            <p class="small text-secondary">Вимкнений API не отримує нових запитів. Типовий агент працює в чатах без власного вибору та в коментарях.</p>
            <form data-channels-form>
                <fieldset disabled>
                    <div class="d-flex flex-wrap gap-4 mb-3">
                        <label class="form-check form-switch"><input class="form-check-input" name="claude_enabled" type="checkbox"> Claude API</label>
                        <label class="form-check form-switch"><input class="form-check-input" name="gpt_enabled" type="checkbox"> GPT API</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="default-provider">Типовий агент</label><select class="form-select" id="default-provider" name="default_provider"><option value="claude">Claude</option><option value="gpt">GPT</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="gpt-live-key">Ключ GPT API</label><input class="form-control" type="password" autocomplete="off" id="gpt-live-key" name="gpt_api_key" placeholder="sk-…"></div>
                        <div class="col-md-4"><label class="form-label" for="gpt-live-model">Модель GPT</label><input class="form-control" id="gpt-live-model" name="gpt_model" list="gpt-live-models" placeholder="ID моделі з вашого API"><datalist id="gpt-live-models"></datalist></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-primary" type="submit">Зберегти канали</button><button class="btn btn-outline-secondary" type="button" data-load-models>Перевірити GPT і завантажити моделі</button><a class="btn btn-outline-secondary" href="{{ route('gptSandbox.index') }}">Тестовий чат GPT</a></div>
                </fieldset>
            </form>
            <p class="small text-secondary mt-3 mb-0">Каталог, лінійки, фото, ціни й залишки GPT бере з цієї CRM. Ключ тестового стенда зберігається окремо.</p>
        </div>
    </div>
</div>
