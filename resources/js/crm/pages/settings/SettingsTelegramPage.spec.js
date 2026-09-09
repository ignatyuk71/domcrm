import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Page from './SettingsTelegramPage.vue';
import http from '@/crm/api/http';

vi.mock('@/crm/api/http', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));
const settings = () => ({ has_token: true, chat_id: '-12345', bot_name: 'Помічник', bot_username: 'example_bot', chat_title: 'Робоча група', enabled: false, permissions: { manual_test: true, warehouse_reminder: false, new_order: false, return_alert: false }, verified_at: '2026-09-09T09:00:00Z', last_test_at: null });
let wrapper;
afterEach(() => { wrapper?.unmount(); vi.resetAllMocks(); });
async function open() {
  http.get.mockResolvedValue({ data: settings() });
  wrapper = mount(Page);
  await flushPromises();
}

describe('налаштування Telegram', () => {
  it('не надсилає повідомлення під час відкриття та вмикання незбереженого перемикача', async () => {
    await open();
    expect(wrapper.find('#telegram-token').element.value).toBe('');
    expect(wrapper.find('.btn-test').attributes('disabled')).toBeDefined();
    await wrapper.find('[aria-label="Підключення Telegram"]').setValue(true);
    expect(http.post).not.toHaveBeenCalled();
    expect(wrapper.find('.btn-test').attributes('disabled')).toBeDefined();
    http.put.mockResolvedValue({ data: { ...settings(), enabled: true } });
    await wrapper.find('.btn-save').trigger('click');
    await flushPromises();
    expect(wrapper.find('.btn-test').attributes('disabled')).toBeUndefined();
    expect(http.post).not.toHaveBeenCalled();
    http.post.mockResolvedValue({ data: { ...settings(), enabled: true, last_test_at: '2026-09-09T10:00:00Z' } });
    await wrapper.find('.btn-test').trigger('click');
    await flushPromises();
    expect(http.post).toHaveBeenCalledExactlyOnceWith('/settings/telegram/test');
  });

  it('зміна одержувача блокує тест і збереження дозволів до перевірки підключення', async () => {
    await open();
    await wrapper.find('[aria-label="Підключення Telegram"]').setValue(true);
    await wrapper.find('#telegram-chat').setValue('-67890');
    expect(wrapper.find('.btn-save').attributes('disabled')).toBeDefined();
    expect(wrapper.find('.btn-test').attributes('disabled')).toBeDefined();
    await wrapper.find('.btn-cancel').trigger('click');
    expect(wrapper.find('#telegram-chat').element.value).toBe('-12345');
    expect(wrapper.find('[aria-label="Підключення Telegram"]').element.checked).toBe(false);
  });

  it('помилка підключення не втрачає введені значення й дозволи', async () => {
    await open();
    await wrapper.find('#telegram-token').setValue('123456:new-token');
    await wrapper.find('#permission-warehouse_reminder').setValue(true);
    http.post.mockRejectedValue({ response: { data: { errors: { connection: ['Telegram не відповів.'] } } } });
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').text()).toContain('Telegram не відповів.');
    expect(wrapper.find('#telegram-token').element.value).toBe('123456:new-token');
    expect(wrapper.find('#permission-warehouse_reminder').element.checked).toBe(true);
    expect(wrapper.find('.btn-test').attributes('disabled')).toBeDefined();
  });
});
