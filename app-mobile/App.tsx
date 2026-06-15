import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Linking,
  Platform,
  Pressable,
  RefreshControl,
  SafeAreaView,
  ScrollView,
  StatusBar,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { login as apiLogin, logout as apiLogout, me as apiMe } from './src/api/auth';
import { ApiError } from './src/api/client';
import { getPlanning } from './src/api/planning';
import { getToken } from './src/storage/secure';
import colors from './src/theme/colors';
import { MobileUser, PlanningAppointment, PlanningPayload } from './src/types/api';

function App(): React.JSX.Element {
  const [user, setUser] = useState<MobileUser | null>(null);
  const [booting, setBooting] = useState(true);

  useEffect(() => {
    let mounted = true;

    async function bootstrap() {
      try {
        const token = await getToken();
        if (!token) {
          return;
        }

        const currentUser = await apiMe();
        if (mounted) {
          setUser(currentUser);
        }
      } catch {
        await apiLogout();
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
    const loggedUser = await apiLogin(email, password);
    setUser(loggedUser);
  }, []);

  const handleLogout = useCallback(async () => {
    await apiLogout();
    setUser(null);
  }, []);

  return (
    <SafeAreaProvider>
      <StatusBar barStyle="dark-content" backgroundColor={colors.background} />
      {booting ? (
        <LoadingScreen />
      ) : user ? (
        <PlanningScreen user={user} onLogout={handleLogout} />
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
              Cette application est réservée aux comptes techniciens Genius Contrôle.
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
            <View style={styles.passwordRow}>
              <TextInput
                value={password}
                onChangeText={setPassword}
                autoCapitalize="none"
                autoComplete="password"
                secureTextEntry={!showPassword}
                placeholder="Mot de passe"
                placeholderTextColor={colors.inkMuted}
                style={styles.passwordInput}
              />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                onPress={() => setShowPassword((current) => !current)}
                style={styles.eyeButton}
              >
                <Text style={styles.eyeButtonText}>{showPassword ? 'Masquer' : 'Voir'}</Text>
              </Pressable>
            </View>

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

function PlanningScreen({ user, onLogout }: { user: MobileUser; onLogout: () => Promise<void> }): React.JSX.Element {
  const [planning, setPlanning] = useState<PlanningPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedDate, setSelectedDate] = useState(toDateKey(new Date()));

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
    } catch (exception) {
      setError(errorMessage(exception));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadPlanning();
  }, [loadPlanning]);

  const days = useMemo(() => buildCalendarDays(planning?.appointments ?? []), [planning]);
  const appointmentsByDate = useMemo(() => groupAppointmentsByDate(planning?.appointments ?? []), [planning]);
  const selectedAppointments = appointmentsByDate.get(selectedDate) ?? [];

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
          <View style={styles.userCluster}>
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>{user.initials}</Text>
            </View>
            <Pressable accessibilityRole="button" onPress={onLogout} style={styles.logoutButton}>
              <Text style={styles.logoutText}>Déconnexion</Text>
            </Pressable>
          </View>
        </View>

        {loading ? (
          <View style={styles.mobileLoader}>
            <ActivityIndicator size="large" color={colors.ink} />
            <Text style={styles.mobileLoaderText}>Chargement du planning...</Text>
          </View>
        ) : error ? (
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
              <WidgetCard label="Aujourd'hui" value={String(planning.widgets.today_count)} detail="RDV prévus" tone="blue" />
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

            <NextAppointmentCard appointment={planning.widgets.next_appointment} />

            <View style={styles.sectionHeader}>
              <Text style={styles.sectionTitle}>Calendrier</Text>
              <Text style={styles.sectionMeta}>{planning.appointments.length} RDV à venir</Text>
            </View>

            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.dayRail}>
              {days.map((day) => {
                const selected = day.key === selectedDate;
                const count = appointmentsByDate.get(day.key)?.length ?? 0;

                return (
                  <Pressable
                    accessibilityRole="button"
                    key={day.key}
                    onPress={() => setSelectedDate(day.key)}
                    style={[styles.dayPill, selected && styles.dayPillActive]}
                  >
                    <Text style={[styles.dayWeekday, selected && styles.dayTextActive]}>{day.weekday}</Text>
                    <Text style={[styles.dayNumber, selected && styles.dayTextActive]}>{day.day}</Text>
                    <Text style={[styles.dayMonth, selected && styles.dayTextActive]}>{day.month}</Text>
                    {count > 0 ? <View style={[styles.dayDot, selected && styles.dayDotActive]} /> : null}
                  </Pressable>
                );
              })}
            </ScrollView>

            <View style={styles.agendaCard}>
              <View style={styles.agendaHeader}>
                <Text style={styles.agendaTitle}>{formatLongDate(selectedDate)}</Text>
                <Text style={styles.agendaCount}>{selectedAppointments.length} RDV</Text>
              </View>

              {selectedAppointments.length > 0 ? (
                selectedAppointments.map((appointment) => (
                  <AppointmentCard appointment={appointment} key={appointment.id} />
                ))
              ) : (
                <View style={styles.emptyDay}>
                  <Text style={styles.emptyDayTitle}>Aucun RDV ce jour-là.</Text>
                  <Text style={styles.emptyDayText}>Profite du calme, ou tire pour actualiser le planning.</Text>
                </View>
              )}
            </View>
          </>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  );
}

function InputLabel({ label }: { label: string }): React.JSX.Element {
  return <Text style={styles.inputLabel}>{label}</Text>;
}

function WidgetCard({ label, value, detail, tone }: { label: string; value: string; detail: string; tone: 'blue' | 'green' | 'gold' | 'coral' }): React.JSX.Element {
  const toneStyle = widgetToneStyles[tone];

  return (
    <View style={[styles.widgetCard, toneStyle.background]}>
      <Text style={styles.widgetLabel}>{label}</Text>
      <Text style={[styles.widgetValue, toneStyle.text]}>{value}</Text>
      <Text style={styles.widgetDetail}>{detail}</Text>
    </View>
  );
}

function NextAppointmentCard({ appointment }: { appointment: PlanningAppointment | null }): React.JSX.Element {
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
    <View style={styles.nextCard}>
      <Text style={styles.nextEyebrow}>Prochain RDV</Text>
      <Text style={styles.nextTitle}>{appointment.customer_name}</Text>
      <Text style={styles.nextText}>{appointment.service_label}</Text>
      <View style={styles.nextDetailsRow}>
        <Text style={styles.nextBadge}>{formatDayAndTime(appointment.starts_at)}</Text>
        <Text style={styles.nextBadge}>{formatDuration(appointment.duration_minutes)}</Text>
      </View>
      <Text style={styles.nextAddress}>{appointment.address}</Text>
    </View>
  );
}

function AppointmentCard({ appointment }: { appointment: PlanningAppointment }): React.JSX.Element {
  const openPhone = useCallback(() => {
    if (appointment.customer_phone) {
      Linking.openURL(`tel:${appointment.customer_phone}`);
    }
  }, [appointment.customer_phone]);

  const openMap = useCallback(() => {
    const encodedAddress = encodeURIComponent(appointment.address);
    Linking.openURL(Platform.select({
      ios: `maps://?q=${encodedAddress}`,
      android: `geo:0,0?q=${encodedAddress}`,
      default: `https://maps.google.com/?q=${encodedAddress}`,
    }) as string);
  }, [appointment.address]);

  return (
    <View style={styles.appointmentCard}>
      <View style={styles.appointmentTimeline}>
        <Text style={styles.appointmentStart}>{formatTime(appointment.starts_at)}</Text>
        <View style={styles.timelineLine} />
        <Text style={styles.appointmentEnd}>{formatTime(appointment.ends_at)}</Text>
      </View>
      <View style={styles.appointmentBody}>
        <Text style={styles.appointmentCustomer}>{appointment.customer_name}</Text>
        <Text style={styles.appointmentService}>{appointment.service_label}</Text>
        <Text style={styles.appointmentAddress}>{appointment.address}</Text>
        {appointment.comment ? <Text style={styles.appointmentComment}>{appointment.comment}</Text> : null}
        <View style={styles.appointmentActions}>
          <Pressable accessibilityRole="button" onPress={openMap} style={styles.smallActionButton}>
            <Text style={styles.smallActionText}>Itinéraire</Text>
          </Pressable>
          {appointment.customer_phone ? (
            <Pressable accessibilityRole="button" onPress={openPhone} style={styles.smallActionButtonAlt}>
              <Text style={styles.smallActionTextAlt}>Appeler</Text>
            </Pressable>
          ) : null}
        </View>
      </View>
    </View>
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

function buildCalendarDays(appointments: PlanningAppointment[]): Array<{ key: string; weekday: string; day: string; month: string }> {
  const today = new Date();
  const keys = new Set<string>();

  for (let index = 0; index < 14; index += 1) {
    const date = new Date(today);
    date.setDate(today.getDate() + index);
    keys.add(toDateKey(date));
  }

  appointments.slice(0, 30).forEach((appointment) => keys.add(toDateKey(new Date(appointment.starts_at))));

  return [...keys]
    .sort()
    .map((key) => {
      const date = dateFromKey(key);
      return {
        key,
        weekday: new Intl.DateTimeFormat('fr-FR', { weekday: 'short' }).format(date).replace('.', ''),
        day: new Intl.DateTimeFormat('fr-FR', { day: '2-digit' }).format(date),
        month: new Intl.DateTimeFormat('fr-FR', { month: 'short' }).format(date).replace('.', ''),
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

function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  if (hours <= 0) {
    return `${rest} min`;
  }

  return rest > 0 ? `${hours}h${String(rest).padStart(2, '0')}` : `${hours}h`;
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
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 20,
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
  userCluster: {
    alignItems: 'flex-end',
    gap: 8,
  },
  avatar: {
    width: 46,
    height: 46,
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
  logoutButton: {
    paddingVertical: 4,
    paddingHorizontal: 8,
  },
  logoutText: {
    color: colors.inkMuted,
    fontSize: 12,
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
    alignItems: 'flex-end',
    justifyContent: 'space-between',
  },
  sectionTitle: {
    color: colors.ink,
    fontSize: 24,
    fontWeight: '900',
  },
  sectionMeta: {
    color: colors.inkMuted,
    fontSize: 13,
    fontWeight: '800',
  },
  dayRail: {
    gap: 10,
    paddingVertical: 14,
  },
  dayPill: {
    width: 72,
    minHeight: 104,
    borderRadius: 24,
    paddingVertical: 12,
    alignItems: 'center',
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  dayPillActive: {
    backgroundColor: colors.ink,
    borderColor: colors.ink,
  },
  dayWeekday: {
    color: colors.inkMuted,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'capitalize',
  },
  dayNumber: {
    marginTop: 8,
    color: colors.ink,
    fontSize: 24,
    fontWeight: '900',
  },
  dayMonth: {
    marginTop: 2,
    color: colors.inkMuted,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'capitalize',
  },
  dayTextActive: {
    color: colors.white,
  },
  dayDot: {
    marginTop: 8,
    width: 8,
    height: 8,
    borderRadius: 999,
    backgroundColor: colors.gold,
  },
  dayDotActive: {
    backgroundColor: colors.gold,
  },
  agendaCard: {
    padding: 16,
    borderRadius: 28,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  agendaHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 12,
  },
  agendaTitle: {
    color: colors.ink,
    fontSize: 18,
    fontWeight: '900',
    textTransform: 'capitalize',
  },
  agendaCount: {
    color: colors.inkMuted,
    fontSize: 13,
    fontWeight: '900',
  },
  appointmentCard: {
    flexDirection: 'row',
    paddingVertical: 14,
    borderTopWidth: 1,
    borderTopColor: colors.inkSoft,
  },
  appointmentTimeline: {
    width: 58,
    alignItems: 'center',
  },
  appointmentStart: {
    color: colors.ink,
    fontSize: 14,
    fontWeight: '900',
  },
  appointmentEnd: {
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
  appointmentBody: {
    flex: 1,
    paddingLeft: 12,
  },
  appointmentCustomer: {
    color: colors.ink,
    fontSize: 18,
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
  appointmentComment: {
    marginTop: 10,
    padding: 10,
    borderRadius: 14,
    overflow: 'hidden',
    color: colors.ink,
    backgroundColor: colors.goldSoft,
    fontSize: 13,
    lineHeight: 18,
  },
  appointmentActions: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 12,
  },
  smallActionButton: {
    paddingVertical: 9,
    paddingHorizontal: 13,
    borderRadius: 999,
    backgroundColor: colors.ink,
  },
  smallActionText: {
    color: colors.white,
    fontSize: 12,
    fontWeight: '900',
  },
  smallActionButtonAlt: {
    paddingVertical: 9,
    paddingHorizontal: 13,
    borderRadius: 999,
    backgroundColor: colors.inkSoft,
  },
  smallActionTextAlt: {
    color: colors.ink,
    fontSize: 12,
    fontWeight: '900',
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
});

export default App;
