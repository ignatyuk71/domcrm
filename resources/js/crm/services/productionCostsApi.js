import http from '@/crm/api/http';

const params = (page, modelId) => ({ page, ...(modelId == null ? {} : { model_id: modelId }) });
export const fetchCostModels = () => http.get('/api/production-costs/models');
export const fetchCostSummary = modelId => http.get('/api/production-costs/summary', { params: { model_id: modelId } });
export const openCostModel = categoryId => http.post('/api/production-costs/models', { category_id: categoryId });
export const fetchCostBatches = (page = 1, modelId) => http.get('/api/production-costs/sole-batches', { params: params(page, modelId) });
export const createCostBatch = data => http.post('/api/production-costs/sole-batches', data);
export const updateCostBatch = (id, data) => http.put(`/api/production-costs/sole-batches/${id}`, data);
export const fetchCardboardBatches = (page = 1, modelId) => http.get('/api/production-costs/cardboard-batches', { params: params(page, modelId) });
export const createCardboardBatch = data => http.post('/api/production-costs/cardboard-batches', data);
export const updateCardboardBatch = (id, data) => http.put(`/api/production-costs/cardboard-batches/${id}`, data);
export const fetchFoamCosts = (page = 1, modelId) => http.get('/api/production-costs/foam-calculations', { params: params(page, modelId) });
export const createFoamCost = data => http.post('/api/production-costs/foam-calculations', data);
export const updateFoamCost = (id, data) => http.put(`/api/production-costs/foam-calculations/${id}`, data);
export const fetchFurBatches = (page = 1, modelId) => http.get('/api/production-costs/fur-batches', { params: params(page, modelId) });
export const createFurBatch = data => http.post('/api/production-costs/fur-batches', data);
export const updateFurBatch = (id, data) => http.put(`/api/production-costs/fur-batches/${id}`, data);
export const fetchLaminateCosts = (page = 1, modelId) => http.get('/api/production-costs/laminate-calculations', { params: params(page, modelId) });
export const createLaminateCost = data => http.post('/api/production-costs/laminate-calculations', data);
export const updateLaminateCost = (id, data) => http.put(`/api/production-costs/laminate-calculations/${id}`, data);
export const fetchTapeBatches = (page = 1, modelId) => http.get('/api/production-costs/tape-batches', { params: params(page, modelId) });
export const createTapeBatch = data => http.post('/api/production-costs/tape-batches', data);
export const updateTapeBatch = (id, data) => http.put(`/api/production-costs/tape-batches/${id}`, data);
export function costError(error) {
  const errors = error.response?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'Не вдалося виконати запит. Перевірте з’єднання та спробуйте ще раз.';
}
