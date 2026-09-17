import { describe, expect, it } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import { costSummaryKey, useCostSummaryPart } from './useCostSummaryPart';
import { costModelKey } from './useCostModelApi';

describe('Передача вибраної складової до підсумку', () => {
  it.each(['soles', 'cardboard', 'foam', 'fur', 'laminate', 'tape'])('передає %s разом із категорією, реагує на невалідну чернетку', async component => {
    let form, preview, dirty;
    const reports = [];
    const Panel = defineComponent({ setup() {
      form = ref(null); preview = ref(null); dirty = ref(false);
      useCostSummaryPart(component, { form, selectedId: ref(4), preview, dirty });
      return () => null;
    } });
    const wrapper = mount(Panel, { global: { provide: { [costModelKey]: 12, [costSummaryKey]: (...args) => reports.push(args) } } });
    expect(reports).toHaveLength(0);
    form.value = { name: 'Тест' }; preview.value = { unit_cost_uah: 10 }; await flushPromises();
    expect(reports.at(-1)).toEqual([12, component, { id: 4, name: 'Тест', calculation: { unit_cost_uah: 10 }, dirty: false }]);
    preview.value = null; dirty.value = true; await flushPromises();
    expect(reports.at(-1)[2]).toMatchObject({ calculation: null, dirty: true });
    wrapper.unmount();
  });
});
