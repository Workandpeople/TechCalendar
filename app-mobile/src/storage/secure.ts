import * as Keychain from 'react-native-keychain';

const TOKEN_SERVICE = 'tech-calendar.mobile-token';
const OFFLINE_CREDENTIALS_SERVICE = 'tech-calendar.offline-credentials';
const BIOMETRIC_CREDENTIALS_SERVICE = 'tech-calendar.biometric-credentials';

type StoredCredentials = {
  email: string;
  password: string;
};

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

export async function getOfflineCredentials(): Promise<StoredCredentials | null> {
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

export async function getBiometryType(): Promise<Keychain.BIOMETRY_TYPE | null> {
  try {
    return await Keychain.getSupportedBiometryType();
  } catch {
    return null;
  }
}

export async function hasBiometricCredentials(): Promise<boolean> {
  try {
    return await Keychain.hasGenericPassword({
      service: BIOMETRIC_CREDENTIALS_SERVICE,
    });
  } catch {
    return false;
  }
}

export async function setBiometricCredentials(email: string, password: string): Promise<boolean> {
  const biometryType = await getBiometryType();

  if (!biometryType) {
    return false;
  }

  try {
    const result = await Keychain.setGenericPassword(email.trim().toLowerCase(), password, {
      service: BIOMETRIC_CREDENTIALS_SERVICE,
      accessible: Keychain.ACCESSIBLE.WHEN_PASSCODE_SET_THIS_DEVICE_ONLY,
      accessControl: Keychain.ACCESS_CONTROL.BIOMETRY_CURRENT_SET,
      authenticationPrompt: biometricPrompt('Activer la connexion biométrique'),
    });

    return Boolean(result);
  } catch {
    return false;
  }
}

export async function setBiometricCredentialsFromOfflineCredentials(): Promise<boolean> {
  const credentials = await getOfflineCredentials();

  if (!credentials) {
    return false;
  }

  return setBiometricCredentials(credentials.email, credentials.password);
}

export async function getBiometricCredentials(): Promise<StoredCredentials | null> {
  try {
    const credentials = await Keychain.getGenericPassword({
      service: BIOMETRIC_CREDENTIALS_SERVICE,
      accessControl: Keychain.ACCESS_CONTROL.BIOMETRY_CURRENT_SET,
      authenticationPrompt: biometricPrompt('Déverrouiller Tech Calendar'),
    });

    return credentials
      ? { email: credentials.username, password: credentials.password }
      : null;
  } catch {
    return null;
  }
}

export async function clearBiometricCredentials(): Promise<void> {
  await Keychain.resetGenericPassword({
    service: BIOMETRIC_CREDENTIALS_SERVICE,
  });
}

function biometricPrompt(title: string) {
  return {
    title,
    subtitle: 'Planning technicien',
    description: 'Confirme ton identité pour accéder au planning hors ligne.',
    cancel: 'Annuler',
  };
}
