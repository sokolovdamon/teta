import { describe, expect, it } from "vitest";
import { CLIENT_STATUS, STATUS, cancelRuleText, choiceReason, isUpcoming, paymentLine, rescheduleRuleText } from "../labels";
import type { ClientSession } from "../types";

const nbsp = (s: string | null) => (s ?? "").replace(/ /g, " ");

function session(overrides: Partial<ClientSession> = {}): ClientSession {
  return {
    id: "s1",
    status: "booked",
    status_label: "Забронирована",
    format: "individual",
    format_label: "Индивидуальная сессия",
    starts_at: "2026-10-12T11:00:00Z",
    ends_at: "2026-10-12T11:50:00Z",
    duration_min: 50,
    psychologist: { id: "p", slug: "anna", name: "Анна Соколова", timezone: "Europe/Moscow" },
    price: 400000,
    amount_due: 400000,
    amount_charged: 0,
    discount: 0,
    balance_refunded: 0,
    payment_status: "Ожидает списания",
    is_corporate: false,
    client_choice: null,
    choice_deadline_at: null,
    cancel_kind: null,
    cancelled_at: null,
    rescheduled_from_id: null,
    room: { url: "/room/session/s1", opens_at: "2026-10-12T10:50:00Z", closes_at: "2026-10-12T12:05:00Z", available: false },
    role: "client",
    timezone: "Europe/Moscow",
    is_charged: false,
    free_cancel_until: "2026-10-11T23:00:00Z",
    charge: { id: "t1", status: "scheduled", due_at: "2026-10-11T23:00:00Z", deadline_at: "2026-10-12T09:00:00Z", last_error_category: null, last_error_code: null },
    actions: { join: false, reschedule: true, cancel: true },
    ...overrides,
  };
}

describe("booking rules in plain Russian", () => {
  it("has a label for every status and never uses forbidden words", () => {
    for (const text of [...Object.values(CLIENT_STATUS), ...Object.values(STATUS)]) {
      expect(text).not.toMatch(/пациент|лечени|врач|диагноз|штраф/i);
    }
    expect(CLIENT_STATUS.cancelled_by_client).toBe("Отменена вами");
  });

  it("explains the free cancel until the charge time in the client's timezone", () => {
    expect(nbsp(cancelRuleText(session(), "Europe/Moscow"))).toBe(
      "Отмена бесплатна до 12 октября, понедельник, 02:00 (МСК) — в этот момент спишется оплата. Сейчас оплата не списана, ничего не удержим.",
    );
  });

  it("warns that a charged session is not refunded and suggests a reschedule", () => {
    const text = nbsp(cancelRuleText(session({ status: "paid", is_charged: true, amount_charged: 400000 }), "Europe/Moscow"));
    expect(text).toContain("4 000 ₽ уже списана: при отмене она не возвращается");
    expect(text).toContain("не раньше чем через 12 часов");
  });

  it("corporate sessions keep or consume the limit", () => {
    expect(cancelRuleText(session({ is_corporate: true }), "Europe/Moscow")).toContain("лимит корпоративной программы сохранится");
    expect(cancelRuleText(session({ is_corporate: true, is_charged: true }), "Europe/Moscow")).toContain("засчитана в лимит");
  });

  it("describes reschedule rules before and after the charge", () => {
    expect(rescheduleRuleText(false, null)).toContain("перенос бесплатный и не ограничен");
    expect(rescheduleRuleText(true, 1)).toContain("Осталось переносов: 1");
    expect(rescheduleRuleText(true, 0)).toContain("Лимит переносов исчерпан");
  });

  it("gives the reason for the refund-or-reschedule choice", () => {
    expect(choiceReason("psy_no_show")).toContain("не подключился");
    expect(choiceReason("tech_issue")).toContain("технической проблемы");
    expect(choiceReason("cancelled_by_psy")).toContain("отменил");
  });

  it("summarises payment of a session", () => {
    expect(nbsp(paymentLine(session(), "Europe/Moscow"))).toBe("4 000 ₽ спишется 12 октября, понедельник, 02:00 (МСК)");
    expect(paymentLine(session({ amount_due: 0 }), "Europe/Moscow")).toBe("Бесплатно по промокоду");
    expect(nbsp(paymentLine(session({ charge: { ...session().charge!, status: "retry_wait" } }), "Europe/Moscow"))).toContain("Оплата не прошла");
    expect(nbsp(paymentLine(session({ status: "paid", paid_at: "x", amount_charged: 360000, discount: 40000 }), "Europe/Moscow"))).toBe("Оплачено 3 600 ₽, скидка 400 ₽");
    expect(nbsp(paymentLine(session({ status: "cancelled_by_psy", paid_at: "x", amount_charged: 400000, balance_refunded: 400000, client_choice: "refund" }), "Europe/Moscow"))).toBe(
      "4 000 ₽ возвращено на баланс",
    );
    expect(paymentLine(session({ role: "partner" }), "Europe/Moscow")).toBe("Оплату вносит пригласивший");
    expect(paymentLine(session({ is_corporate: true }), "Europe/Moscow")).toBe("Оплачивает компания");
  });

  it("keeps sessions awaiting the client's choice among upcoming ones", () => {
    expect(isUpcoming({ status: "paid", client_choice: null })).toBe(true);
    expect(isUpcoming({ status: "cancelled_by_psy", client_choice: "pending" })).toBe(true);
    expect(isUpcoming({ status: "held", client_choice: null })).toBe(false);
  });
});
