import { apiFetch } from './client';
import { PlanningPayload } from '../types/api';

export async function getPlanning(): Promise<PlanningPayload> {
  return apiFetch<PlanningPayload>('/mobile/planning', {
    auth: true,
  });
}
