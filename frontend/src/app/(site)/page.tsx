import type { Metadata } from "next";
import { LinkButton } from "@/components/ui";
import { ArticlesTeaser } from "@/features/content/ArticlesTeaser";
import { PsychologistCard } from "@/features/catalog/PsychologistCard";
import { EMPTY_FILTERS } from "@/features/catalog/query";
import { getCatalog, getDictionaries } from "@/features/catalog/server";
import { FAQ_EXCERPT } from "@/features/site/faq";
import { PriceCategories } from "@/features/site/PriceCategories";
import { RequestsCarousel } from "@/features/site/RequestsCarousel";
import { CtaBand, FaqList, FeatureGrid, Section, Steps, TextLink } from "@/features/site/ui";

export const metadata: Metadata = {
  title: { absolute: "ТЕТА — онлайн-психологи с подтверждённой квалификацией" },
  description:
    "Только дипломированные специалисты: администратор проверяет дипломы каждого психолога, работа проходит ежемесячную супервизию. Подбор по запросу и видеосессии онлайн.",
  alternates: { canonical: "/" },
};

const STEPS = [
  { title: "Расскажите о запросе", text: "Ответьте на вопросы анкеты или поговорите с ботом Германом — это займёт несколько минут." },
  { title: "Получите подбор", text: "Главная рекомендация и все подходящие альтернативы с понятным объяснением, почему они вам подходят." },
  { title: "Выберите время", text: "Запишитесь на удобный слот и один раз привяжите карту. Оплата спишется за 12 часов до сессии." },
  { title: "Встречайтесь онлайн", text: "Видеосессия в TetaMeet с компьютера или телефона. Между встречами — рекомендации и дневник эмоций." },
];

const TRUST = [
  { title: "Дипломы проверены", text: "Администратор проверяет документы о психологическом образовании каждого специалиста." },
  { title: "Ежемесячная супервизия", text: "Психологи регулярно разбирают свою работу с опытным коллегой — без этого профиль скрывается." },
  { title: "Конфиденциально", text: "Сессии не записываются, заметки психолога видит только он, данные хранятся в России." },
  { title: "Цена известна заранее", text: "Стоимость видна в профиле, до списания оплаты отмена бесплатна." },
];

