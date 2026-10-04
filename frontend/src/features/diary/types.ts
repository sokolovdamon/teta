export type EmotionTag = { id: string; code: string; title: string };

export type DiaryEntry = {
  id: string;
  mood: number;
  tags: EmotionTag[];
  note: string | null;
  recorded_at: string;
  local_date: string;
};

export type DiaryPrompt = { show: boolean; today: string; tags: EmotionTag[] };

export type DynamicsGroup = "day" | "week";

export type DynamicsPoint = { date: string; avg_mood: number; min_mood: number; max_mood: number; entries: number };

export type Dynamics = {
  from: string;
  to: string;
  group: DynamicsGroup;
  points: DynamicsPoint[];
  tags: (EmotionTag & { count: number })[];
  summary: { entries: number; avg_mood: number | null; days_with_entries: number };
  /** Present in the psychologist's view (PRO-06): DM-08 access window. */
  access?: { restricted: boolean; until: string | null };
};

export type PeriodPreset = "week" | "month" | "quarter" | "year";
