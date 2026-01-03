import axios from 'axios';

const http = axios.create({
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
});

// CSRF token from meta
const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (token) {
    http.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

http.interceptors.response.use(
    (resp) => resp,
    (error) => {
        // normalize error shape
        return Promise.reject(error);
    }
);

export default http;
