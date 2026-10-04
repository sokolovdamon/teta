# ТЕТА — платформа онлайн-психологии

Монорепозиторий: `backend/` — Laravel 13 (модульный монолит, API), `frontend/` — Next.js 16 (сайт, кабинеты, админ-панель, PWA), `infra/` — Docker, nginx, coturn, `docs/` — проектная документация.

## Источники требований (по приоритету)

1. `docs/01_inputs/decisions_v2.md` — реестр решений DEC-01…DEC-58, модули, ID разделов интерфейса, 43 запроса, терминология. Приоритетнее всего остального.
2. `docs/01_inputs/tz_v2.md` — ТЗ v2.
3. `docs/06_architecture/sequences_states.md` — сценарии SEQ-01…22 и машины состояний ST-01…20. Статусы и переходы в коде совпадают с ними.
4. `docs/01_inputs/open_questions.md` — открытые вопросы; реализуются рекомендации (допущения Q-43, Q-52…Q-56).
5. `docs/_recovery/agents_tasks_and_reports.md` — подробные постановки потерянных документов (FR, BR, модель данных, архитектура). `docs/_recovery/outdated_v1/` — v1, только для структуры.

План и статус работ — `IMPLEMENTATION_PLAN.md`.

## Бэкенд (`backend/`)

- Модули — `app/Modules/<Module>/`: `Models/`, `Http/Controllers/`, `Services/`, `routes.php` (монтируется в `/api/v1`), `console.php` (команды и расписание), `notifications.php` (шаблоны писем), `<Module>ServiceProvider.php` (подключается автоматически). Общие механизмы — `app/Support/`.
- Миграции — `database/migrations/`, UUID-ключи (`HasUuids`), время в UTC, деньги — целые копейки (`App\Support\Money`).
- Каждая модель использует `HasUuids` и `App\Support\Database\UtcDates` (даты в любом поясе сохраняются в UTC; параметры запросов приводит к UTC `UtcPostgresConnection`).
- Машины состояний: трейт `App\Support\StateMachine\HasStateMachine`; переход только через `transitionTo()` — он пишет историю `state_transitions` и доменное событие.
- Доменные события: `App\Support\Events\Outbox::record()` в той же транзакции; подписчики — `Outbox::listen('book.session.held', Listener::class)` в сервис-провайдере модуля, реализуют `DomainEventListener` и идемпотентны.
- Параметры правил `P-*`: `App\Support\Settings\Settings::get('P-CHARGE-OFFSET')`; значения по умолчанию — `config/platform.php`. Числа в коде не хардкодим.
- Письма и центр уведомлений: `app(App\Modules\Notifications\Notifier::class)->send($user, 'code', $vars, $link)`; шаблоны — `app/Modules/<Module>/notifications.php`. В письмах нет сведений о состоянии клиента.
- Действия администратора пишем в аудит: `App\Modules\Audit\Audit::log('ADM-xx', 'action', $subject, $changes)`.
- Права: middleware `permission:admin.users.view` (каталог — `config/rbac.php`), `role:psychologist,supervisor`, `verified.email`. Жёсткие запреты (заметки психолога — только автор; дневник и история сессий — только психолог клиента) проверяются в коде, не через RBAC.
- В кеш кладём только скаляры и массивы: Laravel 13 не десериализует объекты (`cache.serializable_classes = false`), коллекция из кеша превращается в `__PHP_Incomplete_Class`.
- Полиморфные типы регистрируем в сервис-провайдере модуля: `Relation::morphMap([...])`.
- Сиды: справочные данные модуля — `database/seeders/Reference/*Seeder.php`, демо — `database/seeders/Demo/*Seeder.php` (подключаются автоматически).
- Общие сервисы: свободные слоты и удержание — `App\Modules\Schedule\Services\SlotService`; требование ежемесячной супервизии и статус активности (ST-09) — `App\Modules\Psychologists\Services\ActivityService` (`markMonthMet`, `payoutAllowed`); промокоды для записи — контракт `App\Modules\Promo\Contracts\PromoCodes`.
- Платёжный сервис не выбран (Q-43, DEC-38): всё работает через контракт `App\Modules\Payments\Gateway\PaymentGateway` и тестовый эмулятор.
- Тестовые хелперы: `Tests\Concerns\CreatesPsychologists` (`makePsychologist`, `makeSession`), в `Tests\TestCase` — `actingAsRole()`, `userWithRole()`.
- Тесты: `php artisan test` на PostgreSQL (`phpunit.xml`, база `teta_test`; своя база — `DB_DATABASE=teta_test_x php artisan test`). Краткая сводка: `php artisan test 2>&1 | python3 ../scripts/test-summary.py`. Форматирование: `vendor/bin/pint`.

## Фронтенд (`frontend/`)

- Next.js 16 отличается от прежних версий: перед кодом читай `frontend/node_modules/next/dist/docs/` (async `params`/`cookies()`, `proxy.ts` вместо middleware, Turbopack).
- Браузер ходит в API только через BFF `/bff/v1/...` (`src/lib/api.ts`); токен — в httpOnly-cookie. Серверные компоненты — `serverApi()` и `getCurrentUser()` из `src/lib/server.ts`; защита кабинетов — `requireUser()` / `requireAdmin()` из `src/lib/guard.ts`.
- UI-кит — `src/components/ui`; цвета только через токены (`bg-brand`, `text-ink-2`, `border-line` и т. д.), шрифт Onest. Навигация кабинетов — `src/config/nav.ts` (ID разделов из реестра).
- Код фичи — `src/features/<module>/` (компоненты, типы, клиентские вызовы); маршруты — `src/app/...`. Публичные страницы — в группе `src/app/(site)/` с SSR и метаданными для SEO.
- Проверки: `npm run typecheck`, `npm run lint`, `npm test` (Vitest).

## Язык и терминология

Интерфейс на русском, дружелюбный минимализм. Термины: «запросы» (не «темы»), «подходы» (не «методы»), «сведения о состоянии», «журнал сессий», «бот Герман», «TetaMeet». Не используем слова «пациент», «лечение», «врач», «диагноз», «штраф». Рейтингов психологов нет (DEC-32).

## Демо-доступ (dev)

Пароль у всех демо-пользователей — `teta12345`: `super@teta.local`, `admin@teta.local`, `client@teta.local`, `hr@teta.local`, `supervisor@teta.local`, психологи `anna.sokolova@teta.local` и др.
