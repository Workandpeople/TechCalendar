import * as Keychain from 'react-native-keychain';

const TOKEN_SERVICE = 'tech-calendar.mobile-token';
const OFFLINE_CREDENTIALS_SERVICE = 'tech-calendar.offline-credentials';

export async function setToken(token: string): Promise<void> {
  await Keychain.setGenericPassword('mobile-token', token, {
    service: TOKEN_SERVICE,
  });
}

export async function getToken(): Promise<string | null> {
  const credentials = await Keychain.getGenericPassword({
    service: TOKEN_SERVICE,
  });

  return credentials ? credentials.password : null;
}

export async function clearToken(): Promise<void> {
  await Keychain.resetGenericPassword({
    service: TOKEN_SERVICE,
  });
}

export async function setOfflineCredentials(email: string, password: string): Promise<void> {
  await Keychain.setGenericPassword(email.trim().toLowerCase(), password, {
    service: OFFLINE_CREDENTIALS_SERVICE,
  });
}

export async function getOfflineCredentials(): Promise<{ email: string; password: string } | null> {
  const credentials = await Keychain.getGenericPassword({
    service: OFFLINE_CREDENTIALS_SERVICE,
  });

  return credentials
    ? { email: credentials.username, password: credentials.password }
    : null;
}

export async function clearOfflineCredentials(): Promise<void> {
  await Keychain.resetGenericPassword({
    service: OFFLINE_CREDENTIALS_SERVICE,
  });
}
