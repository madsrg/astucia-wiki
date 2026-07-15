// Shared /aiUsers command overview — a table of the wiki's AI users showing the
// model each uses and the MCP servers enabled for it. Used by both team chat and
// Page Chat so the two stay identical.

import { api } from './api.js';
import { confirmModal } from './utils.js';
import { t } from '../i18n/index.js';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const dash = `<span class="ai-users-empty">—</span>`;

// Pure: build the overview table markup from the overview list. Exported for
// testing; all cell values are escaped since names/models are admin-configured.
export const buildAiUsersTableHtml = (list) => {
    const rows = list.map(u => {
        const model   = u.model ? `<code>${esc(u.model)}</code>` : dash;
        const servers = (u.mcp_servers && u.mcp_servers.length) ? esc(u.mcp_servers.join(', ')) : dash;
        // provider shown as a hover title for extra context without cluttering the cell
        const modelCell = u.provider ? `<span title="${esc(u.provider)}">${model}</span>` : model;
        return `<tr><td>#${esc(u.name)}</td><td>${modelCell}</td><td>${servers}</td></tr>`;
    }).join('');
    return `<table class="ai-users-table">
        <thead><tr>
            <th>${esc(t('chat.cmd.ai-users-col-user'))}</th>
            <th>${esc(t('chat.cmd.ai-users-col-model'))}</th>
            <th>${esc(t('chat.cmd.ai-users-col-mcp'))}</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
};

export const showAiUsersOverview = async () => {
    const res = await api.call('get_ai_users_overview');
    const list = res.success ? (res.data || []) : [];

    if (!list.length) {
        await confirmModal(t('chat.cmd.ai-users-title'), {
            message: t('chat.cmd.ai-users-none'),
            confirmLabel: t('chat.cmd.ai-users-close'),
            hideCancel: true,
        });
        return;
    }

    await confirmModal(t('chat.cmd.ai-users-title'), {
        messageHtml: buildAiUsersTableHtml(list),
        confirmLabel: t('chat.cmd.ai-users-close'),
        hideCancel: true,
    });
};
