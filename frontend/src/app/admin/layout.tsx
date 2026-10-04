import { CabinetShell } from "@/components/layout/CabinetShell";
import { adminNav } from "@/config/nav";
import { requireAdmin } from "@/lib/guard";

export default async function AdminLayout({ children }: LayoutProps<"/admin">) {
  const user = await requireAdmin();
  return (
    <CabinetShell user={user} nav={adminNav} title="Админ-панель" notificationsHref="/admin/notifications-center">
      {children}
    </CabinetShell>
  );
}
