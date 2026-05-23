document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-password-toggle]');

    if (!trigger) {
        return;
    }

    const targetId = trigger.getAttribute('data-target');
    const input = targetId ? document.getElementById(targetId) : null;

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    trigger.textContent = isPassword ? 'Hide' : 'Show';
});