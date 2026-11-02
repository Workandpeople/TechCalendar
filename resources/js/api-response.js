export async function readApiJson(response) {
    const status = Number(response.status);
    const fail = message => {
        const error = new Error(message);
        error.name = 'ApiResponseError';
        error.status = status;
        throw error;
    };
    if ([401, 419].includes(status)) fail('Votre session a expiré. Rechargez la page et reconnectez-vous si nécessaire.');
    if (response.redirected) {
        let login = false;
        try { login = new URL(response.url).pathname === '/login'; } catch {}
        fail(login ? 'Votre session a expiré. Rechargez la page et reconnectez-vous si nécessaire.'
            : 'La demande a été redirigée au lieu d’être traitée. Rechargez la page avant de réessayer.');
    }
    const reference = response.headers?.get('X-Error-Reference') || '';
    const suffix = /^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(reference) ? ` Référence : ${reference}.` : '';
    const unavailable = () => fail(`Le serveur n’a pas pu terminer la demande (HTTP ${status || 'inconnu'}). Réessayez dans un instant.${suffix}`);
    if (status >= 500) unavailable();
    const type = response.headers?.get('Content-Type') || '';
    if (!/^application\/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)/i.test(type)) unavailable();
    let data;
    try { data = await response.json(); } catch (error) {
        if (error.name === 'AbortError') throw error;
        unavailable();
    }
    if (!data || typeof data !== 'object' || Array.isArray(data)) unavailable();
    return data;
}
