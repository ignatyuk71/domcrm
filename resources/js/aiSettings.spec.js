import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { initAiSettings } from './aiSettings';

const markup = readFileSync(resolve('resources/views/settings/ai.blade.php'), 'utf8');
const settings = () => ({ global: { model: 'claude-custom-existing', has_key: true, key_hint: '•••1234', debounce_seconds: 0, operator_pause_minutes: 0, follow_up_hours: 0 }, stores: [{ meta_connection_id: 7, page_name: 'Магазин', enabled: true, system_prompt: 'Чернетка інструкції', schedule: { mode: 'always' } }] });
const response = (data, ok = true) => Promise.resolve({ ok, status: ok ? 200 : 422, json: async () => data });
let root, notify, controller;
const el = id => root.querySelector('#' + id);

beforeEach(async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="test">';
    document.body.innerHTML = markup;
    root = document.querySelector('[data-ai-settings]');
    Object.assign(root.dataset, { urlData: '/data', urlSave: '/save', urlTest: '/test' });
    notify = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => response(settings())));
    controller = initAiSettings(root, notify);
    await controller.ready;
});

afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; });

it('loads the saved Claude model without announcing a save', () => {
    expect(el('ai-model').value).toBe('claude-custom-existing');
    expect(el('ai-key').value).toBe('');
    expect(el('ai-save').disabled).toBe(false);
    expect(notify).not.toHaveBeenCalled();
});

it('saves Claude settings, preserves zero values and clears only a saved credential', async () => {
    el('ai-key').value = 'sk-ant-new-key';
    fetch.mockImplementationOnce(() => response({ ok: true }));
    await controller.save();
    const [url, request] = fetch.mock.calls.at(-1);
    const body = JSON.parse(request.body);
    expect(url).toBe('/save');
    expect(body).toMatchObject({ api_key: 'sk-ant-new-key', model: 'claude-custom-existing', debounce_seconds: 0, operator_pause_minutes: 0, follow_up_hours: 0 });
    expect(body).not.toHaveProperty('provider');
    expect(body).not.toHaveProperty('openai_api_key');
    expect(body.stores[0]).toMatchObject({ enabled: true, system_prompt: 'Чернетка інструкції' });
    expect(el('ai-key').value).toBe('');
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ context: 'save', type: 'success' }));
});

it('preserves drafts and highlights validation errors when saving fails', async () => {
    el('ai-key').value = 'sk-ant-draft';
    fetch.mockImplementationOnce(() => response({ message: 'Помилка', errors: { api_key: ['Ключ недійсний'] } }, false));
    await controller.save();
    expect(el('ai-key').value).toBe('sk-ant-draft');
    expect(el('ai-key').getAttribute('aria-invalid')).toBe('true');
    expect(el('st-prompt-0').value).toBe('Чернетка інструкції');
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error', messages: ['Ключ недійсний'], onAction: expect.any(Function) }));
});

it('checks the Claude key without a provider selector', () => {
    el('ai-key').value = 'sk-ant-draft';
    fetch.mockImplementationOnce(() => response({ ok: true }));
    el('ai-test').click();
    expect(fetch.mock.calls.at(-1)[0]).toBe('/test');
    expect(JSON.parse(fetch.mock.calls.at(-1)[1].body)).toEqual({ api_key: 'sk-ant-draft' });
});

it('renders only the Claude connection', () => {
    expect(root.querySelector('#ai-provider')).toBeNull();
    expect(root.querySelector('#openai-key')).toBeNull();
    expect(root.querySelector('#openai-model')).toBeNull();
    expect(root.textContent).not.toContain('ChatGPT');
    expect(root.textContent).toContain('Підключення Claude API');
});
