import { CabinetShell } from "@/components/layout/CabinetShell";
import { proNav } from "@/config/nav";
import { requireUser } from "@/lib/guard";

export default async function ProLayout({ children }: LayoutProps<"/pro">) {
  const user = await requireUser(["psychologist", "supervisor"]);
  return (
    <CabinetShell user={user} nav={proNav} title="Кабинет психолога" notificationsHref="/pro/notifications">
      {children}
    </CabinetShell>
  );
}
