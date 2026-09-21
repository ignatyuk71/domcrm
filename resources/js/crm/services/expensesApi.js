import http from '@/crm/api/http';

const base = '/api/expenses';
export const fetchExpenseMeta = () => http.get(`${base}/meta`);
export const fetchExpenses = params => http.get(base, { params });
export const fetchExpense = id => http.get(`${base}/${id}`);
export const createExpense = payload => http.post(base, payload);
export const updateExpense = (id, payload) => http.put(`${base}/${id}`, payload);
export const deleteExpense = (id, version) => http.delete(`${base}/${id}`, { data: { version } });
export const createExpensePayment = (id, payload) => http.post(`${base}/${id}/payments`, payload);
export const updateExpensePayment = (id, paymentId, payload) => http.put(`${base}/${id}/payments/${paymentId}`, payload);
export const deleteExpensePayment = (id, paymentId, version) => http.delete(`${base}/${id}/payments/${paymentId}`, { data: { version } });
export const createExpenseDictionary = (kind, payload) => http.post(`${base}/${kind}`, payload);
export const deleteExpenseReceipt = id => http.delete(`${base}/receipts/${id}`);
export const exportExpenses = params => http.get(`${base}/export`, { params, responseType: 'blob' });
export function uploadExpenseReceipts(id, paymentId, files) {
  const data = new FormData();
  files.forEach(file => data.append('files[]', file));
  return http.post(`${base}/${id}/payments/${paymentId}/receipts`, data);
}
export function expenseError(error) {
  const data = error?.response?.data;
  if (error?.response?.status === 409) return 'Запис уже змінено в іншому вікні. Чернетку збережено. Оновіть версію, перевірте дані та повторіть збереження.';
  if (data?.errors) return Object.values(data.errors).flat().join(' ');
  return data?.message || error?.message || 'Не вдалося виконати запит. Перевірте з’єднання та повторіть спробу.';
}
