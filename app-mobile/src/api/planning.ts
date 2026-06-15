import { apiFetch } from './client';
import { PlanningPayload } from '../types/api';
import { setCachedPlanning } from '../storage/cache';

export async function getPlanning(): Promise<PlanningPayload> {
  const planning = await apiFetch<PlanningPayload>('/mobile/planning', {
    auth: true,
  });

  await setCachedPlanning(planning);

  return planning;
}
