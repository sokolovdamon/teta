export type SessionStatus =
  | "booked"
  | "paid"
  | "in_progress"
  | "held"
  | "client_no_show"
  | "psy_no_show"
  | "tech_issue"
  | "cancelled_by_client"
  | "cancelled_by_psy"
  | "cancelled_by_system";

export type ClientChoice = "pending" | "refund" | "reschedule" | "none" | null;

export type Room = { url: string; opens_at: string; closes_at: string; available: boolean };

export type SessionBase = {
  id: string;
  status: SessionStatus;
  status_label: string;
  format: "individual" | "pair";
  format_label: string;
  starts_at: string;
  ends_at: string;
  duration_min: number;
  psychologist: { id: string; slug: string; name: string; timezone: string } | null;
  price?: number;
  amount_charged?: number;
  payment_status: string;
  is_corporate: boolean;
  client_choice: ClientChoice;
  choice_deadline_at: string | null;
  cancel_kind: string | null;
  cancelled_at: string | null;
  rescheduled_from_id: string | null;
  room: Room;
};

export type ClientSession = SessionBase & {
  role: "client" | "partner";
  timezone: string;
  discount?: number;
  amount_due?: number;
  balance_refunded?: number;
  payment_source?: string | null;
  paid_at?: string | null;
  charge?: {
    id: string;
    status: string;
    due_at: string | null;
    deadline_at: string | null;
    last_error_category: string | null;
    last_error_code: string | null;
  } | null;
  is_charged?: boolean;
  free_cancel_until?: string | null;
  late_reschedules_left?: number | null;
  partner?: { email: string | null; accepted: boolean } | null;
  complaint?: { id: string; status: string; due_date: string | null } | null;
  actions: {
    join: boolean;
    reschedule?: boolean;
    cancel?: boolean;
    cancel_free?: boolean;
    choose?: boolean;
    pay?: boolean;
    complaint?: boolean;
  };
};

export type Invitation = { id: string; starts_at: string; psychologist: string | null; inviter: string | null };

export type SessionsResponse = { data: ClientSession[]; invitations: Invitation[]; timezone: string };

export type SessionLog = {
  client_joined_at: string | null;
  psychologist_joined_at: string | null;
  joint_duration_sec: number | null;
  actual_duration_sec: number | null;
};

export type ProSession = SessionBase & {
  client: { id: string; name: string };
  partner_joined: boolean;
  client_requests: string[];
  log: SessionLog;
  actions: { join: boolean; cancel: boolean; reschedule: boolean; outcome: ("held" | "client_no_show" | "tech_issue")[] };
};

export type ProSessionsResponse = { data: ProSession[]; timezone: string; from: string; to: string; awaiting_outcome: number };

export type AdminSession = SessionBase & {
  client: { id: string; name: string; email: string } | null;
  partner: { email: string; user_id: string | null } | null;
  client_timezone: string;
  source: string;
  cancel_reason: string | null;
  charge_due_at: string | null;
  charge_deadline_at: string | null;
  paid: { card: number; balance: number; certificate: number };
  log: SessionLog;
  late_reschedule_count: number;
  discount: number;
  amount_due: number;
  balance_refunded: number;
  payment_source: string | null;
  outcome_source: string | null;
};

export type HistoryEntry = {
  entity: string;
  field: string;
  from: string | null;
  to: string;
  event: string;
  reason: string | null;
  context: Record<string, unknown> | null;
  actor: { id: string; name: string; last_name: string | null } | null;
  created_at: string;
};

export type AdminSessionCard = {
  data: AdminSession;
  history: HistoryEntry[];
  charge_task: {
    id: string;
    status: string;
    amount: number;
    balance_part: number;
    attempts: number;
    due_at: string | null;
    deadline_at: string | null;
    next_attempt_at: string | null;
    last_error_category: string | null;
    last_error_code: string | null;
    attempts_log: { number: number; result: string; error_category: string | null; error_code: string | null; created_at: string }[];
  } | null;
  payments: { id: string; purpose: string; amount: number; refunded_amount: number; status: string; card_mask: string | null; with_payer: boolean; error_code: string | null; paid_at: string | null }[];
  balance_operations: { id: string; type: string; status: string; amount: number; reason_label: string; created_at: string }[];
  complaints: { id: string; status: string; due_date: string; refund_amount: number | null }[];
  rescheduled_to: string | null;
};

export type RescheduleSlots = {
  data: string[];
  late: boolean;
  min_start: string;
  late_reschedules_left: number | null;
  psychologist_timezone: string;
};

export type TimeRequest = {
  id: string;
  status: "open" | "offered" | "booked" | "closed";
  format: string;
  preferred: { weekday?: number; date?: string; from: string; to: string }[];
  preferred_text: string;
  comment: string | null;
  psychologist_comment: string | null;
  offered_slots: string[];
  psychologist: { id: string; slug: string; name: string } | null;
  client: { name: string } | null;
  created_at: string;
  answered_at: string | null;
};

export type MyPsychologist = { psychologist_id: string; slug: string | null; name: string | null; upcoming_sessions: number; last_session_at: string | null };
