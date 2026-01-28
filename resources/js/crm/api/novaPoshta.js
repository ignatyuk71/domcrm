import http from './http';

export function fetchCities(query) {
    return http.get('/nova-poshta/cities', { params: { q: query } });
}

export function fetchWarehouses({ cityRef, query, limit = 50 }) {
    return http.get('/nova-poshta/warehouses', { params: { city_ref: cityRef, q: query, limit } });
}

export function fetchStreets({ cityRef, settlementRef, query }) {
    return http.get('/nova-poshta/streets', { params: { city_ref: cityRef, settlement_ref: settlementRef, q: query } });
}
