import type { Metadata } from "next";
import { LinkButton } from "@/components/ui";
import { getDictionaries } from "@/features/catalog/server";
import { PriceCategories } from "@/features/site/PriceCategories";
import { Container, CtaBand, FaqList, PageHero, Section, TextLink } from "@/features/site/ui";

export const metadata: Metadata = {
  title: "Цены и оплата",
  description:
    "Сколько стоят сессии с психологом на ТЕТА: ценовые категории, автосписание за 12 часов до сессии, бесплатная отмена до списания, перенос и возвраты.",
  alternates: { canonical: "/prices" },
};

const RULES = [
  {
    title: "Оплата",
    items: [
      "Карту вы привязываете один раз при первой записи. Данные карты хранит платёжный сервис, а не платформа.",
      "Оплата списывается автоматически за 12 часов до начала сессии. Если вы записались, когда до сессии меньше 12 часов, — сразу.",
      "Если на балансе личного кабинета есть деньги, сначала списываются они, затем — карта.",
      "Чек приходит на email.",
    ],
  },
  {
    title: "Отмена клиентом",
    items: [
      "До списания оплаты, то есть раньше чем за 12 часов до начала, — бесплатно.",
      "После списания оплата за отменённую сессию не возвращается.",
    ],
  },
  {
    title: "Перенос",
    items: [
      "До списания оплаты — бесплатно, на любое свободное время.",
      "После списания перенос возможен, если новая сессия начинается не раньше чем через 12 часов от момента переноса. Оплата переходит на новую сессию.",
    ],
  },
  {
    title: "Если сессию отменил или пропустил психолог",
    items: ["Вы выбираете: полный возврат на баланс личного кабинета или бесплатный перенос на другое время."],
  },
  {
    title: "Возвраты и жалобы",
    items: [
      "Если вы не согласны со списанием, оставьте жалобу в разделе «Платежи» личного кабинета — мы рассмотрим её в течение 14 рабочих дней.",
      "Возвраты зачисляются на баланс личного кабинета. Остаток баланса можно вывести на карту по заявке.",
      "При смене психолога назначенные, но не проведённые сессии отменяются, а оплата за них возвращается на баланс.",
    ],
  },
];

/** SITE-05: price categories (DEC-55), autocharge and cancellations (DEC-23, DEC-56). */
export default async function PricesPage() {
  const dictionaries = await getDictionaries();
  return (
    <Container>
      <PageHero
        eyebrow="Цены и оплата"
        title="Цена известна заранее"
        lead="Каждый психолог сам назначает стоимость своей сессии — её видно в профиле до записи. Индивидуальная сессия длится 50 минут, парная — 90 минут."
        actions={
          <>
            <LinkButton href="/psychologists" size="lg">
              Смотреть психологов
            </LinkButton>
            <LinkButton href="/podbor" size="lg" variant="secondary">
              Подобрать по цене
            </LinkButton>
          </>
        }
      />

      <Section title="Ценовые категории" lead="Категория определяется ценой индивидуальной сессии. Квалификация подтверждена у всех психологов, независимо от цены.">
        {dictionaries ? <PriceCategories categories={dictionaries.price_categories} /> : <p className="text-muted">Категории временно недоступны.</p>}
      </Section>

      <Section title="Как устроены оплата, отмена и возвраты">
        <div className="grid gap-4 md:grid-cols-2">
          {RULES.map((r) => (
            <div key={r.title} className="rounded-2xl bg-surface p-6 ring-1 ring-line">
              <h3 className="mb-3 text-lg font-semibold">{r.title}</h3>
              <ul className="grid list-disc gap-2 pl-5 text-ink-2 marker:text-brand-tint">
                {r.items.map((i) => (
                  <li key={i}>{i}</li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Промокоды, сертификаты и корпоративная оплата">
        <FaqList
          withSchema={false}
          items={[
            {
              q: "Как применить промокод?",
              text: "Введите промокод при записи на сессию. Скидка уменьшает стоимость для вас и не влияет на вознаграждение психолога.",
              a: "Введите промокод при записи на сессию. Скидка уменьшает стоимость для вас и не влияет на вознаграждение психолога.",
            },
            {
              q: "Можно ли подарить сессии?",
              text: "Да, подарочный сертификат на фиксированную сумму.",
              a: (
                <>
                  Да, <TextLink href="/gift">подарочный сертификат</TextLink> на фиксированную сумму — получатель активирует его в личном кабинете.
                </>
              ),
            },
            {
              q: "Если сессии оплачивает работодатель",
              text: "Сессии в пределах лимита программы оплачивает компания, сверх лимита — вы сами привязанной картой.",
              a: (
                <>
                  Сессии в пределах лимита программы оплачивает компания, сверх лимита — вы сами привязанной картой. <TextLink href="/business">О программе для компаний</TextLink>.
                </>
              ),
            },
          ]}
        />
      </Section>

      <CtaBand
        title="Выберите психолога в своей ценовой категории"
        actions={
          <LinkButton href="/psychologists" variant="secondary" size="lg">
            Перейти в каталог
          </LinkButton>
        }
      />
    </Container>
  );
}
