import { describe, expect, it } from "vitest";
import { buildQuery } from "@/lib/api";
import { activeFilterCount, EMPTY_FILTERS, filtersToApiQuery, filtersToSearch, parseFilters, toggleValue, withFilters } from "../query";

describe("catalog query state", () => {
  it("parses arrays from Next searchParams and URLSearchParams alike", () => {
    const fromNext = parseFilters({ requests: ["trevoga", "stress"], approaches: "kpt", gender: "female", page: "2" });
    const fromUrl = parseFilters(new URLSearchParams("requests=trevoga&requests=stress&approaches=kpt&gender=female&page=2"));
    expect(fromNext).toEqual(fromUrl);
    expect(fromNext.requests).toEqual(["trevoga", "stress"]);
    expect(fromNext.page).toBe(2);
  });

  it("drops invalid values and normalises ranges", () => {
    const f = parseFilters({
      requests: ["ok-slug", "<script>", "ok-slug"],
      gender: "other",
      format: "group",
      sort: "rating",
      age_min: "60",
      age_max: "30",
      within: "999",
      page: "-1",
    });
    expect(f.requests).toEqual(["ok-slug"]);
    expect(f.gender).toBe("");
    expect(f.format).toBe("");
    expect(f.sort).toBe("nearest");
    expect([f.ageMin, f.ageMax]).toEqual([30, 60]);
    expect(f.within).toBeNull();
    expect(f.page).toBe(1);
  });

  it("sorting by relevance needs a search text", () => {
    expect(parseFilters({ sort: "relevance" }).sort).toBe("nearest");
    expect(parseFilters({ sort: "relevance", q: "тревога" }).sort).toBe("relevance");
  });

  it("round-trips through the site URL and omits defaults", () => {
    expect(filtersToSearch(EMPTY_FILTERS)).toBe("");
    const f = withFilters(EMPTY_FILTERS, { requests: ["trevoga"], price: ["economy", "standard"], format: "pair", within: 7, q: "КПТ", sort: "price_asc" });
    const search = filtersToSearch(f);
    expect(search).toBe("?requests=trevoga&price=economy&price=standard&format=pair&within=7&q=%D0%9A%D0%9F%D0%A2&sort=price_asc");
    expect(parseFilters(new URLSearchParams(search.slice(1)))).toEqual(f);
  });

  it("maps to the API query with array keys", () => {
    const f = withFilters(EMPTY_FILTERS, { requests: ["trevoga"], price: ["premium"], ageMin: 30, within: 3 });
    expect(buildQuery(filtersToApiQuery(f, 12))).toBe(
      "?requests%5B%5D=trevoga&age_min=30&price_category%5B%5D=premium&available_within_days=3&sort=nearest&page=1&per_page=12",
    );
  });

  it("counts narrowing filters, toggles values and resets the page on change", () => {
    const f = { ...withFilters(EMPTY_FILTERS, { requests: ["a", "b"], gender: "male", ageMax: 40, q: "x" }), page: 3 };
    expect(activeFilterCount(f)).toBe(4);
    expect(toggleValue(["a", "b"], "a")).toEqual(["b"]);
    expect(toggleValue(["a"], "c")).toEqual(["a", "c"]);
    expect(withFilters(f, { gender: "" }).page).toBe(1);
  });
});
