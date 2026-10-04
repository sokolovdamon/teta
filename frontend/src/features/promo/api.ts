import { api } from "@/lib/api";
import type { Batch, PromoDetail, ReferralSettings } from "./types";

export function createCode(payload: Record<string, unknown>) {
  return api<{ data: PromoDetail }>("/admin/promo/codes", { method: "POST", body: payload }).then((r) => r.data);
}

export function updateCode(id: string, payload: Record<string, unknown>) {
  return api<{ data: PromoDetail }>(`/admin/promo/codes/${id}`, { method: "PUT", body: payload }).then((r) => r.data);
}

export function publishCode(id: string) {
  return api<{ data: PromoDetail }>(`/admin/promo/codes/${id}/publish`, { method: "POST" }).then((r) => r.data);
}

export function deactivateCode(id: string, reason: string) {
  return api<{ data: PromoDetail }>(`/admin/promo/codes/${id}/deactivate`, { method: "POST", body: { reason } }).then((r) => r.data);
}

export function createBatch(payload: Record<string, unknown>) {
  return api<{ data: Batch }>("/admin/promo/batches", { method: "POST", body: payload }).then((r) => r.data);
}

export function publishBatch(id: string) {
  return api<{ data: Batch; affected: number }>(`/admin/promo/batches/${id}/publish`, { method: "POST" });
}

export function deactivateBatch(id: string, reason: string) {
  return api<{ data: Batch; affected: number }>(`/admin/promo/batches/${id}/deactivate`, { method: "POST", body: { reason } });
}

/** CSV with every code of the batch (UTF-8 with BOM, ";"), downloaded through the BFF. */
export function batchExportUrl(id: string): string {
  return `/bff/v1/admin/promo/batches/${id}/export`;
}

export function saveReferralSettings(settings: ReferralSettings) {
  return api<{ data: ReferralSettings }>("/admin/promo/referral-settings", { method: "PUT", body: settings }).then((r) => r.data);
}
