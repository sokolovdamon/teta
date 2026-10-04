import { describe, expect, it } from "vitest";
import { diaryAccessText, finishBlockReason, parseTab } from "../access";

describe("diary access window texts (DEC-41, DM-08)", () => {
  it("explains that there is no access without a booking or a held session", () => {
    expect(diaryAccessText({ available: false, restricted: false, until: null }, "no_upcoming", "Europe/Moscow")).toMatch(/записан или у которого проходил сессии/);
  });

  it("says nothing when the dynamics are fully visible", () => {
    expect(diaryAccessText({ available: true, restricted: false, until: null }, "active", "Europe/Moscow")).toBeNull();
  });

  it("names the date of the change of psychologist", () => {
    const text = diaryAccessText({ available: true, restricted: true, until: "2026-10-17T21:30:00+00:00" }, "changed", "Europe/Moscow");
    expect(text).toBe("Клиент сменил психолога 18 октября 2026 г.: вы видите динамику только до этой даты.");
  });

  it("explains the finish mark and how the restriction is lifted", () => {
    const text = diaryAccessText({ available: true, restricted: true, until: "2026-10-17T12:00:00+00:00" }, "finished", "Europe/Moscow");
    expect(text).toMatch(/^Работа отмечена завершённой 17 октября 2026 г\./);
    expect(text).toMatch(/снова запишется/);
  });
});

describe("finish work availability", () => {
  it("is available when the backend allows it", () => {
    expect(finishBlockReason({ status: "no_upcoming", upcoming_count: 0, can_finish: true })).toBeNull();
  });

  it("explains why it is blocked", () => {
    expect(finishBlockReason({ status: "active", upcoming_count: 2, can_finish: false })).toMatch(/назначенные сессии/);
    expect(finishBlockReason({ status: "finished", upcoming_count: 0, can_finish: false })).toMatch(/уже отмечена/);
    expect(finishBlockReason({ status: "changed", upcoming_count: 0, can_finish: false })).toMatch(/сменил психолога/);
  });
});

describe("card tabs", () => {
  it("falls back to sessions for unknown tabs", () => {
    expect(parseTab("notes")).toBe("notes");
    expect(parseTab(["dynamics", "x"])).toBe("dynamics");
    expect(parseTab("admin")).toBe("sessions");
    expect(parseTab(undefined)).toBe("sessions");
  });
});
