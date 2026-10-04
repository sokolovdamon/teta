import { describe, expect, it } from "vitest";
import { describeRestrictions, emptyForm, fromCode, isoToLocal, kopecksToRubles, localToIso, rublesToKopecks, toPayload } from "../form";
import type { PromoCodeRow } from "../types";

describe("promo form", () => {
  it("converts roubles and kopecks", () => {
    expect(rublesToKopecks("1 500,50")).toBe(150050);
    expect(rublesToKopecks("500")).toBe(50000);
    expect(rublesToKopecks("")).toBeNull();
    expect(rublesToKopecks("abc")).toBeNull();
    expect(kopecksToRubles(150050)).toBe("1500,50");
    expect(kopecksToRubles(50000)).toBe("500");
    expect(kopecksToRubles(null)).toBe("");
  });

  it("round-trips datetime-local values", () => {
    const iso = localToIso("2026-10-10T09:30");
    expect(iso).not.toBeNull();
    expect(isoToLocal(iso)).toBe("2026-10-10T09:30");
    expect(localToIso("")).toBeNull();
  });

  it("builds the API payload with kopecks, limits and restrictions", () => {
    const payload = toPayload({
      ...emptyForm(),
      code: " autumn ",
      type: "fixed",
      value: "500",
      kind: "individual",
      owner_user_id: "u1",
      total_limit: "100",
      per_user_limit: "",
      min_amount: "3 000",
      service_types: ["pair"],
      new_clients: true,
      min_held_sessions: "2",
      publish: true,
    });
    expect(payload).toMatchObject({
      code: "autumn",
      type: "fixed",
      value: 50000,
      kind: "individual",
      owner_user_id: "u1",
      total_limit: 100,
      per_user_limit: null,
      min_amount: 300000,
      restrictions: { service_types: ["pair"], segment: { new_clients: true, min_held_sessions: 2 } },
      publish: true,
      valid_from: null,
    });
    expect(toPayload({ ...emptyForm(), type: "percent", value: "20" })).toMatchObject({ value: 20, restrictions: null, owner_user_id: null, code: null });
  });

  it("fills the edit form from a code", () => {
    const row = {
      id: "p1",
      code: "AUTUMN",
      title: "Осень",
      description: null,
      type: "fixed",
      value: 150050,
      kind: "mass",
      owner: null,
      valid_from: null,
      valid_until: null,
      total_limit: null,
      per_user_limit: 1,
      min_amount: 400000,
      restrictions: { psychologist_ids: ["psy1"], segment: { registered_after: "2026-01-01" } },
    } as unknown as PromoCodeRow;
    const form = fromCode(row);
    expect(form.value).toBe("1500,50");
    expect(form.min_amount).toBe("4000");
    expect(form.per_user_limit).toBe("1");
    expect(form.total_limit).toBe("");
    expect(form.psychologist_ids).toEqual(["psy1"]);
    expect(form.registered_after).toBe("2026-01-01");
  });

  it("describes restrictions in Russian", () => {
    expect(
      describeRestrictions(
        { service_types: ["individual"], psychologist_ids: ["a"], price_category_ids: ["c"], segment: { new_clients: true, registered_after: "2026-03-01", min_held_sessions: 3 } },
        { psychologists: { a: "Анна Соколова" }, categories: { c: "До 3 500 ₽" } },
      ),
    ).toEqual([
      "индивидуальные сессии",
      "психологи: Анна Соколова",
      "категории: До 3 500 ₽",
      "только новые клиенты",
      "зарегистрированные с 01.03.2026",
      "проведено от 3 сессий",
    ]);
    expect(describeRestrictions(null)).toEqual([]);
  });
});
