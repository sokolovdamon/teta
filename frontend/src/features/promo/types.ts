/** PROMO API types (ADM-09, CL-13). Money is in kopecks. */

export type PromoType = "percent" | "fixed" | "first_session";
export type PromoKind = "mass" | "individual";
export type PromoSource = "admin" | "batch" | "referral" | "compensation";
export type PromoStatus = "draft" | "scheduled" | "active" | "exhausted" | "expired" | "deactivated";
export type ServiceType = "individual" | "pair";

export type Restrictions = {
  service_types?: ServiceType[];
  psychologist_ids?: string[];
  price_category_ids?: string[];
  segment?: { new_clients?: boolean; registered_after?: string; min_held_sessions?: number };
};

export type PromoStats = {
  reserved_total: number;
  pending: number;
  applied: number;
  restored: number;
  discount_sum: number;
  conversion: number | null;
};

export type PromoCodeRow = {
  id: string;
  code: string;
  title: string | null;
  description: string | null;
  type: PromoType;
  type_label: string;
  value: number;
  discount_label: string;
  kind: PromoKind;
  kind_label: string;
  source: PromoSource;
  source_label: string;
  batch: { id: string; title: string } | null;
  owner: { id: string; name: string; email: string } | null;
  valid_from: string | null;
  valid_until: string | null;
  total_limit: number | null;
  per_user_limit: number | null;
  min_amount: number | null;
  restrictions: Restrictions;
  status: PromoStatus;
  status_label: string;
  uses_count: number;
  published_at: string | null;
  deactivated_at: string | null;
  deactivation_reason: string | null;
  created_at: string | null;
  stats: PromoStats;
};

export type Redemption = {
  id: string;
  user: { id: string; name: string; email: string } | null;
  session_starts_at: string | null;
  discount_amount: number;
  status: "reserved" | "applied" | "restored";
  created_at: string | null;
  applied_at: string | null;
  restored_at: string | null;
};

export type PromoDetail = PromoCodeRow & {
  editable: string[];
  redemptions: Redemption[];
  history: { from: string | null; to: string; reason: string | null; at: string | null }[];
};

export type Batch = {
  id: string;
  title: string;
  description: string | null;
  prefix: string | null;
  size: number;
  created_at: string | null;
  created_by: string | null;
  terms: {
    type: PromoType;
    type_label: string;
    value: number;
    discount_label: string;
    valid_from: string | null;
    valid_until: string | null;
    total_limit: number | null;
    per_user_limit: number | null;
    min_amount: number | null;
    restrictions: Restrictions;
  } | null;
  codes_by_status: Partial<Record<PromoStatus, number>>;
  stats: { reserved_total: number; applied: number; discount_sum: number; conversion: number | null };
};

export type Overview = {
  codes_by_status: Partial<Record<PromoStatus, number>>;
  reserved_total: number;
  applied: number;
  pending: number;
  restored: number;
  discount_sum: number;
  conversion: number | null;
  by_type: Partial<Record<PromoType, { applied: number; discount_sum: number }>>;
  referral: Partial<Record<"registered" | "rewarded" | "rejected", number>>;
};

export type Lookups = {
  psychologists: { id: string; name: string }[];
  price_categories: { id: string; title: string }[];
  users: { id: string; name: string; email: string }[];
};

export type ReferralSettings = { friend_discount: number; reward_type: "fixed" | "percent"; reward_value: number; validity_days: number };

export type InviteCode = {
  id: string;
  code: string;
  type: PromoType;
  value: number;
  discount_label: string;
  status: PromoStatus;
  status_label: string;
  valid_until: string | null;
  used: boolean;
};

export type InviteOverview = {
  code: string;
  url: string;
  terms: { friend_discount_percent: number; reward_type: "fixed" | "percent"; reward_value: number; validity_days: number };
  stats: { invited: number; registered: number; rewarded: number };
  invites: {
    id: string;
    friend: string | null;
    status: "sent" | "registered" | "first_paid" | "rewarded" | "rejected";
    status_label: string;
    rejected_reason: string | null;
    registered_at: string | null;
    rewarded_at: string | null;
  }[];
  rewards: InviteCode[];
  friend_code: InviteCode | null;
};
