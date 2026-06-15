import { API_BASE_URL } from '../config';
import { getToken } from '../storage/secure';

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

type ApiOptions = Omit<RequestInit, 'headers'> & {
  auth?: boolean;
  headers?: Record<string, string>;
};

export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const { auth = false, headers, ...rest } = options;
  const mergedHeaders: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...headers,
  };

  if (auth) {
    const token = await getToken();
    if (token) {
      mergedHeaders.Authorization = `Bearer ${token}`;
    }
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...rest,
    headers: mergedHeaders,
  });
  const text = await response.text();
  const data = text ? parseJson(text) : null;

  if (!response.ok) {
    throw new ApiError(
      data?.message || 'Une erreur est survenue.',
      response.status,
      data?.errors,
    );
  }

  return data as T;
}

function parseJson(text: string): any {
  try {
    return JSON.parse(text);
  } catch {
    throw new ApiError('Réponse API invalide.', 500);
  }
}
