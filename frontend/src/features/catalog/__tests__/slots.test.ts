import { describe, expect, it } from "vitest";
import { addDays, dayKey, groupSlotsByDay, isoWeekday, nearestLabel, slotTime, weekView } from "../slots";

const SLOTS = ["2026-10-07T07:00:00Z", "2026-10-06T20:00:00Z", "2026-10-06T07:00:00Z", "2026-10-06T08:00:00Z"];

describe("slot grouping in the visitor's timezone", () => {
  it("groups by the calendar day of the timezone and sorts", () => {
    const msk = groupSlotsByDay(SLOTS, "Europe/Moscow");
    expect(msk.map((d) => d.key)).toEqual(["2026-10-06", "2026-10-07"]);
    expect(msk[0].slots.map((s) => s.time)).toEqual(["10:00", "11:00", "23:00"]);
    expect(msk[1].slots.map((s) => s.time)).toEqual(["10:00"]);

    // 20:00 UTC on the 6th is already the morning of the 7th in Vladivostok (UTC+10).
    const vvo = groupSlotsByDay(SLOTS, "Asia/Vladivostok");
    expect(vvo.map((d) => d.key)).toEqual(["2026-10-06", "2026-10-07"]);
    expect(vvo[0].slots.map((s) => s.time)).toEqual(["17:00", "18:00"]);
    expect(vvo[1].slots.map((s) => s.time)).toEqual(["06:00", "17:00"]);

    // In Los Angeles (UTC-7) the 07:00 UTC slots are at midnight of the same calendar day.
    const la = groupSlotsByDay(SLOTS, "America/Los_Angeles");
    expect(la.map((d) => d.key)).toEqual(["2026-10-06", "2026-10-07"]);
    expect(la[0].slots.map((s) => s.time)).toEqual(["00:00", "01:00", "13:00"]);
  });

  it("keeps the original UTC instant for booking links", () => {
    const [day] = groupSlotsByDay(["2026-10-06T07:00:00Z"], "Asia/Yekaterinburg");
    expect(day.slots[0]).toEqual({ iso: "2026-10-06T07:00:00Z", time: "12:00" });
  });

  it("builds a 7-day week with empty days and Russian labels", () => {
    const week = weekView(groupSlotsByDay(SLOTS, "Europe/Moscow"), "2026-10-05");
    expect(week.map((d) => d.key)).toEqual(["2026-10-05", "2026-10-06", "2026-10-07", "2026-10-08", "2026-10-09", "2026-10-10", "2026-10-11"]);
    expect(week[0].slots).toEqual([]);
    expect(week[1].slots).toHaveLength(3);
    expect(week[0].weekday).toBe("пн");
    expect(week[0].date).toMatch(/^5 окт/);
  });

  it("does day arithmetic across months and years", () => {
    expect(addDays("2026-12-30", 3)).toBe("2027-01-02");
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28");
    expect(isoWeekday("2026-10-05")).toBe(1);
    expect(isoWeekday("2026-10-11")).toBe(7);
    expect(dayKey("2026-10-06T22:30:00Z", "Europe/Moscow")).toBe("2026-10-07");
    expect(slotTime("2026-10-06T22:30:00Z", "Europe/Moscow")).toBe("01:30");
  });

  it("labels the nearest slot relative to now", () => {
    const now = new Date("2026-10-05T05:00:00Z");
    expect(nearestLabel("2026-10-05T12:00:00Z", "Europe/Moscow", now)).toBe("сегодня в 15:00");
    expect(nearestLabel("2026-10-06T07:00:00Z", "Europe/Moscow", now)).toBe("завтра в 10:00");
    expect(nearestLabel("2026-10-08T09:00:00Z", "Europe/Moscow", now)).toMatch(/^чт, 8 окт.* в 12:00$/);
  });
});
