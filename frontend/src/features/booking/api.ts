import { api } from "@/lib/api";
import type { ClientSession, MyPsychologist, ProSession, RescheduleSlots, SessionsResponse, TimeRequest } from "./types";

// ── Client (CL-03) ────────────────────────────────────────────────────────────────────────────────────────────

export function loadSessions(scope: "upcoming" | "past") {
  return api<SessionsResponse>("/booking/sessions", { query: { scope } });
}

export function rescheduleSlots(id: string, pro = false) {
  return api<RescheduleSlots>(pro ? `/booking/pro/sessions/${id}/reschedule-slots` : `/booking/sessions/${id}/reschedule-slots`);
}

export function reschedule(id: string, startsAt: string, pro = false) {
  return api<{ data: ClientSession }>(pro ? `/booking/pro/sessions/${id}/reschedule` : `/booking/sessions/${id}/reschedule`, {
    method: "POST",
    body: { starts_at: startsAt },
  });
}

export function cancelSession(id: string, body: { reason?: string; confirm_late?: boolean }) {
  return api<{ data: ClientSession; kind: string; refund_amount: number; retained_amount: number }>(`/booking/sessions/${id}/cancel`, {
    method: "POST",
    body,
  });
}

export function chooseAfterIssue(id: string, choice: "refund" | "reschedule", startsAt?: string) {
  return api<{ data: ClientSession }>(`/booking/sessions/${id}/choice`, { method: "POST", body: { choice, starts_at: startsAt } });
}

export function acceptInvitation(id: string) {
  return api<{ data: ClientSession }>(`/booking/sessions/${id}/partner/accept`, { method: "POST" });
}

export function myPsychologists() {
  return api<{ data: MyPsychologist[] }>("/booking/my-psychologists");
}

export function loadTimeRequests() {
  return api<{ data: TimeRequest[] }>("/booking/time-requests");
}

export function createTimeRequest(body: { psychologist_id: string; format?: string; preferred: { weekday?: number; from: string; to: string }[]; comment?: string }) {
  return api<{ data: TimeRequest }>("/booking/time-requests", { method: "POST", body });
}

export function cancelTimeRequest(id: string) {
  return api<{ data: TimeRequest }>(`/booking/time-requests/${id}/cancel`, { method: "POST" });
}

// ── Psychologist (PRO-04) ─────────────────────────────────────────────────────────────────────────────────────

export function loadProSessions(from: string, to: string, includeCancelled: boolean) {
  return api<{ data: ProSession[]; timezone: string; awaiting_outcome: number }>("/booking/pro/sessions", {
    query: { from, to, include_cancelled: includeCancelled ? 1 : 0 },
  });
}

export function setOutcome(id: string, outcome: string) {
  return api<{ data: ProSession }>(`/booking/pro/sessions/${id}/outcome`, { method: "POST", body: { outcome } });
}

export function proCancel(id: string, reason: string) {
  return api<{ data: ProSession }>(`/booking/pro/sessions/${id}/cancel`, { method: "POST", body: { reason } });
}

export function loadInbox(status: "active" | "all" = "active") {
  return api<{ data: TimeRequest[]; open: number }>("/booking/pro/time-requests", { query: { status } });
}

export function offerTime(id: string, slots: string[], comment?: string) {
  return api<{ data: TimeRequest }>(`/booking/pro/time-requests/${id}/offer`, { method: "POST", body: { slots, comment } });
}

export function closeTimeRequest(id: string, comment?: string) {
  return api<{ data: TimeRequest }>(`/booking/pro/time-requests/${id}/close`, { method: "POST", body: { comment } });
}

// ── Admin (ADM-04) ────────────────────────────────────────────────────────────────────────────────────────────

export function adminOutcome(id: string, outcome: string, reason: string) {
  return api(`/admin/sessions/${id}/outcome`, { method: "POST", body: { outcome, reason } });
}

export function adminCancel(id: string, reason: string) {
  return api(`/admin/sessions/${id}/cancel`, { method: "POST", body: { reason } });
}
