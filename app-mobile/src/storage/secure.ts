import * as Keychain from 'react-native-keychain';

const TOKEN_SERVICE = 'tech-calendar.mobile-token';

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