/** SITE-01 */
export default async function HomePage() {
  const [dictionaries, teaser] = await Promise.all([getDictionaries(), getCatalog({ ...EMPTY_FILTERS }, 4)]);
  const groups = dictionaries?.request_groups ?? [];
  const psychologists = teaser.status === "ok" ? teaser.data.data : [];
  const requestCount = groups.reduce((n, g) => n + g.requests.length, 0);

  return (
    <main className="grid gap-20 pb-8">
      <section className="bg-surface">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 sm:py-20 lg:grid-cols-[1.3fr_1fr] lg:items-center">
          <div className="grid gap-5">
            <p className="text-sm font-semibold uppercase tracking-wider text-brand">Портал психологической помощи</p>
            <h1 className="text-4xl font-bold tracking-tight sm:text-6xl">Только дипломированные специалисты</h1>
            <p className="max-w-2xl text-lg text-ink-2">
              Подберём психолога под ваш запрос и удобное время. Квалификацию каждого специалиста проверил администратор, а работа проходит ежемесячную
              супервизию.
            </p>
            <div className="mt-2 flex flex-wrap gap-3">
              <LinkButton href="/podbor" size="lg">
                Подобрать психолога
              </LinkButton>
              <LinkButton href="/psychologists" variant="secondary" size="lg">
                Выбрать самостоятельно
              </LinkButton>
            </div>
            <p className="text-sm text-muted">
              Нужна помощь прямо сейчас? <TextLink href="/help-now">Экстренная помощь</TextLink>
            </p>
          </div>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
            {TRUST.map((t) => (
              <li key={t.title} className="rounded-2xl bg-ground p-5">
                <p className="font-semibold">{t.title}</p>
                <p className="mt-1 text-[15px] text-ink-2">{t.text}</p>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <div className="mx-auto grid w-full max-w-7xl gap-20 px-4 sm:px-6">
        {groups.length > 0 && (
          <Section
            title="С чем помогают психологи ТЕТА"
            lead={`${requestCount} запросов в ${groups.length} группах. Выберите, что откликается, — расскажем, как проходит работа, и покажем специалистов.`}
          >
            <RequestsCarousel groups={groups} />
            <p>
              <TextLink href="/help">Все запросы на одной странице →</TextLink>
            </p>
          </Section>
        )}

        <Section title="Как это работает">
          <Steps items={STEPS} />
          <p>
            <TextLink href="/how-it-works">Подробнее о том, как всё устроено →</TextLink>
          </p>
        </Section>

        {psychologists.length > 0 && (
          <Section title="Психологи" lead="Специалисты с ближайшим свободным временем.">
            <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
              {psychologists.map((p) => (
                <li key={p.id}>
                  <PsychologistCard p={p} compact />
                </li>
              ))}
            </ul>
            <div>
              <LinkButton href="/psychologists" variant="secondary">
                Все психологи
              </LinkButton>
            </div>
          </Section>
        )}

        <Section
          title="Цены"
          lead="Психолог сам назначает стоимость сессии и тем самым попадает в одну из ценовых категорий. Индивидуальная сессия длится 50 минут, парная — 90."
        >
          {dictionaries && <PriceCategories categories={dictionaries.price_categories} />}
          <FeatureGrid
            items={[
              { title: "Автосписание за 12 часов", text: "Карту привязываете один раз, оплата списывается автоматически за 12 часов до начала." },
              { title: "Бесплатная отмена до списания", text: "Передумали — отмените или перенесите сессию до списания без потерь." },
              { title: "Если психолог отменил сессию", text: "Полный возврат на баланс личного кабинета или бесплатный перенос." },
            ]}
          />
          <p>
            <TextLink href="/prices">Все условия оплаты и отмены →</TextLink>
          </p>
        </Section>

        <ArticlesTeaser />

        <div className="grid gap-6 lg:grid-cols-2">
          <section className="grid content-start gap-4 rounded-3xl bg-surface p-8 ring-1 ring-line">
            <p className="text-sm font-semibold uppercase tracking-wider text-brand">Для компаний</p>
            <h2 className="text-2xl font-semibold tracking-tight">Психологическая поддержка сотрудников</h2>
            <p className="text-ink-2">
              Сотрудники сами выбирают психолога, компания оплачивает сессии по ежемесячному счёту. HR получает только email сотрудника и число его сессий — и
              только с его согласия.
            </p>
            <div>
              <LinkButton href="/business" variant="secondary">
                Подробнее для компаний
              </LinkButton>
            </div>
          </section>
          <section className="grid content-start gap-4 rounded-3xl bg-surface p-8 ring-1 ring-line">
            <p className="text-sm font-semibold uppercase tracking-wider text-brand">Для психологов</p>
            <h2 className="text-2xl font-semibold tracking-tight">Работайте с клиентами на ТЕТА</h2>
            <p className="text-ink-2">
              Принимаем дипломированных психологов. Вы сами назначаете цену и выставляете график, комиссия платформы — 30 %, выплаты на карту самозанятого
              каждую неделю.
            </p>
            <div>
              <LinkButton href="/for-psychologists" variant="secondary">
                Стать психологом ТЕТА
              </LinkButton>
            </div>
          </section>
        </div>

        <Section title="Вопросы и ответы">
          <FaqList items={FAQ_EXCERPT} withSchema={false} />
          <p>
            <TextLink href="/faq">Все вопросы и ответы →</TextLink>
          </p>
        </Section>

        <CtaBand
          title="Первый шаг — самый сложный. Мы поможем сделать его бережно"
          text="Подбор займёт несколько минут: расскажите о запросе, и мы предложим подходящих специалистов."
          actions={
            <>
              <LinkButton href="/podbor" variant="secondary" size="lg">
                Подобрать психолога
              </LinkButton>
            </>
          }
        />
      </div>
    </main>
  );
}
