import http from './http';

export function fiscalize(orderId, type = 'sell') {
  return http.post(`/api/orders/${orderId}/fiscalize`, { type });
}

export function refund(orderId) {
  return http.post(`/api/orders/${orderId}/refund`);
}

export function fetchFiscalStatus(orderId) {
  return http.get(`/api/orders/${orderId}/fiscal-status`);
}
