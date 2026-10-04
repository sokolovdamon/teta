export type RecoType = "task" | "exercise" | "material";

export type RecoStatus = "draft" | "sent" | "viewed" | "done" | "revoked";

export type RecoLink = { url: string; title: string | null; kb_material_id: string | null };

export type RecoFile = { id: string; name: string; mime_type: string; size: number; url: string };

export type Recommendation = {
  id: string;
  type: RecoType;
  title: string;
  body: string | null;
  links: RecoLink[];
  due_date: string | null;
  status: RecoStatus;
  session_id: string;
  session_starts_at: string | null;
  sent_at: string | null;
  viewed_at: string | null;
  done_at: string | null;
  files: RecoFile[];
  created_at: string;
  updated_at: string;
  /** Client view. */
  psychologist?: { id: string; slug: string; name: string } | null;
  /** Psychologist view. */
  client_id?: string;
  revoked_at?: string | null;
  window_until?: string;
  can_revoke?: boolean;
  editable?: boolean;
};

export type EligibleSession = { id: string; starts_at: string; format: string; window_until: string };
