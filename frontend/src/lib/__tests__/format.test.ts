import { describe, expect, it } from "vitest";
import { plural, rub } from "../format";
import { buildQuery } from "../api";

describe("format", () => {
  it("formats kopecks as roubles", () => {
    expect(rub(350000).replace(/\s/g, " ")).toBe("3 500 ₽");
    expect(rub(null)).toBe("—");
  });

  it("picks Russian plural forms", () => {
    const forms: [string, string, string] = ["сессия", "сессии", "сессий"];
    expect(plural(1, forms)).toBe("сессия");
    expect(plural(3, forms)).toBe("сессии");
    expect(plural(11, forms)).toBe("сессий");
    expect(plural(21, forms)).toBe("сессия");
  });

  it("builds query strings with arrays and skips empty values", () => {
    expect(buildQuery({ a: 1, b: "", c: null, d: [1, 2] })).toBe("?a=1&d%5B%5D=1&d%5B%5D=2");
  });
});
