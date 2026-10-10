/**
 * Show or hide the password next to a toggle button.
 *
 * The plain login page does not load Alpine. This keeps the control working
 * until Alpine is started. Once Alpine is present it owns the same button.
 *
 * @param {HTMLButtonElement} button
 * @returns {void}
 */
function toggle_password_visibility(button) {
    const field = button.closest('[data-password-field]');
    const input = field?.querySelector('input');

    if (! (input instanceof HTMLInputElement)) {
        return;
    }

    const shown = input.type === 'password';

    input.type = shown ? 'text' : 'password';
    button.setAttribute('aria-pressed', shown ? 'true' : 'false');
    button.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');

    field.querySelectorAll('[data-password-icon]').forEach((icon) => {
        const visible = icon.getAttribute('data-password-icon') === (shown ? 'hide' : 'show');

        icon.classList.toggle('hidden', ! visible);

        if (visible) {
            icon.removeAttribute('x-cloak');
        } else {
            icon.setAttribute('x-cloak', '');
        }
    });
}

document.addEventListener('click', (event) => {
    if (window.Alpine) {
        return;
    }

    const target = event.target;

    if (! (target instanceof Element)) {
        return;
    }

    const button = target.closest('[data-password-toggle]');

    if (button instanceof HTMLButtonElement && ! button.disabled) {
        toggle_password_visibility(button);
    }
});
