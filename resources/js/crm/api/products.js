import http from './http';

export function searchProducts(queryOrParams = '') {
    const params = typeof queryOrParams === 'string'
        ? { q: queryOrParams }
        : (queryOrParams || {});

    return http.get('/products', {
        params,
        headers: { Accept: 'application/json' },
    });
}
