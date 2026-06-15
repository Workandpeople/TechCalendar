export type MobileUser = {
  id: number;
  first_name: string | null;
  last_name: string | null;
  full_name: string;
  initials: string;
  email: string;
  must_change_password: boolean;
  notification_mail_enabled: boolean;
  notification_push_enabled: boolean;
  phone: string | null;
  address: string | null;
  department_code: string | null;
  day_start_time: string | null;
  day_end_time: string | null;
};

export type PlanningAppointment = {
  id: number;
  service_label: string;
  service_type: string | null;
  service_name: string | null;
  customer_name: string;
  customer_phone: string | null;
  address: string;
  postal_code: string | null;
  city: string | null;
  latitude: number | null;
  longitude: number | null;
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  comment: string | null;
};

export type PlanningWidgets = {
  today_count: number;
  week_count: number;
  week_planned_hours: number;
  week_drive_km: number;
  week_drive_hours: number;
  week_overtime_hours: number;
  next_appointment: PlanningAppointment | null;
};

export type PlanningPayload = {
  generated_at: string;
  period: {
    start: string;
    end: string;
  };
  widgets: PlanningWidgets;
  appointments: PlanningAppointment[];
};

export type PlanningCacheInfo = {
  cached_at: string;
  generated_at: string | null;
};

export type LoginPayload = {
  token: string;
  token_type: 'Bearer';
  expires_at: string | null;
  user: MobileUser;
};

export type ChangePasswordPayload = {
  message: string;
  user: MobileUser;
};

export type ForgotPasswordPayload = {
  message: string;
};
