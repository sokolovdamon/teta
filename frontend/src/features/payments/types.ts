export type Card = {
  id: string;
  card_mask: string | null;
  card_brand: string | null;
  exp_month: number | null;
  exp_year: number | null;
  purpose: "payment" | "payout";
  is_default: boolean;
};

export type Balance = { available: number; certificate_available: number; withdrawable: number; reserved: number };

export type BalanceOperation = {
  id: string;
  type: "credit" | "spend" | "withdraw";
  status: string;
  amount: number;
  certificate_amount: number;
  is_certificate_funds: boolean;
  reason: string;
  reason_label: string;
  comment: string | null;
  created_at: string;
  user?: { id: string; name: string; last_name: string | null; email: string } | null;
  meta?: Record<string, unknown> | null;
};

export type PendingCharge = {
  id: string;
  status: "scheduled" | "in_progress" | "retry_wait";
  amount: number;
  balance_part: number;
  due_at: string | null;
  deadline_at: string | null;
  last_error_category: string | null;
  last_error_message: string | null;
  can_pay: boolean;
  session: { id: string; starts_at: string; psychologist: string | null } | null;
};

export type Certificate = {
  id: string;
  status: string;
  nominal: number;
  code_hint: string | null;
  recipient_name: string | null;
  valid_until: string | null;
  activated_at: string | null;
  created_at: string | null;
};

export type PaymentsSummary = {
  balance: Balance;
  cards: Card[];
  charges: PendingCharge[];
  withdrawal: BalanceOperation | null;
  certificates: Certificate[];
  timezone: string;
};

export type Receipt = {
  id: string;
  kind: "income" | "income_return";
  kind_label: string;
  calculation_method: string;
  amount: number;
  status: string;
  fiscal: { fn?: string; fd?: string; fpd?: string; registered_at?: string } | null;
  created_at: string;
};

export type PaymentRow = {
  id: string;
  purpose: string;
  purpose_label: string;
  amount: number;
  refunded_amount: number;
  status: string;
  status_label: string;
  card_mask: string | null;
  with_payer: boolean;
  description: string | null;
  error_message: string | null;
  paid_at: string | null;
  created_at: string;
  session: { id: string; starts_at: string; psychologist: string | null; price: number; discount: number; paid_balance: number } | null;
  receipts: Receipt[];
  refunds: { id: string; amount: number; status: string; reason: string | null; error_code: string | null; created_at: string }[];
  user?: { id: string; name: string; last_name: string | null; email: string } | null;
  gateway?: string;
  gateway_payment_id?: string | null;
  idempotency_key?: string;
  error_code?: string | null;
  error_category?: string | null;
};

export type ComplaintStatus = "submitted" | "in_review" | "waiting_client" | "rejected" | "approved" | "refunded" | "withdrawn";

export type Complaint = {
  id: string;
  status: ComplaintStatus;
  reason: string;
  messages: { from: "client" | "admin"; text: string; at: string }[];
  due_date: string;
  working_days_left: number;
  sla: "soon" | "overdue" | null;
  amount_charged: number;
  refund_amount: number | null;
  share_percent: number | null;
  decision_comment: string | null;
  decided_at: string | null;
  created_at: string;
  can_withdraw: boolean;
  session: { id: string; starts_at: string; status: string; psychologist: string | null } | null;
  client?: { id: string; name: string; last_name: string | null; email: string } | null;
  assigned_to?: { id: string; name: string; last_name: string | null } | null;
};

export type PublicPaymentStatus = {
  id: string;
  status: string;
  status_label: string;
  purpose: string;
  purpose_label: string;
  amount: number;
  description: string | null;
  error_message: string | null;
  confirmation_url: string | null;
  next: string | null;
  result: { intent_status?: string; session_id?: string | null; failure_reason?: string | null; certificate_status?: string; send_to?: string; charge_task_id?: string } | null;
};

export type CardBinding = {
  id: string;
  status: "pending" | "succeeded" | "declined";
  purpose: string;
  confirmation_url: string | null;
  return_path: string | null;
  error_code: string | null;
  card: Card | null;
};

export type EmulatorOperation = {
  id: string;
  kind: "binding" | "payment";
  status: "requires_action" | "awaiting_3ds" | "succeeded" | "declined";
  amount: number;
  description: string | null;
  card_mask: string | null;
  error_code: string | null;
  return_url: string | null;
  test_cards: { number: string; title: string; behavior: string }[];
  timeout_rule: string;
  binding_note?: string;
};

export type FinanceSummary = {
  period: { from: string; to: string };
  turnover: { purpose: string; label: string; count: number; amount: number }[];
  turnover_total: number;
  refunds_to_card: number;
  balance: { credited: Record<string, number>; credited_total: number; spent: number; outstanding: number; outstanding_certificate: number };
  sessions: { count: number; retained: number; psychologist_share: number; platform_commission: number };
  certificates: { sold: number; sold_amount: number; activated: number };
  complaints: { open: number; overdue: number };
  failed_charges: number;
};

export type AdminChargeTask = {
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
  session: { id: string; starts_at: string; psychologist: string | null } | null;
};

export type AdminRefund = {
  id: string;
  amount: number;
  status: string;
  reason: string | null;
  error_code: string | null;
  source_type: string | null;
  payment: { id: string; amount: number; purpose: string; card_mask: string | null };
  user: { id: string; name: string; last_name: string | null; email: string } | null;
  created_at: string;
};
