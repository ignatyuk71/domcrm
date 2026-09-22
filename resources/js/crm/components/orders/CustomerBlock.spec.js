import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CustomerBlock from './CustomerBlock.vue';

vi.mock('@/crm/api/customers', () => ({ searchCustomers: vi.fn() }));

describe('CustomerBlock', () => {
  it('зберігає повний ПІБ наявного клієнта при відкритті форми та редагуванні email', async () => {
    const customer = {
      id: 7,
      first_name: 'Марія',
      last_name: 'Андруcишин Михайлівна',
      phone: '380674294332',
      email: 'maria@example.com',
    };
    const wrapper = mount(CustomerBlock, { props: { modelValue: customer } });

    await wrapper.get('.customer-edit').trigger('click');
    expect(wrapper.get('input[autocomplete="name"]').element.value)
      .toBe('Марія Андруcишин Михайлівна');
    expect(wrapper.find('.invalid-feedback').exists()).toBe(false);

    await wrapper.get('input[type="email"]').setValue('maria.updated@example.com');
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual({
      ...customer,
      email: 'maria.updated@example.com',
    });

    await wrapper.get('button.btn-primary').trigger('click');
    expect(wrapper.get('.customer-name').text()).toBe('Марія Андруcишин Михайлівна');
    expect(wrapper.get('.customer-meta').text()).toContain('maria.updated@example.com');
    wrapper.unmount();
  });

  it('не відкидає третє слово ПІБ під час редагування', async () => {
    const wrapper = mount(CustomerBlock, {
      props: { modelValue: { first_name: 'Марія', last_name: 'Андрушишин' } },
    });

    await wrapper.get('.customer-edit').trigger('click');
    await wrapper.get('input[autocomplete="name"]').setValue('Марія Андрушишин Михайлівна');

    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toMatchObject({
      first_name: 'Марія',
      last_name: 'Андрушишин Михайлівна',
    });
    expect(wrapper.find('.invalid-feedback').exists()).toBe(false);
    wrapper.unmount();
  });
});
