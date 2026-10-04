import { describe, expect, it } from "vitest";
import {
  addDaysKey,
  dayKey,
  dayLabel,
  groupSlotsByDay,
  mondayOf,
  roomState,
  sessionDateTime,
  timeOf,
  tzOffsetMinutes,
  untilText,
  weekdayOfKey,
  zoneLabel,
  zonedToUtc,
} from "../time";

const nbsp = (s: string) => s.replace(/ /g, " ");

describe("session time in the viewer's timezone", () => {
  const start = "2026-10-12T11:00:00Z"; // 14:00 MSK, 16:00 in Yekaterinburg

  it("shows Moscow time with the МСК label", () => {
    expect(timeOf(start, "Europe/Moscow")).toBe("14:00");
    expect(nbsp(sessionDateTime(start, "Europe/Moscow"))).toBe("12 октября, понедельник, 14:00 (МСК)");
  });

  it("shows other zones with the UTC offset", () => {
    expect(timeOf(start, "Asia/Yekaterinburg")).toBe("16:00");
    expect(zoneLabel("Asia/Yekaterinburg", new Date(start))).toBe("UTC+05:00");
    expect(zoneLabel("America/New_York", new Date(start))).toBe("UTC−04:00");
    expect(nbsp(sessionDateTime(start, "Asia/Vladivostok"))).toBe("12 октября, понедельник, 21:00 (UTC+10:00)");
  });

  it("puts a late-evening UTC slot on the next calendar day in the east", () => {
    const late = "2026-10-12T20:30:00Z";
    expect(dayKey(late, "Europe/Moscow")).toBe("2026-10-12");
    expect(dayKey(late, "Asia/Vladivostok")).toBe("2026-10-13");
    expect(dayLabel(late, "Asia/Vladivostok")).toBe("13 октября, вторник");
  });

  it("computes offsets and converts wall-clock time back to UTC", () => {
    expect(tzOffsetMinutes("Europe/Moscow", new Date(start))).toBe(180);
    expect(zonedToUtc("2026-10-12", "00:00", "Europe/Moscow").toISOString()).toBe("2026-10-11T21:00:00.000Z");
    expect(zonedToUtc("2026-10-12", "10:00", "Asia/Yekaterinburg").toISOString()).toBe("2026-10-12T05:00:00.000Z");
    // Daylight saving switch in New York on 1 November 2026.
    expect(zonedToUtc("2026-11-01", "12:00", "America/New_York").toISOString()).toBe("2026-11-01T17:00:00.000Z");
  });

  it("walks calendar days and weeks", () => {
    expect(addDaysKey("2026-10-31", 1)).toBe("2026-11-01");
    expect(weekdayOfKey("2026-10-12")).toBe(1);
    expect(weekdayOfKey("2026-10-18")).toBe(7);
    expect(mondayOf("2026-10-15")).toBe("2026-10-12");
    expect(mondayOf("2026-10-18")).toBe("2026-10-12");
  });

  it("groups free slots by day of the viewer", () => {
    const days = groupSlotsByDay(["2026-10-13T08:00:00Z", "2026-10-12T07:00:00Z", "2026-10-12T21:30:00Z"], "Europe/Moscow");
    expect(days.map((d) => d.key)).toEqual(["2026-10-12", "2026-10-13"]);
    expect(days[0].slots).toEqual(["2026-10-12T07:00:00Z"]);
    expect(days[1].slots).toEqual(["2026-10-12T21:30:00Z", "2026-10-13T08:00:00Z"]);
  });
});

describe("TetaMeet entry window", () => {
  const room = { opens_at: "2026-10-12T10:50:00Z", closes_at: "2026-10-12T12:05:00Z" };
  it("opens P-ROOM-OPEN before the start and closes P-ROOM-CLOSE after the end", () => {
    expect(roomState(room, Date.parse("2026-10-12T10:49:59Z"))).toBe("not_yet");
    expect(roomState(room, Date.parse("2026-10-12T10:50:00Z"))).toBe("open");
    expect(roomState(room, Date.parse("2026-10-12T12:05:00Z"))).toBe("open");
    expect(roomState(room, Date.parse("2026-10-12T12:05:01Z"))).toBe("closed");
  });

  it("says how long until the room opens", () => {
    const now = Date.parse("2026-10-12T08:45:00Z");
    expect(untilText("2026-10-12T10:50:00Z", now)).toBe("через 2 ч 5 мин");
    expect(untilText("2026-10-12T09:00:00Z", now)).toBe("через 15 мин");
    expect(untilText("2026-10-14T08:45:00Z", now)).toBe("через 2 дня");
    expect(untilText("2026-10-12T08:00:00Z", now)).toBe("сейчас");
  });
});
