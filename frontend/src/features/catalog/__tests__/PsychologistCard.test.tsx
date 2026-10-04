import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { PsychologistCard } from "../PsychologistCard";
import type { PsychologistCard as Card } from "../types";

afterEach(cleanup);

const CARD: Card = {
  id: "p1",
  slug: "anna-sokolova",
  name: "Анна Соколова",
  first_name: "Анна",
  last_name: "Соколова",
  gender: "female",
  age: 40,
  photo_url: null,
  headline: "Помогаю справляться с тревогой",
  experience_years: 9,
  approaches: [
    { slug: "kpt", title: "КПТ" },
    { slug: "act", title: "ACT" },
    { slug: "geshtalt", title: "Гештальт" },
    { slug: "efs", title: "ЭФТ" },
  ],
  specializations: [],
  requests: [],
  works_individual: true,
  works_pair: false,
  price_individual: 350000,
  price_pair: null,
  price_category: { code: "standard", title: "3 500–5 500 ₽" },
  nearest_slot: "2026-10-06T07:00:00Z",
  nearest_slot_format: "individual",
  has_video: true,
  timezone: "Europe/Moscow",
  is_active: true,
};

describe("PsychologistCard (SITE-02)", () => {
  it("shows name, initials, experience, price with category and booking links, without ratings", () => {
    render(<PsychologistCard p={CARD} />);
    expect(screen.getByRole("link", { name: "Анна Соколова" })).toHaveAttribute("href", "/psychologists/anna-sokolova");
    expect(screen.getByText("АС")).toBeInTheDocument();
    expect(screen.getByText(/Опыт 9 лет · 40 лет/)).toBeInTheDocument();
    expect(screen.getByText(/3\s500\s₽/)).toBeInTheDocument();
    expect(screen.getByText("3 500–5 500 ₽")).toBeInTheDocument();
    expect(screen.getByText("ещё 1")).toBeInTheDocument();
    expect(screen.getByText("Видеовизитка")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Записаться" })).toHaveAttribute("href", "/psychologists/anna-sokolova#booking");
    // The label uses the browser timezone; the link always carries the UTC start.
    const slot = screen.getByRole("link", { name: /в \d{2}:\d{2}$/ });
    expect(slot.getAttribute("href")).toBe("/podbor/book?psychologist=anna-sokolova&format=individual&starts_at=2026-10-06T07%3A00%3A00Z");
    expect(screen.queryByText(/рейтинг/i)).not.toBeInTheDocument();
  });

  it("explains when there is no free time", () => {
    render(<PsychologistCard p={{ ...CARD, nearest_slot: null }} compact />);
    expect(screen.getByText("Свободное время появится позже")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Подробнее" })).not.toBeInTheDocument();
  });
});
