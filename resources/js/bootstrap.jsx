import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Axios automatically handles the XSRF-TOKEN cookie sent by Laravel,
// so we don't need to manually set the X-CSRF-TOKEN header from a stale meta tag.

// Pastikan axios selalu mengirim cookies (penting untuk session auth via NGROK)
window.axios.defaults.withCredentials = true;
