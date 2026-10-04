/** Public catalog of psychologists (SITE-02, SITE-03). Money in kopecks, times in UTC ISO. No ratings (DEC-32). */
export type SessionFormat = "individual" | "pair";

export type DictItem = { slug: string; title: string };

export type RequestRef = DictItem & { format: SessionFormat; path: string };

export type PriceCategoryRef = { code: string; title: string };

export type PsychologistCard = {
  id: string;
  slug: string;
  name: string;
  first_name: string;
  last_name: string;
  gender: "female" | "male" | null;
  age: number | null;
  photo_url: string | null;
  headline: string | null;
  experience_years: number | null;
  approaches: DictItem[];
  specializations: DictItem[];
  requests: RequestRef[];
  works_individual: boolean;
  works_pair: boolean;
  price_individual: number | null;
  price_pair: number | null;
  price_category: PriceCategoryRef | null;
  nearest_slot: string | null;
  nearest_slot_format: SessionFormat;
  has_video: boolean;
  timezone: string;
  is_active: true;
};

export type PsychologistProfile = Omit<PsychologistCard, "approaches"> & {
  about: string | null;
  education: { institution: string | null; specialty: string | null; year: number | null }[];
  approaches: (DictItem & { description: string | null; explanation: string | null })[];
  documents: { kind: string; title: string; institution: string | null; specialty: string | null; year: number | null }[];
  verified: boolean;
  verified_at: string | null;
  video_url: string | null;
  session_durations: Record<SessionFormat, number>;
};

export type InactiveProfile = {
  id: string;
  slug: string;
  name: string;
  first_name: string;
  last_name: string;
  photo_url: string | null;
  headline: string | null;
  is_active: false;
};

export type ProfileResponse = PsychologistProfile | InactiveProfile;

export type SlotsResponse = {
  is_active: boolean;
  format: SessionFormat;
  duration_min: number;
  timezone: string;
  from: string;
  to: string;
  slots: string[];
};

export type CatalogMeta = { current_page: number; last_page: number; per_page: number; total: number };

export type CatalogResponse = { data: PsychologistCard[]; meta: CatalogMeta };

/** GET /dictionaries */
export type Dictionaries = {
  request_groups: {
    id: string;
    slug: string;
    title: string;
    format: SessionFormat;
    requests: { id: string; slug: string; title: string; format: SessionFormat; age_label: string | null; path: string }[];
  }[];
  approaches: { id: string; slug: string; title: string; explanation: string | null }[];
  specializations: { id: string; slug: string; title: string }[];
  service_types: { id: string; code: string; title: string; duration_min: number }[];
  price_categories: { id: string; code: string; title: string; min_price: number; max_price: number | null }[];
};

/** GET /dictionaries/requests/{slug} */
export type RequestLanding = {
  id: string;
  slug: string;
  title: string;
  format: SessionFormat;
  age_label: string | null;
  group: { slug: string; title: string };
  seo_title: string | null;
  seo_description: string | null;
  landing_lead: string | null;
  landing_body: string | null;
  path: string;
};
