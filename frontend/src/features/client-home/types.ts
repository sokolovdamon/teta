import type { DynamicsPoint } from "@/features/diary/types";
import type { RecoStatus, RecoType } from "@/features/recommendations/types";

export type PsychologistCard = {
  id: string;
  slug: string;
  name: string;
  headline: string | null;
  photo_url: string | null;
  is_bookable: boolean;
  profile_url: string;
};

/** Contract of BOOK `GET /booking/my-psychologists`, extended with the card fields of /client/home. */
export type MyPsychologist = {
  psychologist_id: string;
  slug: string;
  name: string;
  upcoming_sessions: number;
  last_session_at: string | null;
  headline?: string | null;
  photo_url?: string | null;
  profile_url?: string;
  is_bookable?: boolean;
};

export type NextSession = {
  id: string;
  starts_at: string;
  ends_at: string;
  duration_min: number;
  format: "individual" | "pair";
  status: string;
  is_paid: boolean;
  psychologist: PsychologistCard | null;
  room: { url: string; opens_at: string; closes_at: string };
};

export type ClientHome = {
  room_window: { open_before_min: number; close_after_min: number };
  next_session: NextSession | null;
  diary: {
    show_prompt: boolean;
    today: string;
    today_mood: number | null;
    recent: { from: string; to: string; points: DynamicsPoint[]; avg_mood: number | null };
  } | null;
  recommendations: {
    unread_count: number;
    active_count: number;
    items: { id: string; type: RecoType; title: string; status: RecoStatus; sent_at: string | null; due_date: string | null; psychologist_name: string | null }[];
  } | null;
  psychologists: (MyPsychologist & PsychologistCard)[];
  timezone: string;
  server_time: string;
};
