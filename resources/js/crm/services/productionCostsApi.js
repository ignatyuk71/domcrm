import http from '@/crm/api/http';

export const fetchCostBatches = (page = 1) => http.get('/api/production-costs/sole-batches', { params: { page } });
export const createCostBatch = data => http.post('/api/production-costs/sole-batches', data);
export const updateCostBatch = (id, data) => http.put(`/api/production-costs/sole-batches/${id}`, data);
export const fetchCardboardBatches = (page = 1) => http.get('/api/production-costs/cardboard-batches', { params: { page } });
export const createCardboardBatch = data => http.post('/api/production-costs/cardboard-batches', data);
export const updateCardboardBatch = (id, data) => http.put(`/api/production-costs/cardboard-batches/${id}`, data);
export function costError(error) {
  const errors = error.response?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'Не вдалося виконати запит. Перевірте з’єднання та спробуйте ще раз.';
}
