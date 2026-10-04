import { describe, expect, it } from "vitest";
import { areaPath, bucketLabel, buckets, buildSeries, linePath, nearestIndex, segments, summaryText, tickIndexes, weekStart, xFor, yFor, type Frame } from "../chart";
import { formatMood, moodFor } from "../moods";
import type { Dynamics } from "../types";

const frame: Frame = { width: 340, height: 140, left: 20, right: 20, top: 10, bottom: 30 };

function dyn(over: Partial<Dynamics> = {}): Dynamics {
  return {
    from: "2026-10-01",
    to: "2026-10-05",
    group: "day",
    points: [
      { date: "2026-10-01", avg_mood: 2, min_mood: 2, max_mood: 2, entries: 1 },
      { date: "2026-10-02", avg_mood: 3.5, min_mood: 3, max_mood: 4, entries: 2 },
      { date: "2026-10-05", avg_mood: 5, min_mood: 5, max_mood: 5, entries: 1 },
    ],
    tags: [],
    summary: { entries: 4, avg_mood: 3.5, days_with_entries: 3 },
    ...over,
  };
}

describe("diary chart data shaping", () => {
  it("lists every day of the period and week starts on Mondays", () => {
    expect(buckets("2026-10-01", "2026-10-05", "day")).toEqual(["2026-10-01", "2026-10-02", "2026-10-03", "2026-10-04", "2026-10-05"]);
    expect(weekStart("2026-10-01")).toBe("2026-09-28");
    expect(weekStart("2026-10-05")).toBe("2026-10-05");
    expect(buckets("2026-10-01", "2026-10-20", "week")).toEqual(["2026-09-28", "2026-10-05", "2026-10-12", "2026-10-19"]);
  });

  it("does not shift days across the DST or the browser time zone", () => {
    expect(buckets("2026-03-28", "2026-03-30", "day")).toEqual(["2026-03-28", "2026-03-29", "2026-03-30"]);
    expect(buckets("2026-10-24", "2026-10-26", "day")).toHaveLength(3);
  });

  it("fills days without entries with nulls instead of inventing values", () => {
    const series = buildSeries(dyn());
    expect(series.map((p) => p.value)).toEqual([2, 3.5, null, null, 5]);
    expect(series[1]).toMatchObject({ min: 3, max: 4, entries: 2 });
    expect(series[2]).toMatchObject({ value: null, entries: 0 });
  });

  it("breaks the line at gaps", () => {
    const runs = segments(buildSeries(dyn()));
    expect(runs).toHaveLength(2);
    expect(runs[0].map((p) => p.index)).toEqual([0, 1]);
    expect(runs[1]).toEqual([{ index: 4, value: 5 }]);
    expect(areaPath(runs[1], 5, frame)).toBe("");
    expect(linePath(runs[0], 5, frame)).toMatch(/^M20,\d+(\.\d)? L95,\d+(\.\d)?$/);
  });

  it("maps mood 5 to the top and mood 1 to the bottom of the plot", () => {
    expect(yFor(5, frame)).toBe(frame.top);
    expect(yFor(1, frame)).toBe(frame.height - frame.bottom);
    expect(yFor(9, frame)).toBe(frame.top);
    expect(xFor(0, 5, frame)).toBe(20);
    expect(xFor(4, 5, frame)).toBe(320);
    expect(xFor(0, 1, frame)).toBe(170);
  });

  it("snaps the crosshair to the nearest bucket", () => {
    expect(nearestIndex(0, 5, frame)).toBe(0);
    expect(nearestIndex(100, 5, frame)).toBe(1);
    expect(nearestIndex(1000, 5, frame)).toBe(4);
    expect(nearestIndex(50, 1, frame)).toBe(0);
  });

  it("chooses evenly spaced ticks including both ends", () => {
    expect(tickIndexes(3, 6)).toEqual([0, 1, 2]);
    const ticks = tickIndexes(30, 4);
    expect(ticks[0]).toBe(0);
    expect(ticks[ticks.length - 1]).toBe(29);
    expect(ticks).toHaveLength(4);
    expect(tickIndexes(0, 4)).toEqual([]);
  });

  it("describes the chart for screen readers", () => {
    expect(summaryText(dyn())).toBe("Настроение с 1 октября по 5 октября: 4 отметки, в среднем 3,5 из 5 («Хорошо»).");
    expect(summaryText(dyn({ summary: { entries: 0, avg_mood: null, days_with_entries: 0 }, points: [] }))).toBe(
      "Настроение с 1 октября по 5 октября: отметок нет.",
    );
    expect(bucketLabel("2026-10-05", "week", true)).toBe("5 октября – 11 октября");
  });

  it("rounds averages to the nearest emoji", () => {
    expect(moodFor(3.4)?.label).toBe("Нормально");
    expect(moodFor(4.6)?.emoji).toBe("😄");
    expect(moodFor(null)).toBeNull();
    expect(formatMood(4)).toBe("4");
    expect(formatMood(3.456)).toBe("3,5");
  });
});
