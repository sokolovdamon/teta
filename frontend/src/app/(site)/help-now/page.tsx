import { LinkButton } from "@/components/ui";
import { cmsMetadata } from "@/features/site/cmsMetadata";
import { getCmsPage, getInstance } from "@/features/site/server";
import { CmsBody, Container, Section } from "@/features/site/ui";

const SLUG = "help-now";

export function generateMetadata() {
  return cmsMetadata(SLUG, "Экстренная помощь", "Если есть угроза жизни — звоните 112. Куда обратиться прямо сейчас, если очень тяжело.");
}

/**
 * SITE-15 (DEC-13): emergency page. The platform does not provide emergency help; crisis lines are editable in the CMS
 * (ADM-20). The default list contains only nationwide free numbers.
 */
export default async function HelpNowPage() {
  const [cms, instance] = await Promise.all([getCmsPage(SLUG), getInstance()]);
  const emergency = instance.emergency_phone ?? "112";
  return (
    <Container narrow>
      <div className="grid gap-4 rounded-3xl bg-danger-soft p-6 sm:p-10">
        <p className="text-sm font-semibold uppercase tracking-wider text-danger">Если есть угроза жизни</p>
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
          Звоните{" "}
          <a href={`tel:${emergency}`} className="underline decoration-2 underline-offset-4">
            {emergency}
          </a>
        </h1>
        <p className="text-lg text-ink-2">
          Если вы или кто-то рядом в опасности прямо сейчас — позвоните по единому номеру экстренных служб. Звонок бесплатный, с мобильного — даже без
          SIM-карты и денег на счёте.
        </p>
        <div>
          <a href={`tel:${emergency}`} className="inline-flex h-13 items-center rounded-xl bg-danger px-7 text-base font-medium text-white hover:opacity-90">
            Позвонить {emergency}
          </a>
        </div>
      </div>

      <p className="text-ink-2">
        ТЕТА — сервис плановых онлайн-сессий с психологом. Мы не оказываем экстренную помощь и не можем отреагировать мгновенно, поэтому в остром состоянии
        обращайтесь в службы ниже.
      </p>

      <CmsBody cms={cms}>
        <Section title="Бесплатные линии помощи">
          <ul className="grid gap-3">
            {[
              { phone: emergency, title: "Единый номер экстренных служб", text: "Круглосуточно, бесплатно. Полиция, скорая, спасатели." },
              { phone: "103", title: "Скорая медицинская помощь", text: "С мобильного телефона — круглосуточно, бесплатно." },
              { phone: "8 800 2000 122", title: "Детский телефон доверия", text: "Для детей, подростков и их родителей. Анонимно и бесплатно по России." },
            ].map((l) => (
              <li key={l.phone} className="flex flex-col gap-1 rounded-2xl bg-surface p-5 ring-1 ring-line sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-semibold">{l.title}</p>
                  <p className="text-sm text-muted">{l.text}</p>
                </div>
                <a href={`tel:${l.phone.replace(/\s/g, "")}`} className="text-xl font-semibold text-brand num">
                  {l.phone}
                </a>
              </li>
            ))}
          </ul>
        </Section>

        <Section title="Что можно сделать прямо сейчас">
          <ul className="grid list-disc gap-2 pl-6 text-ink-2 marker:text-brand-tint">
            <li>Не оставайтесь одни: позвоните или напишите близкому человеку и скажите, что вам сейчас плохо.</li>
            <li>Если рядом есть то, чем можно навредить себе, — уберите это подальше или попросите кого-то помочь.</li>
            <li>Сделайте несколько медленных вдохов и выдохов, выдох длиннее вдоха. Почувствуйте опору под ногами.</li>
            <li>Если тревожно за другого человека — оставайтесь рядом с ним и вызовите помощь.</li>
          </ul>
        </Section>

        <Section title="Когда станет немного легче">
          <p className="text-ink-2">
            Регулярная работа с психологом помогает разобраться в том, что происходит, и найти опору. Когда острое состояние позади, можно подобрать
            специалиста на ТЕТА.
          </p>
          <div>
            <LinkButton href="/podbor" variant="secondary">
              Подобрать психолога
            </LinkButton>
          </div>
        </Section>
      </CmsBody>
    </Container>
  );
}
