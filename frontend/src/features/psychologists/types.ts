import type { PriceCategoryRef } from "@/features/catalog/types";

export type QualificationStatus = "draft" | "in_review" | "approved" | "rejected";
export type ActivityStatus = "grace" | "active_not_met" | "active_met" | "inactive" | null;
export type WorkStatus = "active" | "paused" | "blocked";
export type VideoStatus = "none" | "pending" | "approved" | "rejected";
export type DocumentKind = "diploma" | "retraining" | "certificate" | "other";
export type DocumentStatus = "pending" | "approved" | "rejected";

export type EducationItem = { institution: string; specialty: string | null; year: number | null };

/** Moderated public fields (BR-PSY-05). */
export type ProfileValues = {
  first_name: string | null;
  last_name: string | null;
  gender: "female" | "male" | null;
  birth_year: number | null;
  headline: string | null;
  about: string | null;
  experience_years: number | null;
  education: EducationItem[];
  approaches: { id: string; explanation: string | null }[];
  specializations: string[];
  requests: string[];
  photo_file_id: string | null;
};

export type MissingItem = { code: string; label: string; hint: string };

export type Statuses = {
  id: string;
  slug: string;
  name: string;
  public_path: string;
  qualification_status: QualificationStatus;
  activity_status: ActivityStatus;
  work_status: WorkStatus;
  work_status_reason: string | null;
  is_published: boolean;
  is_bookable: boolean;
};

export type Prices = { price_individual: number | null; price_pair: number | null; price_category: PriceCategoryRef | null };

export type VideoInfo = {
  status: VideoStatus;
  url: string | null;
  approved_url: string | null;
  comment: string | null;
  duration_sec: number | null;
  submitted_at: string | null;
  reviewed_at: string | null;
};

/** GET /pro/profile */
export type OwnProfile = Statuses & {
  requires_moderation: boolean;
  values: ProfileValues;
  photo_url: string | null;
  published_photo_url: string | null;
  pending: { fields: string[]; labels: string[]; submitted_at: string | null } | null;
  last_review: { comment: string | null; reviewed_at: string } | null;
  formats: { works_individual: boolean; works_pair: boolean };
  prices: Prices;
  price_history: { price_individual: number | null; price_pair: number | null; created_at: string }[];
  video: VideoInfo;
  video_limits: { seconds: number; megabytes: number };
  missing: MissingItem[];
  timezone: string;
};

export type QualificationDocument = {
  id: string;
  kind: DocumentKind;
  title: string;
  institution: string | null;
  specialty: string | null;
  year: number | null;
  status: DocumentStatus;
  comment: string | null;
  reviewed_at: string | null;
  created_at: string | null;
  file: { name: string; mime_type: string; size: number; url?: string } | null;
  can_delete?: boolean;
};

export type HistoryItem = { from: string | null; to: string; event: string | null; comment: string | null; at: string | null; actor?: string | null };

/** GET /pro/qualification */
export type QualificationView = {
  status: QualificationStatus;
  comment: string | null;
  submitted_at: string | null;
  qualified_at: string | null;
  documents: QualificationDocument[];
  history: HistoryItem[];
  missing: MissingItem[];
  can_submit: boolean;
  can_upload: boolean;
  document_kinds: DocumentKind[];
  education_kinds: DocumentKind[];
};

/** GET /pro/schedule */
export type ScheduleInterval = { id?: string; weekday: number; starts_at: string; ends_at: string };
export type ScheduleException = { id: string; kind: "vacation" | "blocked"; starts_at: string; ends_at: string; comment: string | null };
export type ScheduleOverview = {
  timezone: string;
  intervals: ScheduleInterval[];
  exceptions: ScheduleException[];
  settings: { min_lead_minutes: number | null; horizon_days: number | null; buffer_minutes: number | null };
  platform: { min_lead_minutes: number; horizon_days: number; buffer_minutes: number; duration_individual: number; duration_pair: number };
  effective: { min_lead_minutes: number; horizon_days: number; buffer_minutes: number };
  formats: { works_individual: boolean; works_pair: boolean };
};

/** GET /admin/psychologists */
export type AdminRow = Statuses & {
  email: string | null;
  photo_url: string | null;
  price_individual: number | null;
  price_pair: number | null;
  price_category: PriceCategoryRef | null;
  qualification_submitted_at: string | null;
  qualified_at: string | null;
  has_pending_changes: boolean;
  video_status: VideoStatus;
  documents_pending: number;
  created_at: string | null;
};

export type DiffValue = string | number | null | string[] | { title: string; explanation: string | null }[] | EducationItem[];

/** GET /admin/psychologists/{id} */
export type AdminCard = AdminRow & {
  user: { id: string; email: string; status: string; email_verified: boolean; created_at: string | null } | null;
  profile: Omit<ProfileValues, "approaches" | "specializations" | "requests"> & {
    approaches: { id: string; title: string; explanation: string | null }[];
    specializations: string[];
    requests: string[];
  };
  formats: { works_individual: boolean; works_pair: boolean };
  prices: Prices;
  price_history: OwnProfile["price_history"];
  qualification: { status: QualificationStatus; comment: string | null; submitted_at: string | null; qualified_at: string | null; history: HistoryItem[] };
  documents: QualificationDocument[];
  pending: { submitted_at: string | null; diff: { field: string; label: string; before: DiffValue; after: DiffValue }[] } | null;
  last_review: { comment: string | null; reviewed_at: string } | null;
  video: VideoInfo;
  work_status_history: { from: string | null; to: string; reason: string | null; actor: string | null; at: string | null }[];
  activity: { month: string; applies: boolean; met: boolean; activity_status: ActivityStatus; deadline: string };
  schedule: { intervals: number; nearest_slot: string | null };
  missing: MissingItem[];
  views_count: number;
};
