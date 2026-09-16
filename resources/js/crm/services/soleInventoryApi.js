import http from '@/crm/api/http';

export const fetchSoleInventory = () => http.get('/api/sole-inventory');
export const saveSolePlan = (category, data) => http.put(`/api/sole-inventory/${category}/plan`, data);
export const addSoleMovement = (category, data) => http.post(`/api/sole-inventory/${category}/movements`, data);

export function inventoryError(error) {
  const errors = error.response?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'Не вдалося виконати запит. Перевірте з’єднання та спробуйте ще раз.';
}
