document.querySelectorAll('[data-show-student-passwords]').forEach(toggle => {
    const fields = [...toggle.closest('fieldset').querySelectorAll('[autocomplete="new-password"]')];
    const [password, confirmation] = fields;
    const checkConfirmation = () => {
        confirmation.setCustomValidity(confirmation.value && password.value !== confirmation.value ? 'Password and confirmation do not match.' : '');
    };
    toggle.addEventListener('change', () => {
        fields.forEach(input => { input.type = toggle.checked ? 'text' : 'password'; });
    });
    fields.forEach(input => input.addEventListener('input', checkConfirmation));
    toggle.closest('form').addEventListener('reset', () => {
        fields.forEach(input => { input.type = 'password'; input.setCustomValidity(''); });
    });
});
