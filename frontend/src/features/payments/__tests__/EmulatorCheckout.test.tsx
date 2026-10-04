import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { EmulatorOperation } from "../types";

const api = vi.hoisted(() => ({
  emulatorOperation: vi.fn(),
  emulatorSubmitCard: vi.fn(),
  emulator3ds: vi.fn(),
  emulatorCancel: vi.fn(),
}));
vi.mock("../api", () => api);

import { EmulatorCheckout } from "../EmulatorCheckout";

const base: EmulatorOperation = {
  id: "op1",
  kind: "payment",
  status: "requires_action",
  amount: 350000,
  description: "Оплата сессии",
  card_mask: null,
  error_code: null,
  return_url: null,
  test_cards: [
    { number: "4111 1111 1111 1111", title: "Оплата проходит", behavior: "success" },
    { number: "4000 0000 0000 3220", title: "Требуется 3-D Secure", behavior: "3ds" },
  ],
  timeout_rule: "13 копеек — обрыв связи",
};

describe("emulator checkout", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("documents test cards and goes through 3-D Secure", async () => {
    api.emulatorOperation.mockResolvedValue(base);
    api.emulatorSubmitCard.mockResolvedValue({ ...base, status: "awaiting_3ds", card_mask: "•••• 3220" });
    api.emulator3ds.mockResolvedValue({ ...base, status: "succeeded", card_mask: "•••• 3220" });

    render(<EmulatorCheckout id="op1" />);
    expect(await screen.findByText("Тестовый платёжный сервис (эмулятор)")).toBeInTheDocument();
    expect(screen.getByText("— Требуется 3-D Secure")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "4000 0000 0000 3220" }));
    expect(screen.getByLabelText("Номер карты")).toHaveValue("4000 0000 0000 3220");
    fireEvent.click(screen.getByRole("button", { name: "Оплатить" }));

    await waitFor(() => expect(api.emulatorSubmitCard).toHaveBeenCalledWith("op1", { card_number: "4000000000003220", exp_month: 12, exp_year: 30, cvc: "123" }));
    expect(await screen.findByText("Страница банка-эмитента · 3-D Secure")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Подтвердить" }));
    await waitFor(() => expect(api.emulator3ds).toHaveBeenCalledWith("op1", "confirm"));
    expect(await screen.findByText("Готово")).toBeInTheDocument();
  });

  it("shows the declined result and lets the payer cancel", async () => {
    api.emulatorOperation.mockResolvedValue(base);
    api.emulatorCancel.mockResolvedValue({ ...base, status: "declined", error_code: "payer_cancelled" });
    render(<EmulatorCheckout id="op1" />);
    fireEvent.click(await screen.findByRole("button", { name: "Отменить" }));
    expect(await screen.findByText("Операция отклонена")).toBeInTheDocument();
  });
});
