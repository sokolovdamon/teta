import { PageHeader } from "@/components/ui";
import { requireUser } from "@/lib/guard";

export default async function Page() {
  const user = await requireUser();
  return <PageHeader title={`Здравствуйте, ${user.name}!`} description="Раздел наполняется в потоке разработки модулей." />;
}
