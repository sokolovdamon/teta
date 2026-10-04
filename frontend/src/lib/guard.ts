import "server-only";
import { redirect } from "next/navigation";
import { getCurrentUser } from "./server";
import { cabinetHome } from "./roles";
import type { Role, User } from "./types";

/** Server-side guard for cabinet layouts: signed in and holding one of the roles. */
export async function requireUser(roles?: Role[]): Promise<User> {
  const user = await getCurrentUser();
  if (!user) redirect("/auth/login");
  if (roles && !roles.some((r) => user.roles.includes(r)) && !user.roles.includes("super_admin")) {
    redirect(cabinetHome(user));
  }
  return user;
}

/** Admin panel: any admin permission (RBAC roles may be custom). */
export async function requireAdmin(): Promise<User> {
  const user = await getCurrentUser();
  if (!user) redirect("/auth/login");
  const isAdmin = user.roles.includes("super_admin") || user.permissions.some((p) => p === "*" || p.startsWith("admin."));
  if (!isAdmin) redirect(cabinetHome(user));
  return user;
}
