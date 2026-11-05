import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import MarketResearchPage from './MarketResearchPage.vue';
import http from '@/crm/api/http';

vi.mock('@/crm/api/http', () => ({ default: { post: vi.fn() } }));

const initial = {
    connections: [{ id: 7, page_name: 'Крамниця', page_id: '123', status: 'active' }],
    library_token_configured: false,
    countries: { PL: 'Польща', DE: 'Німеччина', UA: 'Україна (пошук на сайті Meta)' },
};
const response = (library = {}) => ({ data: {
    checked_at: '2026-11-05T10:00:00+00:00',
    query: { search: 'pajama', country: 'PL', connection_id: 7 },
    page: { state: 'connected', name: 'Крамниця', message: 'Meta підтвердила сторінку.' },
    library: { state: 'connected', token_source: 'page', ads: [], message: 'Ad Library відповідає.', ...library },
} });
let wrapper;
const render = () => { wrapper = mount(MarketResearchPage, { props: { initial }, attachTo: document.body }); };
beforeEach(() => vi.clearAllMocks());
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; });

describe('Пробна сторінка аналізу ринку', () => {
    it('не підтверджує доступ і не викликає Meta до натискання кнопки', () => {
        render();
        expect(http.post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Ще не перевіряли');
        expect(wrapper.text()).not.toContain('Доступ підтверджено');
        expect(wrapper.text()).toContain('Крамниця');
        expect(document.querySelector('.app-toast')).toBeNull();
    });

    it('формує прямий пошук Meta з вибраною країною та ключовим словом', async () => {
        render();
        await wrapper.find('[name="search"]').setValue('піжама & халат');
        await wrapper.find('[name="country"]').setValue('UA');
        const link = wrapper.find('a[target="_blank"]').attributes('href');
        const params = new URL(link).searchParams;
        expect(params.get('q')).toBe('піжама & халат');
        expect(params.get('country')).toBe('UA');
        expect(params.get('ad_type')).toBe('all');
    });

    it('показує реальні оголошення і передає обрані параметри серверу', async () => {
        http.post.mockResolvedValue(response({ ads: [{
            id: '987', page_name: 'Конкурент', body: 'Нова колекція', title: 'Піжами',
            platforms: ['instagram'], started_at: '2026-10-01', url: 'https://www.facebook.com/ads/library/?id=987',
        }] }));
        render();
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith('/api/market-research/check', { search: 'pajama', country: 'PL', connection_id: 7 }, { timeout: 25000 });
        expect(wrapper.findAll('.market-ad')).toHaveLength(1);
        expect(wrapper.text()).toContain('Конкурент');
        expect(document.querySelector('.app-toast--success')).not.toBeNull();
    });

    it('зберігає відмову Ad Library та не перекриває її наступним успіхом', async () => {
        http.post.mockResolvedValueOnce(response({ state: 'error', message: 'Потрібен окремий доступ.', detail: 'Authorization needed', error_code: 10, http_status: 400 }))
            .mockResolvedValueOnce(response());
        render();
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('Доступ підтверджено');
        expect(wrapper.text()).toContain('Authorization needed');
        expect(document.querySelector('.app-toast--warning').textContent).toContain('Потрібен окремий доступ.');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(document.querySelector('.app-toast--warning').textContent).toContain('Потрібен окремий доступ.');
        expect(document.querySelector('.app-toast--success')).toBeNull();
    });

    it('залишає чернетку та попередній результат при помилці запиту і підсвічує поле', async () => {
        http.post.mockResolvedValueOnce(response()).mockRejectedValueOnce({ response: { status: 422, data: { errors: { search: ['Уточніть запит.'] } } } });
        render();
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        await wrapper.find('[name="search"]').setValue('Новий товар');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('[name="search"]').element.value).toBe('Новий товар');
        expect(wrapper.find('[name="search"]').classes()).toContain('is-invalid');
        expect(wrapper.text()).toContain('«pajama»');
        expect(document.querySelector('.app-toast--error').textContent).toContain('Уточніть запит.');
        expect(document.querySelector('.app-toast-actions').textContent).toContain('Повторити');
    });
});
