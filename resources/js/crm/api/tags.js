import http from './http';

export function fetchTags() {
    return http.get('/tags');
}
