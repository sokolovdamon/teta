export type Role = "client" | "psychologist" | "supervisor" | "admin" | "super_admin" | "hr";

export type User = {
  id: string;
  email: string;
  pending_email?: string | null;
  name: string;
  last_name?: string | null;
  phone?: string | null;
  timezone: string;
  roles: Role[];
  permissions: string[];
  avatar_url?: string | null;
  psychologist_id?: string | null;
  company_id?: string | null;
  email_verified: boolean;
  status: "active" | "blocked" | "pending_deletion" | "deleted";
  deletion_requested_at?: string | null;
  birth_date?: string | null;
  gender?: string | null;
};

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

export function hasRole(user: User | null, ...roles: Role[]): boolean {
  return !!user && user.roles.some((r) => roles.includes(r));
}

export function can(user: User | null, permission: string): boolean {
  return !!user && (user.roles.includes("super_admin") || user.permissions.includes(permission));
}
