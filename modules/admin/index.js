import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { invalidateUsers } from '../core/users.js';
import { invalidateMcpServers } from '../core/mcp_servers.js';
import { t } from '../i18n/index.js';

// ── State ─────────────────────────────────────────────────────────────────────

let users        = [];
let requests     = [];
let aiUsers      = [];
let apiAccounts  = [];
let allSpaces    = []; // loaded once when admin lightbox opens
let isDirty     = false;
const ROLES     = ['admin', 'editor', 'reader'];
const AI_ROLES  = ['editor', 'reader'];
const ME_SUB    = () => window.WIKI_USER_SUB || '';

const escHtml = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

// ── Helpers ───────────────────────────────────────────────────────────────────

const markDirty = (dirty = true) => {
    isDirty = dirty;
    document.getElementById('admin-dirty-notice')?.classList.toggle('hidden', !dirty);
    const btn = document.getElementById('admin-save-btn');
    if (btn) btn.disabled = !dirty;
};

const updateRequestsBadge = () => {
    const badge = document.getElementById('admin-requests-badge');
    if (!badge) return;
    const pending = requests.filter(r => r.status === 'pending').length;
    if (pending > 0) {
        badge.textContent = pending;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
};

const TAB_GROUPS = {
    users:      ['users', 'requests', 'api'],
    ai:         ['ai', 'jobs', 'mcp'],
    monitoring: ['logs', 'errorlog', 'diagnostics'],
    content:    ['reindex', 'deleted'],
};
const lastTabInGroup = { users: 'users', ai: 'ai', monitoring: 'logs', content: 'reindex' };

const switchGroup = (groupName) => {
    document.querySelectorAll('.admin-group').forEach(g =>
        g.classList.toggle('active', g.dataset.group === groupName));
    document.querySelectorAll('.admin-tab').forEach(tab =>
        tab.classList.toggle('hidden', tab.dataset.group !== groupName));
    switchTab(lastTabInGroup[groupName] || TAB_GROUPS[groupName][0]);
};

const switchTab = (name) => {
    document.querySelectorAll('.admin-tab').forEach(tb =>
        tb.classList.toggle('active', tb.dataset.tab === name));
    document.querySelectorAll('.admin-pane').forEach(p =>
        p.classList.toggle('hidden', p.id !== `admin-pane-${name}`));
    document.getElementById('admin-footer-users')?.classList.toggle('hidden',        name !== 'users');
    document.getElementById('admin-footer-requests')?.classList.toggle('hidden',     name !== 'requests');
    document.getElementById('admin-footer-logs')?.classList.toggle('hidden',         name !== 'logs');
    document.getElementById('admin-footer-errorlog')?.classList.toggle('hidden',      name !== 'errorlog');
    document.getElementById('admin-footer-diagnostics')?.classList.toggle('hidden',  name !== 'diagnostics');
    document.getElementById('admin-footer-ai')?.classList.toggle('hidden',           name !== 'ai');
    document.getElementById('admin-footer-api')?.classList.toggle('hidden',           name !== 'api');
    document.getElementById('admin-footer-jobs')?.classList.toggle('hidden',         name !== 'jobs');
    document.getElementById('admin-footer-mcp')?.classList.toggle('hidden',          name !== 'mcp');
    document.getElementById('admin-footer-deleted')?.classList.toggle('hidden',      name !== 'deleted');
    document.getElementById('admin-footer-reindex')?.classList.toggle('hidden',      name !== 'reindex');
    const activeTab = document.querySelector(`.admin-tab[data-tab="${name}"]`);
    if (activeTab?.dataset.group) lastTabInGroup[activeTab.dataset.group] = name;
    if (name === 'logs')        loadLogFiles();
    if (name === 'requests')    loadRequests();
    if (name === 'errorlog')    loadErrorLogFiles();
    if (name === 'diagnostics') loadDiagnostics();
    if (name === 'ai')          loadAiUsers();
    if (name === 'api')         loadApiAccounts();
    if (name === 'jobs')        loadAgentJobs();
    if (name === 'deleted')     loadDeletedPages();
    if (name === 'reindex')     loadReindexPane();
    if (name === 'mcp')         loadMcpServers();
};

// ── Users tab ─────────────────────────────────────────────────────────────────

const spacesLabel = (spaces) => {
    if (spaces === null || spaces === undefined) return t('admin.users.all-spaces');
    if (spaces.length === 0) return t('admin.users.none-label');
    return spaces.join(', ');
};

// Standalone Spaces dropdown for the AI User / API Account forms (as opposed to
// makeSpacesCell below, which is bound to a row in the human Users table).
// Renders HTML via spacesFieldHtml(prefix, current), then wireSpacesField(prefix)
// wires it up after insertion, and readSpacesField(prefix) reads the selection
// back out of the DOM at save time — matching how the MCP-server checkboxes on
// the AI User form are read (see .ai-f-mcp-cb), rather than tracking JS state.
const spacesFieldHtml = (prefix, current) => {
    if (!allSpaces.length) return `<span class="admin-spaces-all">${t('admin.users.all-spaces')}</span>`;
    const isAll = current === null || current === undefined;
    return `
        <div class="admin-spaces-wrap">
            <button type="button" id="${prefix}-spaces-btn" class="admin-spaces-btn">${escHtml(spacesLabel(current ?? null))}</button>
            <div id="${prefix}-spaces-dropdown" class="admin-spaces-dropdown hidden">
                <label class="admin-spaces-option">
                    <input type="checkbox" id="${prefix}-spaces-all" ${isAll ? 'checked' : ''}>
                    <span>${t('admin.users.all-spaces')}</span>
                </label>
                <div class="admin-spaces-sep"></div>
                ${allSpaces.map(sp => `
                    <label class="admin-spaces-option">
                        <input type="checkbox" class="${prefix}-spaces-cb" value="${escHtml(sp)}" ${!isAll && current.includes(sp) ? 'checked' : ''} ${isAll ? 'disabled' : ''}>
                        <span>${escHtml(sp)}</span>
                    </label>`).join('')}
            </div>
        </div>`;
};

const wireSpacesField = (prefix) => {
    const btn      = document.getElementById(`${prefix}-spaces-btn`);
    const dropdown = document.getElementById(`${prefix}-spaces-dropdown`);
    const allCb    = document.getElementById(`${prefix}-spaces-all`);
    if (!btn || !dropdown || !allCb) return;
    const updateLabel = () => {
        const selected = [...dropdown.querySelectorAll(`.${prefix}-spaces-cb:checked`)].map(c => c.value);
        btn.textContent = spacesLabel(allCb.checked ? null : selected);
    };
    btn.addEventListener('click', (e) => { e.stopPropagation(); dropdown.classList.toggle('hidden'); });
    allCb.addEventListener('change', () => {
        dropdown.querySelectorAll(`.${prefix}-spaces-cb`).forEach(cb => { cb.checked = false; cb.disabled = allCb.checked; });
        updateLabel();
    });
    dropdown.querySelectorAll(`.${prefix}-spaces-cb`).forEach(cb => cb.addEventListener('change', updateLabel));
};

// Returns null (all spaces) or a string[] of selected space names.
const readSpacesField = (prefix) => {
    const allCb = document.getElementById(`${prefix}-spaces-all`);
    if (!allCb || allCb.checked) return null;
    return [...document.querySelectorAll(`.${prefix}-spaces-cb:checked`)].map(cb => cb.value);
};

// Extra HTTP headers editor — an editable list of name/value pairs sent on every
// outbound request (AI model endpoint or MCP server). Handles gateway auth such
// as Cloudflare Access (CF-Access-Client-Id / CF-Access-Client-Secret). Rendered
// via extraHeadersFieldHtml(prefix, headers), wired with wireExtraHeadersField,
// and read back as a {name,value}[] list by readExtraHeadersField at save time.
const extraHeaderRowHtml = (prefix, name = '', value = '') => `
    <div class="admin-xhdr-row" style="display:flex;gap:0.4rem;margin-bottom:0.35rem">
        <input type="text" class="${prefix}-xhdr-name form-control" value="${escHtml(name)}" placeholder="Header name" style="flex:1">
        <input type="text" class="${prefix}-xhdr-value form-control" value="${escHtml(value)}" placeholder="Value" style="flex:1.4">
        <button type="button" class="${prefix}-xhdr-del btn btn-icon btn-secondary" title="Remove header" aria-label="Remove header">&times;</button>
    </div>`;

const extraHeadersFieldHtml = (prefix, headers) => {
    const rows = (Array.isArray(headers) ? headers : [])
        .map(h => extraHeaderRowHtml(prefix, h?.name ?? '', h?.value ?? '')).join('');
    return `
        <div id="${prefix}-xhdr-rows">${rows}</div>
        <button type="button" id="${prefix}-xhdr-add" class="btn btn-sm btn-secondary">+ Add header</button>`;
};

const wireExtraHeadersField = (prefix) => {
    const rows   = document.getElementById(`${prefix}-xhdr-rows`);
    const addBtn = document.getElementById(`${prefix}-xhdr-add`);
    if (!rows || !addBtn) return;
    addBtn.addEventListener('click', () => rows.insertAdjacentHTML('beforeend', extraHeaderRowHtml(prefix)));
    rows.addEventListener('click', (e) => {
        if (e.target.closest(`.${prefix}-xhdr-del`)) e.target.closest('.admin-xhdr-row')?.remove();
    });
};

// Returns a {name, value}[] list, dropping rows whose name is blank.
const readExtraHeadersField = (prefix) => {
    const rows = document.getElementById(`${prefix}-xhdr-rows`);
    if (!rows) return [];
    return [...rows.querySelectorAll('.admin-xhdr-row')].map(r => ({
        name:  r.querySelector(`.${prefix}-xhdr-name`)?.value.trim() || '',
        value: r.querySelector(`.${prefix}-xhdr-value`)?.value ?? '',
    })).filter(h => h.name !== '');
};

const makeSpacesCell = (u, i) => {
    const isAdmin = u.role === 'admin';
    const td = document.createElement('td');

    if (isAdmin || !allSpaces.length) {
        const note = document.createElement('span');
        note.className = 'admin-spaces-all';
        note.textContent = isAdmin ? t('admin.users.all-admin') : spacesLabel(u.spaces ?? null);
        td.appendChild(note);
        return td;
    }

    // Dropdown wrapper
    const wrap = document.createElement('div');
    wrap.className = 'admin-spaces-wrap';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'admin-spaces-btn';
    btn.textContent = spacesLabel(u.spaces ?? null);

    const dropdown = document.createElement('div');
    dropdown.className = 'admin-spaces-dropdown hidden';

    // "All spaces" option
    const allRow = document.createElement('label');
    allRow.className = 'admin-spaces-option';
    const allCb = document.createElement('input');
    allCb.type = 'checkbox';
    allCb.checked = (u.spaces === null || u.spaces === undefined);
    const allLbl = document.createElement('span');
    allLbl.textContent = t('admin.users.all-spaces');
    allRow.append(allCb, allLbl);
    dropdown.appendChild(allRow);

    const sep = document.createElement('div');
    sep.className = 'admin-spaces-sep';
    dropdown.appendChild(sep);

    // Individual space checkboxes
    allSpaces.forEach(space => {
        const row = document.createElement('label');
        row.className = 'admin-spaces-option';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = space;
        cb.checked = (u.spaces === null || u.spaces === undefined) ? false : u.spaces.includes(space);
        cb.disabled = allCb.checked;
        const lbl = document.createElement('span');
        lbl.textContent = space;
        row.append(cb, lbl);

        cb.addEventListener('change', () => {
            const selected = [...dropdown.querySelectorAll('input[type=checkbox][value]')]
                .filter(c => c.checked).map(c => c.value);
            users[i] = { ...users[i], spaces: selected };
            btn.textContent = spacesLabel(selected);
            markDirty();
        });

        dropdown.appendChild(row);
    });

    allCb.addEventListener('change', () => {
        const isAll = allCb.checked;
        dropdown.querySelectorAll('input[type=checkbox][value]').forEach(c => {
            c.checked = false;
            c.disabled = isAll;
        });
        users[i] = { ...users[i], spaces: isAll ? null : [] };
        btn.textContent = spacesLabel(isAll ? null : []);
        markDirty();
    });

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });

    wrap.append(btn, dropdown);
    td.appendChild(wrap);
    return td;
};

