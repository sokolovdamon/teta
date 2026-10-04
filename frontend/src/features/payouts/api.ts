import { api } from "@/lib/api";
import type { CardBinding, PayeeRow, Registry, RegistryDetail } from "./types";

/** PRO-09: start binding the self-employed card; the browser then goes to the gateway confirmation page. */
export function startCardBinding() {
  return api<{ data: CardBinding }>("/pro/payouts/card", { method: "POST" }).then((r) => r.data);
}

export function confirmCardBinding(id: string) {
  return api<{ data: CardBinding }>(`/pro/payouts/card/bindings/${id}/confirm`, { method: "POST" }).then((r) => r.data);
}

export function removeCard() {
  return api("/pro/payouts/card", { method: "DELETE" });
}

/** PRO-08 CSV report (UTF-8 with BOM, ";"), downloaded through the BFF with the session cookie. */
export function statsExportUrl(from: string, to: string): string {
  return `/bff/v1/pro/stats/export?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
}

// ADM-08
export function buildRegistry() {
  return api<{ data: Registry }>("/admin/payouts/registries", { method: "POST" }).then((r) => r.data);
}

export function approveRegistry(id: string) {
  return api<{ data: Registry }>(`/admin/payouts/registries/${id}/approve`, { method: "POST" }).then((r) => r.data);
}

export function retryRegistry(id: string) {
  return api<{ data: Registry; stats: { sent: number; checked: number } }>(`/admin/payouts/registries/${id}/retry`, { method: "POST" });
}

export function excludeLine(id: string, reason: string) {
  return api<{ data: RegistryDetail["lines"][number] }>(`/admin/payouts/lines/${id}/exclude`, { method: "POST", body: { reason } }).then((r) => r.data);
}

export function suspendPayee(userId: string, reason: string) {
  return api<{ data: PayeeRow }>(`/admin/payouts/payees/${userId}/suspend`, { method: "POST", body: { reason } }).then((r) => r.data);
}

export function resumePayee(userId: string) {
  return api<{ data: PayeeRow }>(`/admin/payouts/payees/${userId}/resume`, { method: "POST" }).then((r) => r.data);
}
