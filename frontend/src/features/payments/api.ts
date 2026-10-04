import { api } from "@/lib/api";
import type { BalanceOperation, CardBinding, Certificate, Complaint, EmulatorOperation, PublicPaymentStatus } from "./types";

// ── CL-07 ─────────────────────────────────────────────────────────────────────────────────────────────────────

export function startBinding(returnPath = "/client/payments") {
  return api<{ data: CardBinding }>("/payments/cards", { method: "POST", body: { purpose: "payment", return_path: returnPath } }).then((r) => r.data);
}

export function bindingStatus(id: string) {
  return api<{ data: CardBinding }>(`/payments/cards/bindings/${id}`).then((r) => r.data);
}

export function removeCard(id: string) {
  return api<{ ok: boolean; unpaid_sessions: number; deadline: string | null; has_other_card: boolean }>(`/payments/cards/${id}`, { method: "DELETE" });
}

export function makeDefault(id: string) {
  return api(`/payments/cards/${id}/default`, { method: "POST" });
}

export function payCharge(taskId: string) {
  return api<{ paid: boolean; payment_id: string | null; confirmation_url: string | null }>(`/payments/charges/${taskId}/pay`, { method: "POST", body: { save_card: true } });
}

export function withdraw() {
  return api<{ data: BalanceOperation }>("/payments/balance/withdraw", { method: "POST" });
}

export function activateCertificate(code: string) {
  return api<{ data: Certificate }>("/payments/certificates/activate", { method: "POST", body: { code } });
}

export function submitComplaint(sessionId: string, reason: string) {
  return api<{ data: Complaint }>("/payments/complaints", { method: "POST", body: { session_id: sessionId, reason } });
}

export function answerComplaint(id: string, text: string) {
  return api<{ data: Complaint }>(`/payments/complaints/${id}/answer`, { method: "POST", body: { text } });
}

export function withdrawComplaint(id: string) {
  return api<{ data: Complaint }>(`/payments/complaints/${id}/withdraw`, { method: "POST" });
}

// ── Checkout and return (public) ──────────────────────────────────────────────────────────────────────────────

export function paymentStatus(id: string) {
  return api<{ data: PublicPaymentStatus }>(`/payments/status/${id}`).then((r) => r.data);
}

export function emulatorOperation(id: string) {
  return api<{ data: EmulatorOperation }>(`/payments/emulator/operations/${id}`).then((r) => r.data);
}

export function emulatorSubmitCard(id: string, card: { card_number: string; exp_month: number; exp_year: number; cvc: string }) {
  return api<{ data: EmulatorOperation }>(`/payments/emulator/operations/${id}/card`, { method: "POST", body: card }).then((r) => r.data);
}

export function emulator3ds(id: string, decision: "confirm" | "decline") {
  return api<{ data: EmulatorOperation }>(`/payments/emulator/operations/${id}/3ds`, { method: "POST", body: { decision } }).then((r) => r.data);
}

export function emulatorCancel(id: string) {
  return api<{ data: EmulatorOperation }>(`/payments/emulator/operations/${id}/cancel`, { method: "POST" }).then((r) => r.data);
}

export type GiftForm = {
  nominal: number;
  buyer_email: string;
  buyer_name?: string;
  recipient_name?: string;
  recipient_email?: string;
  message?: string;
  send_to: "recipient" | "buyer";
  accept_offer: boolean;
  accept_personal_data: boolean;
};

export function buyCertificate(form: GiftForm) {
  return api<{ payment_id: string; certificate_id: string; confirmation_url: string | null }>("/payments/gift", { method: "POST", body: form });
}

export function certificateStatus(id: string) {
  return api<{ data: { id: string; status: string; nominal: number; send_to: string; valid_until: string | null } }>(`/payments/gift/${id}`).then((r) => r.data);
}

// ── ADM-07 ────────────────────────────────────────────────────────────────────────────────────────────────────

export function adminRefund(paymentId: string, amount: number, reason: string) {
  return api<{ refund_status: string }>(`/admin/finance/payments/${paymentId}/refund`, { method: "POST", body: { amount, reason } });
}

export function adminResolveWithdrawal(id: string, action: "withdrawn" | "cancel", reason: string) {
  return api(`/admin/finance/withdrawals/${id}/resolve`, { method: "POST", body: { action, reason } });
}

export function adminComplaintAction(id: string, action: "take" | "resume" | "ask" | "reject" | "approve", body?: Record<string, unknown>) {
  return api<{ data: Complaint }>(`/admin/finance/complaints/${id}/${action}`, { method: "POST", body: body ?? {} });
}
