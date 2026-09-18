import http from '../api/http';

const options = { timeout: 15000 };
export const fetchWorkEmployees = () => http.get('/settings/work-payroll/employees', options);
export const fetchPayrollReport = month => http.get('/settings/work-payroll/report', { ...options, params: { month } });
export const saveMonthlyPayroll = (id, data) => http.put(`/settings/work-payroll/employees/${id}`, data, options);
