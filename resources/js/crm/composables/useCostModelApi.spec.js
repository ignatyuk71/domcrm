import { describe, expect, it, vi } from 'vitest';
import { scopedCostApi } from './useCostModelApi';

describe('Окремий API категорії собівартості', () => {
  it('передає модель у GET, POST, PUT та не змінює початкові дані', () => {
    const api = { fetch: vi.fn(), create: vi.fn(), update: vi.fn() }, form = { model_id: 99, name: 'Приклад' };
    const first = scopedCostApi(11, api), second = scopedCostApi(22, api);
    first.fetch(2); second.fetch(1); first.create(form); second.update(8, form);
    expect(api.fetch.mock.calls).toEqual([[2, 11], [1, 22]]);
    expect(api.create).toHaveBeenCalledWith({ name: 'Приклад', model_id: 11 });
    expect(api.update).toHaveBeenCalledWith(8, { name: 'Приклад', model_id: 22 });
    expect(form.model_id).toBe(99);
  });
  it('не змінює старі незалежні виклики', () => {
    const api = { fetch: vi.fn(), create: vi.fn(), update: vi.fn() }, form = { name: 'Приклад' };
    const legacy = scopedCostApi(undefined, api); legacy.fetch(1); legacy.create(form); legacy.update(8, form);
    expect(api.fetch).toHaveBeenCalledWith(1); expect(api.create).toHaveBeenCalledWith(form); expect(api.update).toHaveBeenCalledWith(8, form);
  });
});
