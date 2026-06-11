const reverbConfig = {
    key: window.TechCalendarReverbConfig?.key || import.meta.env.VITE_REVERB_APP_KEY,
    host: window.TechCalendarReverbConfig?.host || import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    port: window.TechCalendarReverbConfig?.port || import.meta.env.VITE_REVERB_PORT,
    scheme: window.TechCalendarReverbConfig?.scheme || import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', ''),
};

const safeJsonParse = (value, fallback = null) => {
    if (value && typeof value === 'object') {
        return value;
    }

    try {
        return JSON.parse(value);
    } catch (error) {
        return fallback;
    }
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const reverbSocketUrl = () => {
    if (!reverbConfig.key || !reverbConfig.host) {
        return null;
    }

    const usesTls = ['https', 'wss'].includes(String(reverbConfig.scheme));
    const protocol = usesTls ? 'wss' : 'ws';
    const port = reverbConfig.port ? `:${reverbConfig.port}` : '';

    return `${protocol}://${reverbConfig.host}${port}/app/${encodeURIComponent(reverbConfig.key)}?protocol=7&client=techcalendar&version=1.0&flash=false`;
};

const subscribePrivateReverbChannel = (channelName, eventName, callback, options = {}) => {
    const url = reverbSocketUrl();

    if (!url || typeof WebSocket === 'undefined') {
        options.onError?.(new Error('Reverb websocket unavailable.'));
        return { unsubscribe: () => {} };
    }

    const privateChannelName = channelName.startsWith('private-') ? channelName : `private-${channelName}`;
    let socket = null;
    let closedByClient = false;

    const subscribe = async (socketId) => {
        const response = await fetch('/broadcasting/auth', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                socket_id: socketId,
                channel_name: privateChannelName,
            }),
        });

        if (!response.ok) {
            throw new Error('Broadcast auth failed.');
        }

        const auth = await response.json();

        socket?.send(JSON.stringify({
            event: 'pusher:subscribe',
            data: {
                auth: auth.auth,
                channel: privateChannelName,
                ...(auth.channel_data ? { channel_data: auth.channel_data } : {}),
            },
        }));
    };

    socket = new WebSocket(url);
    options.onState?.('connecting');

    socket.addEventListener('open', () => {
        options.onState?.('connected');
    });

    socket.addEventListener('message', async (message) => {
        const envelope = safeJsonParse(message.data, {});
        const payload = safeJsonParse(envelope.data, envelope.data || {});

        if (envelope.event === 'pusher:connection_established') {
            try {
                await subscribe(payload?.socket_id);
                options.onState?.('subscribed');
            } catch (error) {
                options.onError?.(error);
            }

            return;
        }

        if (envelope.event === 'pusher:error') {
            options.onError?.(new Error(payload?.message || 'Reverb error.'));
            return;
        }

        if (envelope.channel !== privateChannelName) {
            return;
        }

        const expectedNames = new Set([eventName, `.${eventName}`]);

        if (!expectedNames.has(envelope.event)) {
            return;
        }

        callback(payload);
    });

    socket.addEventListener('close', () => {
        options.onState?.(closedByClient ? 'closed' : 'disconnected');
    });

    socket.addEventListener('error', () => {
        options.onError?.(new Error('Reverb websocket error.'));
    });

    return {
        unsubscribe: () => {
            closedByClient = true;
            socket?.close();
        },
    };
};

window.TechCalendarReverb = {
    subscribePrivate: subscribePrivateReverbChannel,
};

const fieldLabel = (field) => {
    const explicitLabel = field.getAttribute('data-label');
    const label = explicitLabel
        || document.querySelector(`label[for="${field.id}"]`)?.textContent
        || field.closest('label')?.textContent
        || field.name
        || 'Ce champ';

    return label.trim().replace(/\s+/g, ' ');
};

const visibleForValidation = (element) => {
    if (element.disabled) return false;
    if (element.type === 'hidden') return false;
    if (element.closest('.hidden')) return false;

    return true;
};

const ensureMessageElement = (field) => {
    if (field.dataset.validationMessageId) {
        return document.getElementById(field.dataset.validationMessageId);
    }

    const message = document.createElement('p');
    message.className = 'gc-field-message hidden';
    message.id = `validation-message-${Math.random().toString(36).slice(2)}`;
    field.dataset.validationMessageId = message.id;
    field.insertAdjacentElement('afterend', message);

    return message;
};

const ensureGroupMessageElement = (group) => {
    if (group.dataset.validationMessageId) {
        return document.getElementById(group.dataset.validationMessageId);
    }

    const message = document.createElement('p');
    message.className = 'gc-field-message hidden';
    message.id = `validation-message-${Math.random().toString(36).slice(2)}`;
    group.dataset.validationMessageId = message.id;
    group.insertAdjacentElement('afterend', message);

    return message;
};

const setFieldState = (field, isValid, message = '', showValidState = false) => {
    const messageElement = ensureMessageElement(field);

    field.classList.toggle('is-invalid', !isValid && message !== '');
    field.classList.toggle('is-valid', isValid && showValidState);

    messageElement.textContent = message;
    messageElement.classList.toggle('hidden', message === '');
    messageElement.classList.toggle('is-valid', isValid);
};

