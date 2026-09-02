import http from '@/crm/api/http';

export function fetchSalesAnalytics(params = {}) {
  return http.get('/api/analytics/sales', { params });
}
