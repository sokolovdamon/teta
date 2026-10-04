/** PAYOUT API types (PRO-08, PRO-09, ADM-08). Money is in kopecks. */

export type AccrualStatus = "accrued" | "in_registry" | "paid" | "reversed" | "corrected";
export type AccrualKind = "session" | "client_no_show" | "late_cancel" | "supervision" | "correction";
export type PayoutStatus =
  | "checking"
  | "blocked_supervision"
  | "deferred"
  | "in_registry"
  | "excluded"
  | "sent"
  | "unknown"
  | "paid"
  | "rejected";
export type RegistryStatus = "draft" | "approved" | "sent" | "completed";

export type Balance = {
  available: number;
  in_payout: number;
  paid_total: number;
  payouts_suspended: boolean;
  suspended_reason: string | null;
  suspended_at: string | null;
};

export type Card = {
  id: string;
  card_mask: string | null;
  card_brand: string | null;
  exp_month: number | null;
  exp_year: number | null;
  purpose: string;
  is_default: boolean;
};

export type PayoutLine = {
  id: string;
  registry_id: string | null;
  amount: number;
  status: PayoutStatus;
  status_label: string;
  reason: string | null;
  card_mask: string | null;
  sent_at: string | null;
  paid_at: string | null;
  created_at: string | null;
};

export type SupervisionStatus = {
  month: string;
  applies: boolean;
  met: boolean;
  activity_status: string | null;
  deadline: string;
  payout_allowed: boolean;
};

export type PayoutCheck = { code: "supervision" | "card" | "not_suspended" | "min_amount"; ok: boolean; title: string; hint: string | null };

export type PayoutOverview = {
  balance: Balance;
  commission_percent: number;
  min_amount: number;
  next_payout_at: string;
  supervision: SupervisionStatus;
  card: Card | null;
  checks: PayoutCheck[];
  last_line: PayoutLine | null;
  recent_payouts: PayoutLine[];
};

export type CardBinding = { id: string; status: "pending" | "succeeded" | "declined"; confirmation_url: string | null; error_code: string | null; card: Card | null };

export type Adjustment = { type: "reversal" | "correction"; amount: number; reason: string | null; at: string | null };

export type AccrualRow = {
  id: string;
  kind: AccrualKind;
  kind_label: string;
  status: AccrualStatus;
  status_label: string;
  occurred_at: string | null;
  base_amount: number;
  commission_percent: number;
  amount: number;
  reversed_amount: number;
  net: number;
  reason: string | null;
  adjustments: Adjustment[];
  correction_of_id: string | null;
  session: { id: string | null; starts_at: string; format: "individual" | "pair"; client_name: string | null; corporate: boolean } | null;
  payout: { id: string; status: PayoutStatus; paid_at: string | null } | null;
};

export type StatsSummary = {
  period: { from: string; to: string };
  sessions: Record<"held" | "client_no_show" | "cancelled_by_client" | "cancelled_by_psy" | "psy_no_show" | "tech_issue" | "upcoming", number>;
  totals: { accrued: number; reversed: number; net: number; paid_out: number; by_kind: Record<string, { count: number; amount: number }> };
  balance: Balance;
};

export type Payee = { id: string; name: string; email: string };

export type RegistrySummary = {
  lines: number;
  amount: number;
  paid_amount: number;
  blocked: number;
  deferred: number;
  by_status: Partial<Record<PayoutStatus, number>>;
};

export type Registry = {
  id: string;
  period_start: string;
  period_end: string;
  status: RegistryStatus;
  status_label: string;
  auto_approve: boolean;
  approved_at: string | null;
  approved_by: string | null;
  sent_at: string | null;
  completed_at: string | null;
  created_at: string | null;
  summary: RegistrySummary;
};

export type RegistryDetail = Registry & { lines: (PayoutLine & { payee: Payee | null })[] };

export type Parameter = { value: unknown; default: unknown; unit: string; group: string; title: string; overridden: boolean };

export type PayoutSettings = { parameters: Record<string, Parameter>; next_run_at: string; payout_weekday: number; edit_section: string };

export type BlockedPayee = {
  psychologist_id: string;
  payee: Payee | null;
  activity_status: string | null;
  requirement: { month: string; applies: boolean; met: boolean; deadline: string };
  available: number;
  last_blocked_at: string | null;
  last_blocked_amount: number | null;
};

export type PayeeRow = Balance & { payee: Payee | null; has_card: boolean };
