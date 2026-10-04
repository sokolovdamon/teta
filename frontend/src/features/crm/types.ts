export type RelationStatus = "active" | "no_upcoming" | "finished" | "changed";

export type ClientRow = {
  client_id: string;
  name: string;
  timezone: string;
  status: RelationStatus;
  first_session_at: string | null;
  last_session_at: string | null;
  next_session_at: string | null;
  held_count: number;
  upcoming_count: number;
  has_pair_sessions: boolean;
  diary_available: boolean;
  access_until: string | null;
  work_finished_at: string | null;
  changed_psychologist_at: string | null;
};

export type DiaryAccess = { available: boolean; restricted: boolean; until: string | null };

export type ClientCardData = ClientRow & {
  requests: { id: string; title: string; format: string }[];
  diary: DiaryAccess;
  can_finish: boolean;
  can_recommend: boolean;
};

export type SessionRow = {
  id: string;
  starts_at: string;
  ends_at: string;
  duration_min: number;
  format: "individual" | "pair";
  status: string;
  is_partner: boolean;
  actual_duration_sec: number | null;
};

export type Note = {
  id: string;
  body: string;
  session_id: string | null;
  session_starts_at: string | null;
  created_at: string;
  updated_at: string;
};