const validatePasswordRules = (password) => ({
    length: password.length >= 8,
    uppercase: /[A-Z]/.test(password),
    lowercase: /[a-z]/.test(password),
    number: /\d/.test(password),
});

const renderPasswordRules = (field) => {
    if (!field.matches('[data-password-rules]')) return;

    let container = field.parentElement?.querySelector('[data-password-rule-list]');

    if (!container) {
        container = document.createElement('div');
        container.className = 'gc-password-rules';
        container.dataset.passwordRuleList = 'true';
        field.insertAdjacentElement('afterend', container);
    }

    const rules = validatePasswordRules(field.value);
    const labels = {
        length: '8 caracteres minimum',
        uppercase: 'Au moins une majuscule',
        lowercase: 'Au moins une minuscule',
        number: 'Au moins un chiffre',
    };

    container.innerHTML = Object.entries(labels)
        .map(([rule, label]) => `
            <div class="gc-password-rule ${rules[rule] ? 'is-valid' : 'is-invalid'}">
                ${rules[rule] ? 'OK' : 'A faire'} - ${label}
            </div>
        `)
        .join('');
};

const fieldError = (field, form) => {
    if (!visibleForValidation(field)) return '';

    const label = fieldLabel(field);
    const value = field.value.trim();

    if (field.required && value === '') {
        return `${label} est obligatoire.`;
    }

    if (value === '') return '';

    if (field.type === 'email' && !field.validity.valid) {
        return 'Renseigne une adresse email valide.';
    }

    if (field.type === 'number') {
        const numericValue = Number(value);

        if (Number.isNaN(numericValue)) {
            return `${label} doit etre un nombre.`;
        }

        if (field.min !== '' && numericValue < Number(field.min)) {
            return `${label} doit etre au minimum ${field.min}.`;
        }

        if (field.max !== '' && numericValue > Number(field.max)) {
            return `${label} doit etre au maximum ${field.max}.`;
        }
    }

    if (field.maxLength > -1 && value.length > field.maxLength) {
        return `${label} ne doit pas depasser ${field.maxLength} caracteres.`;
    }

    if (field.matches('[data-password-rules]')) {
        const rules = validatePasswordRules(value);

        if (!Object.values(rules).every(Boolean)) {
            return 'Le mot de passe ne respecte pas toutes les regles.';
        }
    }

    if (field.name === 'password_confirmation') {
        const password = form.querySelector('input[name="password"]');

        if (password && value !== password.value) {
            return 'La confirmation doit etre identique au mot de passe.';
        }
    }

    return '';
};

const checkboxGroupError = (group) => {
    if (group.dataset.validateHiddenGroup === 'true') {
        if (group.closest('.tech-only-fields.hidden')) return '';
    } else if (!visibleForValidation(group)) {
        return '';
    }

    const checkedCount = group.querySelectorAll('input[type="checkbox"]:checked').length;
    const label = group.dataset.validationLabel || 'Cette selection';

    return checkedCount > 0 ? '' : `${label} est obligatoire.`;
};

const setGroupState = (group, isValid, message = '') => {
    const messageElement = ensureGroupMessageElement(group);

    group.classList.toggle('is-invalid', !isValid && message !== '');
    messageElement.textContent = message;
    messageElement.classList.toggle('hidden', message === '');
};

const validateForm = (form, showErrors = false) => {
    const fields = Array.from(form.querySelectorAll('input, select, textarea'))
        .filter((field) => !['button', 'submit', 'reset', 'checkbox', 'radio'].includes(field.type));
    const checkboxGroups = Array.from(form.querySelectorAll('[data-required-checkbox-group]'));
    let isValid = true;

    fields.forEach((field) => {
        renderPasswordRules(field);

        const error = fieldError(field, form);
        const shouldShow = showErrors || field.dataset.touched === '1';

        if (error !== '') {
            isValid = false;
        }

        setFieldState(field, error === '', shouldShow ? error : '', shouldShow && field.value.trim() !== '');
    });

    checkboxGroups.forEach((group) => {
        const error = checkboxGroupError(group);

        if (error !== '') {
            isValid = false;
        }

        setGroupState(group, error === '', showErrors || group.dataset.touched === '1' ? error : '');
    });

    form.querySelectorAll('[type="submit"]').forEach((button) => {
        if (button.form !== form) return;

        button.disabled = !isValid;
    });

    return isValid;
};

const initValidatedForms = () => {
    document.querySelectorAll('form[data-validate-form]').forEach((form) => {
        form.noValidate = true;
        const refresh = (showErrors = false) => validateForm(form, showErrors);

        form.addEventListener('input', (event) => {
            event.target.dataset.touched = '1';
            refresh();
        });

        form.addEventListener('change', (event) => {
            event.target.dataset.touched = '1';
            event.target.closest('[data-required-checkbox-group]')?.setAttribute('data-touched', '1');
            refresh();
        });

        form.addEventListener('submit', (event) => {
            if (!refresh(true)) {
                event.preventDefault();
                form.querySelector('.is-invalid, .gc-validation-group.is-invalid')?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                });
            }
        });

        refresh();
    });
};

document.addEventListener('DOMContentLoaded', initValidatedForms);

window.TechCalendarForms = {
    refresh: (form) => validateForm(form, false),
    refreshAll: initValidatedForms,
};
