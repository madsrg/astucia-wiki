import { api } from './api.js';

let _active     = false;
let _closeTimer = null;
let _pollTimer  = null;
let _startTime  = 0;
let _rafHandle  = null;

const _startTimer = () => {
    _startTime = Date.now();
    const el = document.getElementById('ai-status-timer');
    const tick = () => {
        if (!_active) return;
        if (el) el.textContent = ((Date.now() - _startTime) / 1000).toFixed(1) + 's';
        _rafHandle = requestAnimationFrame(tick);
    };
    _rafHandle = requestAnimationFrame(tick);
};

const _stopTimer = () => {
    if (_rafHandle) { cancelAnimationFrame(_rafHandle); _rafHandle = null; }
    clearInterval(_pollTimer); _pollTimer = null;
    document.getElementById('ai-status-panel')?.classList.add('hidden');
};

const _stepLabel = s => ({
    preparing:      'Preparing context…',
    calling_api:    'Calling API…',
    received:       'Processing response…',
    executing_tool: 'Executing tool…',
}[s] || s || '');

export const isActive = () => _active;

export const closeAiModal = (delay = 0) => {
    if (!_active) return;
    _active = false;
    _stopTimer();
    const modal = document.getElementById('ai-processing-modal');
    if (!modal) return;
    if (delay > 0) {
        _closeTimer = setTimeout(() => modal.classList.add('hidden'), delay);
    } else {
        clearTimeout(_closeTimer);
        modal.classList.add('hidden');
    }
};

export const checkAiModal = (messages) => {
    if (_active && !messages.some(m => m.pending)) closeAiModal(1000);
};

export const startStatusPoll = (filePath, pendingId) => {
    const panel  = document.getElementById('ai-status-panel');
    const stepEl = document.getElementById('ai-status-step');
    const metaEl = document.getElementById('ai-status-meta');
    if (!panel) return;
    panel.classList.remove('hidden');
    if (stepEl) stepEl.textContent = 'Starting…';
    if (metaEl) metaEl.textContent = '';
    _pollTimer = setInterval(async () => {
        if (!_active) { _stopTimer(); return; }
        let res;
        try { res = await api.call('get_ai_status', { file: filePath, id: pendingId }); }
        catch (e) { console.warn('[ai-status] poll error', e); return; }
        if (!res?.success) return;
        if (!res.data) { if (stepEl && !stepEl.textContent) stepEl.textContent = 'Starting…'; return; }
        const d = res.data;
        let step = _stepLabel(d.step);
        if (d.step === 'calling_api' || d.step === 'received')
            step += ` (call ${d.api_calls}${d.last_call_ms ? ', ' + (d.last_call_ms / 1000).toFixed(1) + 's' : ''})`;
        if (d.step === 'executing_tool' && d.tool)
            step += `: ${d.tool}`;
        if (stepEl) stepEl.textContent = step;
        const parts = [`model: ${d.model || '?'}`, `ctx: ${d.context_messages ?? '?'} msgs`];
        if (d.tools_used?.length) parts.push(`tools: ${[...new Set(d.tools_used)].join(', ')}`);
        if (metaEl) metaEl.textContent = parts.join(' · ');
    }, 500);
};

export const openAiModal = (botName, onCancel) => {
    clearTimeout(_closeTimer);
    _active = true;
    const nameEl    = document.getElementById('ai-processing-name');
    const cancelBtn = document.getElementById('ai-processing-cancel-btn');
    const modal     = document.getElementById('ai-processing-modal');
    if (nameEl) nameEl.textContent = botName;
    if (modal) modal.classList.remove('hidden');
    _startTimer();
    if (cancelBtn && onCancel) {
        cancelBtn.addEventListener('click', () => { onCancel(); closeAiModal(); }, { once: true });
    }
};
