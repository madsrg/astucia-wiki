import { state } from './state.js';

let toastTimeout;

export const showToast = (message, type = 'info') => {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toast-message');
    toastMessage.textContent = message;
    toast.style.backgroundColor = type === 'error' ? '#c53030' : '#2d3748';
    toast.classList.add('show');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('show'), 3000);
    if (type === 'error') {
        const fd = new FormData();
        fd.append('message', message);
        fd.append('page', state.currentPagePath ?? '');
        fetch('api.php?action=log_client_error', { method: 'POST', body: fd }).catch(() => {});
    }
};

export const confirmModal = (title, { message = '', confirmLabel = 'Confirm', cancelLabel = 'Cancel', dangerous = false, icon = '', hideCancel = false } = {}) => new Promise(resolve => {
    const overlay = document.getElementById('confirm-modal');
    const titleEl = document.getElementById('confirm-modal-title');
    const iconEl = document.getElementById('confirm-modal-icon');
    const messageEl = document.getElementById('confirm-modal-message');
    const okBtn = document.getElementById('confirm-modal-ok');
    const cancelBtn = document.getElementById('confirm-modal-cancel');

    titleEl.textContent = title;
    iconEl.innerHTML = icon;
    iconEl.classList.toggle('hidden', !icon);
    messageEl.textContent = message;
    messageEl.classList.toggle('hidden', !message);
    okBtn.textContent = confirmLabel;
    okBtn.className = `btn ${dangerous ? 'btn-danger' : 'btn-blue'}`;
    cancelBtn.textContent = cancelLabel;
    cancelBtn.classList.toggle('hidden', hideCancel);
    overlay.classList.remove('hidden');
    setTimeout(() => (dangerous ? cancelBtn : okBtn).focus(), 50);

    const close = value => {
        overlay.classList.add('hidden');
        cancelBtn.classList.remove('hidden'); // restore for next caller
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        document.removeEventListener('keydown', onKeydown);
        resolve(value);
    };

    const onOk = () => close(true);
    const onCancel = () => close(false);
    const onKeydown = e => {
        if (e.key === 'Enter' && !dangerous) { e.preventDefault(); close(true); }
        if (e.key === 'Escape') close(false);
    };

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    // Delay to avoid auto-repeat keydown from the keystroke that triggered the modal
    setTimeout(() => document.addEventListener('keydown', onKeydown), 300);
});

export const promptModal = (title, defaultValue = '', placeholder = '', icon = '') => new Promise(resolve => {
    const overlay = document.getElementById('input-modal');
    const titleEl = document.getElementById('input-modal-title');
    const iconEl = document.getElementById('input-modal-icon');
    const input = document.getElementById('input-modal-input');
    const okBtn = document.getElementById('input-modal-ok');
    const cancelBtn = document.getElementById('input-modal-cancel');

    titleEl.textContent = title;
    iconEl.innerHTML = icon;
    iconEl.classList.toggle('hidden', !icon);
    input.value = defaultValue;
    input.placeholder = placeholder;
    overlay.classList.remove('hidden');
    setTimeout(() => { input.focus(); input.select(); }, 50);

    const close = value => {
        overlay.classList.add('hidden');
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        input.removeEventListener('keydown', onKeydown);
        resolve(value);
    };

    const onOk = () => { const v = input.value.trim(); close(v || null); };
    const onCancel = () => close(null);
    const onKeydown = e => {
        if (e.key === 'Enter') { e.preventDefault(); const v = input.value.trim(); close(v || null); }
        if (e.key === 'Escape') close(null);
    };

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    input.addEventListener('keydown', onKeydown);
});
