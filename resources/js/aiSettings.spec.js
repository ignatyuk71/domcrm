import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { initAiSettings } from './aiSettings';

const markup = readFileSync(resolve('resources/views/settings/ai.blade.php'), 'utf8');
const settings = () => ({ global: { provider: 'claude', model: 'claude-custom-existing', openai_model: 'gpt-6.1-sol', has_key: true, key_hint: '•••1234', openai_has_key: false, debounce_seconds: 0, operator_pause_minutes: 0, follow_up_hours: 0 }, stores: [{ meta_connection_id: 7, page_name: 'Магазин', enabled: true, provider: null, system_prompt: 'Чернетка інструкції', schedule: { mode: 'always' } }] });
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

it('loads settings without switching provider or announcing a save', () => {
    expect(el('ai-provider').value).toBe('claude');
    expect(el('ai-model').value).toBe('claude-custom-existing');
    expect(el('openai-key').value).toBe('');
    expect(el('ai-save').disabled).toBe(false);
    expect(notify).not.toHaveBeenCalled();
});

it('switches the global API while preserving separate credentials and explicit zero values', async () => {
    el('openai-key').value = 'sk-openai-test';
    el('ai-provider').value = 'openai';
    fetch.mockImplementationOnce(() => response({ ok: true }));
    await controller.save();
    const [url, request] = fetch.mock.calls.at(-1);
    const body = JSON.parse(request.body);
    expect(url).toBe('/save');
    expect(body).toMatchObject({ provider: 'openai', api_key: null, openai_api_key: 'sk-openai-test', debounce_seconds: 0, operator_pause_minutes: 0, follow_up_hours: 0 });
    expect(body.stores[0]).not.toHaveProperty('provider');
    expect(el('openai-key').value).toBe('');
    expect(el('ai-current-provider').textContent).toBe('ChatGPT API (OpenAI)');
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ context: 'save', type: 'success' }));
});

it('preserves drafts and highlights validation errors when saving fails', async () => {
    el('openai-key').value = 'sk-draft';
    el('ai-provider').value = 'openai';
    fetch.mockImplementationOnce(() => response({ message: 'Помилка', errors: { openai_api_key: ['Ключ недійсний'] } }, false));
    await controller.save();
    expect(el('openai-key').value).toBe('sk-draft');
    expect(el('openai-key').getAttribute('aria-invalid')).toBe('true');
    expect(el('ai-current-provider').textContent).toBe('Claude API');
    expect(el('st-prompt-0').value).toBe('Чернетка інструкції');
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error', messages: ['Ключ недійсний'], onAction: expect.any(Function) }));
});

it('has one global API selector and no sandbox or per-store provider controls', () => {
    expect(root.querySelectorAll('#ai-provider')).toHaveLength(1);
    expect(root.querySelector('#st-provider-0')).toBeNull();
    expect(root.querySelector('#ai-preview')).toBeNull();
});
