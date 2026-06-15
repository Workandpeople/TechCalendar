import AsyncStorage from '@react-native-async-storage/async-storage';
import { MobileUser, PlanningPayload } from '../types/api';

const USER_CACHE_KEY = 'tech-calendar.cached-user';
const PLANNING_CACHE_KEY = 'tech-calendar.cached-planning';

export async function setCachedUser(user: MobileUser): Promise<void> {
  await AsyncStorage.setItem(USER_CACHE_KEY, JSON.stringify(user));
}

export async function getCachedUser(): Promise<MobileUser | null> {
  return readJson<MobileUser>(USER_CACHE_KEY);
}

export async function setCachedPlanning(planning: PlanningPayload): Promise<void> {
  await AsyncStorage.setItem(PLANNING_CACHE_KEY, JSON.stringify(planning));
}

export async function getCachedPlanning(): Promise<PlanningPayload | null> {
  return readJson<PlanningPayload>(PLANNING_CACHE_KEY);
}

export async function clearCachedSession(): Promise<void> {
  await Promise.all([
    AsyncStorage.removeItem(USER_CACHE_KEY),
    AsyncStorage.removeItem(PLANNING_CACHE_KEY),
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
