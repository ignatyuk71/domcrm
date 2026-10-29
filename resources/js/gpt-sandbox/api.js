import axios from 'axios';

const client = axios.create({
    baseURL: '/settings/gpt-sandbox',
    timeout: 90000,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },
});

export default {
    state: () => client.get('/state'),
    save: data => client.put('/settings', data),
    connection: data => client.post('/connection', data),
    newChat: () => client.post('/chats'),
    send: (chat, data) => client.post(`/chats/${chat}/messages`, data),
    retry: (chat, turn) => client.post(`/chats/${chat}/turns/${turn}/retry`),
};
