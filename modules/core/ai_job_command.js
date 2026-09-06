// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * `/aiJob #AiUser <prompt>` — queue a background job from a chat prompt.
 *
 * Shared by `chat` and `page_chat` for the reason this file exists at all: the two
 * composers each kept their own copy of the command list and their own dispatch, and
 * `/aiJob` was only ever added to one of them. In Page Chat the line fell through to
 * `post_chat_message` and was posted as an ordinary message — which, because it contains
 * an AI mention, then triggered an ordinary inline reply instead of a job.
 *
 * The two callers differ only in which thread they are on and how they repaint it, so
 * that is all they pass in.
 *
 * @param {string} arg        everything after the command word
 * @param {object} ctx
 * @param {string} ctx.chatPath   the `.chat` file to queue against
 * @param {string} ctx.original   the full line, put back in the box if the queue fails
 * @param {HTMLTextAreaElement} ctx.textarea
 * @param {HTMLButtonElement}  ctx.sendBtn
 * @param {(text: string) => void} ctx.setText  write the box and resize it
 * @param {(data: object) => void} ctx.render   repaint the thread from the response
 */
import { api } from './api.js';
import { getUsers } from './users.js';
import { showToast } from './utils.js';
import { t } from '../i18n/index.js';

export const runAiJobCommand = async (arg, { chatPath, original, textarea, sendBtn, setText, render }) => {
    // The AI user is mandatory: an expensive background job must never run on a guess
    // about who was meant, so there is deliberately no fall back to the current chat
    // focus here. Name, then a prompt that must contain something other than whitespace
    // — don't depend on the caller having trimmed `arg`.
    const parsed = arg.match(/^[#@]?(\S+)\s+(\S[\s\S]*)$/);
    if (!parsed) { showToast(t('chat.cmd.aijob-usage'), 'error'); return; }
    const [, aiName, jobPrompt] = parsed;
    const ai = (await getUsers()).find(u => u.is_ai && u.name.toLowerCase() === aiName.toLowerCase());
    if (!ai) { showToast(t('chat.cmd.aijob-unknown-ai', { name: aiName }), 'error'); return; }

    setText('');
    sendBtn.disabled = true;
    let jobRes;
    try {
        jobRes = await api.call('queue_agent_job',
            { file: chatPath, ai_user: ai.name, prompt: jobPrompt.trim() }, 'POST');
    } finally {
        sendBtn.disabled = false;
    }

    if (jobRes?.success) {
        render(jobRes.data);
        // eta_minutes is null when the runner has not checked in — say so rather than
        // promising a time that may never come.
        showToast(jobRes.eta_minutes != null
            ? t('chat.cmd.aijob-accepted', { name: jobRes.ai_user, minutes: jobRes.eta_minutes })
            : t('chat.cmd.aijob-accepted-no-eta', { name: jobRes.ai_user }));
    } else {
        // Put the request back so a long prompt isn't lost to an error.
        setText(original);
        showToast(jobRes?.message || t('chat.cmd.aijob-fail'), 'error');
    }
};
