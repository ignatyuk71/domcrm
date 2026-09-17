import { inject } from 'vue';

export const costModelKey = Symbol('production-cost-model');

export function scopedCostApi(modelId, api) {
  const payload = data => modelId == null ? data : { ...data, model_id: modelId };
  return {
    fetch: page => modelId == null ? api.fetch(page) : api.fetch(page, modelId),
    create: data => api.create(payload(data)),
    update: (id, data) => api.update(id, payload(data)),
  };
}

export function useCostModelApi(api) {
  // Область належить конкретному робочому екрану, не спільному змінному стану API.
  return scopedCostApi(inject(costModelKey, undefined), api);
}
