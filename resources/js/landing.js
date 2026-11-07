// Landing page: shop-the-look pins and waitlist forms. Everything here is an
// enhancement; the page and both forms work without JavaScript.

function initLook(root) {
    const pins = [...root.querySelectorAll('[data-pin]')];

    const close = (pin, { focus = false } = {}) => {
        pin.setAttribute('aria-expanded', 'false');
        document.getElementById(pin.getAttribute('aria-controls')).hidden = true;
        if (focus) pin.focus();
    };

    const open = (pin) => {
        pins.filter((p) => p !== pin).forEach((p) => close(p));
        pin.setAttribute('aria-expanded', 'true');
        document.getElementById(pin.getAttribute('aria-controls')).hidden = false;
    };

    pins.forEach((pin) => {
        pin.addEventListener('click', () => {
            pin.getAttribute('aria-expanded') === 'true' ? close(pin) : open(pin);
        });
    });

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const openPin = pins.find((p) => p.getAttribute('aria-expanded') === 'true');
        if (openPin) close(openPin, { focus: true });
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) pins.forEach((p) => close(p));
    });
}

function initWaitlistForm(form) {
    const button = form.querySelector('[type="submit"]');
    const status = form.querySelector('[data-form-status]');
    const success = document.getElementById(form.dataset.success);

    const clearErrors = () => {
        form.querySelectorAll('[data-error-for]').forEach((el) => (el.textContent = ''));
        form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
        status.textContent = '';
    };

    const showErrors = (errors) => {
        let first = null;
        for (const [key, messages] of Object.entries(errors)) {
            const name = key.split('.')[0]; // event_types.0 -> event_types
            const slot = form.querySelector(`[data-error-for="${name}"]`);
            if (slot) slot.textContent = messages[0];
            const input = form.querySelector(`[name="${name}"], [name="${name}[]"]`);
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                first ??= input;
            }
        }
        status.textContent = 'Please check the highlighted fields.';
        first?.focus();
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
            });
            const body = await response.json().catch(() => ({}));

            if (response.ok) {
                success.querySelector('[data-success-message]').textContent = body.message;
                form.hidden = true;
                success.hidden = false;
                success.focus();
                return;
            }

            if (response.status === 422) showErrors(body.errors ?? {});
            else if (response.status === 429) status.textContent = 'Too many tries. Please wait a minute and try again.';
            else if (response.status === 419) status.textContent = 'This page has been open a while. Refresh it and try again.';
            else status.textContent = 'Something went wrong on our side. Please try again.';
        } catch {
            status.textContent = 'You seem to be offline. Check your connection and try again.';
        } finally {
            button.disabled = false;
            form.removeAttribute('aria-busy');
        }
    });
}

document.querySelectorAll('[data-look]').forEach(initLook);
document.querySelectorAll('[data-waitlist-form]').forEach(initWaitlistForm);
