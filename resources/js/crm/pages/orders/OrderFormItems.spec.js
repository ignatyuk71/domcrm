import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import OrderEditPage from './OrderEditPage.vue';
import OrderCreatePage from './OrderCreatePage.vue';
import ItemsTable from '@/crm/components/orders/ItemsTable.vue';
import http from '@/crm/api/http';

vi.mock('@/crm/api/http', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }));

let wrapper;
let order;
const orderFixture = () => ({
  id: 5980,
  currency: 'UAH',
  status: 'new',
  payment_status: 'unpaid',
  customer: { first_name: 'Олена', last_name: 'Тестова', phone: '380501234567', email: '' },
  delivery: { delivery_type: 'warehouse', city_ref: 'city-ref', city_name: 'Київ', warehouse_ref: 'warehouse-ref', warehouse_name: 'Відділення №1' },
  payment: { method: 'cod', currency: 'UAH' },
  items: [
    { id: 111, product_id: 10, product_variant_id: 101, product_title: 'Історична назва', size: '36/37',
      sku: 'OLD-36', color: 'Історичний чорний', external_id: 'external-line-a', qty: 2, price: 500, total: 1000, cost_price: 100,
      product: { title: 'Нова назва в каталозі', cost_price: 900 }, variant: { size: '40/41' } },
    { id: 222, product_id: 20, product_variant_id: 201, product_title: '', size: '',
      sku: '', color: '', external_id: '', qty: 1, price: 300, total: 300, cost_price: 80,
      product: { title: 'Назва, яку не слід підставляти' }, variant: { size: '38/39' } },
    { id: 333, product_id: 30, product_variant_id: 301, product_title: null, size: null,
      sku: 'FALLBACK', color: null, external_id: null, qty: 1, price: 200, total: 200, cost_price: 50,
      product: { title: 'Назва для відсутнього знімка' }, variant: { size: '42/43' } },
  ],
});
const rows = () => wrapper.findComponent(ItemsTable).findAll('.item-row');

async function open(component, id = 5980) {
  wrapper = mount(component, {
    props: { initialOrderId: id },
    global: { stubs: { teleport: true, CustomerBlock: true, CustomerOrderHistory: true,
      OrderStatusHistory: true, OrderMetaBlock: true, PaymentBlock: true, DeliveryBlock: true } },
  });
  await flushPromises();
  if (!id) {
    // Заповнюємо залежні блоки, не залучаючи їхню окрему логіку пошуку клієнта й доставки.
    Object.assign(wrapper.vm.form.customer, order.customer);
    Object.assign(wrapper.vm.form.delivery, order.delivery);
  }
}

async function submit() {
  const method = wrapper.props('initialOrderId') ? http.put : http.post;
  const previousCalls = method.mock.calls.length;
  await wrapper.get('form').trigger('submit');
  expect(method).toHaveBeenCalledTimes(previousCalls);
  await wrapper.get('.modal .btn-primary-gradient').trigger('click');
  await flushPromises();
  expect(method).toHaveBeenCalledTimes(previousCalls + 1);
  expect(method.mock.lastCall[0]).toBe(wrapper.props('initialOrderId') ? '/orders/5980' : '/orders');
  return method.mock.lastCall[1];
}

async function addCatalogProduct() {
  const table = wrapper.findComponent(ItemsTable);
  await table.get('button.btn-primary').trigger('click');
  await flushPromises();
  await table.get('.product-group-head').trigger('click');
  await table.get('.product-card').trigger('click');
}

