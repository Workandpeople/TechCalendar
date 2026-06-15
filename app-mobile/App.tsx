import React, { useCallback, useEffect, useMemo, useState } from 'react';
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
  Share,
  StatusBar,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useNetInfo } from '@react-native-community/netinfo';
import {
  changeFirstPassword,
  login as apiLogin,
  logout as apiLogout,
  me as apiMe,
  offlineLogin,
} from './src/api/auth';
import { ApiError, isNetworkError } from './src/api/client';
import { getPlanning } from './src/api/planning';
import { getCachedPlanning, getCachedUser } from './src/storage/cache';
import { getToken } from './src/storage/secure';
import colors from './src/theme/colors';
import { MobileUser, PlanningAppointment, PlanningPayload } from './src/types/api';

type MenuOption = {
  label: string;
  action: () => void | Promise<void>;
  destructive?: boolean;
};

function App(): React.JSX.Element {
  const [user, setUser] = useState<MobileUser | null>(null);
  const [booting, setBooting] = useState(true);
  const [bootNotice, setBootNotice] = useState<string | null>(null);

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
          }
        } catch (exception) {
          if (isNetworkError(exception)) {
            const cachedUser = await getCachedUser();

            if (cachedUser && mounted) {
              setUser(cachedUser);
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
      setUser(loggedUser);
    } catch (exception) {
      if (isNetworkError(exception)) {
        const cachedUser = await offlineLogin(email, password);

        if (cachedUser) {
          setBootNotice('Mode hors ligne: données restaurées depuis la dernière synchronisation.');
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
    setUser(null);
  }, []);

  const handlePasswordChanged = useCallback((updatedUser: MobileUser) => {
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
        <PlanningScreen user={user} initialNotice={bootNotice} onLogout={handleLogout} />
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

function LoginScreen({ onLogin }: { onLogin: (email: string, password: string) => Promise<void> }): React.JSX.Element {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canSubmit = email.includes('@') && password.length >= 1 && !submitting;

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
            <Text style={styles.loginEyebrow}>Espace technicien</Text>
            <Text style={styles.loginTitle}>Connecte-toi à ton planning terrain.</Text>
            <Text style={styles.loginSubtitle}>
              Cette application fonctionne aussi hors ligne après une première synchronisation réussie.
            </Text>
          </View>

          <View style={styles.loginCard}>
            <InputLabel label="Adresse e-mail" />
            <TextInput
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              placeholder="tech@exemple.fr"
              placeholderTextColor={colors.inkMuted}
              style={styles.input}
            />

            <InputLabel label="Mot de passe" />
            <PasswordInput
              value={password}
              onChangeText={setPassword}
              showPassword={showPassword}
              onToggleVisibility={() => setShowPassword(current => !current)}
              placeholder="Mot de passe"
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
                <Text style={styles.primaryButtonText}>Se connecter</Text>
              )}
            </Pressable>
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
  onLogout,
}: {
  user: MobileUser;
  initialNotice: string | null;
  onLogout: () => Promise<void>;
}): React.JSX.Element {
  const netInfo = useNetInfo();
  const [planning, setPlanning] = useState<PlanningPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [offlineNotice, setOfflineNotice] = useState<string | null>(initialNotice);
  const [selectedDate, setSelectedDate] = useState(toDateKey(new Date()));
  const [selectedAppointment, setSelectedAppointment] = useState<PlanningAppointment | null>(null);
  const [profileOpen, setProfileOpen] = useState(false);

  const loadPlanning = useCallback(async (asRefresh = false) => {
    if (asRefresh) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }
    setError(null);

    try {
      const payload = await getPlanning();
      setPlanning(payload);
      setOfflineNotice(null);
      setSelectedDate(current => selectStableDate(current, payload.appointments));
    } catch (exception) {
      const cachedPlanning = await getCachedPlanning();

      if (cachedPlanning && isNetworkError(exception)) {
        setPlanning(cachedPlanning);
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
      const cachedPlanning = await getCachedPlanning();

      if (cachedPlanning && mounted) {
        setPlanning(cachedPlanning);
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
    if (netInfo.isConnected && offlineNotice && planning) {
      loadPlanning(true);
    }
  }, [loadPlanning, netInfo.isConnected, offlineNotice, planning]);

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
                <Pressable accessibilityRole="button" onPress={onLogout} style={styles.dropdownLogoutButton}>
                  <Text style={styles.dropdownLogoutText}>Déconnexion</Text>
                </Pressable>
              </View>
            ) : null}
          </View>
        </View>

        {offlineNotice ? <Text style={styles.offlineBanner}>{offlineNotice}</Text> : null}
        {netInfo.isConnected === false ? <Text style={styles.offlineBanner}>Aucune connexion détectée.</Text> : null}

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
  const dataUrl = `data:text/calendar;charset=utf8,${encodeURIComponent(ics)}`;

  try {
    await Share.share({
      title: 'Planning Tech Calendar',
      message: Platform.OS === 'ios' ? 'Planning Tech Calendar en pièce jointe ICS.' : ics,
      url: dataUrl,
    });
  } catch {
    Alert.alert('Export impossible', 'Le menu de partage du téléphone n’a pas pu être ouvert.');
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
