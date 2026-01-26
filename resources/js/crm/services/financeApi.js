import http from '@/crm/api/http';

export const fetchFinanceSettings = async () => {
    const { data } = await http.get('/api/finance/checkbox');
    return data;
};

export const saveFinanceSettings = async (payload) => {
    const { data } = await http.post('/finance/checkbox', payload);
    return data;
};

export const testFinanceConnection = async () => {
    const { data } = await http.post('/finance/checkbox/test');
    return data;
};

export const openFinanceShift = async () => {
    const { data } = await http.post('/finance/checkbox/shift/open');
    return data;
};

export const closeFinanceShift = async () => {
    const { data } = await http.post('/finance/checkbox/shift/close');
    return data;
};

export const processFinanceQueue = async () => {
    const { data } = await http.post('/finance/checkbox/queue/process');
    return data;
};
