import http from '../api/http';

const url = '/api/work-time/piecework';
const options = { timeout: 15000 };
export const fetchPiecework = (month, page = 1) => http.get(url, { ...options, params: { month, page } });
export const createPiecework = data => http.post(url, data, options);
export const updatePiecework = (id, data) => http.put(`${url}/${id}`, data, options);
