export type Role = "client" | "psychologist" | "supervisor" | "admin" | "super_admin" | "hr";

export type User = {
  id: number;
  email: string;
  name: string;
  last_name?: string | null;
  phone?: string | null;
  timezone: string;
  roles: Role[];
  permissions: string[];
  avatar_url?: string | null;
  psychologist_id?: number | null;
  company_id?: number | null;
  email_verified: boolean;
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
