import Link from "next/link";
import { Badge, Card, EmptyState, LinkButton } from "@/components/ui";
import { MoodChart } from "@/features/diary/MoodChart";
import { MOODS, formatMood } from "@/features/diary/moods";
import type { Dynamics } from "@/features/diary/types";
import { TYPE_LABELS } from "@/features/recommendations/labels";
import { date, plural } from "@/lib/format";
import { NextSessionCard } from "./NextSessionCard";
import type { ClientHome } from "./types";

/** CL-02: cabinet home. Each block shows its own empty state, so a missing module never breaks the page. */
export function HomeView({ home, timezone }: { home: ClientHome; timezone: string }) {
  const psychologist = home.psychologists[0] ?? null;

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
      <div className="grid content-start gap-6">
        {home.next_session ? (
          <NextSessionCard session={home.next_session} roomWindow={home.room_window} serverTime={home.server_time} timezone={timezone} />
        ) : (
          <Card className="grid gap-3">
            <h2 className="text-lg font-semibold">Ближайшая сессия</h2>
            {psychologist ? (
              <>
                <p className="text-ink-2">Новых записей пока нет. Выберите удобное время у {psychologist.name}.</p>
                <div className="flex flex-wrap gap-3">
                  <LinkButton href={psychologist.profile_url}>Выбрать время</LinkButton>
                  <LinkButton href="/client/sessions" variant="ghost">
                    Все сессии
                  </LinkButton>
                </div>
              </>
            ) : (
              <>
                <p className="text-ink-2">Подберём специалиста под ваш запрос: анкета займёт несколько минут.</p>
                <div className="flex flex-wrap gap-3">
                  <LinkButton href="/podbor">Подобрать психолога</LinkButton>
                  <LinkButton href="/psychologists" variant="secondary">
                    Каталог психологов
                  </LinkButton>
                </div>
              </>
            )}
          </Card>
        )}

        <MoodWidget diary={home.diary} />
        <RecommendationsWidget block={home.recommendations} />
      </div>

      <div className="grid content-start gap-6">
        <Card className="grid gap-3">
          <h2 className="text-lg font-semibold">Мой психолог</h2>
          {psychologist ? (
            <div className="flex items-center gap-3">
              {psychologist.photo_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- files are served from project storage
                <img src={psychologist.photo_url} alt="" className="size-14 rounded-full object-cover" />
              ) : (
                <span className="grid size-14 place-items-center rounded-full bg-brand-soft text-xl font-semibold text-brand" aria-hidden>
                  {psychologist.name.slice(0, 1)}
                </span>
              )}
              <div className="min-w-0">
                <Link href={psychologist.profile_url} className="font-medium text-brand">
                  {psychologist.name}
                </Link>
                {psychologist.headline && <p className="line-clamp-2 text-sm text-muted">{psychologist.headline}</p>}
              </div>
            </div>
          ) : (
            <p className="text-ink-2">Вы ещё не выбрали психолога.</p>
          )}
          <Link href="/client/psychologist" className="text-sm text-brand">
            {psychologist ? "Мой психолог и смена специалиста →" : "Как выбрать психолога →"}
          </Link>
        </Card>

        <Card className="grid gap-3">
          <h2 className="text-lg font-semibold">Быстрые ссылки</h2>
          <ul className="grid gap-2">
            <QuickLink href="/podbor?again=1" title="Подбор психолога" text="Пройти анкету и получить рекомендации" />
            <QuickLink href="/client/materials" title="Материалы" text="Техники, тесты, медитации" />
            <QuickLink href="/client/chat" title="Поддержка" text="Бот Герман и администратор" />
          </ul>
        </Card>

        <Card className="grid gap-2 border-danger/40">
          <h2 className="text-lg font-semibold">Если очень тяжело прямо сейчас</h2>
          <p className="text-sm text-ink-2">Не ждите сессии: позвоните в службу помощи — там помогут круглосуточно и бесплатно.</p>
          <Link href="/help-now" className="font-medium text-danger">
            Экстренная помощь →
          </Link>
        </Card>
      </div>
    </div>
  );
}

function QuickLink({ href, title, text }: { href: string; title: string; text: string }) {
  return (
    <li>
      <Link href={href} className="grid rounded-xl border border-line px-4 py-3 transition-colors hover:border-brand-tint">
        <span className="font-medium">{title}</span>
        <span className="text-sm text-muted">{text}</span>
      </Link>
    </li>
  );
}

function MoodWidget({ diary }: { diary: ClientHome["diary"] }) {
  if (!diary) return null;
  const today = diary.today_mood ? MOODS[diary.today_mood - 1] : null;
  const entries = diary.recent.points.reduce((sum, p) => sum + p.entries, 0);
  const dynamics: Dynamics = {
    from: diary.recent.from,
    to: diary.recent.to,
    group: "day",
    points: diary.recent.points,
    tags: [],
    summary: { entries, avg_mood: diary.recent.avg_mood, days_with_entries: diary.recent.points.length },
  };

  return (
    <Card className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Настроение</h2>
        <Link href="/client/diary" className="text-sm text-brand">
          Дневник эмоций →
        </Link>
      </div>
      {today ? (
        <p className="text-ink-2">
          Сегодня: <span aria-hidden>{today.emoji}</span> {today.label}
        </p>
      ) : (
        <div className="flex flex-wrap items-center gap-3">
          <p className="text-ink-2">Сегодня отметки ещё нет.</p>
          <LinkButton href="/client/diary" size="sm" variant="secondary">
            Отметить настроение
          </LinkButton>
        </div>
      )}
      {entries > 0 ? (
        <div className="grid gap-1 pt-6">
          <MoodChart dynamics={dynamics} compact />
          <p className="text-sm text-muted">
            За 14 дней: {entries} {plural(entries, ["отметка", "отметки", "отметок"])}, в среднем {formatMood(diary.recent.avg_mood)} из 5
          </p>
        </div>
      ) : (
        <p className="text-sm text-muted">Здесь появится график настроения за последние две недели.</p>
      )}
    </Card>
  );
}

function RecommendationsWidget({ block }: { block: ClientHome["recommendations"] }) {
  if (!block) return null;
  return (
    <Card className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-lg font-semibold">
          Рекомендации
          {block.unread_count > 0 && <Badge tone="brand">{block.unread_count} новых</Badge>}
        </h2>
        <Link href="/client/recommendations" className="text-sm text-brand">
          Все →
        </Link>
      </div>
      {block.items.length === 0 ? (
        <EmptyState title="Пока пусто" description="Задания и материалы от психолога появятся здесь после сессий." />
      ) : (
        <ul className="grid gap-2">
          {block.items.map((r) => (
            <li key={r.id}>
              <Link href={`/client/recommendations/${r.id}`} className="grid gap-0.5 rounded-xl border border-line px-4 py-3 transition-colors hover:border-brand-tint">
                <span className="flex flex-wrap items-center gap-2 text-sm text-muted">
                  {TYPE_LABELS[r.type]}
                  {r.status === "sent" && <Badge tone="brand">Новая</Badge>}
                </span>
                <span className="font-medium">{r.title}</span>
                {r.due_date && <span className="text-sm text-muted">до {date(r.due_date, "UTC")}</span>}
              </Link>
            </li>
          ))}
        </ul>
      )}
      {block.active_count > block.items.length && <p className="text-sm text-muted">И ещё {block.active_count - block.items.length} в разделе «Рекомендации».</p>}
    </Card>
  );
}
