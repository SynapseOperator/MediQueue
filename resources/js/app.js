function restore(form) {
    form.dataset.submitting = 'false';
    const button = form.querySelector('[data-submit-disabled]');
    if (button) {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
        button.textContent = button.dataset.label;
        delete button.dataset.submitDisabled;
    }
}
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-submit-once]')) return;
    if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
    form.dataset.submitting = 'true';
    const button = event.submitter;
    if (button instanceof HTMLButtonElement && !button.disabled) {
        button.dataset.submitDisabled = 'true';
        button.dataset.label = button.textContent.trim();
        // Named buttons submit the chosen payment scenario with the form.
        if (button.name) button.setAttribute('aria-disabled', 'true');
        else button.disabled = true;
        button.textContent = '正在提交…';
    }
    window.setTimeout(() => {
        if (form.dataset.submitting !== 'true') return;
        restore(form);
        let notice = form.querySelector('[data-submit-notice]');
        if (!notice) {
            notice = document.createElement('p');
            notice.dataset.submitNotice = 'true';
            notice.setAttribute('role', 'status');
            notice.className = 'mt-3 text-sm text-amber-800';
            form.appendChild(notice);
        }
        notice.textContent = '网络不稳定，请先查看预约或订单状态，不要重复提交。';
    }, 15000);
});
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const menu = document.querySelector('.nav-mobile[open]');
    if (menu) {
        menu.open = false;
        menu.querySelector('summary').focus();
    }
});
window.addEventListener('pageshow', (event) => {
    if (event.persisted) document.querySelectorAll('form[data-submit-once]').forEach(restore);
});
