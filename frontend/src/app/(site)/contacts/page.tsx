import type { ReactNode } from "react";
import { cmsMetadata } from "@/features/site/cmsMetadata";
import { getCmsPage, getInstance } from "@/features/site/server";
import { CmsBody, Container, PageHero, TextLink } from "@/features/site/ui";

const SLUG = "contacts";

export function generateMetadata() {
  return cmsMetadata(SLUG, "Контакты", "Как связаться с ТЕТА: поддержка клиентов и психологов, сотрудничество с компаниями, реквизиты.");
}

/** SITE-16 */
export default async function ContactsPage() {
  const [cms, instance] = await Promise.all([getCmsPage(SLUG), getInstance()]);
  const email = instance.support_email;
  return (
    <Container narrow>
      <PageHero title={cms?.title ?? "Контакты"} lead="Быстрее всего ответим в личном кабинете — бот Герман решает организационные вопросы сразу, а сложные передаёт администратору." />
      <CmsBody cms={cms}>
        <div className="grid gap-4 sm:grid-cols-2">
          <ContactCard title="Поддержка клиентов и психологов">
            {email ? (
              <a href={`mailto:${email}`} className="font-medium text-brand hover:underline">
                {email}
              </a>
            ) : (
              "Напишите в чат поддержки в личном кабинете."
            )}
            <p className="mt-2 text-sm text-muted">Администратор на связи ежедневно с 10:00 до 20:00 по московскому времени.</p>
          </ContactCard>
          <ContactCard title="Чат в личном кабинете">
            Бот Герман и техподдержка — в разделе «Чат» кабинета клиента и «Техподдержка» кабинета психолога.
          </ContactCard>
          <ContactCard title="Компаниям">
            Подключение корпоративной программы — <TextLink href="/business">условия для компаний</TextLink>
            {email ? (
              <>
                , письмо на{" "}
                <a href={`mailto:${email}?subject=${encodeURIComponent("Корпоративная программа")}`} className="font-medium text-brand hover:underline">
                  {email}
                </a>
              </>
            ) : null}
            .
          </ContactCard>
          <ContactCard title="Психологам">
            Как присоединиться к платформе — на странице <TextLink href="/for-psychologists">«Для психологов»</TextLink>.
          </ContactCard>
        </div>
        <div className="rounded-2xl bg-surface p-6 ring-1 ring-line">
          <h2 className="mb-2 text-lg font-semibold">Реквизиты</h2>
          <p className="text-ink-2">{instance.legal_name ?? "ИП Иващенко"}</p>
          <p className="mt-1 text-sm text-muted">
            Полные реквизиты указаны в <TextLink href="/legal">документах платформы</TextLink>.
          </p>
        </div>
      </CmsBody>
      <p className="rounded-2xl bg-danger-soft px-5 py-4 text-ink-2">
        Платформа не оказывает экстренную помощь. Если есть угроза жизни, звоните {instance.emergency_phone ?? "112"} или откройте страницу{" "}
        <TextLink href="/help-now">«Экстренная помощь»</TextLink>.
      </p>
    </Container>
  );
}

function ContactCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-2xl bg-surface p-6 ring-1 ring-line">
      <h2 className="mb-2 text-lg font-semibold">{title}</h2>
      <div className="text-ink-2">{children}</div>
    </div>
  );
}
