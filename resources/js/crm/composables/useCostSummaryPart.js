import { inject, watch } from 'vue';
import { costModelKey } from './useCostModelApi';

export const costSummaryKey = Symbol('cost-summary');

export function useCostSummaryPart(component, { form, selectedId, preview, dirty }, modelId = inject(costModelKey, undefined)) {
  const report = inject(costSummaryKey, null);
  if (!report) return;
  watch([form, selectedId, preview, dirty], () => {
    if (!form.value) return;
    // Чернетка перекриває збережену ціну, навіть коли її поля ще невалідні.
    report(modelId, component, { id: selectedId.value, name: form.value.name || 'Новий розрахунок', calculation: preview.value, dirty: dirty.value });
  }, { immediate: true, deep: true });
}
