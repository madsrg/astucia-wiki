import { showToast } from './utils.js';
import { state } from './state.js';

export const api = {
    call: async (action, params = {}, method = 'GET', signal = null) => {
        // Automatically include the active space in every request
        if (state.currentSpace && !Object.prototype.hasOwnProperty.call(params, 'space')) {
            params = { space: state.currentSpace, ...params };
        }

        let url = `api.php?action=${action}`;
        let options = { method };
        if (signal) options.signal = signal;

        if (method === 'GET') {
            if (Object.keys(params).length) {
                url += '&' + new URLSearchParams(params).toString();
            }
        } else {
            const formData = new FormData();
            for (const key in params) {
                formData.append(key, params[key]);
            }
            options.body = formData;
        }

        try {
            const response = await fetch(url, options);
            if (!response.ok) {
                if (response.status === 401) {
                    window.location.href = 'login.php';
                    return { success: false };
                }
                let message;
                try { const e = await response.json(); message = e.message; } catch {}
                throw new Error(message || `HTTP error ${response.status}`);
            }
            const data = await response.json();
            if (data.session_expired) {
                window.location.href = 'login.php';
                return data;
            }
            state.lastApiCallTime = Date.now();
            return data;
        } catch (error) {
            if (error.name === 'AbortError') return { success: false, aborted: true };
            console.error('API Error:', error);
            showToast(`Error: ${error.message}`, 'error');
            return { success: false, message: error.message };
        }
    },
};
