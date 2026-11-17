function restore(form) {
    form.dataset.submitting = 'false';
    const button = form.querySelector('[data-submit-disabled]');
    if (button) {
        button.disabled = false;
        button.textContent = button.dataset.label || '重试';
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
        button.disabled = true;
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
        notice.textContent = '提交结果尚未确认，请先查看已有预约或订单，再使用原操作重试。';
    }, 15000);
});
window.addEventListener('pageshow', (event) => {
    if (event.persisted) document.querySelectorAll('form[data-submit-once]').forEach(restore);
});
