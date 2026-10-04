import { CabinetShell } from "@/components/layout/CabinetShell";
import { hrNav } from "@/config/nav";
import { requireUser } from "@/lib/guard";

export default async function HrLayout({ children }: LayoutProps<"/hr">) {
  const user = await requireUser(["hr"]);
  return (
    <CabinetShell user={user} nav={hrNav} title="Кабинет HR" notificationsHref="/hr/notifications">
      {children}
    </CabinetShell>
  );
}
