import { describe, expect, it } from "vitest";
import { isoDate, payoutDayLabel, presetRange, signed } from "../labels";

describe("payout labels", () => {
  it("builds period presets from a given day", () => {
    const today = new Date(2026, 9, 8); // 8 October 2026
    expect(presetRange("month", today)).toEqual({ from: "2026-10-01", to: "2026-10-08" });
    expect(presetRange("prev_month", today)).toEqual({ from: "2026-09-01", to: "2026-09-30" });
    expect(presetRange("quarter", today)).toEqual({ from: "2026-08-01", to: "2026-10-08" });
    expect(presetRange("year", today)).toEqual({ from: "2026-01-01", to: "2026-10-08" });
    expect(presetRange("prev_month", new Date(2026, 0, 15))).toEqual({ from: "2025-12-01", to: "2025-12-31" });
  });

  it("formats local dates without timezone shifts", () => {
    expect(isoDate(new Date(2026, 1, 3))).toBe("2026-02-03");
  });

  it("names the payout day in Russian", () => {
    expect(payoutDayLabel(1)).toBe("каждый понедельник");
    expect(payoutDayLabel(3)).toBe("каждую среду");
    expect(payoutDayLabel(9)).toBe("раз в неделю");
  });

  it("signs ledger amounts", () => {
    const fmt = (k: number) => `${k / 100} ₽`;
    expect(signed(280000, fmt)).toBe("+2800 ₽");
    expect(signed(-140000, fmt)).toBe("−1400 ₽");
    expect(signed(0, fmt)).toBe("0 ₽");
  });
});
