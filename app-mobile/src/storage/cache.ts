import AsyncStorage from '@react-native-async-storage/async-storage';
import { MobileUser, PlanningCacheInfo, PlanningPayload } from '../types/api';

const USER_CACHE_KEY = 'tech-calendar.cached-user';
const PLANNING_CACHE_KEY = 'tech-calendar.cached-planning';
const PLANNING_CACHE_INFO_KEY = 'tech-calendar.cached-planning-info';
const BIOMETRIC_ONBOARDING_ASKED_KEY = 'tech-calendar.biometric-onboarding-asked';
const PUSH_ONBOARDING_DECISION_KEY = 'tech-calendar.push-onboarding-decision';

export type PushOnboardingDecision = 'accepted' | 'declined';

export async function setCachedUser(user: MobileUser): Promise<void> {
  await AsyncStorage.setItem(USER_CACHE_KEY, JSON.stringify(user));
}

export async function getCachedUser(): Promise<MobileUser | null> {
  const user = await readJson<MobileUser>(USER_CACHE_KEY);

  return user ? normalizeCachedUser(user) : null;
}

export async function setCachedPlanning(planning: PlanningPayload): Promise<void> {
  const cacheInfo: PlanningCacheInfo = {
    cached_at: new Date().toISOString(),
    generated_at: planning.generated_at ?? null,
  };

  await Promise.all([
    AsyncStorage.setItem(PLANNING_CACHE_KEY, JSON.stringify(planning)),
    AsyncStorage.setItem(PLANNING_CACHE_INFO_KEY, JSON.stringify(cacheInfo)),
  ]);
}

export async function getCachedPlanning(): Promise<PlanningPayload | null> {
  return readJson<PlanningPayload>(PLANNING_CACHE_KEY);
}

export async function getCachedPlanningInfo(): Promise<PlanningCacheInfo | null> {
  const cachedInfo = await readJson<PlanningCacheInfo>(PLANNING_CACHE_INFO_KEY);

  if (cachedInfo) {
    return cachedInfo;
  }

  const planning = await getCachedPlanning();

  return planning
    ? { cached_at: planning.generated_at ?? new Date(0).toISOString(), generated_at: planning.generated_at ?? null }
    : null;
}

export async function clearCachedSession(): Promise<void> {
  await Promise.all([
    AsyncStorage.removeItem(USER_CACHE_KEY),
    AsyncStorage.removeItem(PLANNING_CACHE_KEY),
    AsyncStorage.removeItem(PLANNING_CACHE_INFO_KEY),
  ]);
}

export async function wasBiometricOnboardingAsked(): Promise<boolean> {
  return await AsyncStorage.getItem(BIOMETRIC_ONBOARDING_ASKED_KEY) === 'true';
}

export async function markBiometricOnboardingAsked(): Promise<void> {
  await AsyncStorage.setItem(BIOMETRIC_ONBOARDING_ASKED_KEY, 'true');
}

export async function getPushOnboardingDecision(): Promise<PushOnboardingDecision | null> {
  const decision = await AsyncStorage.getItem(PUSH_ONBOARDING_DECISION_KEY);

  return decision === 'accepted' || decision === 'declined' ? decision : null;
}

export async function setPushOnboardingDecision(decision: PushOnboardingDecision): Promise<void> {
  await AsyncStorage.setItem(PUSH_ONBOARDING_DECISION_KEY, decision);
}

async function readJson<T>(key: string): Promise<T | null> {
  const raw = await AsyncStorage.getItem(key);

  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as T;
  } catch {
    await AsyncStorage.removeItem(key);
    return null;
  }
}

function normalizeCachedUser(user: MobileUser): MobileUser {
  return {
    ...user,
    notification_mail_enabled: user.notification_mail_enabled ?? true,
    notification_push_enabled: user.notification_push_enabled ?? true,
  };
}
