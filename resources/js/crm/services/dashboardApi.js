import axios from 'axios';

export function fetchDashboardData(days = 30) {
  return axios.get('/api/dashboard/data', { params: { days } });
}
