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
