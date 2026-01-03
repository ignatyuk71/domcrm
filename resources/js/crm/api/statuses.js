import http from './http';

export function fetchStatuses(params = {}) {
    return http.get('/statuses', { params });
}
