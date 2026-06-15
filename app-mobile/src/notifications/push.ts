import { Platform } from 'react-native';
import { getApp } from '@react-native-firebase/app';
import {
  AuthorizationStatus,
  hasPermission,
  getInitialNotification,
  getMessaging,
  getToken,
  onMessage,
  onNotificationOpenedApp,
  onTokenRefresh,
  registerDeviceForRemoteMessages,
  requestPermission,
} from '@react-native-firebase/messaging';
import type { RemoteMessage } from '@react-native-firebase/messaging';
import { registerPushToken } from '../api/preferences';

type MessageHandler = (message: RemoteMessage) => void;

export async function registerForPushNotifications(): Promise<boolean> {
  const messaging = getMessagingInstance();

  if (!messaging) {
    return false;
  }

  try {
    const permission = await requestPermission(messaging);
    if (!isPermissionAuthorized(permission)) {
      return false;
    }

    return registerCurrentDeviceToken(messaging);
  } catch {
    return false;
  }
}

export async function registerPushTokenIfAuthorized(): Promise<boolean> {
  const messaging = getMessagingInstance();

  if (!messaging) {
    return false;
  }

  try {
    const permission = await hasPermission(messaging);

    if (!isPermissionAuthorized(permission)) {
      return false;
    }

    return registerCurrentDeviceToken(messaging);
  } catch {
    return false;
  }
}

export function subscribeToPushTokenRefresh(): () => void {
  const messaging = getMessagingInstance();

  if (!messaging) {
    return () => undefined;
  }

  return onTokenRefresh(messaging, token => {
    registerPushToken(token, Platform.OS === 'ios' || Platform.OS === 'android' ? Platform.OS : 'unknown').catch(() => undefined);
  });
}

export function subscribeToForegroundMessages(handler: MessageHandler): () => void {
  const messaging = getMessagingInstance();

  if (!messaging) {
    return () => undefined;
  }

  return onMessage(messaging, handler);
}

export function subscribeToNotificationOpens(handler: MessageHandler): () => void {
  const messaging = getMessagingInstance();

  if (!messaging) {
    return () => undefined;
  }

  return onNotificationOpenedApp(messaging, handler);
}

export async function getOpeningNotification(): Promise<RemoteMessage | null> {
  const messaging = getMessagingInstance();

  if (!messaging) {
    return null;
  }

  try {
    return await getInitialNotification(messaging);
  } catch {
    return null;
  }
}

function getMessagingInstance() {
  try {
    return getMessaging(getApp());
  } catch {
    return null;
  }
}

function isPermissionAuthorized(permission: number): boolean {
  return permission === AuthorizationStatus.AUTHORIZED
    || permission === AuthorizationStatus.PROVISIONAL;
}

async function registerCurrentDeviceToken(messaging: NonNullable<ReturnType<typeof getMessagingInstance>>): Promise<boolean> {
  await registerDeviceForRemoteMessages(messaging);
  const token = await getToken(messaging);

  if (!token) {
    return false;
  }

  await registerPushToken(token, Platform.OS === 'ios' || Platform.OS === 'android' ? Platform.OS : 'unknown');

  return true;
}