beforeEach(() => {
  vi.clearAllMocks();
  order = orderFixture();
  http.get.mockImplementation(async (url) => {
    if (url === '/orders/5980') return { data: { data: order } };
    if (url === '/products') return { data: { data: [{ id: 90, title: 'Новий товар', sale_price: 700,
      variants: [{ id: 901, size: '44/45', sku: 'NEW-44', stock_qty: 10 }] }], current_page: 1, last_page: 1 } };
    return { data: [] };
  });
  http.put.mockResolvedValue({ data: { saved: true } });
  http.post.mockResolvedValue({ data: { saved: true } });
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe.each([
  ['OrderEditPage', OrderEditPage],
  ['OrderCreatePage', OrderCreatePage],
])('сталі позиції замовлення: %s', (name, component) => {
  it('завантажує історичні знімки й метадані, не підміняючи їх змінами каталогу', async () => {
    await open(component);
    expect(rows().map(row => row.get('input[placeholder="Назва товару"]').element.value))
      .toEqual(['Історична назва', '', 'Назва для відсутнього знімка']);
    expect(wrapper.vm.form.items.map(item => item.size)).toEqual(['36/37', '', '42/43']);

    const payload = await submit();
    expect(payload.items.map(item => item.id)).toEqual([111, 222, 333]);
    expect(payload.items[0]).toMatchObject({ product_id: 10, product_variant_id: 101,
      title: 'Історична назва', size: '36/37', color: 'Історичний чорний', external_id: 'external-line-a' });
    expect(payload.items[1]).toMatchObject({ title: '', size: '', color: '', external_id: '' });
    payload.items.forEach(item => expect(item).not.toHaveProperty('cost_price'));
  });

  it('зберігає ID після перестановки та редагування кількості й ціни', async () => {
    await open(component);
    wrapper.vm.form.items.reverse();
    await nextTick();
    await rows()[0].findAll('input[type="number"]')[0].setValue('3');
    await rows()[0].findAll('input[type="number"]')[1].setValue('250');

    const payload = await submit();
    expect(payload.items.map(item => item.id)).toEqual([333, 222, 111]);
    expect(payload.items[0]).toMatchObject({ product_id: 30, product_variant_id: 301, qty: 3, price: 250 });
    expect(payload.deleted_item_ids).toEqual([]);
  });

  it('додає товар із каталогу без підміни ID позиції ідентифікатором товару чи варіанта', async () => {
    await open(component);
    await addCatalogProduct();

    const payload = await submit();
    expect(payload.items.slice(0, 3).map(item => item.id)).toEqual([111, 222, 333]);
    expect(payload.items[3]).toMatchObject({ product_id: 90, product_variant_id: 901, qty: 1 });
    expect(payload.items[3]).not.toHaveProperty('id');
    expect(payload.items[3]).not.toHaveProperty('cost_price');
    expect(payload.deleted_item_ids).toEqual([]);
  });

  it('підтверджує видалення існуючої позиції та зберігає чернетку після скасування', async () => {
    await open(component);
    await rows()[0].get('.btn-icon-sm').trigger('click');
    await wrapper.get('form').trigger('submit');

    const modal = wrapper.get('.modal');
    expect(modal.get('p').text()).toContain('остаточно видалено');
    expect(modal.get('p').text()).toContain('Кількість: 1.');
    expect(modal.get('.btn-primary-gradient').text()).toBe('Зберегти й видалити');
    await modal.get('.btn-light').trigger('click');
    expect(http.put).not.toHaveBeenCalled();
    expect(rows()).toHaveLength(2);

    const payload = await submit();
    expect(payload.items.map(item => item.id)).toEqual([222, 333]);
    expect(payload.deleted_item_ids).toEqual([111]);
  });

  it('не передає старі ID позицій та собівартість під час створення нового замовлення', async () => {
    await open(component, null);
    await addCatalogProduct();
    // Навіть скопійований обʼєкт товару не надає новій позиції старий ID замовлення.
    Object.assign(wrapper.vm.form.items[0], { id: 111, cost_price: 999, external_id: 'new-external-line' });

    const payload = await submit();
    expect(payload.items).toHaveLength(1);
    expect(payload.items[0]).toMatchObject({ product_id: 90, product_variant_id: 901, external_id: 'new-external-line' });
    expect(payload.items[0]).not.toHaveProperty('id');
    expect(payload.items[0]).not.toHaveProperty('cost_price');
    expect(http.put).not.toHaveBeenCalled();
  });

  it('залишає серверну помилку та чернетку доступними для повторного збереження', async () => {
    vi.useFakeTimers();
    await open(component);
    await rows()[0].get('input[placeholder="Назва товару"]').setValue('Назва в чернетці');
    await rows()[1].get('.btn-icon-sm').trigger('click');
    const message = 'Позицію змінено іншим менеджером. Оновіть форму.';
    http.put.mockRejectedValueOnce({ response: { status: 422, data: { errors: { 'items.0.id': [message] } } } });

    const failedPayload = JSON.parse(JSON.stringify(await submit()));
    expect(wrapper.get('[role="alert"]').text()).toContain(message);
    expect(wrapper.findComponent(ItemsTable).attributes('aria-invalid')).toBe('true');
    await vi.advanceTimersByTimeAsync(10000);
    expect(wrapper.get('[role="alert"]').text()).toContain(message);
    expect(rows()[0].get('input[placeholder="Назва товару"]').element.value).toBe('Назва в чернетці');
    expect(rows()).toHaveLength(2);

    await wrapper.get('.app-toast-actions button').trigger('click');
    await wrapper.get('.modal .btn-primary-gradient').trigger('click');
    await flushPromises();
    expect(http.put).toHaveBeenCalledTimes(2);
    expect(http.put.mock.lastCall[1].items).toEqual(failedPayload.items);
    expect(http.put.mock.lastCall[1].deleted_item_ids).toEqual(failedPayload.deleted_item_ids);
  });
});
