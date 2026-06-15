import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActionSheetIOS,
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Linking,
  Modal,
  Platform,
  Pressable,
  RefreshControl,
  SafeAreaView,
  ScrollView,
  StatusBar,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useNetInfo } from '@react-native-community/netinfo';
import Share from 'react-native-share';
import {
  changeFirstPassword,
  login as apiLogin,
  logout as apiLogout,
  me as apiMe,
  offlineLogin,
  requestPasswordReset,
  restoreOnlineSessionFromOfflineCredentials,
} from './src/api/auth';
import { ApiError, isNetworkError } from './src/api/client';
import { updateNotificationPreferences } from './src/api/preferences';
import { getPlanning } from './src/api/planning';
import {
  getCachedPlanning,
  getCachedPlanningInfo,
  getCachedUser,
  getPushOnboardingDecision,
  markBiometricOnboardingAsked,
  setPushOnboardingDecision,
  wasBiometricOnboardingAsked,
} from './src/storage/cache';
import {
  getBiometricCredentials,
  getBiometryType,
  getToken,
  hasBiometricCredentials,
  setBiometricCredentialsFromOfflineCredentials,
} from './src/storage/secure';
import {
  getOpeningNotification,
  registerForPushNotifications,
  registerPushTokenIfAuthorized,
  subscribeToForegroundMessages,
  subscribeToNotificationOpens,
  subscribeToPushTokenRefresh,
} from './src/notifications/push';
import colors from './src/theme/colors';
import { MobileUser, PlanningAppointment, PlanningCacheInfo, PlanningPayload } from './src/types/api';

type MenuOption = {
  label: string;
  action: () => void | Promise<void>;
  destructive?: boolean;
};

type SessionMode = 'online' | 'offline';

function App(): React.JSX.Element {
  const [user, setUser] = useState<MobileUser | null>(null);
  const [booting, setBooting] = useState(true);
  const [bootNotice, setBootNotice] = useState<string | null>(null);
  const [sessionMode, setSessionMode] = useState<SessionMode>('online');

  useEffect(() => {
    let mounted = true;

    async function bootstrap() {
      try {
        const token = await getToken();
        if (!token) {
          return;
        }

        try {
          const currentUser = await apiMe();
          if (mounted) {
            setUser(currentUser);
            setSessionMode('online');
          }
        } catch (exception) {
          if (isNetworkError(exception)) {
            const cachedUser = await getCachedUser();

            if (cachedUser && mounted) {
              setUser(cachedUser);
              setSessionMode('offline');
              setBootNotice('Mode hors ligne: session restaurée depuis ce téléphone.');
            }

            return;
          }

          await apiLogout();
        }
      } finally {
        if (mounted) {
          setBooting(false);
        }
      }
    }

    bootstrap();

    return () => {
      mounted = false;
    };
  }, []);

  const handleLogin = useCallback(async (email: string, password: string) => {
    try {
      const loggedUser = await apiLogin(email, password);
      setBootNotice(null);
      setSessionMode('online');
      setUser(loggedUser);
    } catch (exception) {
      if (isNetworkError(exception)) {
        const cachedUser = await offlineLogin(email, password);

        if (cachedUser) {
          setBootNotice('Mode hors ligne: données restaurées depuis la dernière synchronisation.');
          setSessionMode('offline');
          setUser(cachedUser);
          return;
        }

        throw new Error('Connexion indisponible. Une première connexion en ligne est nécessaire sur ce téléphone.');
      }

      throw exception;
    }
  }, []);

  const handleLogout = useCallback(async () => {
    await apiLogout();
    setBootNotice(null);
    setSessionMode('online');
    setUser(null);
  }, []);

  const handlePasswordChanged = useCallback((updatedUser: MobileUser) => {
    setSessionMode('online');
    setUser(updatedUser);
  }, []);

  const handleOnlineSessionRestored = useCallback((updatedUser: MobileUser) => {
    setBootNotice(null);
    setSessionMode('online');
    setUser(updatedUser);
  }, []);

  return (
    <SafeAreaProvider>
      <StatusBar barStyle="dark-content" backgroundColor={colors.background} />
      {booting ? (
        <LoadingScreen />
      ) : user?.must_change_password ? (
        <FirstPasswordScreen user={user} notice={bootNotice} onChanged={handlePasswordChanged} onLogout={handleLogout} />
      ) : user ? (
        <PlanningScreen
          user={user}
          initialNotice={bootNotice}
          sessionMode={sessionMode}
          onLogout={handleLogout}
          onOnlineSessionRestored={handleOnlineSessionRestored}
          onUserUpdated={setUser}
        />
      ) : (
        <LoginScreen onLogin={handleLogin} />
      )}
    </SafeAreaProvider>
  );
}

function LoadingScreen(): React.JSX.Element {
  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.centeredScreen}>
        <View style={styles.logoMark}>
          <Text style={styles.logoMarkText}>GC</Text>
        </View>
        <Text style={styles.loadingTitle}>Tech Calendar</Text>
        <Text style={styles.loadingText}>Synchronisation du planning...</Text>
        <ActivityIndicator size="large" color={colors.ink} style={styles.loadingSpinner} />
      </View>
    </SafeAreaView>
  );
}

function formatBiometryLabel(type: string): string {
  switch (type) {
    case 'FaceID':
      return 'Face ID';
    case 'TouchID':
      return 'Touch ID';
    case 'Fingerprint':
      return 'l’empreinte digitale';
    case 'Face':
      return 'la reconnaissance faciale';
    case 'Iris':
      return 'la reconnaissance de l’iris';
    default:
      return 'la biométrie';
  }
}

async function askForBiometricEnrollment(): Promise<void> {
  const [biometryType, alreadyConfigured, alreadyAsked] = await Promise.all([
    getBiometryType(),
    hasBiometricCredentials(),
    wasBiometricOnboardingAsked(),
  ]);

  if (!biometryType || alreadyConfigured || alreadyAsked) {
    return;
  }

  const label = formatBiometryLabel(biometryType);

  await new Promise<void>(resolve => {
    Alert.alert(
      `Activer ${label} ?`,
      'Tu pourras rouvrir ton planning plus vite, même hors ligne, après une première synchronisation sur ce téléphone.',
      [
        {
          text: 'Plus tard',
          style: 'cancel',
          onPress: () => {
            markBiometricOnboardingAsked()
              .catch(() => undefined)
              .finally(() => resolve());
          },
        },
        {
          text: 'Activer',
          onPress: () => {
            markBiometricOnboardingAsked()
              .then(() => setBiometricCredentialsFromOfflineCredentials())
              .then(enabled => {
                if (!enabled) {
                  Alert.alert('Activation impossible', `${label} n’a pas pu être activé sur cet appareil.`);
                }
              })
              .finally(() => resolve());
          },
        },
      ],
    );
  });
}

async function askForPushEnrollment(): Promise<boolean> {
  const decision = await getPushOnboardingDecision();

  if (decision === 'accepted') {
    return registerForPushNotifications();
  }

  if (decision === 'declined') {
    return false;
  }

  return new Promise<boolean>(resolve => {
    Alert.alert(
      'Activer les notifications ?',
      'Les techniciens reçoivent une alerte quand un rendez-vous est ajouté, modifié ou annulé.',
      [
        {
          text: 'Plus tard',
          style: 'cancel',
          onPress: () => {
            setPushOnboardingDecision('declined')
              .catch(() => undefined)
              .finally(() => resolve(false));
          },
        },
        {
          text: 'Autoriser',
          onPress: () => {
            setPushOnboardingDecision('accepted')
              .then(() => registerForPushNotifications())
              .then(resolve)
              .catch(() => resolve(false));
          },
        },
      ],
    );
  });
}

