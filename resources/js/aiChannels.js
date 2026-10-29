export function initAiChannels(root, notify) {
    const form = root.querySelector('form');
    const fieldset = form.querySelector('fieldset');
    const field = name => form.elements.namedItem(name);
    let busy = false;
    let dirty = false;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    async function request(path = '', body) {
        const response = await fetch('/settings/ai/channels' + path, {
            method: body === undefined ? 'GET' : 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
        });
        const data = await response.json();
        if (!response.ok) { const error = new Error(data.message || 'Не вдалося зберегти канали.'); error.fields = data.errors; throw error; }
        return data;
    }
    function show(data) {
        for (const name of ['claude_enabled', 'gpt_enabled']) field(name).checked = data[name];
        field('default_provider').value = data.default_provider;
        field('gpt_model').value = data.gpt_model || '';
        field('gpt_api_key').value = '';
        field('gpt_api_key').placeholder = data.gpt_key_hint || 'sk-…';
        dirty = false;
    }
    function failure(error, context, retry) {
        for (const name of Object.keys(error.fields || {})) field(name)?.classList.add('is-invalid');
        notify({ type: 'error', context, title: 'Канали AI', messages: error.fields ? Object.values(error.fields).flat() : [error.message], actionLabel: 'Повторити', onAction: retry });
    }
    async function load() {
        try { show(await request()); fieldset.disabled = false; }
        catch (error) { failure(error, 'channels-load', load); }
    }
    async function save(event) {
        event?.preventDefault();
        if (busy) return;
        busy = true; fieldset.disabled = true;
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        const data = Object.fromEntries(['default_provider', 'gpt_api_key', 'gpt_model'].map(name => [name, field(name).value.trim() || null]));
        for (const name of ['claude_enabled', 'gpt_enabled']) data[name] = field(name).checked;
        try {
            show(await request('', data));
            notify({ type: 'success', context: 'channels-save', title: 'Канали AI', messages: ['Збережено.'] });
        } catch (error) { failure(error, 'channels-save', save); }
        finally { busy = false; fieldset.disabled = false; }
    }
    async function models() {
        if (busy) return;
        busy = true;
        try {
            const data = await request('/models', {});
            const list = root.querySelector('datalist'); list.replaceChildren();
            for (const model of data.models) { const option = document.createElement('option'); option.value = model; list.append(option); }
            notify({ type: 'success', context: 'channels-models', title: 'GPT', messages: ['Підключення працює. Моделі завантажено.'] });
        } catch (error) { failure(error, 'channels-models', models); }
        finally { busy = false; }
    }
    form.addEventListener('submit', save);
    form.addEventListener('input', () => { dirty = true; });
    root.querySelector('[data-load-models]').addEventListener('click', models);
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    return load();
}
