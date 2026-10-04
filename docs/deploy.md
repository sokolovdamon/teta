# Развёртывание платформы ТЕТА

Окружения dev, stage и prod размещаются на VDS Timeweb в дата-центрах РФ (DEC-36). Один инстанс — одна база данных; партнёр white-label получает отдельную копию платформы на своём сервере (DEC-52).

## 1. Сервер

Рекомендуемая конфигурация prod под НФТ ТЗ v2 (100 одновременно активных пользователей, 20 параллельных видеосессий):

| Роль | Ресурсы |
|---|---|
| Приложение (nginx, backend, queue, scheduler, reverb, frontend, postgres, redis, minio) | 8 vCPU, 16 ГБ RAM, 200 ГБ NVMe |
| TURN-сервер coturn (отдельная VDS с публичным IP) | 4 vCPU, 4 ГБ RAM, канал от 500 Мбит/с |

ОС — Ubuntu 24.04 LTS, Docker и Docker Compose plugin.

## 2. Первая установка

```bash
git clone <репозиторий> /opt/teta && cd /opt/teta
cp backend/.env.example backend/.env       # заполнить значения ниже
docker compose build
docker compose up -d postgres redis minio
docker compose run --rm backend php artisan key:generate --force
docker compose run --rm backend php artisan migrate --force
docker compose run --rm backend php artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder --force
docker compose up -d
docker compose run --rm backend php artisan tinker   # создать первого супер-администратора
```

Ключевые переменные `backend/.env`:

| Переменная | Значение |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL`, `FRONTEND_URL` | `https://teta.su` |
| `DB_*` | хост `postgres`, база `teta`, пользователь и пароль из `POSTGRES_PASSWORD` |
| `REDIS_HOST` | `redis` |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT=443`, `REVERB_SCHEME=https` | ключи вебсокетов; те же ключ, хост и порт передаются во фронтенд при сборке |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | собственный почтовый сервер (DEC-51); на dev и stage — тестовый SMTP без доставки |
| `FILESYSTEM_DISK`, `AWS_*` с `AWS_ENDPOINT=http://minio:9000`, `AWS_USE_PATH_STYLE_ENDPOINT=true` | файлы на своих серверах в РФ |
| `PAYMENT_GATEWAY` | `emulator` до выбора сервиса (Q-43) |
| `MEET_TURN_URLS`, `MEET_TURN_SECRET`, `MEET_STUN_URLS` | TURN для TetaMeet; секрет совпадает со `static-auth-secret` в `infra/coturn/turnserver.conf` |

## 3. TLS и домены

nginx из `docker-compose.yml` слушает 80 порт. На prod перед ним ставится TLS-терминация: certbot с плагином nginx на хосте либо отдельный контейнер (traefik или nginx-proxy с acme-companion). Нужны сертификаты для `teta.su`, `www.teta.su` и домена TURN (`turn.teta.su`, порты 3478/5349 TCP+UDP и диапазон 49160–49200 UDP).

Пока работает текущий сайт на Tilda, новая платформа живёт на stage-домене; переключение `teta.su` — в день запуска (Q-55).

## 4. Почта (DEC-51)

1. Поднять почтовый сервер: профиль `mail` в compose (`docker compose --profile mail up -d postfix`) или отдельная VDS.
2. DNS: `SPF` — `v=spf1 ip4:<IP сервера> -all`; `DKIM` — публичный ключ из тома `dkim`; `DMARC` — `v=DMARC1; p=quarantine; rua=mailto:dmarc@teta.su`; обратная DNS-запись (PTR) IP-адреса на `mail.teta.su`.
3. Прогревать IP не меньше четырёх недель (RISK-32): начинать с транзакционных писем, рассылки — с малых сегментов.
4. Проверить ограничения Timeweb на исходящий 25 порт.

## 5. Бэкапы и мониторинг

- Контейнер `backup` раз в сутки делает `pg_dump` в том `backups` и хранит копии `BACKUP_KEEP_DAYS` дней (по умолчанию 14, требование ТЗ — не меньше 7). Копии регулярно выгружаются за пределы сервера (S3-совместимое хранилище в РФ). Восстановление: `gunzip -c teta-YYYYMMDD.sql.gz | psql teta`. Проверку восстановления проводить раз в месяц на stage.
- Доступность: `GET /up` (Laravel) и главная страница; внешний мониторинг с оповещением.
- Логи: `docker compose logs`, ошибки Laravel — в `storage/logs`; журнал действий пользователей — в админ-панели (ADM-25).

## 6. Обновление

```bash
cd /opt/teta && git pull
docker compose build
docker compose run --rm backend php artisan migrate --force
docker compose up -d
docker compose exec backend php artisan optimize
```

Перед миграциями prod — резервная копия. Все инстансы (основной и партнёрские) работают на одной версии (ST-20).

## 7. Партнёрский инстанс white-label (SEQ-21)

1. Супер-администратор основного инстанса вносит партнёра в реестр (ADM-21) и получает токен служебного сигнала.
2. Выделяется отдельная VDS, разворачивается та же версия платформы по шагам 2–4 со своей базой, Redis, файловым хранилищем и TetaMeet. Персональные данные из основного инстанса не переносятся.
3. Создаётся первый супер-администратор инстанса партнёра; в его админ-панели (ADM-21) задаются наименование, логотип, палитра, домен и отправитель писем; партнёр добавляет своих психологов.
4. Служебный сигнал: в планировщик инстанса партнёра добавляется POST на `https://teta.su/api/v1/instance/heartbeat` с токеном и версией (раз в 5 минут). Основной инстанс отмечает партнёра «Недоступен», если сигнала нет дольше 15 минут.
5. До подключения платёжного сервиса партнёр работает на эмуляторе (DEC-38).
