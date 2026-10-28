document.getElementById('show-passwords').addEventListener('change', function () {
    document.querySelectorAll('#password-form input[autocomplete$="password"]').forEach(input => {
        input.type = this.checked ? 'text' : 'password';
    });
});
const newPassword = document.getElementById('new_password');
const confirmation = document.getElementById('confirm_password');
function checkConfirmation() {
    confirmation.setCustomValidity(confirmation.value && confirmation.value !== newPassword.value
        ? 'The new passwords do not match.' : '');
}
newPassword.addEventListener('input', checkConfirmation);
confirmation.addEventListener('input', checkConfirmation);
