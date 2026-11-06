import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ProductFormPage from './ProductFormPage.vue';
import http from '@/crm/api/http';

vi.mock('@/crm/api/http', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

let wrapper;
const product = () => ({
  id: 44,
  title: 'Капці',
  variants: [
    { id: 111, size: '36/37', sku: 'MODEL-36', stock_qty: 3, is_active: true },
    { id: 222, size: '38/39', sku: 'MODEL-38', stock_qty: 5, is_active: true },
  ],
});
const rows = () => wrapper.findAll('tbody tr');

async function open(initialProduct = product()) {
  wrapper = mount(ProductFormPage, {
    props: { initialProduct },
    global: { stubs: { teleport: true } },
  });
  await flushPromises();
}

async function submitVariants() {
  const previousCalls = http.post.mock.calls.length;
  await wrapper.get('form').trigger('submit');
  expect(http.post).toHaveBeenCalledTimes(previousCalls);
  await wrapper.get('.modal .btn-primary-gradient').trigger('click');
  await flushPromises();

  expect(http.post).toHaveBeenCalledTimes(previousCalls + 1);
  const [url, body] = http.post.mock.lastCall;
  const id = wrapper.props('initialProduct')?.id;
  expect(url).toBe(id ? `/products/${id}` : '/products');
  expect(body).toBeInstanceOf(FormData);
  expect(body.get('_method')).toBe(id ? 'PUT' : null);
  return JSON.parse(body.get('variants'));
}

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockResolvedValue({ data: [] });
  http.post.mockResolvedValue({ data: { saved: true } });
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('сталі ідентифікатори варіантів у формі товару', () => {
  it('надсилає початковий ID разом зі зміненими полями варіанта', async () => {
    await open();
    await rows()[0].get('input[placeholder="38-39"]').setValue('36/38');
    await rows()[0].get('input[placeholder="SKU-38-39"]').setValue('MODEL-36-NEW');
    await rows()[0].get('input[type="number"]').setValue('9');
    await rows()[0].get('input[type="checkbox"]').setValue(false);

    expect(await submitVariants()).toEqual([
      { id: 111, size: '36/38', sku: 'MODEL-36-NEW', stock_qty: 9, is_active: false },
      { id: 222, size: '38/39', sku: 'MODEL-38', stock_qty: 5, is_active: true },
    ]);
  });

  it('зберігає відповідність ID після зміни порядку рядків', async () => {
    await open();
    // Ідентичність варіанта не повинна залежати від його позиції в масиві.
    wrapper.vm.form.variants.reverse();
    await nextTick();
    expect(rows()[0].get('input[placeholder="38-39"]').element.value).toBe('38/39');
    await rows()[0].get('input[placeholder="SKU-38-39"]').setValue('MODEL-38-NEW');

    expect(await submitVariants()).toEqual([
      { id: 222, size: '38/39', sku: 'MODEL-38-NEW', stock_qty: 5, is_active: true },
      { id: 111, size: '36/37', sku: 'MODEL-36', stock_qty: 3, is_active: true },
    ]);
    expect(JSON.parse(http.post.mock.lastCall[1].get('deleted_variant_ids'))).toEqual([]);
  });

  it('надсилає новий варіант без ID та зберігає ID наявних', async () => {
    await open();
    const add = wrapper.findAll('button').find(button => button.text().includes('Додати розмір'));
    await add.trigger('click');
    await rows()[2].get('input[placeholder="38-39"]').setValue('40/41');
    await rows()[2].get('input[placeholder="SKU-38-39"]').setValue('MODEL-40');
    await rows()[2].get('input[type="number"]').setValue('7');

    const variants = await submitVariants();
    expect(variants.slice(0, 2).map(variant => variant.id)).toEqual([111, 222]);
    expect(variants[2]).toEqual({ size: '40/41', sku: 'MODEL-40', stock_qty: 7, is_active: true });
    expect(variants[2]).not.toHaveProperty('id');
    expect(variants[2]).not.toHaveProperty('local_id');
    expect(JSON.parse(http.post.mock.lastCall[1].get('deleted_variant_ids'))).toEqual([]);
  });

  it('не надсилає видалений ID та не перепризначає його іншому рядку', async () => {
    await open();
    await rows()[0].get('button').trigger('click');

    const variants = await submitVariants();
    expect(variants).toEqual([
      { id: 222, size: '38/39', sku: 'MODEL-38', stock_qty: 5, is_active: true },
    ]);
    expect(variants.map(variant => variant.id)).not.toContain(111);
    expect(JSON.parse(http.post.mock.lastCall[1].get('deleted_variant_ids'))).toEqual([111]);
  });

  it('підтверджує остаточне видалення та зберігає чернетку після скасування', async () => {
    await open();
    await rows()[0].get('button').trigger('click');
    await rows()[0].get('input[placeholder="SKU-38-39"]').setValue('MODEL-38-DRAFT');
    await wrapper.get('form').trigger('submit');

    const confirmation = wrapper.get('.modal');
    expect(confirmation.get('p').text()).toContain('остаточно видалено');
    expect(confirmation.get('p').text()).toContain('Кількість: 1.');
    expect(confirmation.get('.btn-primary-gradient').text()).toBe('Зберегти й видалити');
    expect(http.post).not.toHaveBeenCalled();
    await confirmation.get('.btn-light').trigger('click');

    expect(wrapper.find('.modal').exists()).toBe(false);
    expect(http.post).not.toHaveBeenCalled();
    expect(rows()).toHaveLength(1);
    expect(rows()[0].get('input[placeholder="SKU-38-39"]').element.value).toBe('MODEL-38-DRAFT');
    expect(await submitVariants()).toEqual([
      { id: 222, size: '38/39', sku: 'MODEL-38-DRAFT', stock_qty: 5, is_active: true },
    ]);
    expect(JSON.parse(http.post.mock.lastCall[1].get('deleted_variant_ids'))).toEqual([111]);
  });

  it('надсилає порожній список видалень під час створення нового товару', async () => {
    await open(null);
    await wrapper.get('input[placeholder="Наприклад: Кросівки Nike Air Max"]').setValue('Новий товар');

    expect(await submitVariants()).toEqual([]);
    expect(JSON.parse(http.post.mock.lastCall[1].get('deleted_variant_ids'))).toEqual([]);
  });

  it('показує стійку помилку сервера та дозволяє повторити збереження без втрати чернетки', async () => {
    vi.useFakeTimers();
    const alert = vi.spyOn(window, 'alert').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
    await open();
    await rows()[0].get('input[placeholder="38-39"]').setValue('36/38');
    await rows()[1].get('button').trigger('click');
    const message = 'Варіант змінився. Оновіть форму та повторіть збереження.';
    http.post.mockRejectedValueOnce({ response: { status: 422, data: {
      message: 'Не вдалося перевірити варіанти.',
      errors: { 'variants.0.id': [message] },
    } } });

    await submitVariants();
    const firstBody = http.post.mock.lastCall[1];
    expect(wrapper.get('[role="alert"]').text()).toContain(message);
    expect(rows()[0].classes()).toContain('table-danger');
    expect(rows()[0].get('input[placeholder="38-39"]').element.value).toBe('36/38');
    expect(rows()).toHaveLength(1);
    expect(alert).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(10000);
    expect(wrapper.get('[role="alert"]').text()).toContain(message);

    await wrapper.get('.app-toast-actions button').trigger('click');
    expect(wrapper.get('.modal').text()).toContain('Зберегти товар?');
    await wrapper.get('.modal .btn-primary-gradient').trigger('click');
    await flushPromises();

    expect(http.post).toHaveBeenCalledTimes(2);
    expect(http.post.mock.lastCall[1].get('variants')).toBe(firstBody.get('variants'));
    expect(http.post.mock.lastCall[1].get('deleted_variant_ids')).toBe(firstBody.get('deleted_variant_ids'));
  });
});
