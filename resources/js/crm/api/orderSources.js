import http from './http';

export function fetchOrderSources(params = {}) {
  return http.get('/order-sources', { params });
}
