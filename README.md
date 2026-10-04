# ТЕТА — платформа онлайн-психологии

Портал психологической помощи teta.su: подбор психолога по анкете или с ботом Германом, запись и автосписание оплаты, видеосессии TetaMeet, кабинеты клиента, психолога и HR, админ-панель с RBAC, супервизия и интервизия, статьи с автопостингом в «Яндекс Дзен», база знаний, техподдержка и email-маркетинг.

Требования: [ТЗ v2](docs/01_inputs/tz_v2.md) и [реестр решений](docs/01_inputs/decisions_v2.md); план и статус — [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md); правила кода — [CLAUDE.md](CLAUDE.md).

## Состав

| Папка | Что внутри |
|---|---|
| `backend/` | Laravel 13, модульный монолит: `app/Modules/*` (34 модуля реестра), REST API `/api/v1`, очереди и планировщик на Redis, Reverb (вебсокеты) |
| `frontend/` | Next.js 16 + PWA: публичный сайт (SSR), мастер подбора, кабинеты клиента, психолога, HR, админ-панель, видеокомната TetaMeet |
| `infra/` | Dockerfile, nginx, coturn (TURN для видео), бэкапы |
| `docs/` | Проектная документация, восстановленная из сессии анализа |
| `scripts/` | Вспомогательные скрипты |

## Локальный запуск

Нужны PHP 8.3+, Composer, Node.js 22, PostgreSQL 16+ и Redis.

```bash
# база
createdb teta && createdb teta_test

# бэкенд
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # справочники и демо-данные
php artisan serve                   # API на :8000
php artisan reverb:start            # вебсокеты на :8080
php artisan queue:work redis --queue=payments,webhooks,payouts,outbox,mail-transactional,meet,default,mailing,mail-events,exports,content,files
php artisan schedule:work           # автосписания, выплаты, напоминания

# фронтенд
cd ../frontend
npm ci
cp .env.example .env.local
npm run dev                         # сайт на :3000
```

Письма в dev уходят на SMTP `127.0.0.1:1025` — удобно поднять Mailpit: `docker compose --profile dev up -d mailpit` (веб-интерфейс на :8025).

### Демо-доступ

Пароль у всех — `teta12345`.

| Email | Роль |
|---|---|
| `super@teta.local` | Супер-администратор |
| `admin@teta.local` | Администратор |
| `client@teta.local` | Клиент |
| `hr@teta.local` | HR-менеджер компании «Ромашка» (код программы `ROMASHKA2026`) |
| `supervisor@teta.local` | Психолог и супервизор |
| `anna.sokolova@teta.local` и ещё 7 психологов | Психологи с подтверждённой квалификацией и графиком |

Оплата работает на тестовом эмуляторе платёжного сервиса (DEC-38): тестовые карты перечислены на странице оплаты эмулятора.

## Тесты и проверки

```bash
cd backend && php artisan test && vendor/bin/pint --test
cd frontend && npm run typecheck && npm run lint && npm test
```

CI (`.github/workflows/ci.yml`) запускает то же самое на каждый пуш.

## Развёртывание

Production и stage разворачиваются через `docker compose` на VDS Timeweb в РФ (DEC-36) — пошагово в [docs/deploy.md](docs/deploy.md): переменные окружения, TLS, почтовые записи SPF/DKIM/DMARC, бэкапы, обновление, развёртывание партнёрского инстанса white-label.

## Что зависит от заказчика

- Выбор платёжного сервиса (Q-43): после него пишется адаптер к контракту `PaymentGateway` вместо эмулятора, подключаются онлайн-касса 54-ФЗ и выплаты самозанятым.
- Почтовый сервер: DNS-записи SPF, DKIM, DMARC и прогрев IP (DEC-51).
- LLM для бота Германа: российская или локальная модель (DEC-54), адрес и ключ задаются в админ-панели.
- Счётчик Яндекс.Метрики, аккаунт «Яндекс Дзен», тексты сайта, посадочных и юридических документов (DEC-34).
