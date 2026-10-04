import { describe, expect, it } from "vitest";
import { categoryFor, kopecksToRubles, rublesToKopecks, toMinutes, tzOffsetMinutes, validateIntervals, zonedToIso } from "../schedule";

const CATEGORIES = [
  { id: "1", code: "economy", title: "До 3 500 ₽", min_price: 0, max_price: 349999 },
  { id: "2", code: "standard", title: "3 500–5 500 ₽", min_price: 350000, max_price: 549999 },
  { id: "3", code: "premium", title: "От 5 500 ₽", min_price: 550000, max_price: null },
];

describe("schedule editor helpers", () => {
  it("parses times including midnight as the end of a day", () => {
    expect(toMinutes("09:30")).toBe(570);
    expect(toMinutes("24:00")).toBe(1440);
    expect(toMinutes("24:30")).toBeNull();
    expect(toMinutes("9:5")).toBeNull();
  });

  it("validates intervals like the server does", () => {
    const errors = validateIntervals(
      [
        { weekday: 1, starts_at: "10:00", ends_at: "12:00" },
        { weekday: 1, starts_at: "11:30", ends_at: "14:00" },
        { weekday: 2, starts_at: "10:00", ends_at: "10:30" },
        { weekday: 3, starts_at: "12:00", ends_at: "10:00" },
        { weekday: 4, starts_at: "10:00", ends_at: "12:00" },
        { weekday: 4, starts_at: "12:00", ends_at: "24:00" },
      ],
      50,
    );
    expect(errors).toEqual({
      1: "Пересекается с другим интервалом",
      2: "Интервал короче сессии (50 мин)",
      3: "Конец должен быть позже начала",
    });
  });

  it("converts local wall time of a timezone to UTC", () => {
    expect(tzOffsetMinutes("Europe/Moscow", new Date("2026-10-05T00:00:00Z"))).toBe(180);
    expect(zonedToIso("2026-10-10", "00:00", "Europe/Moscow")).toBe("2026-10-09T21:00:00.000Z");
    expect(zonedToIso("2026-10-10", "00:00", "Asia/Vladivostok")).toBe("2026-10-09T14:00:00.000Z");
    // Berlin changes to winter time on 25 October 2026.
    expect(zonedToIso("2026-10-24", "12:00", "Europe/Berlin")).toBe("2026-10-24T10:00:00.000Z");
    expect(zonedToIso("2026-10-26", "12:00", "Europe/Berlin")).toBe("2026-10-26T11:00:00.000Z");
  });

  it("derives the price category from the individual price (DEC-55)", () => {
    expect(categoryFor(349999, CATEGORIES)?.code).toBe("economy");
    expect(categoryFor(350000, CATEGORIES)?.code).toBe("standard");
    expect(categoryFor(550000, CATEGORIES)?.code).toBe("premium");
    expect(categoryFor(null, CATEGORIES)).toBeNull();
  });

  it("converts roubles and kopecks", () => {
    expect(rublesToKopecks("3 500")).toBe(350000);
    expect(rublesToKopecks("3500,5")).toBe(350050);
    expect(rublesToKopecks("")).toBeNull();
    expect(rublesToKopecks("abc")).toBeNull();
    expect(kopecksToRubles(350000)).toBe("3500");
    expect(kopecksToRubles(null)).toBe("");
  });
});
