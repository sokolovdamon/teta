import type { User } from "./types";

/** Where a signed-in user lands: the most privileged cabinet they have. */
export function cabinetHome(user: User): string {
  if (user.roles.includes("super_admin") || user.roles.includes("admin")) return "/admin";
  if (user.roles.includes("psychologist") || user.roles.includes("supervisor")) return "/pro";
  if (user.roles.includes("hr")) return "/hr";
  return "/client";
}
