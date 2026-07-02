import { apiFetch } from './client';
import { PlanningAppointmentDocument, PlanningPayload } from '../types/api';
import { setCachedPlanning } from '../storage/cache';

export type MobileUploadFile = {
  uri: string;
  name: string;
  type: string;
};

export async function getPlanning(): Promise<PlanningPayload> {
  const planning = await apiFetch<PlanningPayload>('/mobile/planning', {
    auth: true,
  });

  await setCachedPlanning(planning);

  return planning;
}

export async function uploadAppointmentDocument(
  appointmentId: number,
  file: MobileUploadFile,
  comment?: string,
): Promise<PlanningAppointmentDocument> {
  const body = new FormData();

  body.append('document', {
    uri: file.uri,
    name: file.name,
    type: file.type,
  } as unknown as Blob);
  body.append('name', file.name);

  if (comment) {
    body.append('comment', comment);
  }

  const response = await apiFetch<{
    message: string;
    document: PlanningAppointmentDocument;
  }>(`/mobile/appointments/${appointmentId}/documents`, {
    auth: true,
    method: 'POST',
    body,
  });

  return response.document;
}

export async function refreshAppointmentDocuments(appointmentId: number): Promise<PlanningAppointmentDocument[]> {
  const response = await apiFetch<{
    message: string;
    documents: PlanningAppointmentDocument[];
  }>(`/mobile/appointments/${appointmentId}/refresh`, {
    auth: true,
    method: 'POST',
  });

  return response.documents;
}
