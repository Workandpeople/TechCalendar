import { ApiError, apiFetch, isNetworkError } from './client';
import { ChangePasswordPayload, LoginPayload, MobileUser } from '../types/api';
import { getCachedUser, setCachedUser } from '../storage/cache';
import {
  clearToken,
  getOfflineCredentials,
  setBiometricCredentials,
  setOfflineCredentials,
  setToken,
} from '../storage/secure';

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
  await setOfflineCredentials(email, password);
  await setBiometricCredentials(email, password);
  await setCachedUser(payload.user);

  return payload.user;
}

export async function offlineLogin(email: string, password: string): Promise<MobileUser | null> {
  const credentials = await getOfflineCredentials();
  const cachedUser = await getCachedUser();

  if (!credentials || !cachedUser) {
    return null;
  }

  const normalizedEmail = email.trim().toLowerCase();

  if (credentials.email !== normalizedEmail || credentials.password !== password) {
    return null;
  }

  return cachedUser;
}

export async function restoreOnlineSessionFromOfflineCredentials(): Promise<MobileUser> {
  const credentials = await getOfflineCredentials();

  if (!credentials) {
    throw new Error('Aucune session hors ligne disponible sur ce téléphone.');
  }

  return login(credentials.email, credentials.password);
}

export async function me(): Promise<MobileUser> {
  const payload = await apiFetch<{ user: MobileUser }>('/mobile/me', {
    auth: true,
  });

  await setCachedUser(payload.user);

  return payload.user;
}

export async function changeFirstPassword(password: string, passwordConfirmation: string): Promise<MobileUser> {
  const payload = await apiFetch<ChangePasswordPayload>('/mobile/first-password', {
    auth: true,
    method: 'POST',
    body: JSON.stringify({
      password,
      password_confirmation: passwordConfirmation,
    }),
  });

  await setCachedUser(payload.user);
  await setOfflineCredentials(payload.user.email, password);
  await setBiometricCredentials(payload.user.email, password);

  return payload.user;
}

export async function logout(): Promise<void> {
  try {
    await apiFetch('/mobile/logout', {
      auth: true,
      method: 'POST',
    });
  } catch (exception) {
    if (!isNetworkError(exception) && !(exception instanceof ApiError && exception.status === 401)) {
      throw exception;
    }
  } finally {
    await clearToken();
  }
}
