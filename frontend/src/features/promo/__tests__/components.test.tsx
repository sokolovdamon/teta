import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { InviteOverview } from "../types";

const apiMock = vi.fn();
vi.mock("@/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api")>();
  return { ...actual, api: (...args: unknown[]) => apiMock(...args) };
});

import { InviteFriend } from "../InviteFriend";
import { PromoForm } from "../PromoForm";

const invite: InviteOverview = {
  code: "K7M2PQ9X",
  url: "http://localhost:3000/auth/register?ref=K7M2PQ9X",
  terms: { friend_discount_percent: 50, reward_type: "fixed", reward_value: 100000, validity_days: 90 },
  stats: { invited: 1, registered: 1, rewarded: 1 },
  invites: [{ id: "i1", friend: "Мария П.", status: "rewarded", status_label: "Промокод получен", rejected_reason: null, registered_at: "2026-10-01T10:00:00Z", rewarded_at: "2026-10-05T10:00:00Z" }],
  rewards: [{ id: "c1", code: "DRUG-ABC234", type: "fixed", value: 100000, discount_label: "−1 000 ₽", status: "active", status_label: "Активен", valid_until: "2027-01-03T20:59:59Z", used: false }],
  friend_code: null,
};

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("CL-13 invite a friend", () => {

  it("shows the personal link, terms, friends and reward codes", async () => {
    apiMock.mockResolvedValue({ data: invite });
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
    render(<InviteFriend timezone="Europe/Moscow" />);

    expect(await screen.findByDisplayValue(invite.url)).toBeInTheDocument();
    expect(screen.getByText(/Другу — скидка 50 % на первую сессию/)).toBeInTheDocument();
    expect(screen.getByText("Мария П.")).toBeInTheDocument();
    expect(screen.getByText("DRUG-ABC234")).toBeInTheDocument();
    expect(screen.getByText("Промокод получен")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Скопировать ссылку" }));
    expect(writeText).toHaveBeenCalledWith(invite.url);
    expect(await screen.findByRole("button", { name: "Ссылка скопирована" })).toBeInTheDocument();
  });

  it("shows an empty state without friends", async () => {
    apiMock.mockResolvedValue({ data: { ...invite, stats: { invited: 0, registered: 0, rewarded: 0 }, invites: [], rewards: [] } });
    render(<InviteFriend timezone="Europe/Moscow" />);
    expect(await screen.findByText("Пока никого")).toBeInTheDocument();
    expect(screen.getByText("Промокодов пока нет")).toBeInTheDocument();
  });

  it("shows an error with retry", async () => {
    apiMock.mockRejectedValueOnce(new Error("offline")).mockResolvedValue({ data: invite });
    render(<InviteFriend timezone="Europe/Moscow" />);
    await userEvent.click(await screen.findByRole("button", { name: "Повторить" }));
    expect(await screen.findByDisplayValue(invite.url)).toBeInTheDocument();
  });
});

describe("ADM-09 promo form", () => {
  it("submits a fixed discount in kopecks with restrictions", async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(
      <PromoForm
        mode="create"
        lookups={{ psychologists: [{ id: "psy1", name: "Анна Соколова" }], price_categories: [], users: [] }}
        onSubmit={onSubmit}
        onCancel={() => undefined}
      />,
    );

    await userEvent.type(screen.getByPlaceholderText("AUTUMN20"), "minus500");
    await userEvent.selectOptions(screen.getByLabelText(/^Тип/), "fixed");
    await userEvent.type(screen.getByLabelText("Скидка, ₽"), "500");
    await userEvent.click(screen.getByLabelText("Анна Соколова"));
    await userEvent.click(screen.getByLabelText("Парная сессия"));
    await userEvent.click(screen.getByRole("button", { name: "Создать" }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0][0]).toMatchObject({
      code: "MINUS500",
      type: "fixed",
      value: 50000,
      kind: "mass",
      restrictions: { service_types: ["pair"], psychologist_ids: ["psy1"] },
    });
  });

  it("generates a batch with size and prefix", async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<PromoForm mode="batch" lookups={null} onSubmit={onSubmit} onCancel={() => undefined} />);

    await userEvent.type(screen.getByLabelText("Название пакета"), "Партнёры");
    await userEvent.type(screen.getByLabelText("Скидка, %"), "15");
    await userEvent.type(screen.getByLabelText(/^Префикс/), "GIFT");
    await userEvent.click(screen.getByRole("button", { name: "Сгенерировать" }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0][0]).toMatchObject({ title: "Партнёры", type: "percent", value: 15, size: 100, prefix: "GIFT" });
    expect(onSubmit.mock.calls[0][0].code).toBeUndefined();
  });
});
