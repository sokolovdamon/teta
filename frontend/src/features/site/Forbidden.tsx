import { EmptyState } from "@/components/ui";

/** Admin section without the needed RBAC permission. */
export function Forbidden({ section }: { section: string }) {
  return <EmptyState title="Нет доступа" description={`Для раздела «${section}» нужны права, которых нет у вашей роли. Обратитесь к супер-администратору.`} />;
}
