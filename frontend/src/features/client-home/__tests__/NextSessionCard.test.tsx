import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { NextSessionCard } from "../NextSessionCard";
import type { NextSession } from "../types";

vi.mock("next/link", () => ({
  default: ({ href, children, className }: { href: string; children: React.ReactNode; className?: string }) => (
    <a href={href} className={className}>
      {children}
    </a>
  ),
}));

const session: NextSession = {
  id: "s1",
  starts_at: "2026-10-20T16:00:00Z",
  ends_at: "2026-10-20T16:50:00Z",
  duration_min: 50,
  format: "individual",
  status: "paid",
  is_paid: true,
  psychologist: { id: "p1", slug: "anna", name: "Анна Соколова", headline: null, photo_url: null, is_bookable: true, profile_url: "/psychologists/anna" },
  room: { url: "/room/session/s1", opens_at: "2026-10-20T15:50:00Z", closes_at: "2026-10-20T17:05:00Z" },
};

const roomWindow = { open_before_min: 10, close_after_min: 15 };

afterEach(cleanup);

describe("CL-02 next session card", () => {
  it("opens TetaMeet inside the room window", () => {
    render(<NextSessionCard session={session} roomWindow={roomWindow} serverTime="2026-10-20T15:55:00Z" timezone="Europe/Moscow" />);
    expect(screen.getByRole("link", { name: "Войти в TetaMeet" })).toHaveAttribute("href", "/room/session/s1");
    expect(screen.getByText("Оплачена")).toBeInTheDocument();
  });

  it("explains when the room opens before the window", () => {
    render(<NextSessionCard session={session} roomWindow={roomWindow} serverTime="2026-10-20T13:45:00Z" timezone="Europe/Moscow" />);
    expect(screen.getByRole("button", { name: "Войти в TetaMeet" })).toBeDisabled();
    expect(screen.getByText("Вход откроется за 10 мин до начала")).toBeInTheDocument();
    expect(screen.getByText(/через 2 ч 15 мин/)).toBeInTheDocument();
    expect(screen.getByText(/вторник, 20 октября/i)).toBeInTheDocument();
  });
});
