import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { PayoutOverview } from "../types";

const replace = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, push: vi.fn(), refresh: vi.fn() }) }));

const apiMock = vi.fn();
vi.mock("@/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api")>();
  return { ...actual, api: (...args: unknown[]) => apiMock(...args) };
});

import { ProPayouts } from "../ProPayouts";

const overview: PayoutOverview = {
  balance: { available: 280000, in_payout: 0, paid_total: 560000, payouts_suspended: false, suspended_reason: null, suspended_at: null },
  commission_percent: 30,
  min_amount: 100000,
  next_payout_at: "2026-10-12T00:00:00+00:00",
  supervision: { month: "2026-10-01", applies: true, met: false, activity_status: "active_not_met", deadline: "2026-10-31T20:59:59+00:00", payout_allowed: false },
  card: null,
  checks: [
    { code: "supervision", ok: false, title: "Супервизия текущего месяца", hint: "Пройдите и оплатите супервизию" },
    { code: "card", ok: false, title: "Карта для выплат", hint: "Привяжите карту самозанятого" },
    { code: "not_suspended", ok: true, title: "Выплаты не приостановлены", hint: null },
    { code: "min_amount", ok: true, title: "Сумма не меньше 1 000 ₽", hint: null },
  ],
  last_line: { id: "p1", registry_id: "r1", amount: 280000, status: "blocked_supervision", status_label: "Заблокирована: нет супервизии месяца", reason: "Не выполнено требование ежемесячной супервизии", card_mask: null, sent_at: null, paid_at: null, created_at: "2026-10-05T00:00:00+00:00" },
  recent_payouts: [],
};

function respond(path: string, init?: { method?: string }) {
  if (path === "/pro/payouts") return Promise.resolve({ data: overview });
  if (path.startsWith("/pro/payouts/history")) return Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } });
  if (path === "/pro/payouts/card" && init?.method === "POST") {
    return Promise.resolve({ data: { id: "b1", status: "pending", confirmation_url: "/pay/emulator/abc", error_code: null, card: null } });
  }
  if (path === "/pro/payouts/card/bindings/b1/confirm") {
    return Promise.resolve({ data: { id: "b1", status: "succeeded", confirmation_url: null, error_code: null, card: { id: "c1", card_mask: "2200 00** **** 0001", card_brand: "MIR", exp_month: 12, exp_year: 2030, purpose: "payout", is_default: true } } });
  }
  return Promise.reject(new Error(`unexpected ${path}`));
}

describe("PRO-09 payouts", () => {
  const assign = vi.fn();
  const originalLocation = window.location;

  beforeEach(() => {
    apiMock.mockImplementation(respond);
    Object.defineProperty(window, "location", { configurable: true, value: { ...originalLocation, assign } });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    Object.defineProperty(window, "location", { configurable: true, value: originalLocation });
  });

  it("shows the balance net of commission, failed conditions and the supervision requirement", async () => {
    render(<ProPayouts timezone="Europe/Moscow" />);

    expect(await screen.findByText("за вычетом комиссии платформы 30 %")).toBeInTheDocument();
    expect(screen.getByText("Супервизия текущего месяца")).toBeInTheDocument();
    expect(screen.getByText("Не пройдена")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Перейти к супервизии" })).toHaveAttribute("href", "/pro/supervision");
    expect(screen.getByText("Не выполнено требование ежемесячной супервизии")).toBeInTheDocument();
    expect(screen.getByText("Карта не привязана")).toBeInTheDocument();
    expect(screen.getByText("Выплат пока не было")).toBeInTheDocument();
  });

  it("sends the psychologist to the gateway confirmation page to bind a card", async () => {
    render(<ProPayouts timezone="Europe/Moscow" />);
    await userEvent.click(await screen.findByRole("button", { name: "Привязать карту" }));

    await waitFor(() => expect(assign).toHaveBeenCalledWith("/pay/emulator/abc"));
    expect(apiMock).toHaveBeenCalledWith("/pro/payouts/card", { method: "POST" });
  });

  it("confirms the binding after returning from the confirmation page", async () => {
    render(<ProPayouts timezone="Europe/Moscow" bindingId="b1" />);

    expect(await screen.findByText("Карта 2200 00** **** 0001 привязана для выплат.")).toBeInTheDocument();
    expect(replace).toHaveBeenCalledWith("/pro/payouts");
  });
});