const renderUsers = () => {
    const container = document.getElementById('admin-users-table');
    if (!container) return;

    if (!users.length) {
        container.innerHTML = `<p class="admin-empty">${t('admin.users.none')}</p>`;
        return;
    }

    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = '<thead><tr><th>Name</th><th>Email</th><th>Auth</th><th>Role</th><th>Spaces</th><th></th></tr></thead>';
    const tbody = document.createElement('tbody');

    users.forEach((u, i) => {
        const isMe = !!(u.uid && u.uid === window.WIKI_USER_UID);
        const tr = document.createElement('tr');
        if (isMe) tr.classList.add('admin-row-self');

        const tdName = document.createElement('td');
        tdName.className = 'admin-td-name';
        if (u.auth === 'otp') {
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control admin-inline-input';
            inp.value = u.name || '';
            inp.placeholder = 'Name';
            inp.addEventListener('input', () => { users[i] = { ...users[i], name: inp.value }; markDirty(); inp.style.borderColor = ''; });
            tdName.appendChild(inp);
        } else {
            tdName.textContent = u.name || '—';
            if (isMe) {
                const badge = document.createElement('span');
                badge.className = 'admin-you-badge';
                badge.textContent = t('admin.users.you');
                tdName.appendChild(badge);
            }
        }

        const tdEmail = document.createElement('td');
        tdEmail.className = 'admin-td-email';
        if (u.auth === 'otp') {
            const inp = document.createElement('input');
            inp.type = 'email';
            inp.className = 'form-control admin-inline-input';
            inp.value = u.email || '';
            inp.placeholder = 'email@example.com';
            inp.addEventListener('input', () => { users[i] = { ...users[i], email: inp.value }; markDirty(); inp.style.borderColor = ''; });
            tdEmail.appendChild(inp);
        } else {
            tdEmail.textContent = u.email || '—';
        }

        const tdAuth = document.createElement('td');
        const authVal = u.auth || 'oidc';
        tdAuth.innerHTML = `<span class="admin-auth-badge admin-auth-${authVal}">${authVal.toUpperCase()}</span>`;

        const tdRole = document.createElement('td');
        const sel = document.createElement('select');
        sel.className = 'form-control admin-role-select';
        if (isMe) sel.title = t('admin.users.own-role');
        if (isMe) sel.disabled = true;
        ROLES.forEach(r => {
            const opt = document.createElement('option');
            opt.value = r;
            opt.textContent = r.charAt(0).toUpperCase() + r.slice(1);
            opt.selected = u.role === r;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => {
            users[i] = { ...users[i], role: sel.value };
            markDirty();
            // Re-render spaces cell: admin role locks it to "All"
            renderUsers();
        });
        tdRole.appendChild(sel);

        const tdSpaces = makeSpacesCell(u, i);

        const tdDel = document.createElement('td');
        if (!isMe) {
            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-sm btn-danger admin-del-btn';
            delBtn.title = t('admin.users.remove');
            delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            delBtn.addEventListener('click', () => { users.splice(i, 1); markDirty(); renderUsers(); });
            tdDel.appendChild(delBtn);
        }

        tr.append(tdName, tdEmail, tdAuth, tdRole, tdSpaces, tdDel);
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.innerHTML = '';
    container.appendChild(table);

};

const loadUsers = async () => {
    const container = document.getElementById('admin-users-table');
    if (container) container.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    // Load spaces and users in parallel
    const [spacesResult, usersResult] = await Promise.all([
        api.call('list_spaces'),
        api.call('admin_get_users'),
    ]);
    allSpaces = spacesResult.data || [];
    if (usersResult.success) {
        users = usersResult.data || [];
        renderUsers();
    } else {
        if (container) container.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
    }
};

const saveUsers = async () => {
    // Validate OTP users before sending
    const invalid = users.filter(u => u.auth === 'otp' && (!u.name?.trim() || !u.email?.trim()));
    if (invalid.length) {
        showToast('OTP users require both a name and an email address.', 'error');
        // Highlight the first offending row
        document.querySelectorAll('#admin-users-table .admin-inline-input').forEach(inp => {
            inp.style.borderColor = (!inp.value.trim()) ? '#fc8181' : '';
        });
        return;
    }

    const btn = document.getElementById('admin-save-btn');
    btn.disabled = true;
    btn.textContent = t('admin.users.saving');
    const result = await api.call('admin_save_users', { users: JSON.stringify(users) }, 'POST');
    btn.textContent = t('admin.save-btn');
    if (result.success) {
        markDirty(false);
        invalidateUsers();
        showToast(t('admin.users.saved'), 'success');
    } else {
        btn.disabled = false;
        showToast(result.message || t('admin.users.save-failed'), 'error');
    }
};

// ── Requests tab ──────────────────────────────────────────────────────────────

const renderRequests = () => {
    const container = document.getElementById('admin-requests-table');
    if (!container) return;

    const countEl = document.getElementById('admin-requests-count');
    const pending = requests.filter(r => r.status === 'pending');
    const denied  = requests.filter(r => r.status === 'denied');

    if (!requests.length) {
        container.innerHTML = `<p class="admin-empty">${t('admin.req.none')}</p>`;
        if (countEl) countEl.textContent = '';
        return;
    }
    if (countEl) countEl.textContent = `${pending.length} ${t('admin.req.pending')}, ${denied.length} ${t('admin.req.denied')}`;

    container.innerHTML = '';

    const buildRow = (r) => {
        const tr = document.createElement('tr');
        const isPending = r.status === 'pending';

        const tdName = document.createElement('td');
        tdName.className = 'admin-td-email';
        tdName.textContent = r.name || '—';

        const tdEmail = document.createElement('td');
        tdEmail.className = 'admin-td-email';
        tdEmail.textContent = r.email || '—';

        const tdDate = document.createElement('td');
        tdDate.className = 'admin-log-time';
        tdDate.textContent = r.requested_at ? new Date(r.requested_at).toLocaleString() : '—';

        const tdStatus = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `admin-log-badge ${isPending ? 'log-ok' : 'log-denied'}`;
        badge.textContent = isPending ? t('admin.req.pending') : t('admin.req.denied');
        tdStatus.appendChild(badge);

        const tdActions = document.createElement('td');
        tdActions.className = 'admin-requests-actions';

        if (isPending) {
            const roleWrap = document.createElement('span');
            roleWrap.className = 'admin-approve-role';
            const roleLabel = document.createElement('label');
            roleLabel.textContent = t('admin.req.role');
            const roleSel = document.createElement('select');
            roleSel.className = 'form-control admin-role-select';
            ROLES.forEach(rr => {
                const opt = document.createElement('option');
                opt.value = rr;
                opt.textContent = rr.charAt(0).toUpperCase() + rr.slice(1);
                opt.selected = rr === 'editor';
                roleSel.appendChild(opt);
            });
            roleWrap.append(roleLabel, roleSel);

            const approveBtn = document.createElement('button');
            approveBtn.className = 'btn btn-sm btn-green';
            approveBtn.textContent = t('admin.req.approve');
            approveBtn.addEventListener('click', async () => {
                approveBtn.disabled = true;
                const res = await api.call('admin_approve_request',
                    { sub: r.sub, role: roleSel.value }, 'POST');
                if (res.success) {
                    invalidateUsers();
                    showToast(t('admin.req.approved', { name: r.name || r.email, role: roleSel.value }), 'success');
                    await loadRequests();
                    await loadUsers();
                } else {
                    approveBtn.disabled = false;
                    showToast(res.message || 'Failed to approve', 'error');
                }
            });

            const denyBtn = document.createElement('button');
            denyBtn.className = 'btn btn-sm btn-danger';
            denyBtn.textContent = t('admin.req.deny');
            denyBtn.addEventListener('click', async () => {
                const ok = await confirmModal(t('admin.req.deny-confirm', { name: r.name || r.email }), {
                    confirmLabel: t('admin.req.deny'), dangerous: true,
                });
                if (!ok) return;
                denyBtn.disabled = true;
                const res = await api.call('admin_deny_request', { sub: r.sub }, 'POST');
                if (res.success) {
                    showToast(t('admin.req.denied-msg', { name: r.name || r.email }), 'success');
                    await loadRequests();
                } else {
                    denyBtn.disabled = false;
                    showToast(res.message || 'Failed to deny', 'error');
                }
            });

            tdActions.append(roleWrap, approveBtn, denyBtn);
        } else {
            // Denied — allow re-approval
            const reApproveBtn = document.createElement('button');
            reApproveBtn.className = 'btn btn-sm btn-secondary';
            reApproveBtn.textContent = t('admin.req.reapprove');
            reApproveBtn.addEventListener('click', async () => {
                reApproveBtn.disabled = true;
                const res = await api.call('admin_approve_request',
                    { sub: r.sub, role: 'editor' }, 'POST');
                if (res.success) {
                    invalidateUsers();
                    showToast(t('admin.req.re-approved', { name: r.name || r.email }), 'success');
                    await loadRequests();
                    await loadUsers();
                } else {
                    reApproveBtn.disabled = false;
                    showToast(res.message || 'Failed', 'error');
                }
            });
            tdActions.appendChild(reApproveBtn);
        }

        tr.append(tdName, tdEmail, tdDate, tdStatus, tdActions);
        return tr;
    };

    if (requests.length) {
        const table = document.createElement('table');
        table.className = 'admin-table';
        table.innerHTML = '<thead><tr><th>Name</th><th>Email</th><th>Requested</th><th>Status</th><th></th></tr></thead>';
        const tbody = document.createElement('tbody');
        requests.forEach(r => tbody.appendChild(buildRow(r)));
        table.appendChild(tbody);
        container.appendChild(table);
    }
};

const loadRequests = async () => {
    const container = document.getElementById('admin-requests-table');
    if (container) container.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const result = await api.call('admin_get_user_requests');
    if (result.success) {
        requests = result.data || [];
        updateRequestsBadge();
        renderRequests();
    } else {
        if (container) container.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
    }
};

// ── Log tab ───────────────────────────────────────────────────────────────────

const EVENT_CLASS = {
    LOGIN_OK:          'log-ok',
    LOGIN_DENIED:      'log-denied',
    LOGIN_ERROR:       'log-error',
    LOGOUT:            'log-logout',
    ACCESS_REQUESTED:  'log-ok',
    USER_APPROVED:     'log-ok',
    USER_DENIED:       'log-denied',
};

const renderLogEntries = (entries) => {
    const container = document.getElementById('admin-log-entries');
    const countEl   = document.getElementById('admin-log-count');
    const suffix = entries.length === 1 ? t('admin.logs.entry') : t('admin.logs.entries');
    if (countEl) countEl.textContent = t('admin.logs.count', { n: entries.length, suffix });

    if (!entries.length) {
        container.innerHTML = `<p class="admin-empty">${t('admin.logs.no-entries')}</p>`;
        return;
    }

    const table = document.createElement('table');
    table.className = 'admin-log-table';
    table.innerHTML = '<thead><tr><th>Time</th><th>Event</th><th>Source</th><th>Name</th><th>IP</th><th>Detail</th></tr></thead>';
    const tbody = document.createElement('tbody');

    entries.forEach(e => {
        const tr = document.createElement('tr');
        const cls    = EVENT_CLASS[e.event] || 'log-unknown';
        const source = e.sub  ? e.sub.split('|')[0]  : '-';
        const name   = e.name || '-';
        const detail = e.detail && e.detail !== '-'
            ? `<span class="admin-log-detail" title="${e.detail.replace(/"/g, '&quot;')}">…</span>` : '';
        tr.innerHTML = `
            <td class="admin-log-time">${e.time}</td>
            <td><span class="admin-log-badge ${cls}">${e.event}</span></td>
            <td class="admin-log-source">${source}</td>
            <td class="admin-log-name">${name}</td>
            <td class="admin-log-ip">${e.ip}</td>
            <td>${detail}</td>`;
        const detailEl = tr.querySelector('.admin-log-detail');
        if (detailEl) detailEl.addEventListener('click', () => alert(e.detail));
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.innerHTML = '';
    container.appendChild(table);
};

const loadLogContent = async (filename) => {
    if (!filename) return;
    const container = document.getElementById('admin-log-entries');
    container.innerHTML = `<p class="admin-loading">${t('admin.logs.loading')}</p>`;
    const result = await api.call('admin_get_log_content', { file: filename });
    if (result.success) {
        renderLogEntries(result.data);
    } else {
        container.innerHTML = `<p class="admin-empty">${t('admin.diag.failed', { error: result.message || '' })}</p>`;
    }
};

const loadLogFiles = async () => {
    const select  = document.getElementById('admin-log-date');
    const entries = document.getElementById('admin-log-entries');
    select.innerHTML = `<option value="">${t('admin.logs.loading')}</option>`;
    if (entries) entries.innerHTML = '';

    const result = await api.call('admin_get_logs');
    if (!result.success || !result.data.length) {
        select.innerHTML = `<option value="">${t('admin.logs.no-files')}</option>`;
        if (entries) entries.innerHTML = `<p class="admin-empty">${t('admin.logs.none')}</p>`;
        return;
    }

    select.innerHTML = result.data.map(f =>
        `<option value="${f.file}">${f.date}</option>`
    ).join('');
    loadLogContent(result.data[0].file);
};

// ── Diagnostics tab ───────────────────────────────────────────────────────────

const renderDiagLog = (outputId, result) => {
    const el = document.getElementById(outputId);
    if (!el) return;
    if (!result.configured) {
        el.innerHTML = `<p class="admin-diag-hint">${t('admin.diag.not-configured', { hint: result.hint })}</p>`;
        return;
    }
    if (result.message) {
        el.innerHTML = `<p class="admin-empty">${result.message}</p>`;
        return;
    }
    if (!result.lines.length) {
        el.innerHTML = `<p class="admin-empty">${t('admin.diag.empty')}</p>`;
        return;
    }
    const pre = document.createElement('pre');
    pre.className = 'admin-diag-pre';
    pre.textContent = result.lines.join('\n');
    el.innerHTML = '';
    el.appendChild(pre);
    pre.scrollTop = pre.scrollHeight;
};

const loadDiagLog = async (type, outputId) => {
    const el = document.getElementById(outputId);
    if (el) el.innerHTML = `<p class="admin-loading">${t('admin.diag.loading')}</p>`;
    const result = await api.call('admin_get_diag_log', { type });
    if (result.success) {
        renderDiagLog(outputId, result);
    } else {
        if (el) el.innerHTML = `<p class="admin-empty">${t('admin.diag.failed', { error: result.message || '' })}</p>`;
    }
};

const loadDiagnostics = () => {
    loadDiagLog('php',          'admin-diag-php-output');
    loadDiagLog('nginx_error',  'admin-diag-nginx-error-output');
    loadDiagLog('nginx_access', 'admin-diag-nginx-access-output');
};

const loadErrorLogFiles = async () => {
    const sel = document.getElementById('admin-diag-error-log-select');
    const out = document.getElementById('admin-diag-error-log-output');
    if (!sel || !out) return;
    const result = await api.call('admin_get_error_logs');
    if (!result.success) { out.innerHTML = `<p class="admin-empty">${t('admin.errlog.failed')}</p>`; return; }
    sel.innerHTML = '';
    if (!result.data.length) {
        sel.innerHTML = `<option value="">${t('admin.errlog.no-files')}</option>`;
        out.innerHTML = `<p class="admin-empty">${t('admin.errlog.none')}</p>`;
        return;
    }
    result.data.forEach(f => {
        const opt = document.createElement('option');
        opt.value = f.file;
        opt.textContent = f.date;
        sel.appendChild(opt);
    });
    loadErrorLogContent(result.data[0].file);
};

const loadErrorLogContent = async (filename) => {
    const out = document.getElementById('admin-diag-error-log-output');
    if (!out) return;
    out.innerHTML = `<p class="admin-loading">${t('admin.diag.loading')}</p>`;
    const result = await api.call('admin_get_error_log_content', { file: filename });
    if (!result.success) { out.innerHTML = `<p class="admin-empty">${t('admin.diag.failed', { error: result.message || '' })}</p>`; return; }
    if (!result.data.length) { out.innerHTML = `<p class="admin-empty">${t('admin.errlog.no-entries')}</p>`; return; }
    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = '<thead><tr><th>Time</th><th>Page</th><th>Actor</th><th>IP</th><th>Message</th></tr></thead>';
    const tbody = document.createElement('tbody');
    result.data.forEach(e => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td style="white-space:nowrap">${e.time}</td><td>${e.page}</td><td>${e.actor}</td><td>${e.ip}</td><td>${e.message}</td>`;
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    out.innerHTML = '';
    out.appendChild(table);
};

// ── AI Agent Instructions lightbox ────────────────────────────────────────────

const showAgentInstructions = async (token) => {
    const lightbox  = document.getElementById('agent-instructions-lightbox');
    const textarea  = document.getElementById('agent-instructions-text');
    const copyBtn   = document.getElementById('agent-instructions-copy-btn');
    const closeBtn  = document.getElementById('agent-instructions-close-btn');
    if (!lightbox || !textarea) return;

    textarea.value = t('admin.ai.agent-instructions-loading');
    lightbox.classList.remove('hidden');

    const result = await api.call('api_agent_instructions', { token });
    if (result.success) {
        textarea.value = result.instructions;
    } else {
        textarea.value = result.message || 'Failed to load instructions.';
    }

    const close = () => lightbox.classList.add('hidden');
    closeBtn.onclick = close;
    lightbox.onclick = (e) => { if (e.target === lightbox) close(); };

    copyBtn.onclick = async () => {
        try {
            await navigator.clipboard.writeText(textarea.value);
            const orig = copyBtn.textContent;
            copyBtn.textContent = t('admin.ai.agent-instructions-copied');
            setTimeout(() => { copyBtn.textContent = orig; }, 2000);
        } catch {
            textarea.select();
            document.execCommand('copy');
        }
    };
};

// ── AI Users tab ──────────────────────────────────────────────────────────────

const renderAiUserList = () => {
    const container = document.getElementById('admin-ai-list');
    if (!container) return;

    if (!aiUsers.length) {
        container.innerHTML = `<p class="admin-empty">${t('admin.ai.none')}</p>`;
        return;
    }

    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = `<thead><tr><th>${t('admin.ai.name')}</th><th>${t('admin.ai.role')}</th><th>Spaces</th><th>Model</th><th>API URL</th><th></th></tr></thead>`;
    const tbody = document.createElement('tbody');

    aiUsers.forEach(u => {
        const tr = document.createElement('tr');
        const cfg = u.ai_config || {};

        const tdName = document.createElement('td');
        tdName.className = 'admin-td-name';
        tdName.innerHTML = escHtml(u.name) + ' <span class="admin-ai-badge">AI</span>';

        const tdRole = document.createElement('td');
        tdRole.textContent = u.role || 'editor';

        const tdSpaces = document.createElement('td');
        tdSpaces.className = 'admin-spaces-all';
        tdSpaces.textContent = spacesLabel(u.spaces ?? null);

        const tdModel = document.createElement('td');
        tdModel.className = 'admin-log-source';
        const providerLabel = cfg.provider === 'anthropic' ? t('admin.ai.anthropic').split(' ')[0]
            : (cfg.provider === 'openai-responses' ? 'OpenAI Responses' : 'OpenAI');
        tdModel.textContent = cfg.model ? `${cfg.model} (${providerLabel})` : `— (${providerLabel})`;

        const tdUrl = document.createElement('td');
        tdUrl.className = 'admin-td-email';
        const urlText = cfg.api_url || '—';
        tdUrl.textContent = urlText.length > 40 ? urlText.slice(0, 40) + '…' : urlText;
        tdUrl.title = urlText;

        const tdActions = document.createElement('td');
        tdActions.style.cssText = 'white-space:nowrap;display:flex;gap:4px;align-items:center;';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-secondary';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => openAiUserForm(u));

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-sm btn-danger admin-del-btn';
        delBtn.title = 'Delete AI user';
        delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        delBtn.addEventListener('click', () => deleteAiUser(u));

        tdActions.append(editBtn, delBtn);
        tr.append(tdName, tdRole, tdSpaces, tdModel, tdUrl, tdActions);
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.innerHTML = '';
    container.appendChild(table);
};

const loadAiUsers = async () => {
    const container = document.getElementById('admin-ai-list');
    if (container) container.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const result = await api.call('admin_get_ai_users');
    if (result.success) {
        aiUsers = result.data || [];
        renderAiUserList();
    } else {
        if (container) container.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
    }
};

let _llmProviders = null;
const getLlmProviders = async () => {
    if (_llmProviders) return _llmProviders;
    const res = await api.call('get_llm_providers');
    _llmProviders = (res.success && res.data && res.data.length) ? res.data
        : [{ id: 'openai', label: 'OpenAI / compatible', default_url: 'https://api.openai.com/v1/chat/completions' }];
    return _llmProviders;
};

const openAiUserForm = async (u) => {
    const container = document.getElementById('admin-ai-list');
    if (!container) return;
    const isNew = !u;
    const isClone = !!u?._cloneSourceUid;
    const cfg = u?.ai_config || {};
    const providers = await getLlmProviders();
    const curProvider = cfg.provider || 'openai';
    const providerOpts = providers.map(p =>
        `<option value="${escHtml(p.id)}" ${curProvider === p.id ? 'selected' : ''}>${escHtml(p.label)}</option>`
    ).join('');
    const urlByProvider = {};
    providers.forEach(p => { if (p.default_url) urlByProvider[p.id] = p.default_url; });

    container.innerHTML = `
        <div class="admin-ai-form">
            <div style="display:flex;justify-content:flex-start;gap:0.4rem;margin-bottom:0.5rem">
                ${!isNew ? `<button type="button" id="ai-f-agent-instructions-btn" class="btn btn-icon btn-secondary" title="${t('admin.ai.agent-instructions-btn')}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M12 11V6"/><circle cx="12" cy="4" r="2"/><line x1="8" y1="16" x2="8.01" y2="16" stroke-width="3"/><line x1="16" y1="16" x2="16.01" y2="16" stroke-width="3"/></svg>
                </button>` : ''}
                <button type="button" id="ai-f-help-btn" class="btn btn-icon btn-secondary" title="${t('admin.ai.help-btn')}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17" stroke-width="3"/></svg>
                </button>
            </div>
            <div class="admin-ai-form-section">
                <div class="admin-ai-form-row">
                    <div class="form-group">
                        <label>${t('admin.ai.name')}</label>
                        <input type="text" id="ai-f-name" class="form-control" value="${escHtml(u?.name || '')}" placeholder="e.g. Atlas">
                    </div>
                    <div class="form-group">
                        <label>${t('admin.ai.role')}</label>
                        <select id="ai-f-role" class="form-control">
                            ${AI_ROLES.map(r => `<option value="${r}" ${(u?.role || 'editor') === r ? 'selected' : ''}>${r.charAt(0).toUpperCase() + r.slice(1)}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Spaces</label>
                    ${spacesFieldHtml('ai-f', u?.spaces ?? null)}
                    <p class="form-hint">Which Spaces this AI user can access (chat @mentions, Page Chat, Agent Jobs, and MCP all respect this). Defaults to all Spaces.</p>
                </div>
            </div>
            <div class="admin-ai-form-section-header">${t('admin.ai.api-cfg')}</div>
            <div class="admin-ai-form-section">
                <div class="admin-ai-form-row">
                    <div class="form-group">
                        <label>${t('admin.ai.provider')}</label>
                        <select id="ai-f-provider" class="form-control">${providerOpts}</select>
                    </div>
                    <div class="form-group">
                        <label>${t('admin.ai.url')}</label>
                        <input type="url" id="ai-f-url" class="form-control" value="${escHtml(cfg.api_url || '')}" placeholder="https://api.openai.com/v1/chat/completions">
                    </div>
                </div>
                <div class="admin-ai-form-row">
                    <div class="form-group">
                        <label>${t('admin.ai.key')} ${cfg.api_key_set && !isClone ? `<span class="admin-ai-key-set">${t('admin.ai.key-set')}</span>` : ''}</label>
                        <input type="password" id="ai-f-key" class="form-control" placeholder="${isClone ? 'Leave blank to copy key from source' : cfg.api_key_set ? 'Leave blank to keep existing key' : 'sk-…'}">
                        ${isClone ? `<input type="hidden" id="ai-f-source-uid" value="${escHtml(String(u._cloneSourceUid))}">` : '<input type="hidden" id="ai-f-source-uid" value="">'}
                    </div>
                    <div class="form-group">
                        <label>${t('admin.ai.model')}</label>
                        <input type="text" id="ai-f-model" class="form-control" value="${escHtml(cfg.model || '')}" placeholder="gpt-4o">
                    </div>
                </div>
                <div class="admin-ai-form-row" style="align-items:center;gap:0.75rem">
                    <button type="button" id="ai-f-test-btn" class="btn btn-secondary btn-sm">${t('admin.ai.test-btn')}</button>
                    <div id="ai-f-test-result"></div>
                </div>
                <div class="form-group">
                    <label>${t('admin.ai.context')} — <span id="ai-f-context-display" class="admin-ai-temp-val">${cfg.context_messages ?? 10}</span></label>
                    <input type="range" id="ai-f-context" class="admin-ai-temp-slider" value="${cfg.context_messages ?? 10}" min="0" max="20" step="1">
                    <p class="form-hint"><strong>0</strong> sends only the current message — the AI has no memory of earlier exchanges. <strong>10</strong> (default) covers a short focused thread. <strong>20</strong> (maximum) gives the most context but risks confusing the AI with unrelated earlier topics — use <code>/newTopic</code> to reset when switching subjects.</p>
                </div>
                <div class="admin-ai-form-row">
                    <div class="form-group">
                        <label>${t('admin.ai.tokens')}</label>
                        <input type="number" id="ai-f-tokens" class="form-control" value="${cfg.max_tokens ?? 4096}" min="100" max="32000">
                    </div>
                </div>
                <div class="form-group">
                    <label>${t('admin.ai.temp')} — <span id="ai-f-temperature-display" class="admin-ai-temp-val">${cfg.temperature ?? 0.7}</span></label>
                    <input type="range" id="ai-f-temperature" class="admin-ai-temp-slider" value="${cfg.temperature ?? 0.7}" min="0" max="2" step="0.05">
                    <p class="form-hint">Controls randomness. <strong>0.7</strong> (default) balances creativity with coherence — good for most tasks. Lower values (0–0.4) produce more focused, deterministic replies; useful for factual Q&amp;A or structured output. Higher values (1.0–2.0) increase variety and creativity but risk incoherent or off-topic replies.</p>
                </div>
                <div class="form-group">
                    <label>Extra request headers <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                    ${extraHeadersFieldHtml('ai-f', cfg.extra_headers ?? [])}
                    <p class="form-hint">Additional HTTP headers sent on every request to the model endpoint — on top of the provider's auth. Useful for a gateway in front of a self-hosted model, e.g. a Cloudflare Access tunnel needing <code>CF-Access-Client-Id</code> and <code>CF-Access-Client-Secret</code>.</p>
                </div>
            </div>
            <div class="admin-ai-form-section-header">${t('admin.ai.behaviour')}</div>
            <div class="admin-ai-form-section">
                <div class="form-group">
                    <label>${t('admin.ai.prompt')}</label>
                    <textarea id="ai-f-prompt" class="form-control admin-ai-prompt" rows="10" placeholder="${t('admin.ai.prompt-ph')}">${escHtml(cfg.system_prompt || '')}</textarea>
                </div>
            </div>
            <div id="ai-f-mcp-section"></div>
            ${!isNew ? `
            <div class="admin-ai-form-section-header">${t('admin.ai.svc-token')}</div>
            <div class="admin-ai-form-section">
                <div class="admin-ai-token-row">
                    <code id="ai-f-token" class="admin-ai-token">${escHtml(u.service_token || '')}</code>
                    <button type="button" id="ai-f-copy-btn" class="btn btn-sm btn-secondary">${t('admin.ai.copy-btn')}</button>
                    <button type="button" id="ai-f-regen-btn" class="btn btn-sm btn-secondary">${t('admin.ai.regen-btn')}</button>
                </div>
                <p class="admin-ai-token-hint">${t('admin.ai.token-hint')}</p>
            </div>` : ''}
            <div class="admin-ai-form-actions">
                <button type="button" id="ai-f-cancel-btn" class="btn btn-secondary">${t('btn.cancel')}</button>
                ${!isNew ? `<button type="button" id="ai-f-clone-btn" class="btn btn-secondary">Clone…</button>` : ''}
                <button type="button" id="ai-f-save-btn" class="btn btn-green">${t('admin.ai.save-btn')}</button>
            </div>
        </div>`;

    document.getElementById('ai-f-context').addEventListener('input', (e) => {
        document.getElementById('ai-f-context-display').textContent = e.target.value;
    });
    document.getElementById('ai-f-temperature').addEventListener('input', (e) => {
        document.getElementById('ai-f-temperature-display').textContent = parseFloat(e.target.value).toFixed(2).replace(/\.?0+$/, '') || '0';
    });
    // Auto-fill the endpoint from the registry when the provider changes, as long
    // as the field is empty or still holds another provider's default.
    const _provSel = document.getElementById('ai-f-provider');
    const _urlField = document.getElementById('ai-f-url');
    if (_provSel && _urlField) {
        const _allDefaults = Object.values(urlByProvider);
        _provSel.addEventListener('change', () => {
            const cur = _urlField.value.trim();
            if ((cur === '' || _allDefaults.includes(cur)) && urlByProvider[_provSel.value]) {
                _urlField.value = urlByProvider[_provSel.value];
            }
        });
    }
    wireSpacesField('ai-f');
    wireExtraHeadersField('ai-f');

    document.getElementById('ai-f-test-btn').addEventListener('click', async () => {
        const provider = document.getElementById('ai-f-provider')?.value || 'openai';
        const api_url  = document.getElementById('ai-f-url')?.value.trim() || '';
        const api_key  = document.getElementById('ai-f-key')?.value || '';
        const model    = document.getElementById('ai-f-model')?.value.trim() || '';
        const btn = document.getElementById('ai-f-test-btn');
        const out = document.getElementById('ai-f-test-result');
        if (!api_url) { showToast('Enter an API URL first.', 'error'); return; }
        if (!model)   { showToast('Enter a model first.', 'error'); return; }
        if (!api_key && !cfg.api_key_set) { showToast('Enter an API key first.', 'error'); return; }
        btn.disabled = true;
        btn.textContent = 'Testing…';
        const params = { provider, api_url, model, extra_headers: JSON.stringify(readExtraHeadersField('ai-f')) };
        if (api_key) params.api_key = api_key;
        else if (u?.uid) params.uid = String(u.uid);
        const res = await api.call('admin_test_ai_user', params, 'POST');
        btn.disabled = false;
        btn.textContent = t('admin.ai.test-btn');
        out.innerHTML = res.success
            ? `<p style="color:#48bb78;font-size:0.85rem">✓ Connected — reply: "${escHtml(res.reply)}"</p>`
            : `<p style="color:#fc8181;font-size:0.85rem">✗ ${escHtml(res.message || 'Connection failed')}</p>`;
    });

    // Async: load MCP server checkboxes
    (async () => {
        const mcpRes = await api.call('admin_get_mcp_servers');
        const servers = mcpRes.data || [];
        const section = document.getElementById('ai-f-mcp-section');
        if (!section) return;
        if (!servers.length) return;
        const activeMcpIds    = cfg.mcp_server_ids   ?? [];
        const activeMcpInstrs = cfg.mcp_instructions ?? {};
        section.innerHTML = `
            <div class="admin-ai-form-section-header">MCP Servers</div>
            <div class="admin-ai-form-section">
                <p class="form-hint" style="margin-bottom:0.75rem">Select which MCP servers this AI user can call as tools. Add instructions to guide the AI on when and how to use each server's tools.</p>
                ${servers.map(s => {
                    const active = activeMcpIds.includes(s.id);
                    return `<div class="ai-f-mcp-row" style="margin-bottom:0.6rem">
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer">
                            <input type="checkbox" class="ai-f-mcp-cb" value="${escHtml(s.id)}" ${active ? 'checked' : ''}>
                            <span style="font-weight:600">${escHtml(s.name)}</span>
                            <span style="font-size:0.78rem;color:var(--text-muted)">${escHtml(s.url)}</span>
                        </label>
                        <div class="ai-f-mcp-instr-wrap" style="margin-top:0.35rem;padding-left:1.4rem;${active ? '' : 'display:none'}">
                            <textarea class="form-control ai-f-mcp-instr" data-mcp-id="${escHtml(s.id)}" rows="2"
                                placeholder="When should the AI use this server? E.g. &quot;Search Microsoft Learn whenever the user asks about Azure, .NET, or any Microsoft technology.&quot;"
                                style="font-size:0.82rem">${escHtml(activeMcpInstrs[s.id] || '')}</textarea>
                        </div>
                    </div>`;
                }).join('')}
            </div>`;
        section.querySelectorAll('.ai-f-mcp-cb').forEach(cb => {
            cb.addEventListener('change', () => {
                cb.closest('.ai-f-mcp-row').querySelector('.ai-f-mcp-instr-wrap').style.display = cb.checked ? '' : 'none';
            });
        });
    })();

    document.getElementById('ai-f-cancel-btn').addEventListener('click', () => {
        renderAiUserList();
        document.getElementById('admin-footer-ai').querySelector('#admin-ai-add-btn').classList.remove('hidden');
    });

    document.getElementById('ai-f-save-btn').addEventListener('click', () => saveAiUser(u?.uid ?? null));

    if (!isNew) {
        document.getElementById('ai-f-copy-btn').addEventListener('click', async () => {
            const token = document.getElementById('ai-f-token')?.textContent.trim() || '';
            if (!token) return;
            try { await navigator.clipboard.writeText(token); showToast(t('admin.ai.token-copied'), 'success'); }
            catch { showToast(t('select.copy-failed'), 'error'); }
        });
        document.getElementById('ai-f-regen-btn').addEventListener('click', () => regenerateAiToken(u.uid));
        document.getElementById('ai-f-agent-instructions-btn').addEventListener('click', () => {
            const token = document.getElementById('ai-f-token')?.textContent.trim() || '';
            showAgentInstructions(token);
        });
        document.getElementById('ai-f-clone-btn').addEventListener('click', () => cloneAiUser(u));
    }

    document.getElementById('ai-f-help-btn').addEventListener('click', () => {
        const lb = document.getElementById('ai-user-help-lightbox');
        if (!lb) return;
        lb.classList.remove('hidden');
        lb.onclick = (e) => { if (e.target === lb) lb.classList.add('hidden'); };
        lb.querySelector('#ai-user-help-close-btn')?.addEventListener('click', () => lb.classList.add('hidden'), { once: true });
    });

    document.getElementById('admin-footer-ai').querySelector('#admin-ai-add-btn').classList.add('hidden');
};

const saveAiUser = async (uid) => {
    const name     = document.getElementById('ai-f-name')?.value.trim() || '';
    const role     = document.getElementById('ai-f-role')?.value || 'editor';
    const provider = document.getElementById('ai-f-provider')?.value || 'openai';
    const api_url  = document.getElementById('ai-f-url')?.value.trim() || '';
    const api_key  = document.getElementById('ai-f-key')?.value || '';
    const model    = document.getElementById('ai-f-model')?.value.trim() || '';
    const system_prompt    = document.getElementById('ai-f-prompt')?.value || '';
    const context_messages = parseInt(document.getElementById('ai-f-context')?.value || '10', 10);
    const temperature      = parseFloat(document.getElementById('ai-f-temperature')?.value || '0.7');
    const max_tokens       = parseInt(document.getElementById('ai-f-tokens')?.value || '4096', 10);
    const mcp_server_ids   = [...document.querySelectorAll('.ai-f-mcp-cb:checked')].map(cb => cb.value);
    const mcp_instructions = {};
    document.querySelectorAll('.ai-f-mcp-instr').forEach(ta => {
        const v = ta.value.trim();
        if (v) mcp_instructions[ta.dataset.mcpId] = v;
    });
    const extra_headers = readExtraHeadersField('ai-f');
    const spaces = readSpacesField('ai-f');

    if (!name)    { showToast(t('admin.ai.name-req'), 'error'); return; }
    if (!api_url) { showToast('API URL is required.', 'error'); return; }

    const saveBtn = document.getElementById('ai-f-save-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = t('btn.saving');

    const source_uid = document.getElementById('ai-f-source-uid')?.value || '';
    const result = await api.call('admin_save_ai_user', {
        uid: uid !== null ? String(uid) : '',
        source_uid,
        name, role,
        spaces: JSON.stringify(spaces),
        ai_config: JSON.stringify({ provider, api_url, api_key, model, system_prompt, context_messages, temperature, max_tokens, mcp_server_ids, mcp_instructions, extra_headers }),
    }, 'POST');

    saveBtn.disabled = false;
    saveBtn.textContent = t('admin.ai.save-btn');

    if (result.success) {
        showToast(t('admin.ai.saved'), 'success');
        invalidateUsers();
        document.getElementById('admin-footer-ai').querySelector('#admin-ai-add-btn').classList.remove('hidden');
        await loadAiUsers();
    } else {
        showToast(result.message || 'Failed to save', 'error');
    }
};

const cloneAiUser = (source) => {
    const lb       = document.getElementById('clone-ai-lightbox');
    const nameInput = document.getElementById('clone-ai-name');
    const confirmBtn = document.getElementById('clone-ai-confirm-btn');
    const cancelBtn  = document.getElementById('clone-ai-cancel-btn');
    const closeBtn   = document.getElementById('clone-ai-close-btn');
    if (!lb || !nameInput) return;

    nameInput.value = '';
    lb.classList.remove('hidden');
    setTimeout(() => nameInput.focus(), 50);

    const close = () => {
        lb.classList.add('hidden');
        confirmBtn.removeEventListener('click', onConfirm);
        cancelBtn.removeEventListener('click', close);
        closeBtn.removeEventListener('click', close);
        lb.removeEventListener('click', onOverlay);
        nameInput.removeEventListener('keydown', onKey);
    };

    const onConfirm = () => {
        const newName = nameInput.value.trim();
        if (!newName) { nameInput.focus(); return; }
        close();
        openAiUserForm({ ...source, uid: null, name: newName, service_token: '', _cloneSourceUid: source.uid });
    };

    const onOverlay = (e) => { if (e.target === lb) close(); };
    const onKey = (e) => {
        if (e.key === 'Enter') { e.preventDefault(); onConfirm(); }
        if (e.key === 'Escape') close();
    };

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', close);
    closeBtn.addEventListener('click', close);
    lb.addEventListener('click', onOverlay);
    nameInput.addEventListener('keydown', onKey);
};

const deleteAiUser = async (u) => {
    const ok = await confirmModal(t('admin.ai.del-confirm', { name: u.name }), { confirmLabel: t('btn.delete'), dangerous: true });
    if (!ok) return;
    const result = await api.call('admin_delete_ai_user', { uid: String(u.uid) }, 'POST');
    if (result.success) {
        showToast(t('admin.ai.deleted', { name: u.name }), 'success');
        await loadAiUsers();
    } else {
        showToast(result.message || 'Failed to delete', 'error');
    }
};

const regenerateAiToken = async (uid) => {
    const ok = await confirmModal(t('admin.ai.regen-confirm'), { confirmLabel: t('admin.ai.regen-btn'), dangerous: true });
    if (!ok) return;
    const result = await api.call('admin_regenerate_ai_token', { uid: String(uid) }, 'POST');
    if (result.success) {
        const tokenEl = document.getElementById('ai-f-token');
        if (tokenEl) tokenEl.textContent = result.token;
        showToast(t('admin.ai.regenerated'), 'success');
    } else {
        showToast(result.message || 'Failed to regenerate', 'error');
    }
};

// ── API Accounts tab ──────────────────────────────────────────────────────────

const renderApiAccountList = () => {
    const container = document.getElementById('admin-api-list');
    if (!container) return;

    if (!apiAccounts.length) {
        container.innerHTML = `<p class="admin-empty">${t('admin.api.none')}</p>`;
        return;
    }

    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = `<thead><tr><th>Name</th><th>Role</th><th>Spaces</th><th>${t('admin.api.token-col')}</th><th></th></tr></thead>`;
    const tbody = document.createElement('tbody');

    apiAccounts.forEach(u => {
        const tr = document.createElement('tr');

        const tdName = document.createElement('td');
        tdName.className = 'admin-td-name';
        tdName.innerHTML = escHtml(u.name) + ` <span class="admin-api-badge">${t('admin.api.badge')}</span>`;

        const tdRole = document.createElement('td');
        tdRole.textContent = u.role || 'editor';

        const tdSpaces = document.createElement('td');
        tdSpaces.className = 'admin-spaces-all';
        tdSpaces.textContent = spacesLabel(u.spaces ?? null);

        const tdToken = document.createElement('td');
        tdToken.className = 'admin-log-source';
        const tok = u.service_token || '';
        tdToken.textContent = tok ? tok.slice(0, 14) + '…' : '—';
        tdToken.title = tok;

        const tdActions = document.createElement('td');
        tdActions.style.cssText = 'white-space:nowrap;display:flex;gap:4px;align-items:center;';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-secondary';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => openApiAccountForm(u));

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-sm btn-danger admin-del-btn';
        delBtn.title = 'Delete API account';
        delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        delBtn.addEventListener('click', () => deleteApiAccount(u));

        tdActions.append(editBtn, delBtn);
        tr.append(tdName, tdRole, tdSpaces, tdToken, tdActions);
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.innerHTML = '';
    container.appendChild(table);
};

const loadApiAccounts = async () => {
    const container = document.getElementById('admin-api-list');
    if (container) container.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const result = await api.call('admin_get_api_accounts');
    if (result.success) {
        apiAccounts = result.data || [];
        renderApiAccountList();
    } else {
        if (container) container.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
    }
};

const showApiAccountHelp = () => {
    const lb = document.getElementById('api-account-help-lightbox');
    if (!lb) return;
    lb.classList.remove('hidden');
    lb.onclick = (e) => { if (e.target === lb) lb.classList.add('hidden'); };
    lb.querySelector('#api-account-help-close-btn')?.addEventListener('click', () => lb.classList.add('hidden'), { once: true });
};

const openApiAccountForm = (u) => {
    const container = document.getElementById('admin-api-list');
    if (!container) return;
    const isNew = !u;

    container.innerHTML = `
        <div class="admin-ai-form">
            <div style="display:flex;justify-content:flex-start;margin-bottom:0.5rem">
                <button type="button" id="api-f-help-btn" class="btn btn-icon btn-secondary" title="${t('admin.api.help-btn')}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17" stroke-width="3"/></svg>
                </button>
            </div>
            <div class="admin-ai-form-section">
                <div class="form-group">
                    <label>${t('admin.api.name-label')}</label>
                    <input type="text" id="api-f-name" class="form-control" value="${escHtml(u?.name || '')}" placeholder="e.g. CI Bot">
                </div>
                <div class="form-group">
                    <label>${t('admin.api.role-label')}</label>
                    <select id="api-f-role" class="form-control">
                        ${AI_ROLES.map(r => `<option value="${r}" ${(u?.role || 'editor') === r ? 'selected' : ''}>${r.charAt(0).toUpperCase() + r.slice(1)}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Spaces</label>
                    ${spacesFieldHtml('api-f', u?.spaces ?? null)}
                    <p class="form-hint">Which Spaces this API account can access. Defaults to all Spaces.</p>
                </div>
            </div>
            ${!isNew ? `
            <div class="admin-ai-form-section-header">${t('admin.api.token-section')}</div>
            <div class="admin-ai-form-section">
                <div class="admin-ai-token-row">
                    <code id="api-f-token" class="admin-ai-token">${escHtml(u.service_token || '')}</code>
                    <button type="button" id="api-f-regen-btn" class="btn btn-sm btn-secondary">${t('admin.ai.regen-btn')}</button>
                </div>
                <p class="admin-ai-token-hint">${t('admin.api.token-hint')}</p>
            </div>` : ''}
            <div class="admin-ai-form-actions">
                <button type="button" id="api-f-cancel-btn" class="btn btn-secondary">${t('btn.cancel')}</button>
                <button type="button" id="api-f-save-btn" class="btn btn-green">${t('admin.api.save-btn')}</button>
            </div>
        </div>`;

    document.getElementById('api-f-help-btn').addEventListener('click', showApiAccountHelp);
    document.getElementById('api-f-cancel-btn').addEventListener('click', () => {
        renderApiAccountList();
        document.getElementById('admin-api-add-btn').classList.remove('hidden');
    });
    document.getElementById('api-f-save-btn').addEventListener('click', () => saveApiAccount(u?.uid ?? null));
    if (!isNew) {
        document.getElementById('api-f-regen-btn').addEventListener('click', () => regenerateApiToken(u.uid));
    }
    wireSpacesField('api-f');
    document.getElementById('admin-api-add-btn').classList.add('hidden');
};

const saveApiAccount = async (uid) => {
    const name = document.getElementById('api-f-name')?.value.trim() || '';
    const role = document.getElementById('api-f-role')?.value || 'editor';
    const spaces = readSpacesField('api-f');
    if (!name) { showToast(t('admin.ai.name-req'), 'error'); return; }

    const saveBtn = document.getElementById('api-f-save-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = t('btn.saving');

    const result = await api.call('admin_save_api_account', {
        uid: uid !== null ? String(uid) : '',
        name, role,
        spaces: JSON.stringify(spaces),
    }, 'POST');

    saveBtn.disabled = false;
    saveBtn.textContent = t('admin.api.save-btn');

    if (result.success) {
        showToast(t('admin.api.saved'), 'success');
        document.getElementById('admin-api-add-btn').classList.remove('hidden');
        await loadApiAccounts();
    } else {
        showToast(result.message || 'Failed to save', 'error');
    }
};

const deleteApiAccount = async (u) => {
    const ok = await confirmModal(t('admin.api.del-confirm', { name: u.name }), { confirmLabel: t('btn.delete'), dangerous: true });
    if (!ok) return;
    const result = await api.call('admin_delete_api_account', { uid: String(u.uid) }, 'POST');
    if (result.success) {
        showToast(t('admin.api.deleted', { name: u.name }), 'success');
        await loadApiAccounts();
    } else {
        showToast(result.message || 'Failed to delete', 'error');
    }
};

const regenerateApiToken = async (uid) => {
    const ok = await confirmModal(t('admin.ai.regen-confirm'), { confirmLabel: t('admin.ai.regen-btn'), dangerous: true });
    if (!ok) return;
    const result = await api.call('admin_regenerate_api_token', { uid: String(uid) }, 'POST');
    if (result.success) {
        const tokenEl = document.getElementById('api-f-token');
        if (tokenEl) tokenEl.textContent = result.token;
        showToast(t('admin.ai.regenerated'), 'success');
    } else {
        showToast(result.message || 'Failed to regenerate', 'error');
    }
};

// ── Agent Jobs tab ────────────────────────────────────────────────────────────

let agentJobs       = [];
let agentJobAiUsers = {};
let agentJobSpaces  = [];
let agentServerTime = '';
let agentServerTz   = '';

const DOW_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const formatSchedule = (sched) => {
    if (!sched || !sched.type) return '—';
    const time = sched.time || '?';
    switch (sched.type) {
        case 'daily':   return `Daily at ${time}`;
        case 'weekly': {
            const days = (sched.days || []).map(d => DOW_NAMES[d] || d).join(', ');
            return `${days || '?'} at ${time}`;
        }
        case 'monthly': return `${sched.day || 1}. of month at ${time}`;
        default:        return '—';
    }
};

const loadAgentJobs = async () => {
    const container = document.getElementById('admin-jobs-list');
    if (container) container.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const result = await api.call('admin_get_agent_jobs');
    if (!result.success) {
        if (container) container.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
        return;
    }
    agentJobs       = result.jobs     || [];
    agentJobAiUsers = result.ai_users || {};
    agentJobSpaces  = result.spaces   || [];
    agentServerTime = result.server_time     || '';
    agentServerTz   = result.server_timezone || '';
    renderAgentJobList();
};

const renderAgentJobList = () => {
    const container = document.getElementById('admin-jobs-list');
    if (!container) return;

    const serverTimeHtml = agentServerTime
        ? `<div class="admin-jobs-server-time">${t('admin.jobs.server-time')}: <strong>${agentServerTime}</strong> (${agentServerTz})</div>`
        : '';

    if (!agentJobs.length) {
        container.innerHTML = serverTimeHtml + `<p class="admin-empty">${t('admin.jobs.none')}</p>`;
        return;
    }
    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = `<thead><tr>
        <th>${t('admin.jobs.col-name')}</th>
        <th>${t('admin.jobs.col-ai-user')}</th>
        <th>${t('admin.jobs.col-space')}</th>
        <th>${t('admin.jobs.col-schedule')}</th>
        <th>${t('admin.jobs.col-last-run')}</th>
        <th></th>
    </tr></thead>`;
    const tbody = document.createElement('tbody');
    agentJobs.forEach(job => {
        const tr = document.createElement('tr');
        const aiName    = agentJobAiUsers[job.ai_user_uid] || `uid:${job.ai_user_uid}`;
        const lastRun   = job.last_run ? new Date(job.last_run).toLocaleString() : '—';
        const statusBadge = job.last_status
            ? `<span class="admin-job-status admin-job-status-${job.last_status === 'ok' ? 'ok' : 'err'}">${job.last_status}</span>`
            : '';
        const enabledBadge = job.enabled
            ? `<span class="admin-ai-badge" style="background:#48bb78">enabled</span>`
            : `<span class="admin-ai-badge" style="background:#a0aec0">disabled</span>`;

        tr.innerHTML = `
            <td class="admin-td-name">${escHtml(job.name)} ${enabledBadge}</td>
            <td>${escHtml(aiName)}</td>
            <td><span class="admin-log-source">${escHtml(job.space || '—')}</span></td>
            <td style="font-size:0.82rem">${escHtml(formatSchedule(job.schedule))}</td>
            <td>${lastRun} ${statusBadge}</td>
            <td style="white-space:nowrap;display:flex;gap:4px;align-items:center"></td>`;

        const tdActions = tr.querySelector('td:last-child');

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-secondary';
        editBtn.textContent = t('admin.jobs.edit-btn');
        editBtn.addEventListener('click', () => openJobForm(job));

        const runBtn = document.createElement('button');
        runBtn.className = 'btn btn-sm btn-blue';
        runBtn.textContent = t('admin.jobs.run-btn');
        runBtn.addEventListener('click', () => runJobNow(job, runBtn));

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-sm btn-danger admin-del-btn';
        delBtn.title = t('admin.jobs.delete-btn');
        delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        delBtn.addEventListener('click', () => deleteJob(job));

        tdActions.append(editBtn, runBtn, delBtn);
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    container.innerHTML = serverTimeHtml;
    container.appendChild(table);
};

const openJobForm = (job) => {
    const container = document.getElementById('admin-jobs-list');
    if (!container) return;
    const isNew  = !job;
    const sched  = (!isNew && job.schedule) ? job.schedule : { type: 'daily', time: '08:00' };
    const aiOptions = Object.entries(agentJobAiUsers)
        .map(([uid, name]) => `<option value="${uid}" ${(!isNew && job.ai_user_uid == uid) ? 'selected' : ''}>${escHtml(name)}</option>`)
        .join('');
    const spaceOptions = agentJobSpaces
        .map(s => `<option value="${s}" ${(!isNew && job.space === s) ? 'selected' : ''}>${escHtml(s)}</option>`)
        .join('');
    const schedDays = sched.days || [];

    const dowCheckboxes = DOW_NAMES.map((name, i) =>
        `<label><input type="checkbox" class="job-f-dow" value="${i}" ${schedDays.includes(i) ? 'checked' : ''}> ${name}</label>`
    ).join('');

    container.innerHTML = `
        <div class="admin-ai-form">
            <div class="job-form-grid">
                <label>${t('admin.jobs.name-label')}</label>
                <input type="text" id="job-f-name" class="form-control" value="${isNew ? '' : escHtml(job.name)}">

                <label>${t('admin.jobs.enabled-label')}</label>
                <label class="toggle-switch">
                    <input type="checkbox" id="job-f-enabled" ${(!isNew && job.enabled) || isNew ? 'checked' : ''}>
                    <span class="toggle-switch-track"></span>
                    <span class="toggle-switch-thumb"></span>
                </label>

                <label>${t('admin.jobs.ai-user-label')}</label>
                <select id="job-f-ai-user" class="form-control">${aiOptions}</select>

                <label>${t('admin.jobs.space-label')}</label>
                <select id="job-f-space" class="form-control">${spaceOptions}</select>
            </div>

            <div class="admin-ai-form-section-header">${t('admin.jobs.sched-label')}${agentServerTime ? ` <span style="font-size:0.78rem;font-weight:400;color:var(--accent-gray)">${t('admin.jobs.server-time')}: ${agentServerTime} (${agentServerTz})</span>` : ''}</div>
            <div class="job-form-grid">
                <label>${t('admin.jobs.sched-type')}</label>
                <select id="job-f-sched-type" class="form-control">
                    <option value="daily"   ${sched.type === 'daily'   ? 'selected' : ''}>${t('admin.jobs.sched-daily')}</option>
                    <option value="weekly"  ${sched.type === 'weekly'  ? 'selected' : ''}>${t('admin.jobs.sched-weekly')}</option>
                    <option value="monthly" ${sched.type === 'monthly' ? 'selected' : ''}>${t('admin.jobs.sched-monthly')}</option>
                </select>

                <label>${t('admin.jobs.sched-time')}</label>
                <input type="time" id="job-f-sched-time" class="form-control" value="${escHtml(sched.time || '08:00')}">

                <div id="job-f-sched-days-wrapper" style="${sched.type === 'weekly' ? 'display:contents' : 'display:none'}">
                    <label>${t('admin.jobs.sched-days')}</label>
                    <div class="admin-sched-days">${dowCheckboxes}</div>
                </div>

                <div id="job-f-sched-dom-wrapper" style="${sched.type === 'monthly' ? 'display:contents' : 'display:none'}">
                    <label>${t('admin.jobs.sched-dom')}</label>
                    <input type="number" id="job-f-sched-dom" class="form-control" min="1" max="31" value="${sched.day || 1}" style="max-width:6rem">
                </div>
            </div>

            <div class="admin-ai-form-section-header">${t('admin.jobs.prompt-section')}</div>
            <div class="admin-ai-form-section">
                <label style="font-size:0.85rem;font-weight:600;color:var(--text-muted);margin-bottom:0.25rem">${t('admin.jobs.prompt-label')}</label>
                <textarea id="job-f-prompt" class="form-control admin-ai-prompt" rows="8" placeholder="${t('admin.jobs.prompt-ph')}">${isNew ? '' : escHtml(job.prompt || '')}</textarea>
            </div>

            ${!isNew && job.last_run ? `
            <div class="admin-ai-form-section-header">${t('admin.jobs.last-run-section')}</div>
            <div class="job-form-grid" style="padding-top:0.5rem">
                <label>${t('admin.jobs.last-run-label')}</label>
                <span style="font-size:0.85rem">${new Date(job.last_run).toLocaleString()}</span>
                <label>Status</label>
                <span style="font-size:0.85rem">${escHtml(job.last_status || '—')}</span>
                ${(job.last_log_file || job.last_log_page) ? `
                <label>${t('admin.jobs.log-file-label')}</label>
                <code style="font-size:0.75rem;word-break:break-all">${escHtml(job.last_log_file || job.last_log_page)}</code>` : ''}
            </div>` : ''}

            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color)">
                <button id="job-f-cancel-btn" class="btn btn-secondary">${t('btn.cancel')}</button>
                <button id="job-f-save-btn" class="btn btn-green" style="margin-left:auto">${t('btn.save')}</button>
            </div>
        </div>`;

    document.getElementById('admin-jobs-add-btn')?.classList.add('hidden');

    // Show/hide schedule sub-rows on type change
    document.getElementById('job-f-sched-type').addEventListener('change', (e) => {
        document.getElementById('job-f-sched-days-wrapper').style.display = e.target.value === 'weekly'  ? 'contents' : 'none';
        document.getElementById('job-f-sched-dom-wrapper').style.display  = e.target.value === 'monthly' ? 'contents' : 'none';
    });

    document.getElementById('job-f-save-btn').addEventListener('click', () => saveJob(isNew ? null : job.id));
    document.getElementById('job-f-cancel-btn').addEventListener('click', () => {
        document.getElementById('admin-jobs-add-btn')?.classList.remove('hidden');
        renderAgentJobList();
    });
};

const buildScheduleObject = () => {
    const type = document.getElementById('job-f-sched-type')?.value || 'daily';
    const time = document.getElementById('job-f-sched-time')?.value || '08:00';
    const sched = { type, time };
    if (type === 'weekly') {
        sched.days = [...document.querySelectorAll('.job-f-dow:checked')].map(el => parseInt(el.value, 10));
    }
    if (type === 'monthly') {
        sched.day = parseInt(document.getElementById('job-f-sched-dom')?.value || '1', 10);
    }
    return sched;
};

const saveJob = async (jobId) => {
    const name   = document.getElementById('job-f-name')?.value.trim();
    const prompt = document.getElementById('job-f-prompt')?.value.trim();
    if (!name || !prompt) { showToast('Name and prompt are required.', 'error'); return; }
    const params = {
        id:          jobId || '',
        name,
        enabled:     document.getElementById('job-f-enabled')?.checked ? 'true' : 'false',
        ai_user_uid: document.getElementById('job-f-ai-user')?.value || '',
        space:       document.getElementById('job-f-space')?.value || '',
        prompt,
        schedule:    JSON.stringify(buildScheduleObject()),
    };
    const result = await api.call('admin_save_agent_job', params, 'POST');
    if (result.success) {
        document.getElementById('admin-jobs-add-btn')?.classList.remove('hidden');
        await loadAgentJobs();
    } else {
        showToast(result.message || 'Failed to save job.', 'error');
    }
};

// "Run now" runs the job detached on the server (see admin_run_agent_job): it
// survives closing the admin lightbox and doesn't block other work. The client
// just polls admin_agent_job_status until it finishes.
let _jobPollTimer = null;
const stopJobPoll = () => { if (_jobPollTimer) { clearInterval(_jobPollTimer); _jobPollTimer = null; } };

const runJobNow = async (job, btn) => {
    stopJobPoll();
    const statusEl = document.getElementById('job-f-run-status');
    const resetBtn = () => { if (btn) { btn.disabled = false; btn.textContent = t('admin.jobs.run-btn'); } };
    const showRunning = () => {
        if (btn) { btn.disabled = true; btn.textContent = t('admin.jobs.running'); }
        if (statusEl) { statusEl.style.display = ''; statusEl.innerHTML = `<span style="color:var(--accent-gray)">${t('admin.jobs.running')}…</span>`; }
    };
    const showResult = (ok, msg, logFile) => {
        if (statusEl) {
            statusEl.style.display = '';
            statusEl.innerHTML = `<div class="admin-job-run-result ${ok ? 'admin-job-run-ok' : 'admin-job-run-err'}">
                <strong>${ok ? '✓ Success' : '✗ Error'}</strong>
                <pre class="admin-job-run-pre">${escHtml(msg)}</pre>
                ${logFile ? `<div style="margin-top:0.4rem;font-size:0.8rem;color:var(--accent-gray)">${t('admin.jobs.log-file-label')}: <code>${escHtml(logFile)}</code></div>` : ''}
            </div>`;
        }
        showToast(ok ? t('admin.jobs.run-ok') : (msg || 'Job failed.'), ok ? 'success' : 'error');
    };
    const showErr = (msg) => {
        if (statusEl) { statusEl.style.display = ''; statusEl.innerHTML = `<div class="admin-job-run-result admin-job-run-err"><strong>✗ Error</strong><pre class="admin-job-run-pre">${escHtml(msg)}</pre></div>`; }
        else showToast(msg, 'error');
    };

    showRunning();
    const started = await api.call('admin_run_agent_job', { id: job.id }, 'POST');
    if (!started || !started.success) {
        resetBtn();
        showErr(started?.message || 'Failed to start job.');
        return;
    }

    // Poll for completion. Cap at ~15 min so a job whose process died mid-run
    // (e.g. a server restart) doesn't poll forever.
    let polls = 0;
    const MAX_POLLS = 450; // 450 × 2s = 15 min
    _jobPollTimer = setInterval(async () => {
        if (++polls > MAX_POLLS) {
            stopJobPoll(); resetBtn();
            showErr('Still running after 15 minutes — check the job list and logs.');
            return;
        }
        const st = await api.call('admin_agent_job_status', { id: job.id });
        if (!st || !st.success || st.state === 'running') return; // keep polling
        stopJobPoll();
        resetBtn();
        const ok = st.state === 'ok';
        showResult(ok, ok ? (st.reply || '(no reply)') : (st.error || 'Error'), st.log_file);
        await loadAgentJobs();
    }, 2000);
};

const deleteJob = async (job) => {
    const { confirmModal } = await import('../core/utils.js');
    const { icons } = await import('../core/icons.js');
    if (!await confirmModal(`Delete job "${job.name}"?`, { confirmLabel: 'Delete', dangerous: true, icon: icons.trash })) return;
    const result = await api.call('admin_delete_agent_job', { id: job.id }, 'POST');
    if (result.success) {
        await loadAgentJobs();
    }
};

// ── MCP Servers tab ───────────────────────────────────────────────────────────

let mcpServers = [];

const loadMcpServers = async () => {
    const container = document.getElementById('admin-mcp-list');
    if (container) container.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const result = await api.call('admin_get_mcp_servers');
    if (result.success) {
        mcpServers = result.data || [];
        renderMcpServerList();
    } else {
        if (container) container.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
    }
};

const renderMcpServerList = () => {
    const container = document.getElementById('admin-mcp-list');
    if (!container) return;

    if (!mcpServers.length) {
        container.innerHTML = '<p class="admin-empty">No MCP servers configured. Add one to give AI users access to external tools.</p>';
        return;
    }

    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = '<thead><tr><th>Name</th><th>URL</th><th>Auth</th><th></th></tr></thead>';
    const tbody = document.createElement('tbody');

    mcpServers.forEach(s => {
        const tr = document.createElement('tr');

        const tdName = document.createElement('td');
        tdName.className = 'admin-td-name';
        tdName.textContent = s.name;

        const tdUrl = document.createElement('td');
        tdUrl.className = 'admin-td-email';
        tdUrl.textContent = s.url.length > 50 ? s.url.slice(0, 50) + '…' : s.url;
        tdUrl.title = s.url;

        const tdAuth = document.createElement('td');
        tdAuth.innerHTML = s.auth_token_set
            ? '<span class="admin-auth-badge" style="background:#667eea;color:#fff">TOKEN</span>'
            : '<span style="color:var(--text-muted);font-size:0.8rem">none</span>';

        const tdActions = document.createElement('td');
        tdActions.style.cssText = 'white-space:nowrap;display:flex;gap:4px;align-items:center;';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-secondary';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => openMcpServerForm(s));

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-sm btn-danger admin-del-btn';
        delBtn.title = 'Delete';
        delBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        delBtn.addEventListener('click', () => deleteMcpServer(s));

        tdActions.append(editBtn, delBtn);
        tr.append(tdName, tdUrl, tdAuth, tdActions);
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.innerHTML = '';
    container.appendChild(table);
};

const openMcpServerForm = (s) => {
    const container = document.getElementById('admin-mcp-list');
    if (!container) return;
    const isNew = !s;

    container.innerHTML = `
        <div class="admin-ai-form">
            <div class="admin-ai-form-section">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" id="mcp-f-name" class="form-control" value="${escHtml(s?.name || '')}" placeholder="e.g. My Tool Server">
                </div>
                <div class="form-group">
                    <label>URL</label>
                    <input type="url" id="mcp-f-url" class="form-control" value="${escHtml(s?.url || '')}" placeholder="https://mcp.example.com">
                    <p class="form-hint">Base URL of the MCP server. Must support HTTP transport with <code>/tools/list</code> and <code>/tools/call</code> endpoints.</p>
                </div>
                <div class="form-group">
                    <label>Auth Token ${s?.auth_token_set ? '<span class="admin-ai-key-set">(set)</span>' : ''}</label>
                    <input type="password" id="mcp-f-token" class="form-control" placeholder="${isNew ? 'Optional token' : 'Leave blank to keep existing'}">
                </div>
                <div class="form-group">
                    <label>Auth header</label>
                    <input type="text" id="mcp-f-auth-header" class="form-control" value="${escHtml(s?.auth_header || 'Authorization')}" placeholder="Authorization">
                    <label style="margin-top:0.5rem">Auth scheme <span style="font-weight:400;color:var(--text-muted)">(prefix)</span></label>
                    <input type="text" id="mcp-f-auth-prefix" class="form-control" value="${escHtml(s?.auth_prefix ?? 'Bearer')}" placeholder="Bearer">
                    <p class="form-hint">How the token is sent: <code>&lt;header&gt;: &lt;scheme&gt; &lt;token&gt;</code>. Defaults to <code>Authorization: Bearer …</code>. For a server that authenticates with a custom header, set the header name (e.g. <code>X-API-Key</code>) and clear the scheme to send the raw token.</p>
                </div>
                <div class="form-group">
                    <label>Extra request headers <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                    ${extraHeadersFieldHtml('mcp-f', s?.extra_headers ?? [])}
                    <p class="form-hint">Additional HTTP headers sent on every request to this server, alongside the auth header above. Useful for a gateway such as a Cloudflare Access tunnel needing <code>CF-Access-Client-Id</code> and <code>CF-Access-Client-Secret</code>.</p>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:0.45rem;font-weight:600;cursor:pointer">
                        <input type="checkbox" id="mcp-f-native" ${s?.wiki_native ? 'checked' : ''} style="width:auto"> This server is an Astucia Wiki
                    </label>
                    <p class="form-hint">Enables <code>tag:</code> / <code>updated:</code> filters and native page results in saved searches. Leave off for generic MCP servers.</p>
                </div>
                <div class="form-group" id="mcp-f-search-group">
                    <label>Search tool <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                    <input type="text" id="mcp-f-search-tool" class="form-control" value="${escHtml(s?.search_tool || '')}" placeholder="e.g. search_docs">
                    <p class="form-hint">Which tool a <code>.search</code> saved search invokes on this server. Blank = auto-detect (a tool named like search/find/query). Ignored for Astucia Wiki servers.</p>
                    <label style="margin-top:0.5rem">Query argument <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                    <input type="text" id="mcp-f-search-arg" class="form-control" value="${escHtml(s?.search_arg || '')}" placeholder="e.g. query">
                    <p class="form-hint">The argument that receives the search text. Blank = auto-detect (<code>query</code>, else the tool's first parameter).</p>
                </div>
            </div>
            <div id="mcp-f-test-result" style="margin-bottom:0.5rem"></div>
            <div class="admin-ai-form-actions">
                <button type="button" id="mcp-f-cancel-btn" class="btn btn-secondary">Cancel</button>
                <button type="button" id="mcp-f-test-btn" class="btn btn-secondary">Test Connection</button>
                <button type="button" id="mcp-f-save-btn" class="btn btn-green">Save</button>
            </div>
        </div>`;

    document.getElementById('mcp-f-cancel-btn').addEventListener('click', () => {
        renderMcpServerList();
        document.getElementById('admin-mcp-add-btn').classList.remove('hidden');
    });

    wireExtraHeadersField('mcp-f');

    document.getElementById('mcp-f-test-btn').addEventListener('click', async () => {
        const url   = document.getElementById('mcp-f-url')?.value.trim();
        const token = document.getElementById('mcp-f-token')?.value;
        const btn   = document.getElementById('mcp-f-test-btn');
        const out   = document.getElementById('mcp-f-test-result');
        if (!url) { showToast('Enter a URL first.', 'error'); return; }
        btn.disabled = true;
        btn.textContent = 'Testing…';
        const params = {
            url,
            auth_header: document.getElementById('mcp-f-auth-header')?.value.trim() || '',
            auth_prefix: document.getElementById('mcp-f-auth-prefix')?.value ?? '',
            extra_headers: JSON.stringify(readExtraHeadersField('mcp-f')),
        };
        if (token) params.auth_token = token;
        else if (s?.id) params.id = s.id;
        const res = await api.call('admin_test_mcp_server', params, 'POST');
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        if (res.success) {
            const tools = res.tools || [];
            const rows  = tools.map(t =>
                `<tr><td style="font-weight:600;vertical-align:top;width:30%;word-break:break-all">${escHtml(t.name)}</td><td style="color:var(--text-muted)">${escHtml(t.description)}</td></tr>`
            ).join('');
            out.innerHTML = `<p style="color:#48bb78;font-size:0.85rem;margin-bottom:${tools.length ? '0.5rem' : '0'}">✓ Connected — ${res.tool_count} tool${res.tool_count !== 1 ? 's' : ''} found</p>`
                + (tools.length ? `<div style="max-height:220px;overflow-y:auto"><table class="admin-table">${rows}</table></div>` : '');
        } else {
            out.innerHTML = `<p style="color:#fc8181;font-size:0.85rem">✗ ${escHtml(res.message || 'Connection failed')}</p>`;
        }
    });

    // The per-server search tool/arg only apply to generic (non-wiki) servers.
    const nativeCb    = document.getElementById('mcp-f-native');
    const searchGroup = document.getElementById('mcp-f-search-group');
    const syncSearchGroup = () => {
        searchGroup.style.opacity = nativeCb.checked ? '0.45' : '';
        searchGroup.querySelectorAll('input').forEach(i => { i.disabled = nativeCb.checked; });
    };
    nativeCb.addEventListener('change', syncSearchGroup);
    syncSearchGroup();

    document.getElementById('mcp-f-save-btn').addEventListener('click', () => saveMcpServer(s?.id ?? null));

    document.getElementById('admin-mcp-add-btn').classList.add('hidden');
};

const saveMcpServer = async (id) => {
    const name   = document.getElementById('mcp-f-name')?.value.trim();
    const url    = document.getElementById('mcp-f-url')?.value.trim();
    const token  = document.getElementById('mcp-f-token')?.value || '';
    const authHeader = document.getElementById('mcp-f-auth-header')?.value.trim() || '';
    const authPrefix = document.getElementById('mcp-f-auth-prefix')?.value ?? '';
    const extraHeaders = readExtraHeadersField('mcp-f');
    const native = document.getElementById('mcp-f-native')?.checked ? '1' : '0';
    const searchTool = document.getElementById('mcp-f-search-tool')?.value.trim() || '';
    const searchArg  = document.getElementById('mcp-f-search-arg')?.value.trim() || '';
    if (!name) { showToast('Name is required.', 'error'); return; }
    if (!url)  { showToast('URL is required.', 'error'); return; }

    const saveBtn = document.getElementById('mcp-f-save-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    const result = await api.call('admin_save_mcp_server',
        { id: id || '', name, url, auth_token: token, auth_header: authHeader, auth_prefix: authPrefix,
          extra_headers: JSON.stringify(extraHeaders),
          wiki_native: native, search_tool: searchTool, search_arg: searchArg }, 'POST');
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save';

    if (result.success) {
        showToast('MCP server saved.', 'success');
        document.getElementById('admin-mcp-add-btn').classList.remove('hidden');
        invalidateMcpServers();
        await loadMcpServers();
    } else {
        showToast(result.message || 'Failed to save.', 'error');
    }
};

const deleteMcpServer = async (s) => {
    const ok = await confirmModal(`Delete MCP server "${s.name}"?`, { confirmLabel: 'Delete', dangerous: true });
    if (!ok) return;
    const result = await api.call('admin_delete_mcp_server', { id: s.id }, 'POST');
    if (result.success) {
        showToast('MCP server deleted.', 'success');
        invalidateMcpServers();
        await loadMcpServers();
    } else {
        showToast(result.message || 'Failed to delete.', 'error');
    }
};

const testMcpServer = async (s, btn) => {
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '…';
    const res = await api.call('admin_test_mcp_server', { id: s.id }, 'POST');
    btn.disabled = false;
    btn.textContent = orig;

    const existing = btn.parentElement.querySelector('.mcp-test-result');
    if (existing) existing.remove();

    const panel = document.createElement('div');
    panel.className = 'mcp-test-result';
    panel.style.cssText = 'margin-top:6px;font-size:0.8rem;';

    if (res.success) {
        const tools = res.tools || [];
        const rows  = tools.map(t =>
            `<tr><td style="font-weight:600;vertical-align:top;width:30%;word-break:break-all">${escHtml(t.name)}</td><td style="color:var(--text-muted)">${escHtml(t.description)}</td></tr>`
        ).join('');
        panel.innerHTML = `<span style="color:#48bb78">✓ ${res.tool_count} tool${res.tool_count !== 1 ? 's' : ''} found</span>`
            + (tools.length ? `<div style="max-height:180px;overflow-y:auto;margin-top:6px"><table class="admin-table">${rows}</table></div>` : '');
    } else {
        panel.innerHTML = `<span style="color:#fc8181">✗ ${escHtml(res.message || 'Connection failed')}</span>`;
    }

    btn.parentElement.appendChild(panel);
};

// ── Deleted Pages tab ─────────────────────────────────────────────────────────

const loadDeletedPages = async () => {
    const container = document.getElementById('admin-deleted-list');
    const countEl   = document.getElementById('admin-deleted-count');
    if (!container) return;
    container.innerHTML = `<p class="admin-empty">${t('admin.deleted.loading')}</p>`;

    const res = await api.call('git_deleted_files');
    if (!res.success) {
        container.innerHTML = `<p class="admin-empty">${t('admin.deleted.failed')}</p>`;
        return;
    }
    const items = res.data || [];
    if (countEl) countEl.textContent = t('admin.deleted.count', { n: items.length });

    if (!items.length) {
        container.innerHTML = `<p class="admin-empty">${t('admin.deleted.none')}</p>`;
        return;
    }

    container.innerHTML = '';
    const table = document.createElement('table');
    table.className = 'admin-table';
    table.innerHTML = `<thead><tr>
        <th>${t('admin.deleted.col-path')}</th>
        <th>${t('admin.deleted.col-deleted')}</th>
        <th>${t('admin.deleted.col-by')}</th>
        <th>${t('admin.deleted.col-commit')}</th>
        <th></th>
    </tr></thead>`;
    const tbody = document.createElement('tbody');

    items.forEach(item => {
        const tr = document.createElement('tr');
        const date = new Date(item.timestamp * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
        tr.innerHTML = `
            <td class="admin-deleted-path">${escHtml(item.path)}</td>
            <td class="admin-deleted-date">${escHtml(date)}</td>
            <td>${escHtml(item.author)}</td>
            <td><code title="${escHtml(item.message)}">${escHtml(item.short_hash)}</code></td>
            <td></td>
        `;
        const restoreBtn = document.createElement('button');
        restoreBtn.className = 'btn btn-sm btn-secondary';
        restoreBtn.textContent = t('admin.deleted.restore-btn');
        restoreBtn.addEventListener('click', async () => {
            const confirmed = await confirmModal(
                t('admin.deleted.restore-confirm', { path: item.path }),
                { confirmLabel: t('admin.deleted.restore-btn'), icon: null }
            );
            if (!confirmed) return;
            restoreBtn.disabled = true;
            const r = await api.call('git_restore_deleted', { file: item.path, hash: item.hash }, 'POST');
            if (r.success) {
                showToast(t('admin.deleted.restore-done', { path: item.path }), 'success');
                loadDeletedPages();
            } else {
                showToast(r.message || t('admin.deleted.restore-failed'), 'error');
                restoreBtn.disabled = false;
            }
        });
        tr.lastElementChild.appendChild(restoreBtn);
        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.appendChild(table);
};

// ── Index Pages tab ───────────────────────────────────────────────────────────

const loadReindexPane = () => {
    const sel = document.getElementById('admin-reindex-space');
    if (!sel) return;
    sel.innerHTML = `<option value="">${t('admin.reindex.all-spaces')}</option>`;
    allSpaces.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        sel.appendChild(opt);
    });
};

const runReindex = async () => {
    const btn         = document.getElementById('admin-reindex-btn');
    const selectedSpace = document.getElementById('admin-reindex-space')?.value || '';
    const spacesToRun = selectedSpace ? [selectedSpace] : (allSpaces.length ? allSpaces : ['']);

    btn.disabled = true;

    // Build and show the progress modal
    const overlay = document.createElement('div');
    overlay.className = 'reindex-modal-overlay';
    overlay.innerHTML = `
        <div class="reindex-modal">
            <div class="reindex-modal-header">
                <span class="reindex-modal-title">${t('admin.reindex.modal-title')}</span>
                <span id="reindex-progress-text" class="reindex-progress-text">${t('admin.reindex.progress', { done: 0, total: spacesToRun.length })}</span>
            </div>
            <div class="reindex-progress-bar-wrap">
                <div id="reindex-progress-fill" class="reindex-progress-fill" style="width:0%"></div>
            </div>
            <div id="reindex-log" class="reindex-log"></div>
            <div id="reindex-footer" class="reindex-modal-footer hidden">
                <span id="reindex-summary" class="reindex-summary"></span>
                <button id="reindex-close-btn" class="btn btn-secondary">${t('btn.close')}</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const logEl      = overlay.querySelector('#reindex-log');
    const fillEl     = overlay.querySelector('#reindex-progress-fill');
    const progressEl = overlay.querySelector('#reindex-progress-text');
    const footer     = overlay.querySelector('#reindex-footer');
    const summary    = overlay.querySelector('#reindex-summary');
    overlay.querySelector('#reindex-close-btn').addEventListener('click', () => overlay.remove());

    const addLogRow = (space, status, detail, isErr = false) => {
        const row = document.createElement('div');
        row.className = 'reindex-log-row' + (isErr ? ' reindex-log-err' : '');
        row.innerHTML = `<span class="reindex-log-icon">${isErr ? '✗' : '✓'}</span>
            <span class="reindex-log-space">${escHtml(space || '(root)')}</span>
            <span class="reindex-log-detail">${escHtml(detail)}</span>`;
        logEl.appendChild(row);
        logEl.scrollTop = logEl.scrollHeight;
    };

    const addSpinner = (space) => {
        const row = document.createElement('div');
        row.className = 'reindex-log-row reindex-log-active';
        row.id = 'reindex-current-row';
        row.innerHTML = `<span class="reindex-log-spinner"></span>
            <span class="reindex-log-space">${escHtml(space || '(root)')}</span>
            <span class="reindex-log-detail reindex-log-muted">indexing…</span>`;
        logEl.appendChild(row);
        logEl.scrollTop = logEl.scrollHeight;
        return row;
    };

    let totalPages = 0;
    let errorCount = 0;

    for (let i = 0; i < spacesToRun.length; i++) {
        const s = spacesToRun[i];
        progressEl.textContent = t('admin.reindex.progress', { done: i, total: spacesToRun.length });
        fillEl.style.width = `${Math.round((i / spacesToRun.length) * 100)}%`;

        const spinnerRow = addSpinner(s);
        const params = s ? { space: s } : {};
        const res = await api.call('admin_reindex', params);
        spinnerRow.remove();

        if (res.success) {
            let detail = t('admin.reindex.space-ok', { n: res.count });
            if (res.sqlite_error) detail += ` · ${t('admin.reindex.fts-error')}: ${res.sqlite_error}`;
            else if (res.sqlite_count !== null) detail += ` · ${t('admin.reindex.fts-ok', { n: res.sqlite_count })}`;
            if (res.users_cleaned) detail += ` · ${t('admin.reindex.users-cleaned', { n: res.users_cleaned })}`;
            addLogRow(s, 'ok', detail);
            totalPages += res.count;
        } else {
            addLogRow(s, 'err', t('admin.reindex.space-err', { error: res.message || '?' }), true);
            errorCount++;
        }
    }

    fillEl.style.width = '100%';
    progressEl.textContent = t('admin.reindex.progress', { done: spacesToRun.length, total: spacesToRun.length });

    const summaryText = spacesToRun.length > 1
        ? t('admin.reindex.modal-done', { n: totalPages, spaces: spacesToRun.length })
        : t('admin.reindex.modal-done-one', { n: totalPages });
    summary.textContent = summaryText;
    footer.classList.remove('hidden');

    btn.disabled = false;
};

// ── Init ──────────────────────────────────────────────────────────────────────

export const init = () => {
    if (!document.getElementById('admin-btn')) return;

    const openAdmin = async () => {
        document.getElementById('admin-lightbox').classList.remove('hidden');
        switchGroup('users');
        markDirty(false);
        await loadUsers();
        // Load request count for badge without switching to that tab.
        const res = await api.call('admin_get_user_requests');
        if (res.success) { requests = res.data || []; updateRequestsBadge(); }
    };

    document.getElementById('admin-btn').addEventListener('click', (e) => { e.preventDefault(); openAdmin(); });

    document.getElementById('admin-lightbox-close-btn').addEventListener('click', () => {
        document.getElementById('admin-lightbox').classList.add('hidden');
        stopJobPoll(); // the job keeps running server-side; just stop the live poll
    });
    // Intentionally no backdrop-click-to-close: the admin lightbox holds forms with
    // unsaved edits, so it closes only via the "×" button to avoid losing work to a
    // stray click outside it.

    document.querySelectorAll('.admin-group').forEach(grp =>
        grp.addEventListener('click', () => switchGroup(grp.dataset.group)));
    document.querySelectorAll('.admin-tab').forEach(tab =>
        tab.addEventListener('click', () => switchTab(tab.dataset.tab)));

    document.getElementById('admin-save-btn').addEventListener('click', saveUsers);

    document.getElementById('admin-ai-add-btn')?.addEventListener('click', () => openAiUserForm(null));
    document.getElementById('admin-api-add-btn')?.addEventListener('click', () => openApiAccountForm(null));
    document.getElementById('admin-jobs-add-btn')?.addEventListener('click', () => openJobForm(null));
    document.getElementById('admin-mcp-add-btn')?.addEventListener('click', () => openMcpServerForm(null));

    // OTP-specific UI
    if (window.WIKI_AUTH_MODE === 'otp') {
        document.querySelector('.admin-tab[data-tab="requests"]')?.classList.add('hidden');
    }
    if (window.WIKI_AUTH_MODE === 'otp' || window.WIKI_AUTH_MODE === 'both') {
        document.getElementById('admin-otp-add-btn')?.classList.remove('hidden');
    }
    document.getElementById('admin-otp-add-btn')?.addEventListener('click', () => {
        users.push({ uid: 0, name: '', email: '', role: 'editor', auth: 'otp', spaces: null });
        markDirty();
        renderUsers();
        // Focus the last name input
        const inputs = document.querySelectorAll('#admin-users-table .admin-inline-input');
        if (inputs.length) inputs[inputs.length - 2]?.focus();
    });

    // Close spaces dropdowns on outside click
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.admin-spaces-wrap')) {
            document.querySelectorAll('.admin-spaces-dropdown').forEach(d => d.classList.add('hidden'));
        }
    });

    document.getElementById('admin-log-date').addEventListener('change', (e) => loadLogContent(e.target.value));
    document.getElementById('admin-log-refresh-btn').addEventListener('click', () => {
        const sel = document.getElementById('admin-log-date');
        if (sel.value) loadLogContent(sel.value); else loadLogFiles();
    });

    document.getElementById('admin-diag-email-btn')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const statusEl = document.getElementById('admin-diag-email-status');
        btn.disabled = true;
        btn.textContent = t('admin.diag.sending');
        if (statusEl) statusEl.innerHTML = '';
        const result = await api.call('admin_send_test_email', {}, 'POST');
        btn.disabled = false;
        btn.textContent = t('admin.diag.email-btn');
        if (statusEl) {
            statusEl.innerHTML = result.success
                ? `<span class="admin-diag-ok">${result.message}</span>`
                : `<span class="admin-diag-err">${result.message || 'Failed to send email.'}</span>`;
        }
    });

    document.querySelectorAll('.admin-diag-refresh-btn').forEach(btn => {
        btn.addEventListener('click', () =>
            loadDiagLog(btn.dataset.logType, btn.dataset.output));
    });

    document.getElementById('admin-reindex-btn')?.addEventListener('click', runReindex);

    document.getElementById('admin-diag-error-log-select')?.addEventListener('change', e => {
        if (e.target.value) loadErrorLogContent(e.target.value);
    });
    document.getElementById('admin-diag-error-log-refresh-btn')?.addEventListener('click', () => {
        const sel = document.getElementById('admin-diag-error-log-select');
        if (sel?.value) loadErrorLogContent(sel.value); else loadErrorLogFiles();
    });
};
