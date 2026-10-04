import { LinkButton } from "@/components/ui";
import { cmsMetadata } from "@/features/site/cmsMetadata";
import { FAQ_SECTIONS } from "@/features/site/faq";
import { getCmsPage } from "@/features/site/server";
import { CmsBody, Container, CtaBand, FaqList, PageHero, Section } from "@/features/site/ui";

const SLUG = "faq";

export function generateMetadata() {
  return cmsMetadata(SLUG, "Вопросы и ответы", "Ответы на частые вопросы о ТЕТА: выбор психолога, сессии и техника, оплата, отмена и перенос, конфиденциальность.");
}

/** SITE-14 */
export default async function FaqPage() {
  const cms = await getCmsPage(SLUG);
  const all = FAQ_SECTIONS.flatMap((s) => s.items);
  return (
    <Container narrow>
      <PageHero title={cms?.title ?? "Вопросы и ответы"} lead="Не нашли ответ? Спросите бота Германа в личном кабинете или напишите в поддержку." />
      <CmsBody cms={cms}>
        <nav aria-label="Разделы" className="flex flex-wrap gap-2">
          {FAQ_SECTIONS.map((s, i) => (
            <a key={s.title} href={`#faq-${i}`} className="rounded-full border border-line bg-surface px-3.5 py-1.5 text-sm text-ink-2 hover:border-brand hover:text-brand">
              {s.title}
            </a>
          ))}
        </nav>
        {FAQ_SECTIONS.map((s, i) => (
          <Section key={s.title} id={`faq-${i}`} title={s.title}>
            <FaqList items={s.items} withSchema={false} />
          </Section>
        ))}
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{
            __html: JSON.stringify({
              "@context": "https://schema.org",
              "@type": "FAQPage",
              mainEntity: all.map((i) => ({ "@type": "Question", name: i.q, acceptedAnswer: { "@type": "Answer", text: i.text } })),
            }).replace(/</g, "\\u003c"),
          }}
        />
      </CmsBody>
      <CtaBand
        title="Остались вопросы?"
        text="Бот Герман ответит в личном кабинете, а сложные ситуации передаст администратору."
        actions={
          <>
            <LinkButton href="/contacts" variant="secondary">
              Контакты
            </LinkButton>
            <LinkButton href="/podbor" variant="secondary">
              Подобрать психолога
            </LinkButton>
          </>
        }
      />
    </Container>
  );
}
