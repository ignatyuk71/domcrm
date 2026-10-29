// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { initAiChannels } from './aiChannels';
const flush = () => new Promise(resolve => setTimeout(resolve, 0));
const settings = { claude_enabled: true, gpt_enabled: false, default_provider: 'claude', gpt_model: 'gpt-test', gpt_key_hint: '••••1234' };
function response(data, ok = true) { return Promise.resolve({ ok, json: async () => data }); }
beforeEach(() => {
    document.body.innerHTML = '<meta name="csrf-token" content="test"><div id="root"><form><fieldset disabled><input type="checkbox" name="claude_enabled"><input type="checkbox" name="gpt_enabled"><select name="default_provider"><option value="claude">Claude</option><option value="gpt">GPT</option></select><input name="gpt_api_key"><input name="gpt_model"><button type="submit">Зберегти</button></fieldset></form><button data-load-models></button><datalist></datalist></div>';
    vi.stubGlobal('fetch', vi.fn());
});
describe('Канали AI', () => {
    it('зберігає явні рубильники й порожній ключ без повідомлення успіху при завантаженні', async () => {
        fetch.mockImplementationOnce(() => response(settings)).mockImplementationOnce(() => response({ ...settings, claude_enabled: false, gpt_enabled: true, default_provider: 'gpt' }));
        const root = document.querySelector('#root'); const notify = vi.fn();
        await initAiChannels(root, notify);
        expect(notify).not.toHaveBeenCalled();
        const form = root.querySelector('form');
        form.elements.claude_enabled.checked = false; form.elements.gpt_enabled.checked = true; form.elements.default_provider.value = 'gpt';
        form.dispatchEvent(new Event('submit', { cancelable: true })); await flush();
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ claude_enabled: false, gpt_enabled: true, default_provider: 'gpt', gpt_api_key: null, gpt_model: 'gpt-test' });
        expect(notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'success' }));
    });
    it('зберігає чернетку та підсвічує поле при відмові сервера', async () => {
        fetch.mockImplementationOnce(() => response(settings)).mockImplementationOnce(() => response({ errors: { gpt_model: ['Невідома модель.'] } }, false));
        const root = document.querySelector('#root'); const notify = vi.fn(); await initAiChannels(root, notify);
        const form = root.querySelector('form'); form.elements.gpt_model.value = 'draft-model'; form.elements.gpt_api_key.value = 'sk-draft';
        form.dispatchEvent(new Event('submit', { cancelable: true })); await flush();
        expect(form.elements.gpt_api_key.value).toBe('sk-draft');
        expect(form.elements.gpt_model.classList.contains('is-invalid')).toBe(true);
        expect(form.querySelector('fieldset').disabled).toBe(false);
        expect(notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'error', actionLabel: 'Повторити' }));
    });
});
