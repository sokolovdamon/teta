import { describe, expect, it } from "vitest";
import { balanceSign, formatCardNumber, normalizeCertificateCode, slaBadge } from "../labels";
import { bindingOutcome, paymentOutcome, safeNext } from "../returnOutcome";
import type { PublicPaymentStatus } from "../types";

function payment(overrides: Partial<PublicPaymentStatus> = {}): PublicPaymentStatus {
  return {
    id: "p1",
    status: "succeeded",
    status_label: "Проведён",
    purpose: "session",
    purpose_label: "Оплата сессии",
    amount: 400000,
    description: "Оплата сессии",
    error_message: null,
    confirmation_url: null,
    next: "/client/payments",
    result: null,
    ...overrides,
  };
}

describe("payments helpers", () => {
  it("signs ledger operations", () => {
    expect(balanceSign("credit", "credited")).toBe(1);
    expect(balanceSign("spend", "spent")).toBe(-1);
    expect(balanceSign("spend", "spend_reversed")).toBe(0);
    expect(balanceSign("withdraw", "withdraw_cancelled")).toBe(0);
    expect(balanceSign("withdraw", "withdrawn")).toBe(-1);
  });

  it("shows the complaint SLA (14 working days)", () => {
    expect(slaBadge(null, 9)).toEqual({ tone: "neutral", text: "Осталось 9 раб. дн." });
    expect(slaBadge("soon", 2)).toEqual({ tone: "warning", text: "Осталось 2 раб. дн." });
    expect(slaBadge("soon", 0).text).toBe("Срок сегодня");
    expect(slaBadge("overdue", -3)).toEqual({ tone: "danger", text: "Просрочена на 3 раб. дн." });
  });

  it("formats card numbers and certificate codes as typed", () => {
    expect(formatCardNumber("4111111111111111")).toBe("4111 1111 1111 1111");
    expect(formatCardNumber("4000-0000 0000 3220")).toBe("4000 0000 0000 3220");
    expect(normalizeCertificateCode("teta-ab12 cd34")).toBe("TETA-AB12CD34");
  });
});

describe("/pay/return outcomes", () => {
  it("booking: success when the session was created, failure when the slot was lost", () => {
    expect(paymentOutcome(payment({ purpose: "booking", result: { intent_status: "completed", session_id: "s1" } }))).toMatchObject({ state: "success", next: "/client/sessions" });
    expect(paymentOutcome(payment({ purpose: "booking", result: { intent_status: "failed" } }))).toMatchObject({ state: "failed", title: "Запись не создана" });
    expect(paymentOutcome(payment({ purpose: "booking", result: { intent_status: "pending" } })).state).toBe("pending");
  });

  it("declined and pending payments", () => {
    expect(paymentOutcome(payment({ status: "declined", error_message: "Недостаточно средств на карте." }))).toMatchObject({
      state: "failed",
      text: "Недостаточно средств на карте. Деньги не списаны. Попробуйте ещё раз или другой картой.",
    });
    expect(paymentOutcome(payment({ status: "requires_3ds" })).state).toBe("pending");
  });

  it("certificate goes to the success page", () => {
    expect(paymentOutcome(payment({ purpose: "certificate", next: "/gift/success?certificate=c1" }))).toMatchObject({ state: "success", next: "/gift/success?certificate=c1" });
  });

  it("card binding", () => {
    const card = { id: "m1", card_mask: "•••• 1111", card_brand: "Visa", exp_month: 12, exp_year: 2030, purpose: "payment" as const, is_default: true };
    expect(bindingOutcome({ id: "b", status: "succeeded", purpose: "payment", confirmation_url: null, return_path: "/client/payments?bound=1", error_code: null, card })).toMatchObject({
      state: "success",
      next: "/client/payments?bound=1",
    });
    expect(bindingOutcome({ id: "b", status: "declined", purpose: "payment", confirmation_url: null, return_path: null, error_code: "authentication_failed", card: null }).state).toBe("failed");
  });

  it("follows only internal paths", () => {
    expect(safeNext("/client/sessions")).toBe("/client/sessions");
    expect(safeNext("https://evil.example")).toBe("/client");
    expect(safeNext("//evil.example")).toBe("/client");
    expect(safeNext(null, "/gift")).toBe("/gift");
  });
});
