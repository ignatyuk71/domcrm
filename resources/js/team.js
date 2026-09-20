import '../css/team.css';

export function initTeamPage(root, bootstrap) {
    const form = root.querySelector('[data-team-form]');
    if (!form) return;
    const members = new Map(JSON.parse(root.dataset.members).map(member => [String(member.id), member]));
    const editorElement = root.querySelector('#team-editor');
    const editor = bootstrap.Offcanvas.getOrCreateInstance(editorElement);
    const deleteElement = root.querySelector('#team-delete-dialog');
    const deleteDialog = bootstrap.Modal.getOrCreateInstance(deleteElement);
    const rows = [...root.querySelectorAll('[data-team-row]')];
    const search = root.querySelector('[data-team-search]');
    const roleFilter = root.querySelector('[data-team-role]');
    const statusButtons = [...root.querySelectorAll('[data-team-status]')];
    const fields = ['name', 'email', 'username', 'role', 'is_active', 'password', 'password_confirmation'];
    const drafts = new Map();
    const baselines = new Map();
    const validationState = new Map();
    let currentKey = null, status = 'all', submitting = false;
    const values = () => Object.fromEntries(fields.map(name => [name, form.elements.namedItem(name).value]));
    const baseline = (member) => ({ name: member?.name ?? '', email: member?.email ?? '', username: member?.username ?? '', role: member?.role ?? 'operator', is_active: member?.is_active === false ? '0' : '1', password: '', password_confirmation: '' });
    const rememberDraft = () => { if (currentKey) drafts.set(currentKey, values()); };
    const dirty = (key, value) => JSON.stringify(value) !== JSON.stringify(baselines.get(key));
    const updateDraftNote = () => { root.querySelector('[data-team-draft-note]').hidden = !dirty(currentKey, values()); };

    function filterRows() {
        const term = search.value.trim().toLocaleLowerCase('uk');
        let count = 0;
        rows.forEach(row => {
            const matches = (!term || row.dataset.search.toLocaleLowerCase('uk').includes(term))
                && (!roleFilter.value || row.dataset.role === roleFilter.value)
                && (status === 'all' || row.dataset.status === status);
            row.hidden = !matches;
            if (matches) count++;
        });
        root.querySelector('[data-team-empty]').hidden = count > 0;
        root.querySelector('[data-team-count]').textContent = `Показано ${count} із ${rows.length}`;
        statusButtons.forEach(button => {
            const selected = button.dataset.teamStatus === status;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
    }
    search.addEventListener('input', filterRows);
    roleFilter.addEventListener('change', filterRows);
    statusButtons.forEach(button => button.addEventListener('click', () => { status = button.dataset.teamStatus; filterRows(); }));
    root.querySelector('[data-team-reset]').addEventListener('click', () => { search.value = ''; roleFilter.value = ''; status = 'all'; filterRows(); });

    function openEditor(id = null, restore = false) {
        rememberDraft();
        const member = id === null ? null : members.get(String(id));
        if (id !== null && !member) return;
        currentKey = member ? `user${member.id}` : 'createUser';
        if (!baselines.has(currentKey)) baselines.set(currentKey, baseline(member));
        if (restore) {
            validationState.set(currentKey, {
                fields: [...form.querySelectorAll('.is-invalid')].map(field => field.name),
                message: form.querySelector('[data-team-field-error]').textContent,
            });
        } else {
            const draft = drafts.get(currentKey) ?? baselines.get(currentKey);
            fields.forEach(name => { form.elements.namedItem(name).value = draft[name]; });
            form.querySelectorAll('.is-invalid').forEach(field => { field.classList.remove('is-invalid'); field.setAttribute('aria-invalid', 'false'); });
            form.querySelectorAll('[data-team-field-error]').forEach(element => { element.textContent = ''; });
            const errors = validationState.get(currentKey);
            errors?.fields.forEach(name => { form.elements.namedItem(name).classList.add('is-invalid'); form.elements.namedItem(name).setAttribute('aria-invalid', 'true'); });
            if (errors) form.querySelector('[data-team-field-error]').textContent = errors.message;
        }
        form.action = member?.update_url ?? root.dataset.createUrl;
        form.elements.namedItem('_method').value = member ? 'PATCH' : 'POST';
        form.elements.namedItem('_team_form').value = currentKey;
        ['name', 'email'].forEach(name => { form.elements.namedItem(name).readOnly = !!member; form.elements.namedItem(name).required = !member; });
        ['password', 'password_confirmation'].forEach(name => { form.elements.namedItem(name).required = !member; });
        root.querySelector('[data-team-status-field]').hidden = !member;
        root.querySelector('#team-editor-title').textContent = member ? 'Редагувати користувача' : 'Новий користувач';
        root.querySelector('[data-team-editor-intro]').textContent = member ? 'Налаштуйте логін і доступи цього користувача.' : 'Створіть обліковий запис для нового учасника команди.';
        root.querySelector('[data-team-password-title]').textContent = member ? 'Змінити пароль' : 'Пароль для входу';
        root.querySelector('[data-team-password-help]').textContent = member ? 'Залиште поля порожніми, щоб зберегти поточний пароль.' : 'Передайте пароль користувачу особисто.';
        root.querySelector('[data-team-save]').textContent = member ? 'Зберегти зміни' : 'Створити користувача';
        updateDraftNote();
        editor.show();
    }
    root.querySelector('[data-team-create]').addEventListener('click', () => openEditor());
    document.addEventListener('crm:focus-validation-error', event => {
        const key = root.dataset.restore;
        if (!key) return;
        event.preventDefault();
        openEditor(key === 'createUser' ? null : key.slice(4));
        if (editorElement.classList.contains('show')) form.querySelector('.is-invalid')?.focus();
    });
    root.querySelectorAll('[data-team-edit]').forEach(button => button.addEventListener('click', () => openEditor(button.dataset.teamEdit)));
    editorElement.addEventListener('shown.bs.offcanvas', () => {
        (form.querySelector('.is-invalid') ?? form.elements.namedItem(currentKey === 'createUser' ? 'name' : 'username')).focus();
    });
    editorElement.addEventListener('hide.bs.offcanvas', rememberDraft);
    editorElement.addEventListener('hidden.bs.offcanvas', () => {
        const trigger = currentKey === 'createUser' ? root.querySelector('[data-team-create]') : root.querySelector(`[data-team-edit="${currentKey.slice(4)}"]`);
        trigger?.focus();
    });
    form.addEventListener('input', updateDraftNote);
    form.addEventListener('change', updateDraftNote);
    form.addEventListener('submit', () => { submitting = true; root.querySelector('[data-team-save]').disabled = true; });

    // Чернетки живуть лише в пам’яті вкладки, включно з незбереженим паролем.
    window.addEventListener('beforeunload', event => {
        rememberDraft();
        if (!submitting && [...drafts].some(([key, value]) => dirty(key, value))) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', () => { submitting = false; root.querySelector('[data-team-save]').disabled = false; });
    root.querySelectorAll('[data-team-delete]').forEach(button => button.addEventListener('click', () => {
        const member = members.get(button.dataset.teamDelete);
        if (!member?.delete_url) return;
        root.querySelector('[data-team-delete-name]').textContent = member.name;
        root.querySelector('[data-team-delete-form]').action = member.delete_url;
        deleteDialog.show();
        deleteElement.addEventListener('hidden.bs.modal', () => button.focus(), { once: true });
    }));
    if (root.dataset.restore) {
        openEditor(root.dataset.restore === 'createUser' ? null : root.dataset.restore.slice(4), true);
    }
}
