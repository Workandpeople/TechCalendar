import { apiFetch } from './client';
import { LoginPayload, MobileUser } from '../types/api';
import { clearToken, setToken } from '../storage/secure';

export async function login(email: string, password: string): Promise<MobileUser> {
  const payload = await apiFetch<LoginPayload>('/mobile/login', {
    method: 'POST',
    body: JSON.stringify({
      email,
      password,
      device_name: 'Tech Calendar mobile',
    }),
  });

  await setToken(payload.token);

  return payload.user;
}

export async function me(): Promise<MobileUser> {
  const payload = await apiFetch<{ user: MobileUser }>('/mobile/me', {
    auth: true,
  });

  return payload.user;
}

export async function logout(): Promise<void> {
  try {
    await apiFetch('/mobile/logout', {
      auth: true,
      method: 'POST',
    });
  } finally {
    await clearToken();
  }
}