function LoginScreen({ onLogin }: { onLogin: (email: string, password: string) => Promise<void> }): React.JSX.Element {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [resetMode, setResetMode] = useState(false);
  const [resetSentMessage, setResetSentMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [resetSubmitting, setResetSubmitting] = useState(false);
  const [biometricSubmitting, setBiometricSubmitting] = useState(false);
  const [biometricLabel, setBiometricLabel] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const canSubmit = email.includes('@') && password.length >= 1 && !submitting && !biometricSubmitting && !resetSubmitting;
  const canRequestReset = email.includes('@') && !submitting && !resetSubmitting && !biometricSubmitting;

  useEffect(() => {
    let mounted = true;

    async function loadBiometricStatus() {
      const [biometryType, alreadyConfigured] = await Promise.all([
        getBiometryType(),
        hasBiometricCredentials(),
      ]);

      if (mounted && biometryType && alreadyConfigured) {
        setBiometricLabel(formatBiometryLabel(biometryType));
      }
    }

    loadBiometricStatus();

    return () => {
      mounted = false;
    };
  }, []);

  const submit = useCallback(async () => {
    if (!canSubmit) {
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      await onLogin(email.trim(), password);
    } catch (exception) {
      setError(errorMessage(exception));
    } finally {
      setSubmitting(false);
    }
  }, [canSubmit, email, onLogin, password]);

  const submitPasswordReset = useCallback(async () => {
    if (!canRequestReset) {
      return;
    }

    setResetSubmitting(true);
    setResetSentMessage(null);
    setError(null);

    try {
      const message = await requestPasswordReset(email.trim());
      setResetSentMessage(message);
    } catch (exception) {
      if (isNetworkError(exception)) {
        setError('Connexion requise pour demander un lien de réinitialisation.');
      } else {
        setError(errorMessage(exception));
      }
    } finally {
      setResetSubmitting(false);
    }
  }, [canRequestReset, email]);

  const submitWithBiometrics = useCallback(async () => {
    if (!biometricLabel || biometricSubmitting || submitting) {
      return;
    }

    setBiometricSubmitting(true);
    setError(null);

    try {
      const credentials = await getBiometricCredentials();

      if (!credentials) {
        setError('Authentification biométrique annulée ou indisponible.');
        return;
      }

      setEmail(credentials.email);
      await onLogin(credentials.email, credentials.password);
    } catch (exception) {
      setError(errorMessage(exception));
    } finally {
      setBiometricSubmitting(false);
    }
  }, [biometricLabel, biometricSubmitting, onLogin, submitting]);

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView
        behavior={Platform.select({ ios: 'padding', android: undefined })}
        style={styles.loginWrapper}
      >
        <ScrollView contentContainerStyle={styles.loginScroll} keyboardShouldPersistTaps="handled">
          <View style={styles.loginHero}>
            <View style={styles.logoMark}>
              <Text style={styles.logoMarkText}>GC</Text>
            </View>
            <Text style={styles.loginEyebrow}>{resetMode ? 'Mot de passe oublié' : 'Espace technicien'}</Text>
            <Text style={styles.loginTitle}>{resetMode ? 'Réinitialise ton accès.' : 'Connecte-toi à ton planning terrain.'}</Text>
            <Text style={styles.loginSubtitle}>
              {resetMode
                ? 'Saisis ton adresse e-mail. Si elle correspond à un compte technicien actif, tu recevras un lien de réinitialisation.'
                : 'Cette application fonctionne aussi hors ligne après une première synchronisation réussie.'}
            </Text>
          </View>

          <View style={styles.loginCard}>
            <InputLabel label="Adresse e-mail" />
            <TextInput
              value={email}
              onChangeText={value => {
                setEmail(value);
                setResetSentMessage(null);
              }}
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              placeholder="tech@exemple.fr"
              placeholderTextColor={colors.inkMuted}
              style={styles.input}
            />

            {resetMode ? (
              <>
                {resetSentMessage ? <Text style={styles.formSuccess}>{resetSentMessage}</Text> : null}
                {error ? <Text style={styles.formError}>{error}</Text> : null}

                <Pressable
                  accessibilityRole="button"
                  disabled={!canRequestReset}
                  onPress={submitPasswordReset}
                  style={[styles.primaryButton, !canRequestReset && styles.primaryButtonDisabled]}
                >
                  {resetSubmitting ? (
                    <ActivityIndicator color={colors.white} />
                  ) : (
                    <Text style={styles.primaryButtonText}>Envoyer le lien</Text>
                  )}
                </Pressable>

                <Pressable
                  accessibilityRole="button"
                  onPress={() => {
                    setResetMode(false);
                    setResetSentMessage(null);
                    setError(null);
                  }}
                  style={styles.centerLinkButton}
                >
                  <Text style={styles.centerLinkText}>Retour à la connexion</Text>
                </Pressable>
              </>
            ) : (
              <>
                <InputLabel label="Mot de passe" />
                <PasswordInput
                  value={password}
                  onChangeText={setPassword}
                  showPassword={showPassword}
                  onToggleVisibility={() => setShowPassword(current => !current)}
                  placeholder="Mot de passe"
                />

                <Pressable
                  accessibilityRole="button"
                  onPress={() => {
                    setResetMode(true);
                    setResetSentMessage(null);
                    setError(null);
                  }}
                  style={styles.forgotPasswordButton}
                >
                  <Text style={styles.forgotPasswordText}>Mot de passe oublié ?</Text>
                </Pressable>

                {error ? <Text style={styles.formError}>{error}</Text> : null}

                {biometricLabel ? (
                  <Pressable
                    accessibilityRole="button"
                    disabled={submitting || biometricSubmitting}
                    onPress={submitWithBiometrics}
                    style={[styles.biometricButton, (submitting || biometricSubmitting) && styles.primaryButtonDisabled]}
                  >
                    {biometricSubmitting ? (
                      <ActivityIndicator color={colors.ink} />
                    ) : (
                      <>
                        <Text style={styles.biometricButtonIcon}>◇</Text>
                        <Text style={styles.biometricButtonText}>Se connecter avec {biometricLabel}</Text>
                      </>
                    )}
                  </Pressable>
                ) : null}

                <Pressable
                  accessibilityRole="button"
                  disabled={!canSubmit}
                  onPress={submit}
                  style={[styles.primaryButton, !canSubmit && styles.primaryButtonDisabled]}
                >
                  {submitting ? (
                    <ActivityIndicator color={colors.white} />
                  ) : (
                    <Text style={styles.primaryButtonText}>Se connecter</Text>
                  )}
                </Pressable>
              </>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function FirstPasswordScreen({
  user,
  notice,
  onChanged,
  onLogout,
}: {
  user: MobileUser;
  notice: string | null;
  onChanged: (user: MobileUser) => void;
  onLogout: () => Promise<void>;
}): React.JSX.Element {
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canSubmit = password.length >= 8 && confirmation.length >= 8 && password === confirmation && !submitting;

  const submit = useCallback(async () => {
    if (!canSubmit) {
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const updatedUser = await changeFirstPassword(password, confirmation);
      onChanged(updatedUser);
    } catch (exception) {
      if (isNetworkError(exception)) {
        setError('Connexion requise pour remplacer le mot de passe initial.');
      } else {
        setError(errorMessage(exception));
      }
    } finally {
      setSubmitting(false);
    }
  }, [canSubmit, confirmation, onChanged, password]);

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView behavior={Platform.select({ ios: 'padding', android: undefined })} style={styles.loginWrapper}>
        <ScrollView contentContainerStyle={styles.loginScroll} keyboardShouldPersistTaps="handled">
          <View style={styles.loginHero}>
            <View style={styles.logoMark}>
              <Text style={styles.logoMarkText}>{user.initials}</Text>
            </View>
            <Text style={styles.loginEyebrow}>Première connexion</Text>
            <Text style={styles.loginTitle}>Remplace ton mot de passe initial.</Text>
            <Text style={styles.loginSubtitle}>
              Cette étape protège l’accès mobile de {user.full_name}. Elle nécessite une connexion réseau.
            </Text>
          </View>

          <View style={styles.loginCard}>
            {notice ? <Text style={styles.offlineNotice}>{notice}</Text> : null}

            <InputLabel label="Nouveau mot de passe" />
            <PasswordInput
              value={password}
              onChangeText={setPassword}
              showPassword={showPassword}
              onToggleVisibility={() => setShowPassword(current => !current)}
              placeholder="Au moins 8 caractères"
            />

            <InputLabel label="Confirmation" />
            <PasswordInput
              value={confirmation}
              onChangeText={setConfirmation}
              showPassword={showPassword}
              onToggleVisibility={() => setShowPassword(current => !current)}
              placeholder="Confirme le mot de passe"
            />

            {error ? <Text style={styles.formError}>{error}</Text> : null}

            <Pressable
              accessibilityRole="button"
              disabled={!canSubmit}
              onPress={submit}
              style={[styles.primaryButton, !canSubmit && styles.primaryButtonDisabled]}
            >
              {submitting ? (
                <ActivityIndicator color={colors.white} />
              ) : (
                <Text style={styles.primaryButtonText}>Mettre à jour</Text>
              )}
            </Pressable>

            <Pressable accessibilityRole="button" onPress={onLogout} style={styles.centerLinkButton}>
              <Text style={styles.centerLinkText}>Se déconnecter</Text>
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function PlanningScreen({
  user,
  initialNotice,
  sessionMode,
  onLogout,
  onOnlineSessionRestored,
  onUserUpdated,
}: {
  user: MobileUser;
  initialNotice: string | null;
  sessionMode: SessionMode;
  onLogout: () => Promise<void>;
  onOnlineSessionRestored: (user: MobileUser) => void;
  onUserUpdated: (user: MobileUser) => void;
}): React.JSX.Element {
  const netInfo = useNetInfo();
  const reconnectingRef = useRef(false);
  const onboardingPromptsStartedRef = useRef(false);
  const [planning, setPlanning] = useState<PlanningPayload | null>(null);
  const [cacheInfo, setCacheInfo] = useState<PlanningCacheInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [reconnecting, setReconnecting] = useState(false);
  const [restoreBlocked, setRestoreBlocked] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [offlineNotice, setOfflineNotice] = useState<string | null>(initialNotice);
  const [selectedDate, setSelectedDate] = useState(toDateKey(new Date()));
  const [selectedAppointment, setSelectedAppointment] = useState<PlanningAppointment | null>(null);
  const [profileOpen, setProfileOpen] = useState(false);
  const [profileVisible, setProfileVisible] = useState(false);

  const loadPlanning = useCallback(async (asRefresh = false) => {
    if (asRefresh) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }
    setError(null);

    try {
      const payload = await getPlanning();
      const updatedCacheInfo = await getCachedPlanningInfo();
      setPlanning(payload);
      setCacheInfo(updatedCacheInfo);
      setOfflineNotice(null);
      setRestoreBlocked(false);
      setSelectedDate(current => selectStableDate(current, payload.appointments));
    } catch (exception) {
      const [cachedPlanning, cachedInfo] = await Promise.all([
        getCachedPlanning(),
        getCachedPlanningInfo(),
      ]);

      if (cachedPlanning && isNetworkError(exception)) {
        setPlanning(cachedPlanning);
        setCacheInfo(cachedInfo);
        setSelectedDate(current => selectStableDate(current, cachedPlanning.appointments));
        setOfflineNotice('Mode hors ligne: planning affiché depuis la dernière synchronisation.');
      } else {
        setError(errorMessage(exception));
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    let mounted = true;

    async function warmStart() {
      const [cachedPlanning, cachedInfo] = await Promise.all([
        getCachedPlanning(),
        getCachedPlanningInfo(),
      ]);

      if (cachedPlanning && mounted) {
        setPlanning(cachedPlanning);
        setCacheInfo(cachedInfo);
        setSelectedDate(current => selectStableDate(current, cachedPlanning.appointments));
        setLoading(false);
      }

      await loadPlanning(Boolean(cachedPlanning));
    }

    warmStart();

    return () => {
      mounted = false;
    };
  }, [loadPlanning]);

  useEffect(() => {
    if (netInfo.isConnected && offlineNotice && planning && sessionMode === 'online') {
      loadPlanning(true);
    }
  }, [loadPlanning, netInfo.isConnected, offlineNotice, planning, sessionMode]);

  useEffect(() => {
    if (netInfo.isConnected === false) {
      setRestoreBlocked(false);
    }
  }, [netInfo.isConnected]);

  useEffect(() => {
    if (
      netInfo.isConnected !== true
      || sessionMode !== 'offline'
      || reconnectingRef.current
      || restoreBlocked
    ) {
      return undefined;
    }

    let cancelled = false;

    async function restoreSession() {
      reconnectingRef.current = true;
      setReconnecting(true);
      setOfflineNotice('Connexion retrouvée: resynchronisation du planning...');
      setError(null);

      try {
        const restoredUser = await restoreOnlineSessionFromOfflineCredentials();

        if (cancelled) {
          return;
        }

        onOnlineSessionRestored(restoredUser);
        await loadPlanning(true);
      } catch (exception) {
        if (!cancelled) {
          setRestoreBlocked(true);
          setOfflineNotice('Mode hors ligne maintenu: reconnecte-toi en ligne pour resynchroniser ce téléphone.');
          setError(errorMessage(exception));
        }
      } finally {
        if (!cancelled) {
          setReconnecting(false);
        }

        reconnectingRef.current = false;
      }
    }

    restoreSession();

    return () => {
      cancelled = true;
    };
  }, [loadPlanning, netInfo.isConnected, onOnlineSessionRestored, restoreBlocked, sessionMode]);

  useEffect(() => {
    if (sessionMode !== 'online' || onboardingPromptsStartedRef.current) {
      return;
    }

    let cancelled = false;
    onboardingPromptsStartedRef.current = true;

    async function runOnboardingPrompts() {
      await askForBiometricEnrollment();

      if (!cancelled && user.notification_push_enabled) {
        await askForPushEnrollment();
      }
    }

    runOnboardingPrompts().catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [sessionMode, user.notification_push_enabled]);

  useEffect(() => {
    if (!user.notification_push_enabled) {
      return undefined;
    }

    const unsubscribeTokenRefresh = subscribeToPushTokenRefresh();
    registerPushTokenIfAuthorized().catch(() => undefined);

    const unsubscribeForeground = subscribeToForegroundMessages(message => {
      Alert.alert(
        message.notification?.title || 'Planning mis à jour',
        message.notification?.body || 'Ton planning a été modifié.',
      );
      loadPlanning(true);
    });
    const unsubscribeOpens = subscribeToNotificationOpens(() => loadPlanning(true));

    getOpeningNotification().then(message => {
      if (message) {
        loadPlanning(true);
      }
    }).catch(() => undefined);

    return () => {
      unsubscribeTokenRefresh();
      unsubscribeForeground();
      unsubscribeOpens();
    };
  }, [loadPlanning, user.notification_push_enabled]);

  const appointmentsByDate = useMemo(() => groupAppointmentsByDate(planning?.appointments ?? []), [planning]);
  const selectedAppointments = appointmentsByDate.get(selectedDate) ?? [];
  const weekDays = useMemo(
    () => buildWeekDays(selectedDate, appointmentsByDate),
    [appointmentsByDate, selectedDate],
  );
  const canExport = Boolean(planning?.appointments.length);

  const refreshControl = (
    <RefreshControl
      refreshing={refreshing}
      onRefresh={() => loadPlanning(true)}
      tintColor={colors.ink}
      colors={[colors.ink]}
    />
  );

  return (
    <SafeAreaView style={styles.safeArea}>
      <ScrollView
        style={styles.planningScroll}
        contentContainerStyle={styles.planningContent}
        refreshControl={refreshControl}
      >
        <View style={styles.topBar}>
          <View>
            <Text style={styles.helloText}>Bonjour</Text>
            <Text style={styles.userName}>{user.full_name}</Text>
          </View>
          <View style={styles.profileMenuWrapper}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Menu utilisateur"
              onPress={() => setProfileOpen(current => !current)}
              style={styles.avatar}
            >
              <Text style={styles.avatarText}>{user.initials}</Text>
            </Pressable>
            {profileOpen ? (
              <View style={styles.profileDropdown}>
                <Text style={styles.profileDropdownName}>{user.full_name}</Text>
                <Text style={styles.profileDropdownEmail}>{user.email}</Text>
                <Pressable
                  accessibilityRole="button"
                  onPress={() => {
                    setProfileOpen(false);
                    setProfileVisible(true);
                  }}
                  style={styles.dropdownProfileButton}
                >
                  <Text style={styles.dropdownProfileText}>Profil</Text>
                </Pressable>
                <Pressable accessibilityRole="button" onPress={onLogout} style={styles.dropdownLogoutButton}>
                  <Text style={styles.dropdownLogoutText}>Déconnexion</Text>
                </Pressable>
              </View>
            ) : null}
          </View>
        </View>

        {offlineNotice ? <Text style={styles.offlineBanner}>{offlineNotice}</Text> : null}
        {netInfo.isConnected === false ? <Text style={styles.offlineBanner}>Aucune connexion détectée.</Text> : null}
        {reconnecting ? (
          <View style={styles.syncStatusCard}>
            <ActivityIndicator color={colors.ink} />
            <View style={styles.syncStatusTextBlock}>
              <Text style={styles.syncStatusTitle}>Retour en ligne détecté</Text>
              <Text style={styles.syncStatusText}>Restauration de la session et synchronisation du planning...</Text>
            </View>
          </View>
        ) : null}

        {loading && !planning ? (
          <View style={styles.mobileLoader}>
            <ActivityIndicator size="large" color={colors.ink} />
            <Text style={styles.mobileLoaderText}>Chargement du planning...</Text>
          </View>
        ) : error && !planning ? (
          <View style={styles.errorCard}>
            <Text style={styles.errorTitle}>Planning indisponible</Text>
            <Text style={styles.errorText}>{error}</Text>
            <Pressable accessibilityRole="button" onPress={() => loadPlanning()} style={styles.secondaryButton}>
              <Text style={styles.secondaryButtonText}>Réessayer</Text>
            </Pressable>
          </View>
        ) : planning ? (
          <>
            <View style={styles.widgetsGrid}>
              <WidgetCard label="Aujourd’hui" value={String(planning.widgets.today_count)} detail="RDV prévus" tone="blue" />
              <WidgetCard
                label="Semaine"
                value={String(planning.widgets.week_count)}
                detail={`${planning.widgets.week_planned_hours}h planifiées`}
                tone="green"
              />
              <WidgetCard label="Km semaine" value={`${planning.widgets.week_drive_km}`} detail="km estimés" tone="gold" />
              <WidgetCard
                label="Heures supp."
                value={`${planning.widgets.week_overtime_hours}h`}
                detail="trajets inclus"
                tone="coral"
              />
            </View>

            <SyncInfoCard
              cacheInfo={cacheInfo}
              isOffline={sessionMode === 'offline' || netInfo.isConnected === false}
            />

            <NextAppointmentCard
              appointment={planning.widgets.next_appointment}
              onPress={appointment => setSelectedAppointment(appointment)}
            />

            <View style={styles.sectionHeader}>
              <View>
                <Text style={styles.sectionTitle}>Calendrier</Text>
                <Text style={styles.sectionMeta}>{planning.appointments.length} RDV synchronisés</Text>
              </View>
              <Pressable
                accessibilityRole="button"
                disabled={!canExport}
                onPress={() => exportCalendar(planning.appointments)}
                style={[styles.calendarExportButton, !canExport && styles.primaryButtonDisabled]}
              >
                <Text style={styles.calendarExportText}>Calendrier</Text>
              </Pressable>
            </View>

            <CalendarWeek
              selectedDate={selectedDate}
              weekDays={weekDays}
              appointments={selectedAppointments}
              onSelectDate={setSelectedDate}
              onSelectAppointment={setSelectedAppointment}
            />
          </>
        ) : null}
      </ScrollView>

      <AppointmentDetailsModal
        appointment={selectedAppointment}
        onClose={() => setSelectedAppointment(null)}
      />
      <ProfileModal
        visible={profileVisible}
        user={user}
        onClose={() => setProfileVisible(false)}
        onUserUpdated={onUserUpdated}
      />
    </SafeAreaView>
  );
}

function CalendarWeek({
  selectedDate,
  weekDays,
  appointments,
  onSelectDate,
  onSelectAppointment,
}: {
  selectedDate: string;
  weekDays: Array<{ key: string; weekday: string; day: string; month: string; count: number }>;
  appointments: PlanningAppointment[];
  onSelectDate: (date: string) => void;
  onSelectAppointment: (appointment: PlanningAppointment) => void;
}): React.JSX.Element {
  const moveWeek = useCallback((offset: number) => {
    const date = dateFromKey(selectedDate);
    date.setDate(date.getDate() + offset * 7);
    onSelectDate(toDateKey(date));
  }, [onSelectDate, selectedDate]);

  return (
    <View style={styles.calendarCard}>
      <View style={styles.weekNav}>
        <Pressable accessibilityRole="button" onPress={() => moveWeek(-1)} style={styles.weekNavButton}>
          <Text style={styles.weekNavText}>‹</Text>
        </Pressable>
        <Text style={styles.weekRange}>{formatWeekRange(weekDays[0]?.key, weekDays[6]?.key)}</Text>
        <Pressable accessibilityRole="button" onPress={() => moveWeek(1)} style={styles.weekNavButton}>
          <Text style={styles.weekNavText}>›</Text>
        </Pressable>
      </View>

      <View style={styles.weekGrid}>
        {weekDays.map(day => {
          const selected = day.key === selectedDate;

          return (
            <Pressable
              accessibilityRole="button"
              key={day.key}
              onPress={() => onSelectDate(day.key)}
              style={[styles.weekDay, selected && styles.weekDayActive]}
            >
              <Text style={[styles.weekDayName, selected && styles.weekDayTextActive]}>{day.weekday}</Text>
              <Text style={[styles.weekDayNumber, selected && styles.weekDayTextActive]}>{day.day}</Text>
              <Text style={[styles.weekDayMonth, selected && styles.weekDayTextActive]}>{day.month}</Text>
              <Text style={[styles.weekDayCount, selected && styles.weekDayTextActive]}>{day.count}</Text>
            </Pressable>
          );
        })}
      </View>

      <View style={styles.timelinePanel}>
        <View style={styles.timelineHeader}>
          <Text style={styles.timelineDate}>{formatLongDate(selectedDate)}</Text>
          <Text style={styles.timelineCount}>{appointments.length} RDV</Text>
        </View>

        {appointments.length > 0 ? (
          appointments.map(appointment => (
            <Pressable
              accessibilityRole="button"
              key={appointment.id}
              onPress={() => onSelectAppointment(appointment)}
              style={styles.timelineAppointment}
            >
              <View style={styles.timelineHours}>
                <Text style={styles.timelineStart}>{formatTime(appointment.starts_at)}</Text>
                <View style={styles.timelineLine} />
                <Text style={styles.timelineEnd}>{formatTime(appointment.ends_at)}</Text>
              </View>
              <View style={styles.timelineAppointmentBody}>
                <View style={styles.timelineAppointmentTopline}>
                  <Text style={styles.appointmentCustomer}>{appointment.customer_name}</Text>
                  <Text style={styles.durationBadge}>{formatDuration(appointment.duration_minutes)}</Text>
                </View>
                <Text style={styles.appointmentService}>{appointment.service_label}</Text>
                <Text style={styles.appointmentAddress} numberOfLines={2}>{compactLocation(appointment)}</Text>
              </View>
            </Pressable>
          ))
        ) : (
          <View style={styles.emptyDay}>
            <Text style={styles.emptyDayTitle}>Aucun RDV ce jour-là.</Text>
            <Text style={styles.emptyDayText}>Tire vers le bas pour actualiser dès que tu récupères la connexion.</Text>
          </View>
        )}
      </View>
    </View>
  );
}

function AppointmentDetailsModal({
  appointment,
  onClose,
}: {
  appointment: PlanningAppointment | null;
  onClose: () => void;
}): React.JSX.Element | null {
  if (!appointment) {
    return null;
  }

  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.modalOverlay}>
        <View style={styles.appointmentModal}>
          <View style={styles.modalHandle} />
          <ScrollView showsVerticalScrollIndicator={false}>
            <View style={styles.modalHeader}>
              <View style={styles.modalTitleBlock}>
                <Text style={styles.modalEyebrow}>{formatDayAndTime(appointment.starts_at)}</Text>
                <Text style={styles.modalTitle}>{appointment.customer_name}</Text>
              </View>
              <Pressable accessibilityRole="button" onPress={onClose} style={styles.modalCloseButton}>
                <Text style={styles.modalCloseText}>Fermer</Text>
              </Pressable>
            </View>

            <InfoRow label="Prestation" value={appointment.service_label} />
            <InfoRow label="Horaire" value={`${formatTime(appointment.starts_at)} - ${formatTime(appointment.ends_at)}`} />
            <InfoRow label="Durée" value={formatDuration(appointment.duration_minutes)} />
            {appointment.customer_phone ? <InfoRow label="Téléphone" value={appointment.customer_phone} /> : null}

            <Text style={styles.modalSectionLabel}>Adresse</Text>
            <Pressable accessibilityRole="button" onPress={() => openNavigationMenu(appointment)} style={styles.addressActionCard}>
              <Text style={styles.addressActionText}>{appointment.address}</Text>
              <Text style={styles.addressActionHint}>Toucher pour choisir l’application GPS</Text>
            </Pressable>

            {appointment.comment ? (
              <View style={styles.modalCommentBox}>
                <Text style={styles.modalSectionLabel}>Commentaire</Text>
                <Text style={styles.modalComment}>{appointment.comment}</Text>
              </View>
            ) : null}

            <View style={styles.modalActions}>
              <Pressable accessibilityRole="button" onPress={() => openNavigationMenu(appointment)} style={styles.primaryButtonCompact}>
                <Text style={styles.primaryButtonText}>Ouvrir GPS</Text>
              </Pressable>
              {appointment.customer_phone ? (
                <Pressable accessibilityRole="button" onPress={() => Linking.openURL(`tel:${appointment.customer_phone}`)} style={styles.secondaryButtonCompact}>
                  <Text style={styles.secondaryButtonText}>Appeler</Text>
                </Pressable>
              ) : null}
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

function ProfileModal({
  visible,
  user,
  onClose,
  onUserUpdated,
}: {
  visible: boolean;
  user: MobileUser;
  onClose: () => void;
  onUserUpdated: (user: MobileUser) => void;
}): React.JSX.Element {
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const updatePreference = useCallback(async (key: 'notification_mail_enabled' | 'notification_push_enabled', value: boolean) => {
    if (saving) {
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const updatedUser = await updateNotificationPreferences({
        notification_mail_enabled: key === 'notification_mail_enabled' ? value : user.notification_mail_enabled,
        notification_push_enabled: key === 'notification_push_enabled' ? value : user.notification_push_enabled,
      });

      onUserUpdated(updatedUser);

      if (key === 'notification_push_enabled' && value) {
        setPushOnboardingDecision('accepted')
          .then(() => registerForPushNotifications())
          .catch(() => undefined);
      }
    } catch (exception) {
      setError(errorMessage(exception));
    } finally {
      setSaving(false);
    }
  }, [onUserUpdated, saving, user.notification_mail_enabled, user.notification_push_enabled]);

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.modalOverlay}>
        <View style={styles.appointmentModal}>
          <View style={styles.modalHandle} />
          <ScrollView showsVerticalScrollIndicator={false}>
            <View style={styles.modalHeader}>
              <View style={styles.modalTitleBlock}>
                <Text style={styles.modalEyebrow}>Profil technicien</Text>
                <Text style={styles.modalTitle}>{user.full_name}</Text>
              </View>
              <Pressable accessibilityRole="button" onPress={onClose} style={styles.modalCloseButton}>
                <Text style={styles.modalCloseText}>Fermer</Text>
              </Pressable>
            </View>

            <InfoRow label="Email" value={user.email} />
            {user.phone ? <InfoRow label="Téléphone" value={user.phone} /> : null}
            {user.department_code ? <InfoRow label="Département" value={user.department_code} /> : null}
            {user.address ? <InfoRow label="Adresse" value={user.address} /> : null}
            <InfoRow label="Journée" value={`${user.day_start_time || '--:--'} - ${user.day_end_time || '--:--'}`} />

            <Text style={styles.modalSectionLabel}>Notifications</Text>
            <View style={styles.preferenceCard}>
              <View style={styles.preferenceTextBlock}>
                <Text style={styles.preferenceTitle}>Emails RDV</Text>
                <Text style={styles.preferenceDescription}>Recevoir les notifications de placement, modification ou annulation par email.</Text>
              </View>
              <Switch
                value={user.notification_mail_enabled}
                disabled={saving}
                onValueChange={value => {
                  updatePreference('notification_mail_enabled', value).catch(() => undefined);
                }}
                trackColor={{ false: colors.inkSoft, true: colors.goldSoft }}
                thumbColor={user.notification_mail_enabled ? colors.gold : colors.inkMuted}
              />
            </View>
            <View style={styles.preferenceCard}>
              <View style={styles.preferenceTextBlock}>
                <Text style={styles.preferenceTitle}>Notifications push</Text>
                <Text style={styles.preferenceDescription}>Recevoir les alertes directement sur ce téléphone.</Text>
              </View>
              <Switch
                value={user.notification_push_enabled}
                disabled={saving}
                onValueChange={value => {
                  updatePreference('notification_push_enabled', value).catch(() => undefined);
                }}
                trackColor={{ false: colors.inkSoft, true: colors.goldSoft }}
                thumbColor={user.notification_push_enabled ? colors.gold : colors.inkMuted}
              />
            </View>

            {saving ? <Text style={styles.preferenceSaving}>Mise à jour des préférences...</Text> : null}
            {error ? <Text style={styles.formError}>{error}</Text> : null}
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

function PasswordInput({
  value,
  onChangeText,
  showPassword,
  onToggleVisibility,
  placeholder,
}: {
  value: string;
  onChangeText: (value: string) => void;
  showPassword: boolean;
  onToggleVisibility: () => void;
  placeholder: string;
}): React.JSX.Element {
  return (
    <View style={styles.passwordRow}>
      <TextInput
        value={value}
        onChangeText={onChangeText}
        autoCapitalize="none"
        autoComplete="password"
        secureTextEntry={!showPassword}
        placeholder={placeholder}
        placeholderTextColor={colors.inkMuted}
        style={styles.passwordInput}
      />
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
        onPress={onToggleVisibility}
        style={styles.eyeButton}
      >
        <Text style={styles.eyeButtonText}>{showPassword ? 'Masquer' : 'Voir'}</Text>
      </Pressable>
    </View>
  );
}

function InputLabel({ label }: { label: string }): React.JSX.Element {
  return <Text style={styles.inputLabel}>{label}</Text>;
}

function InfoRow({ label, value }: { label: string; value: string | number }): React.JSX.Element {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
    </View>
  );
}

function WidgetCard({
  label,
  value,
  detail,
  tone,
}: {
  label: string;
  value: string;
  detail: string;
  tone: 'blue' | 'green' | 'gold' | 'coral';
}): React.JSX.Element {
  const toneStyle = widgetToneStyles[tone];

  return (
    <View style={[styles.widgetCard, toneStyle.background]}>
      <Text style={styles.widgetLabel}>{label}</Text>
      <Text style={[styles.widgetValue, toneStyle.text]}>{value}</Text>
      <Text style={styles.widgetDetail}>{detail}</Text>
    </View>
  );
}

function SyncInfoCard({
  cacheInfo,
  isOffline,
}: {
  cacheInfo: PlanningCacheInfo | null;
  isOffline: boolean;
}): React.JSX.Element {
  const title = isOffline ? 'Planning hors ligne' : 'Planning synchronisé';
  const detail = cacheInfo
    ? `Dernière synchro: ${formatDateTime(cacheInfo.cached_at)}`
    : 'Aucune synchronisation locale enregistrée.';

  return (
    <View style={[styles.syncInfoCard, isOffline && styles.syncInfoCardOffline]}>
      <View style={styles.syncInfoDot} />
      <View style={styles.syncInfoTextBlock}>
        <Text style={styles.syncInfoTitle}>{title}</Text>
        <Text style={styles.syncInfoText}>{detail}</Text>
      </View>
    </View>
  );
}

function NextAppointmentCard({
  appointment,
  onPress,
}: {
  appointment: PlanningAppointment | null;
  onPress: (appointment: PlanningAppointment) => void;
}): React.JSX.Element {
  if (!appointment) {
    return (
      <View style={styles.nextCard}>
        <Text style={styles.nextEyebrow}>Prochain RDV</Text>
        <Text style={styles.nextTitle}>Aucun RDV à venir</Text>
        <Text style={styles.nextText}>Ton planning est vide sur la période synchronisée.</Text>
      </View>
    );
  }

  return (
    <Pressable accessibilityRole="button" onPress={() => onPress(appointment)} style={styles.nextCard}>
      <Text style={styles.nextEyebrow}>Prochain RDV</Text>
      <Text style={styles.nextTitle}>{appointment.customer_name}</Text>
      <Text style={styles.nextText}>{appointment.service_label}</Text>
      <View style={styles.nextDetailsRow}>
        <Text style={styles.nextBadge}>{formatDayAndTime(appointment.starts_at)}</Text>
        <Text style={styles.nextBadge}>{formatDuration(appointment.duration_minutes)}</Text>
      </View>
      <Text style={styles.nextAddress}>{appointment.address}</Text>
    </Pressable>
  );
}

function errorMessage(exception: unknown): string {
  if (exception instanceof ApiError) {
    const firstError = exception.errors ? Object.values(exception.errors)[0]?.[0] : null;
    return firstError || exception.message;
  }

  if (exception instanceof Error) {
    return exception.message;
  }

  return 'Une erreur inattendue est survenue.';
}

function showMenu(title: string, options: MenuOption[]): void {
  if (Platform.OS === 'ios') {
    const destructiveButtonIndex = options.findIndex(option => option.destructive);

    ActionSheetIOS.showActionSheetWithOptions(
      {
        title,
        options: [...options.map(option => option.label), 'Annuler'],
        cancelButtonIndex: options.length,
        ...(destructiveButtonIndex >= 0 ? { destructiveButtonIndex } : {}),
      },
      buttonIndex => {
        if (buttonIndex < options.length) {
          options[buttonIndex].action();
        }
      },
    );
    return;
  }

  Alert.alert(title, undefined, [
    ...options.slice(0, 2).map(option => ({
      text: option.label,
      style: option.destructive ? 'destructive' as const : 'default' as const,
      onPress: option.action,
    })),
    { text: 'Annuler', style: 'cancel' },
  ]);
}

function openNavigationMenu(appointment: PlanningAppointment): void {
  const encodedAddress = encodeURIComponent(appointment.address);
  const geoUrl = appointment.latitude && appointment.longitude
    ? `geo:${appointment.latitude},${appointment.longitude}?q=${encodedAddress}`
    : `geo:0,0?q=${encodedAddress}`;
  const appleMapsUrl = `maps://?q=${encodedAddress}`;
  const googleMapsUrl = Platform.OS === 'ios'
    ? `comgooglemaps://?q=${encodedAddress}`
    : `https://www.google.com/maps/search/?api=1&query=${encodedAddress}`;
  const wazeUrl = `waze://?q=${encodedAddress}`;
  const webUrl = `https://maps.google.com/?q=${encodedAddress}`;

  const open = async (url: string, fallbackUrl = webUrl) => {
    try {
      await Linking.openURL(url);
    } catch {
      await Linking.openURL(fallbackUrl);
    }
  };

  if (Platform.OS === 'android') {
    open(geoUrl, webUrl);
    return;
  }

  showMenu('Ouvrir l’adresse avec', [
    { label: 'Plans', action: () => open(appleMapsUrl, webUrl) },
    { label: 'Google Maps', action: () => open(googleMapsUrl, webUrl) },
    { label: 'Waze', action: () => open(wazeUrl, webUrl) },
  ]);
}

async function exportCalendar(appointments: PlanningAppointment[]): Promise<void> {
  if (appointments.length === 0) {
    Alert.alert('Calendrier', 'Aucun RDV à exporter.');
    return;
  }

  const ics = buildIcs(appointments);
  const fileName = `tech-calendar-planning-${toDateKey(new Date())}.ics`;
  const calendarFileUrl = `data:text/calendar;base64,${base64EncodeUtf8(ics)}`;

  try {
    await Share.open({
      title: 'Ajouter au calendrier',
      subject: 'Planning Tech Calendar',
      message: 'Planning Tech Calendar au format iCalendar.',
      url: calendarFileUrl,
      type: 'text/calendar',
      filename: fileName,
      failOnCancel: false,
    });
  } catch {
    Alert.alert('Export impossible', 'Le fichier calendrier n’a pas pu être préparé.');
  }
}

function buildIcs(appointments: PlanningAppointment[]): string {
  const lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Genius Controle//Tech Calendar//FR',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
  ];

  appointments.forEach(appointment => {
    lines.push(
      'BEGIN:VEVENT',
      `UID:tech-calendar-${appointment.id}@genius-controle`,
      `DTSTAMP:${formatIcsDate(new Date())}`,
      `DTSTART:${formatIcsDate(new Date(appointment.starts_at))}`,
      `DTEND:${formatIcsDate(new Date(appointment.ends_at))}`,
      `SUMMARY:${escapeIcs(`${appointment.service_label} - ${appointment.customer_name}`)}`,
      `LOCATION:${escapeIcs(appointment.address)}`,
      `DESCRIPTION:${escapeIcs([appointment.customer_phone, appointment.comment].filter(Boolean).join('\n'))}`,
      'END:VEVENT',
    );
  });

  lines.push('END:VCALENDAR');

  return `${lines.join('\r\n')}\r\n`;
}

function escapeIcs(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\n/g, '\\n');
}

function base64EncodeUtf8(value: string): string {
  const binary = encodeURIComponent(value).replace(/%([0-9A-F]{2})/g, (_, hex: string) => {
    return String.fromCharCode(Number.parseInt(hex, 16));
  });

  return base64EncodeBinary(binary);
}

function base64EncodeBinary(binary: string): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
  let output = '';

  for (let index = 0; index < binary.length; index += 3) {
    const first = binary.charCodeAt(index);
    const second = binary.charCodeAt(index + 1);
    const third = binary.charCodeAt(index + 2);
    const hasSecond = !Number.isNaN(second);
    const hasThird = !Number.isNaN(third);
    const encodedFirst = Math.floor(first / 4);
    const encodedSecond = ((first % 4) * 16) + (hasSecond ? Math.floor(second / 16) : 0);
    const encodedThird = hasSecond ? ((second % 16) * 4) + (hasThird ? Math.floor(third / 64) : 0) : 64;
    const encodedFourth = hasThird ? third % 64 : 64;

    output += alphabet.charAt(encodedFirst)
      + alphabet.charAt(encodedSecond)
      + alphabet.charAt(encodedThird)
      + alphabet.charAt(encodedFourth);
  }

  return output;
}

function buildWeekDays(
  selectedDate: string,
  appointmentsByDate: Map<string, PlanningAppointment[]>,
): Array<{ key: string; weekday: string; day: string; month: string; count: number }> {
  const start = startOfWeek(dateFromKey(selectedDate));

  return Array.from({ length: 7 }, (_, index) => {
    const date = new Date(start);
    date.setDate(start.getDate() + index);
    const key = toDateKey(date);

    return {
      key,
      weekday: new Intl.DateTimeFormat('fr-FR', { weekday: 'short' }).format(date).replace('.', ''),
      day: new Intl.DateTimeFormat('fr-FR', { day: '2-digit' }).format(date),
      month: new Intl.DateTimeFormat('fr-FR', { month: 'short' }).format(date).replace('.', ''),
      count: appointmentsByDate.get(key)?.length ?? 0,
    };
  });
}

function groupAppointmentsByDate(appointments: PlanningAppointment[]): Map<string, PlanningAppointment[]> {
  return appointments.reduce((groups, appointment) => {
    const key = toDateKey(new Date(appointment.starts_at));
    const list = groups.get(key) ?? [];
    list.push(appointment);
    list.sort((left, right) => new Date(left.starts_at).getTime() - new Date(right.starts_at).getTime());
    groups.set(key, list);
    return groups;
  }, new Map<string, PlanningAppointment[]>());
}

function selectStableDate(current: string, appointments: PlanningAppointment[]): string {
  const appointmentKeys = new Set(appointments.map(appointment => toDateKey(new Date(appointment.starts_at))));

  if (appointmentKeys.has(current) || current >= toDateKey(new Date())) {
    return current;
  }

  return appointments.find(appointment => new Date(appointment.starts_at) >= new Date())
    ? toDateKey(new Date(appointments.find(appointment => new Date(appointment.starts_at) >= new Date())!.starts_at))
    : toDateKey(new Date());
}

function compactLocation(appointment: PlanningAppointment): string {
  const cityLine = [appointment.postal_code, appointment.city].filter(Boolean).join(' ');

  return cityLine || appointment.address;
}

function formatTime(value: string): string {
  return new Intl.DateTimeFormat('fr-FR', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
}

function formatDayAndTime(value: string): string {
  return new Intl.DateTimeFormat('fr-FR', {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
}

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
}

function formatLongDate(key: string): string {
  return new Intl.DateTimeFormat('fr-FR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
  }).format(dateFromKey(key));
}

function formatWeekRange(startKey?: string, endKey?: string): string {
  if (!startKey || !endKey) {
    return '';
  }

  return `${formatShortDate(startKey)} - ${formatShortDate(endKey)}`;
}

function formatShortDate(key: string): string {
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: 'short',
  }).format(dateFromKey(key));
}

function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  if (hours <= 0) {
    return `${rest} min`;
  }

  return rest > 0 ? `${hours}h${String(rest).padStart(2, '0')}` : `${hours}h`;
}

function formatIcsDate(date: Date): string {
  return date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
}

function toDateKey(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function dateFromKey(key: string): Date {
  const [year, month, day] = key.split('-').map(Number);
  return new Date(year, month - 1, day, 12, 0, 0);
}

function startOfWeek(date: Date): Date {
  const start = new Date(date);
  const day = start.getDay() || 7;
  start.setDate(start.getDate() - day + 1);
  start.setHours(12, 0, 0, 0);

  return start;
}

const widgetToneStyles = {
  blue: {
    background: { backgroundColor: colors.blueSoft },
    text: { color: colors.blue },
  },
  green: {
    background: { backgroundColor: colors.greenSoft },
    text: { color: colors.green },
  },
  gold: {
    background: { backgroundColor: colors.goldSoft },
    text: { color: colors.ink },
  },
  coral: {
    background: { backgroundColor: colors.coralSoft },
    text: { color: colors.coral },
  },
};

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: colors.background,
  },
  centeredScreen: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 28,
  },
  logoMark: {
    width: 72,
    height: 72,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.ink,
    shadowColor: colors.ink,
    shadowOffset: { width: 0, height: 12 },
    shadowOpacity: 0.22,
    shadowRadius: 22,
    elevation: 8,
  },
  logoMarkText: {
    color: colors.gold,
    fontSize: 22,
    fontWeight: '900',
    letterSpacing: 2,
  },
  loadingTitle: {
    marginTop: 20,
    fontSize: 26,
    fontWeight: '900',
    color: colors.ink,
  },
  loadingText: {
    marginTop: 8,
    fontSize: 15,
    color: colors.inkMuted,
  },
  loadingSpinner: {
    marginTop: 24,
  },
  loginWrapper: {
    flex: 1,
  },
  loginScroll: {
    flexGrow: 1,
    padding: 24,
    justifyContent: 'center',
  },
  loginHero: {
    marginBottom: 28,
  },
  loginEyebrow: {
    marginTop: 24,
    color: colors.gold,
    fontSize: 13,
    fontWeight: '900',
    letterSpacing: 2,
    textTransform: 'uppercase',
  },
  loginTitle: {
    marginTop: 10,
    fontSize: 38,
    lineHeight: 42,
    color: colors.ink,
    fontWeight: '900',
  },
  loginSubtitle: {
    marginTop: 14,
    color: colors.inkMuted,
    fontSize: 16,
    lineHeight: 24,
  },
  loginCard: {
    padding: 20,
    borderRadius: 28,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  inputLabel: {
    marginTop: 14,
    marginBottom: 8,
    color: colors.ink,
    fontSize: 13,
    fontWeight: '800',
  },
  input: {
    minHeight: 54,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: '#FBFDFD',
    paddingHorizontal: 16,
    color: colors.ink,
    fontSize: 16,
  },
  passwordRow: {
    flexDirection: 'row',
    alignItems: 'center',
    minHeight: 54,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: '#FBFDFD',
  },
  passwordInput: {
    flex: 1,
    paddingHorizontal: 16,
    color: colors.ink,
    fontSize: 16,
  },
  eyeButton: {
    minHeight: 54,
    paddingHorizontal: 14,
    alignItems: 'center',
    justifyContent: 'center',
  },
  eyeButtonText: {
    color: colors.ink,
    fontSize: 13,
    fontWeight: '900',
  },
  formError: {
    marginTop: 14,
    color: colors.danger,
    fontSize: 14,
    lineHeight: 20,
  },
  formSuccess: {
    marginTop: 14,
    padding: 12,
    overflow: 'hidden',
    borderRadius: 16,
    color: colors.ink,
    backgroundColor: colors.greenSoft,
    fontSize: 13,
    lineHeight: 18,
    fontWeight: '800',
  },
  forgotPasswordButton: {
    marginTop: 12,
    alignSelf: 'flex-end',
  },
  forgotPasswordText: {
    color: colors.ink,
    fontSize: 14,
    fontWeight: '900',
    textDecorationLine: 'underline',
  },
  biometricButton: {
    marginTop: 18,
    minHeight: 54,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.ink,
    backgroundColor: colors.inkSoft,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  biometricButtonIcon: {
    color: colors.ink,
    fontSize: 18,
    fontWeight: '900',
  },
  biometricButtonText: {
    color: colors.ink,
    fontSize: 15,
    fontWeight: '900',
  },
  offlineNotice: {
    marginBottom: 12,
    padding: 12,
    overflow: 'hidden',
    borderRadius: 16,
    color: colors.ink,
    backgroundColor: colors.goldSoft,
    fontSize: 13,
    lineHeight: 18,
    fontWeight: '800',
  },
  centerLinkButton: {
    marginTop: 16,
    alignItems: 'center',
  },
  centerLinkText: {
    color: colors.inkMuted,
    fontSize: 14,
    fontWeight: '900',
  },
  primaryButton: {
    marginTop: 20,
    height: 56,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.ink,
  },
  primaryButtonDisabled: {
    opacity: 0.42,
  },
  primaryButtonText: {
    color: colors.white,
    fontSize: 16,
    fontWeight: '900',
  },
  planningScroll: {
    flex: 1,
  },
  planningContent: {
    padding: 18,
    paddingBottom: 42,
  },
  topBar: {
    zIndex: 20,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 16,
  },
  helloText: {
    color: colors.inkMuted,
    fontSize: 14,
    fontWeight: '700',
  },
  userName: {
    marginTop: 2,
    color: colors.ink,
    fontSize: 24,
    fontWeight: '900',
  },
  profileMenuWrapper: {
    position: 'relative',
    alignItems: 'flex-end',
  },
  avatar: {
    width: 48,
    height: 48,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.ink,
  },
  avatarText: {
    color: colors.gold,
    fontSize: 15,
    fontWeight: '900',
  },
  profileDropdown: {
    position: 'absolute',
    top: 56,
    right: 0,
    width: 230,
    padding: 14,
    borderRadius: 22,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    shadowColor: colors.ink,
    shadowOpacity: 0.18,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 10 },
    elevation: 10,
  },
  profileDropdownName: {
    color: colors.ink,
    fontSize: 15,
    fontWeight: '900',
  },
  profileDropdownEmail: {
    marginTop: 3,
    color: colors.inkMuted,
    fontSize: 12,
    fontWeight: '700',
  },
  dropdownProfileButton: {
    marginTop: 12,
    paddingVertical: 10,
    paddingHorizontal: 12,
    borderRadius: 14,
    backgroundColor: colors.goldSoft,
  },
  dropdownProfileText: {
    color: colors.ink,
    fontSize: 13,
    fontWeight: '900',
  },
  dropdownLogoutButton: {
    marginTop: 12,
    paddingVertical: 10,
    paddingHorizontal: 12,
    borderRadius: 14,
    backgroundColor: colors.inkSoft,
  },
  dropdownLogoutText: {
    color: colors.ink,
    fontSize: 13,
    fontWeight: '900',
  },
  offlineBanner: {
    marginBottom: 12,
    paddingVertical: 10,
    paddingHorizontal: 14,
    overflow: 'hidden',
    borderRadius: 16,
    color: colors.ink,
    backgroundColor: colors.goldSoft,
    fontSize: 13,
    lineHeight: 18,
    fontWeight: '800',
  },
  syncStatusCard: {
    marginBottom: 12,
    padding: 14,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  syncStatusTextBlock: {
    flex: 1,
  },
  syncStatusTitle: {
    color: colors.ink,
    fontSize: 14,
    fontWeight: '900',
  },
  syncStatusText: {
    marginTop: 2,
    color: colors.inkMuted,
    fontSize: 12,
    lineHeight: 17,
    fontWeight: '700',
  },
  mobileLoader: {
    minHeight: 420,
    alignItems: 'center',
    justifyContent: 'center',
  },
  mobileLoaderText: {
    marginTop: 12,
    color: colors.inkMuted,
    fontSize: 15,
  },
  errorCard: {
    padding: 22,
    borderRadius: 26,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  errorTitle: {
    color: colors.ink,
    fontSize: 22,
    fontWeight: '900',
  },
  errorText: {
    marginTop: 8,
    color: colors.inkMuted,
    fontSize: 15,
    lineHeight: 22,
  },
  secondaryButton: {
    marginTop: 18,
    height: 48,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.inkSoft,
  },
  secondaryButtonText: {
    color: colors.ink,
    fontWeight: '900',
  },
  widgetsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 12,
  },
  syncInfoCard: {
    marginTop: 14,
    padding: 14,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: 'rgba(48, 138, 95, 0.18)',
    backgroundColor: colors.greenSoft,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  syncInfoCardOffline: {
    borderColor: 'rgba(226, 185, 101, 0.34)',
    backgroundColor: colors.goldSoft,
  },
  syncInfoDot: {
    width: 10,
    height: 10,
    borderRadius: 999,
    backgroundColor: colors.ink,
  },
  syncInfoTextBlock: {
    flex: 1,
  },
  syncInfoTitle: {
    color: colors.ink,
    fontSize: 14,
    fontWeight: '900',
  },
  syncInfoText: {
    marginTop: 2,
    color: colors.inkMuted,
    fontSize: 12,
    lineHeight: 17,
    fontWeight: '700',
  },
  widgetCard: {
    width: '48%',
    minHeight: 118,
    borderRadius: 24,
    padding: 16,
    borderWidth: 1,
    borderColor: 'rgba(47, 64, 72, 0.06)',
  },
  widgetLabel: {
    color: colors.inkMuted,
    fontSize: 12,
    fontWeight: '900',
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  widgetValue: {
    marginTop: 12,
    fontSize: 28,
    fontWeight: '900',
  },
  widgetDetail: {
    marginTop: 6,
    color: colors.inkMuted,
    fontSize: 13,
  },
  nextCard: {
    marginTop: 16,
    padding: 20,
    borderRadius: 28,
    backgroundColor: colors.ink,
  },
  nextEyebrow: {
    color: colors.gold,
    fontSize: 12,
    fontWeight: '900',
    textTransform: 'uppercase',
    letterSpacing: 1.4,
  },
  nextTitle: {
    marginTop: 10,
    color: colors.white,
    fontSize: 24,
    fontWeight: '900',
  },
  nextText: {
    marginTop: 6,
    color: '#D7E0E3',
    fontSize: 15,
    lineHeight: 22,
  },
  nextDetailsRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 14,
  },
  nextBadge: {
    overflow: 'hidden',
    paddingVertical: 6,
    paddingHorizontal: 10,
    borderRadius: 999,
    color: colors.ink,
    backgroundColor: colors.gold,
    fontSize: 12,
    fontWeight: '900',
  },
  nextAddress: {
    marginTop: 14,
    color: '#F4F7F8',
    fontSize: 14,
    lineHeight: 20,
  },
  sectionHeader: {
    marginTop: 24,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  sectionTitle: {
    color: colors.ink,
    fontSize: 24,
    fontWeight: '900',
  },
  sectionMeta: {
    marginTop: 2,
    color: colors.inkMuted,
    fontSize: 13,
    fontWeight: '800',
  },
  calendarExportButton: {
    minHeight: 42,
    paddingHorizontal: 14,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.ink,
  },
  calendarExportText: {
    color: colors.white,
    fontSize: 13,
    fontWeight: '900',
  },
  calendarCard: {
    marginTop: 14,
    padding: 14,
    borderRadius: 30,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  weekNav: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 12,
  },
  weekNavButton: {
    width: 42,
    height: 42,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.inkSoft,
  },
  weekNavText: {
    color: colors.ink,
    fontSize: 28,
    fontWeight: '900',
    lineHeight: 30,
  },
  weekRange: {
    color: colors.ink,
    fontSize: 16,
    fontWeight: '900',
    textTransform: 'capitalize',
  },
  weekGrid: {
    flexDirection: 'row',
    gap: 6,
  },
  weekDay: {
    flex: 1,
    minHeight: 94,
    borderRadius: 18,
    paddingVertical: 9,
    alignItems: 'center',
    backgroundColor: '#F7FAFA',
    borderWidth: 1,
    borderColor: colors.border,
  },
  weekDayActive: {
    backgroundColor: colors.ink,
    borderColor: colors.ink,
  },
  weekDayName: {
    color: colors.inkMuted,
    fontSize: 10,
    fontWeight: '900',
    textTransform: 'capitalize',
  },
  weekDayNumber: {
    marginTop: 5,
    color: colors.ink,
    fontSize: 19,
    fontWeight: '900',
  },
  weekDayMonth: {
    color: colors.inkMuted,
    fontSize: 9,
    fontWeight: '800',
    textTransform: 'capitalize',
  },
  weekDayCount: {
    marginTop: 5,
    minWidth: 22,
    overflow: 'hidden',
    borderRadius: 999,
    color: colors.ink,
    backgroundColor: colors.gold,
    textAlign: 'center',
    fontSize: 11,
    fontWeight: '900',
  },
  weekDayTextActive: {
    color: colors.white,
  },
  timelinePanel: {
    marginTop: 16,
  },
  timelineHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 4,
  },
  timelineDate: {
    color: colors.ink,
    fontSize: 18,
    fontWeight: '900',
    textTransform: 'capitalize',
  },
  timelineCount: {
    color: colors.inkMuted,
    fontSize: 13,
    fontWeight: '900',
  },
  timelineAppointment: {
    flexDirection: 'row',
    paddingVertical: 14,
    borderTopWidth: 1,
    borderTopColor: colors.inkSoft,
  },
  timelineHours: {
    width: 58,
    alignItems: 'center',
  },
  timelineStart: {
    color: colors.ink,
    fontSize: 14,
    fontWeight: '900',
  },
  timelineEnd: {
    color: colors.inkMuted,
    fontSize: 12,
    fontWeight: '800',
  },
  timelineLine: {
    width: 2,
    minHeight: 54,
    marginVertical: 6,
    borderRadius: 999,
    backgroundColor: colors.gold,
  },
  timelineAppointmentBody: {
    flex: 1,
    paddingLeft: 12,
  },
  timelineAppointmentTopline: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: 10,
  },
  appointmentCustomer: {
    flex: 1,
    color: colors.ink,
    fontSize: 18,
    fontWeight: '900',
  },
  durationBadge: {
    overflow: 'hidden',
    paddingVertical: 4,
    paddingHorizontal: 8,
    borderRadius: 999,
    color: colors.ink,
    backgroundColor: colors.goldSoft,
    fontSize: 11,
    fontWeight: '900',
  },
  appointmentService: {
    marginTop: 4,
    color: colors.green,
    fontSize: 13,
    fontWeight: '900',
  },
  appointmentAddress: {
    marginTop: 8,
    color: colors.inkMuted,
    fontSize: 14,
    lineHeight: 20,
  },
  emptyDay: {
    paddingVertical: 32,
    alignItems: 'center',
  },
  emptyDayTitle: {
    color: colors.ink,
    fontSize: 18,
    fontWeight: '900',
  },
  emptyDayText: {
    marginTop: 8,
    maxWidth: 260,
    color: colors.inkMuted,
    fontSize: 14,
    lineHeight: 20,
    textAlign: 'center',
  },
  modalOverlay: {
    flex: 1,
    justifyContent: 'flex-end',
    backgroundColor: 'rgba(15, 23, 42, 0.45)',
  },
  appointmentModal: {
    maxHeight: '88%',
    paddingHorizontal: 20,
    paddingTop: 10,
    paddingBottom: 26,
    borderTopLeftRadius: 34,
    borderTopRightRadius: 34,
    backgroundColor: colors.card,
  },
  modalHandle: {
    alignSelf: 'center',
    width: 54,
    height: 5,
    borderRadius: 999,
    backgroundColor: colors.border,
    marginBottom: 14,
  },
  modalHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: 16,
    marginBottom: 12,
  },
  modalTitleBlock: {
    flex: 1,
  },
  modalEyebrow: {
    color: colors.gold,
    fontSize: 12,
    fontWeight: '900',
    letterSpacing: 1.2,
    textTransform: 'uppercase',
  },
  modalTitle: {
    marginTop: 6,
    color: colors.ink,
    fontSize: 26,
    fontWeight: '900',
  },
  modalCloseButton: {
    paddingVertical: 8,
    paddingHorizontal: 10,
  },
  modalCloseText: {
    color: colors.inkMuted,
    fontSize: 13,
    fontWeight: '900',
  },
  infoRow: {
    paddingVertical: 12,
    borderTopWidth: 1,
    borderTopColor: colors.inkSoft,
  },
  infoLabel: {
    color: colors.inkMuted,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 1,
    textTransform: 'uppercase',
  },
  infoValue: {
    marginTop: 4,
    color: colors.ink,
    fontSize: 16,
    lineHeight: 22,
    fontWeight: '800',
  },
  modalSectionLabel: {
    marginTop: 14,
    marginBottom: 8,
    color: colors.inkMuted,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 1,
    textTransform: 'uppercase',
  },
  addressActionCard: {
    padding: 14,
    borderRadius: 20,
    backgroundColor: colors.blueSoft,
    borderWidth: 1,
    borderColor: 'rgba(109, 167, 216, 0.35)',
  },
  addressActionText: {
    color: colors.ink,
    fontSize: 15,
    lineHeight: 21,
    fontWeight: '800',
  },
  addressActionHint: {
    marginTop: 6,
    color: colors.blue,
    fontSize: 12,
    fontWeight: '900',
  },
  modalCommentBox: {
    marginTop: 4,
  },
  modalComment: {
    padding: 12,
    overflow: 'hidden',
    borderRadius: 18,
    color: colors.ink,
    backgroundColor: colors.goldSoft,
    fontSize: 14,
    lineHeight: 20,
    fontWeight: '700',
  },
  preferenceCard: {
    marginTop: 10,
    padding: 14,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: '#FBFDFD',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 14,
  },
  preferenceTextBlock: {
    flex: 1,
  },
  preferenceTitle: {
    color: colors.ink,
    fontSize: 15,
    fontWeight: '900',
  },
  preferenceDescription: {
    marginTop: 4,
    color: colors.inkMuted,
    fontSize: 12,
    lineHeight: 17,
    fontWeight: '700',
  },
  preferenceSaving: {
    marginTop: 12,
    color: colors.inkMuted,
    fontSize: 13,
    fontWeight: '800',
  },
  modalActions: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 18,
  },
  primaryButtonCompact: {
    flex: 1,
    minHeight: 50,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.ink,
  },
  secondaryButtonCompact: {
    flex: 1,
    minHeight: 50,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.inkSoft,
  },
});

export default App;
