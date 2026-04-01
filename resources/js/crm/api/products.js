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

export function fetchProducts(params = {}) {
    return http.get('/products', {
        params: {
            with_variants: 1,
            ...params,
        },
        headers: { Accept: 'application/json' },
    });
}

export function fetchProductCategories() {
    return http.get('/products/categories', {
        headers: { Accept: 'application/json' },
    });
}

export function updateProductVariant(variantId, payload) {
    return http.patch(`/products/variants/${variantId}`, payload, {
        headers: { Accept: 'application/json' },
    });
}

export function updateProductInline(productId, payload) {
    return http.patch(`/products/${productId}/inline`, payload, {
        headers: { Accept: 'application/json' },
    });
}

export function destroyProduct(productId) {
    return http.delete(`/products/${productId}`, {
        headers: { Accept: 'application/json' },
    });
}
