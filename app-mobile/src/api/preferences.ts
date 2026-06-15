import { apiFetch } from './client';
import { MobileUser } from '../types/api';
import { setCachedUser } from '../storage/cache';

export type MobileNotificationPreferences = {
  notification_mail_enabled: boolean;
  notification_push_enabled: boolean;
};

export async function updateNotificationPreferences(
  preferences: MobileNotificationPreferences,
): Promise<MobileUser> {
  const payload = await apiFetch<{ message: string; user: MobileUser }>('/mobile/preferences', {
    auth: true,
    method: 'PATCH',
    body: JSON.stringify(preferences),
  });

  await setCachedUser(payload.user);

  return payload.user;
}

export async function registerPushToken(token: string, platform: string): Promise<void> {
  await apiFetch('/mobile/push-tokens', {
    auth: true,
    method: 'POST',
    body: JSON.stringify({
      token,
      platform,
      device_name: 'Tech Calendar mobile',
    }),
  });
}
