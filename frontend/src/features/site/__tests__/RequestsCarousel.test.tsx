import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { RequestsCarousel } from "../RequestsCarousel";

afterEach(cleanup);

const GROUPS = [
  {
    id: "g1",
    slug: "moe-sostoyanie",
    title: "Моё состояние",
    format: "individual" as const,
    requests: [
      { id: "r1", slug: "stress", title: "Стресс", format: "individual" as const, age_label: null, path: "/help/stress" },
      { id: "r2", slug: "trevoga", title: "Приступы страха и тревоги", format: "individual" as const, age_label: null, path: "/help/trevoga" },
    ],
  },
  {
    id: "g2",
    slug: "dlya-pary",
    title: "Для пары",
    format: "pair" as const,
    requests: [
      { id: "r3", slug: "detsko-roditelskie-otnosheniya", title: "Детско-родительские отношения", format: "pair" as const, age_label: "18+", path: "/help/para/detsko-roditelskie-otnosheniya" },
    ],
  },
];

describe("RequestsCarousel (SITE-01, DEC-11)", () => {
  it("shows the first group and switches groups by tabs", () => {
    render(<RequestsCarousel groups={GROUPS} />);
    expect(screen.getByRole("tab", { name: /Моё состояние/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("link", { name: /Стресс/ })).toHaveAttribute("href", "/help/stress");
    expect(screen.queryByText("Детско-родительские отношения")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /Для пары/ }));
    expect(screen.getByRole("tab", { name: /Для пары/ })).toHaveAttribute("aria-selected", "true");
    const link = screen.getByRole("link", { name: /Детско-родительские отношения/ });
    expect(link).toHaveAttribute("href", "/help/para/detsko-roditelskie-otnosheniya");
    expect(link).toHaveTextContent("18+");
    expect(screen.queryByText("Стресс")).not.toBeInTheDocument();
  });

  it("renders nothing without groups", () => {
    const { container } = render(<RequestsCarousel groups={[]} />);
    expect(container).toBeEmptyDOMElement();
  });
});
