import type { CardBinding, PublicPaymentStatus } from "./types";

export type ReturnOutcome = { state: "pending" | "success" | "failed"; title: string; text: string; next: string; nextLabel: string };

/** What /pay/return shows for a payment, by its purpose and status. */
export function paymentOutcome(p: PublicPaymentStatus): ReturnOutcome {
  const next = p.next ?? (p.purpose === "certificate" ? "/gift" : "/client/payments");
  if (p.status === "succeeded" || p.status === "partially_refunded" || p.status === "refunded") {
    if (p.purpose === "booking") {
      if (p.result?.intent_status === "completed") {
        return { state: "success", title: "Сессия оплачена", text: "Запись подтверждена — письмо с днём и временем отправлено на вашу почту.", next: "/client/sessions", nextLabel: "К сессиям" };
      }
      if (p.result?.intent_status === "failed") {
        return {
          state: "failed",
          title: "Запись не создана",
          text: "Пока шла оплата, это время заняли. Деньги возвращены на карту — выберите другое время.",
          next: "/client/sessions",
          nextLabel: "К сессиям",
        };
      }
      return { state: "pending", title: "Оформляем запись…", text: "Оплата прошла, создаём запись.", next, nextLabel: "Продолжить" };
    }
    if (p.purpose === "certificate") {
      return { state: "success", title: "Сертификат оплачен", text: "Код сертификата отправлен на email.", next, nextLabel: "Продолжить" };
    }
    return { state: "success", title: "Оплата прошла", text: `${p.description ?? "Оплата"} — готово. Чек доступен в разделе «Платежи и баланс».`, next, nextLabel: "Продолжить" };
  }
  if (p.status === "declined") {
    return {
      state: "failed",
      title: "Оплата не прошла",
      text: `${p.error_message ?? "Банк отклонил оплату."} Деньги не списаны. Попробуйте ещё раз или другой картой.`,
      next,
      nextLabel: "Вернуться",
    };
  }
  return { state: "pending", title: "Проверяем оплату…", text: "Платёжный сервис подтверждает операцию. Обычно это занимает несколько секунд.", next, nextLabel: "Продолжить" };
}

/** What /pay/return shows for a card binding. */
export function bindingOutcome(b: CardBinding): ReturnOutcome {
  const next = b.return_path ?? "/client/payments";
  if (b.status === "succeeded") {
    return { state: "success", title: "Карта привязана", text: `Карта ${b.card?.card_mask ?? ""} будет использоваться для оплаты сессий.`, next, nextLabel: "Продолжить" };
  }
  if (b.status === "declined") {
    return { state: "failed", title: "Карта не привязана", text: "Банк отклонил привязку или она была отменена. Попробуйте ещё раз или другую карту.", next, nextLabel: "Вернуться" };
  }
  return { state: "pending", title: "Проверяем карту…", text: "Платёжный сервис подтверждает привязку.", next, nextLabel: "Продолжить" };
}

/** Only internal paths are followed after the return (no open redirects). */
export function safeNext(path: string | null | undefined, fallback = "/client"): string {
  return path && path.startsWith("/") && !path.startsWith("//") ? path : fallback;
}
