import http from './http';

export function searchProducts(query) {
    return http.get('/products', {
        params: { q: query },
        headers: { Accept: 'application/json' },
    });
}
