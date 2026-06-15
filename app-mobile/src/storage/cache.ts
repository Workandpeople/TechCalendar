import AsyncStorage from '@react-native-async-storage/async-storage';
import { MobileUser, PlanningCacheInfo, PlanningPayload } from '../types/api';

const USER_CACHE_KEY = 'tech-calendar.cached-user';
const PLANNING_CACHE_KEY = 'tech-calendar.cached-planning';
const PLANNING_CACHE_INFO_KEY = 'tech-calendar.cached-planning-info';

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
