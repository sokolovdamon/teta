import { CabinetShell } from "@/components/layout/CabinetShell";
import { clientNav } from "@/config/nav";
import { requireUser } from "@/lib/guard";

export default async function ClientLayout({ children }: LayoutProps<"/client">) {
  const user = await requireUser(["client"]);
  return (
    <CabinetShell user={user} nav={clientNav} title="Кабинет клиента" notificationsHref="/client/notifications">
      {children}
    </CabinetShell>
  );
}
