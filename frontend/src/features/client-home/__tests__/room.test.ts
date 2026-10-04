import { describe, expect, it } from "vitest";
import { clockSkew, nextChangeIn, roomState, startsIn } from "../room";

const opens = "2026-10-20T15:50:00+00:00";
const closes = "2026-10-20T17:05:00+00:00";
const at = (iso: string) => Date.parse(iso);

describe("TetaMeet entry window", () => {
  it("is closed before P-ROOM-OPEN, open inside the window and closed after P-ROOM-CLOSE", () => {
    expect(roomState(at("2026-10-20T15:49:59Z"), opens, closes)).toBe("before");
    expect(roomState(at("2026-10-20T15:50:00Z"), opens, closes)).toBe("open");
    expect(roomState(at("2026-10-20T17:05:00Z"), opens, closes)).toBe("open");
    expect(roomState(at("2026-10-20T17:05:01Z"), opens, closes)).toBe("closed");
  });

  it("schedules the next re-render at the boundary", () => {
    expect(nextChangeIn(at("2026-10-20T15:49:00Z"), opens, closes)).toBe(60_000);
    expect(nextChangeIn(at("2026-10-20T17:04:00Z"), opens, closes)).toBe(60_001);
    expect(nextChangeIn(at("2026-10-20T18:00:00Z"), opens, closes)).toBeNull();
  });

  it("corrects the browser clock by the server time", () => {
    expect(clockSkew("2026-10-20T12:00:30Z", at("2026-10-20T12:00:00Z"))).toBe(30_000);
    expect(clockSkew("not a date", 0)).toBe(0);
    const browserNow = at("2026-10-20T15:49:30Z");
    const skew = clockSkew("2026-10-20T15:50:10Z", browserNow);
    expect(roomState(browserNow + skew, opens, closes)).toBe("open");
  });

  it("says when the session starts", () => {
    const start = "2026-10-20T16:00:00Z";
    expect(startsIn(at("2026-10-20T15:55:00Z"), start)).toBe("через 5 мин");
    expect(startsIn(at("2026-10-20T13:45:00Z"), start)).toBe("через 2 ч 15 мин");
    expect(startsIn(at("2026-10-20T14:00:00Z"), start)).toBe("через 2 ч");
    expect(startsIn(at("2026-10-18T10:00:00Z"), start)).toBe("через 2 дня");
    expect(startsIn(at("2026-10-20T16:01:00Z"), start)).toBe("сейчас");
  });
});
