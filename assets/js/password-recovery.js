(() => {
    const resend = document.querySelector('[data-cooldown]');
    const readyAt = Date.now() + Number(resend?.dataset.cooldown || 0) * 1000;
    const updateCooldown = () => {
        if (!resend || resend.form.dataset.submitting) return;
        const remaining = Math.max(0, Math.ceil((readyAt - Date.now()) / 1000));
        resend.disabled = remaining > 0;
        resend.textContent = remaining ? `Resend OTP in ${remaining}s` : 'Resend OTP';
    };
    updateCooldown();
    if (resend) setInterval(updateCooldown, 1000);

    document.querySelectorAll('form[data-busy-label]').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.submitting) { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            const button = form.querySelector('[type="submit"]');
            button.dataset.originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = form.dataset.busyLabel;
            form.setAttribute('aria-busy', 'true');
        });
    });
    // Restore controls if the user returns through browser history.
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-submitting]').forEach(form => {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
            const button = form.querySelector('[type="submit"]');
            button.disabled = false;
            button.textContent = button.dataset.originalLabel;
        });
        updateCooldown();
    });
    document.querySelectorAll('.password-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.target);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} password`);
            button.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        });
    });
})();
