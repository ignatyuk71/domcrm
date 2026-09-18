import http from '../api/http';

const employeeUrl = id => `/api/work-time/employees/${id}`;
const requestOptions = { timeout: 15000 };
export const fetchWorkTime = month => http.get('/api/work-time', { ...requestOptions, params: { month } });
export const createWorkEmployee = data => http.post('/api/work-time/employees', data, requestOptions);
export const updateWorkEmployee = (id, data) => http.put(employeeUrl(id), data, requestOptions);
export const deleteWorkEmployee = (id, version) => http.delete(employeeUrl(id), { ...requestOptions, data: { version, confirmed: true } });
export const saveWorkDay = (id, data) => http.put(`${employeeUrl(id)}/entry`, data, requestOptions);
export const fetchWorkPayroll = (id, month) => http.get(`${employeeUrl(id)}/payroll`, { ...requestOptions, params: { month } });
export const saveWorkPayroll = (id, data) => http.put(`${employeeUrl(id)}/payroll`, data, requestOptions);
