import http from './http';

export function searchCustomers(query) {
    return http.get('/customers', { params: { q: query } });
}

export function createCustomer(payload) {
    return http.post('/customers', payload);
}
