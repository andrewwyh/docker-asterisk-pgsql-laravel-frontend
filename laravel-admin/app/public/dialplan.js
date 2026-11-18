(() => {
    const form = document.querySelector('[data-dialplan-form]');
    if (!form) return;
    const container = form.querySelector('[data-steps]');
    const reindex = () => [...container.children].forEach((row, index) => {
        row.querySelector('[data-position]').textContent = index + 1;
        row.querySelectorAll('[data-field]').forEach(input => {
            input.name = `steps[${index}][${input.dataset.field}]`;
        });
    });
    form.querySelector('[data-add-step]').addEventListener('click', () => {
        if (container.children.length >= 20) return;
        container.append(form.querySelector('[data-step-template]').content.cloneNode(true));
        reindex();
    });
    container.addEventListener('click', event => {
        const button = event.target.closest('[data-step-action]');
        if (!button) return;
        const row = button.closest('[data-step]');
        if (button.dataset.stepAction === 'remove') row.remove();
        if (button.dataset.stepAction === 'up' && row.previousElementSibling) container.insertBefore(row, row.previousElementSibling);
        if (button.dataset.stepAction === 'down' && row.nextElementSibling) row.nextElementSibling.after(row);
        reindex();
    });
    form.addEventListener('submit', reindex);
})();
