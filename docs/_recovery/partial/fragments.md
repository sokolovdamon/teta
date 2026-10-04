# Фрагменты документов, доступные только частично
Эти документы итоговой версии писали субагенты; в транскрипте основной сессии есть только фрагменты (Read с offset/limit или вывод Bash). Номера строк — по файлу на момент чтения.

## docs/06_architecture/technical_architecture.md — строки 243–260 из 972 (прочитано 2026-09-11T13:10:35.440534Z)

````text
| CI/CD | GitLab CI (self-hosted) или GitVerse/иной сервис в РФ **[допущение]**; реестр образов в облаке РФ | GitHub Actions | Секреты и артефакты сборки — в РФ; тестовые данные без реальных ПДн | — |

### 4.1. Выбор фронтенд-фреймворка: React/Next.js вместо Vue/Nuxt

| Критерий | React + Next.js | Vue + Nuxt | Вес для ТЕТА |
|---|---|---|---|
| SSR/ISR для SEO, микроразметка, sitemap | Зрелый, ISR для каталога и статей | Зрелый (Nuxt) | Равно |
| Видеокомната | Официальные компоненты LiveKit для React (`components-react`) | Официальных компонентов LiveKit для Vue нет **[ПРОВЕРИТЬ на момент старта]** | **Высокий** — сокращает сроки ROOM |
| Доступные headless-компоненты для WCAG 2.2 AA | Radix UI, React Aria | Radix Vue, Headless UI | Выше у React |
| Рынок разработчиков в РФ и генерация кода | Шире | Уже | Средний |
| Единый стек с PWA-кабинетами | React SPA (Vite) переиспользует компоненты Next.js-сайта | Аналогично | Равно |
| Порог входа | Выше | Ниже | Низкий |

**Решение:** React. Next.js — только для публичной части (SITE, WIZ); кабинеты — SPA на Vite, чтобы не держать Node-сервер рядом с интерфейсами ПДн. Next.js разворачивается self-hosted (контейнер), без облачных функций зарубежных провайдеров.

**Особенность мастера записи (поправка к WIZ-02a, BR-ACC-09, Q-34):** ответы анкеты и данные шага «О вас» до подписания согласия на обработку сведений о здоровье хранятся **только в браузере** (память вкладки и зашифрованный черновик в IndexedDB с ключом сеанса вкладки **[допущение]**); на сервер уходят только после подписания ПЭП. Диалог с Германом начинается только после подписания (текст уходит на сервер и в LLM). Следствие для порядка шагов — раздел 19 и [sequences_states.md](sequences_states.md), сценарий 12.

---
````

## docs/04_project_docs/02_functional_requirements.md — строки 1–70 из 1191 (прочитано 2026-09-11T15:55:21.647834Z)

````text
# Функциональные требования (SRS)

> Версия 1.0 · 11.09.2026 · Статус: черновик на утверждение
> Документ фиксирует, **что должна делать система ТЕТА**, по каждому функциональному модулю ядра. Требования выведены из [ТЗ](../../prd.md), [брифа](../../brief.md), [блоков и разделов](../03_product/teta_platform_blocks.md), [архитектуры продукта](../06_architecture/product_architecture.md), [бизнес-правил](03_business_rules.md), [границ MVP](01_vision_scope.md) и [открытых вопросов](../01_inputs/open_questions.md).
> Нефункциональные требования — [04_nonfunctional_requirements.md](04_nonfunctional_requirements.md). Уведомления — [07_notifications.md](07_notifications.md). Релизы — [09_roadmap_releases.md](09_roadmap_releases.md). Интеграции — [05_integrations.md](05_integrations.md). Правовые требования — [06_legal_compliance.md](06_legal_compliance.md). Техническая реализация — [technical_architecture.md](../06_architecture/technical_architecture.md), модель данных — [data_model.md](../06_architecture/data_model.md).

---

## 1. Как читать документ

### 1.1. Формат требования

| Колонка | Содержание |
|---|---|
| ID | `FR-<КОД>-NNN`: `<КОД>` — код модуля из раздела 3 [архитектуры продукта](../06_architecture/product_architecture.md), `NNN` — номер внутри модуля, начиная с 001. Номера не переиспользуются: отменённое требование переводится в Won't с пояснением, а не удаляется |
| Требование | «Система должна …» — одно проверяемое поведение на строку |
| Приоритет | MoSCoW (1.2) относительно релиза в этой же строке |
| Релиз | MVP · R2 · R3 (1.3) |
| Источник | Откуда требование (1.4) |
| Связи | Бизнес-правила `BR-…`, открытые вопросы `Q-…`, разделы интерфейса (`SITE-`, `WIZ-`, `CL-`, `ROOM-`, `PRO-`, `HR-`, `WL-`, `ADM-`, `X-`), параметры `P-…` |
| Критерии приёмки | 1–3 проверяемых условия; «Дано / когда / тогда» в кратком виде |

### 1.2. Приоритет (MoSCoW)

| Значение | Смысл |
|---|---|
| **Must** | Без этого релиз не выпускается |
| **Should** | Важно; перенос внутри релиза — только по согласованию с заказчиком |
| **Could** | Желательно при наличии ресурса |
| **Won't** | Осознанно не делается в текущем горизонте; в колонке «Релиз» — самый ранний горизонт пересмотра |

### 1.3. Релизы

**MVP** — запуск; **R2** — рост и расширение ТЗ (≈ 2–3 месяца после запуска); **R3** — масштабирование, white-label, интеграции (≈ 6+ месяцев). Состав — [09_roadmap_releases.md](09_roadmap_releases.md) и раздел 11 [блоков](../03_product/teta_platform_blocks.md). Функции R2/R3 разрабатываются в том же ядре и включаются флагами функций (FR-ADMIN-003).

### 1.4. Источники

| Обозначение | Что означает |
|---|---|
| `ТЗ п.4-1` | Первый маркированный пункт раздела 4 [ТЗ](../../prd.md). У разделов 2, 4, 5, 7, 9, 10 ТЗ собственной нумерации пунктов нет — она введена в матрице 4.1 |
| `ТЗ п.6.1` | Нумерованный подпункт ТЗ |
| `Бриф п.3.1` | Пункт [брифа](../../brief.md) |
| `ДНК` | ДНК бренда ([сводка](../01_inputs/inputs_digest.md), раздел 3) |
| `Ясно sNN` | Паттерн конкурента со скриншота ([разбор](../02_competitor_analysis/yasno_screens_teardown.md)) |
| `Рек.` | Рекомендация аналитика; требует согласования с заказчиком |
| `152-ФЗ`, `54-ФЗ`, `38-ФЗ`, `63-ФЗ`, `149-ФЗ`, `376-ФЗ`, `ЗоЗПП` | Нормы права по [справке](../99_sources/research_legal_integrations.md), раздел A |

### 1.5. Приоритет источников и поправки поверх блоков

При расхождении действует порядок: **правовые оговорки в начале [бизнес-правил](03_business_rules.md)** → строки бизнес-правил → [блоки и разделы](../03_product/teta_platform_blocks.md) → паттерны «Ясно». Спорные места ссылаются на `Q-…`; до ответа заказчика требования следуют рекомендации из [реестра вопросов](../01_inputs/open_questions.md).

Поправки, которые меняют текст блоков:

| # | Поправка | Основание | Где учтено |
|---|---|---|---|
| а | Согласие на обработку сведений о здоровье — отдельный документ, подписывается простой электронной подписью (код из email). Email запрашивается на первом шаге анкеты; ответы анкеты уходят на сервер только после подписания | BR-ACC-09, Q-34 | FR-CONSENT-005, FR-CONSENT-006, FR-MATCH-007, FR-AIBOT-003 |
| б | Статья попадает в RSS для Дзена не раньше чем через 48 ч после публикации на сайте | BR-CONTENT-06 | FR-CONTENT-009 |
| в | Удержание оплаты при поздней отмене задаётся только параметром и является юридическим риском | Q-33, Q-05 | FR-BOOK-011, FR-BOOK-016 |
| г | Персональные данные собираются и хранятся только в РФ | BR-ACC-10 | FR-ADMIN-002, FR-VIDEO-002, FR-AIBOT-005, FR-FILES-005, FR-SUPPORT-002 |
| д | Отказ от автосписаний — в 1–2 действия в любой момент | BR-PAY-11 | FR-PAY-013 |

### 1.6. Общие соглашения для всех требований

1. **Время** хранится в UTC и показывается в часовом поясе просматривающего с указанием пояса (BR-SCHED-01).
2. **Параметры** `P-…` берутся из ADM-16 и меняются без выпуска новой версии (FR-ADMIN-001). В критериях приёмки значения по умолчанию указаны для примера.
3. **Тенант:** каждое требование выполняется в контексте тенанта; ТЕТА — тенант по умолчанию (FR-TENANT-002).
4. **Сведения о здоровье** — ответы анкеты, итог и текст диалога с Германом, дневник эмоций, кризисные флаги, запрос клиента в карточке психолога, заметки психолога. Доступ к ним — только по согласию клиента и по ролям; просмотр администратором — «с причиной» и в журнал аудита (FR-AUDIT-003).
5. **Тексты интерфейса** — бережные, без давления и медицинских формулировок (ДНК, Q-26); требования к тону, пустым состояниям и ошибкам — в NFR (область UX).
6. **Каналов связи клиента с психологом вне сессий нет** (ТЗ п.2-4): организационные вопросы решаются структурированными действиями и через поддержку (Q-12).
7. Термин «роль администратора» означает одну из 7 подролей: суперадмин, менеджер психологов, модератор контента, финансист, поддержка, B2B-менеджер, аналитик (раздел 9.2 [архитектуры продукта](../06_architecture/product_architecture.md)).
````

## docs/04_project_docs/04_nonfunctional_requirements.md — строки 1–45 из 319 (прочитано 2026-09-11T15:55:21.647834Z)

````text
# Нефункциональные требования

> Версия 1.0 · 11.09.2026 · Статус: черновик на утверждение
> Документ задаёт измеримые требования к качеству платформы ТЕТА. Функциональные требования — [02_functional_requirements.md](02_functional_requirements.md), бизнес-правила — [03_business_rules.md](03_business_rules.md), техническая архитектура — [technical_architecture.md](../06_architecture/technical_architecture.md), модель данных — [data_model.md](../06_architecture/data_model.md), интеграции — [05_integrations.md](05_integrations.md), правовые требования — [06_legal_compliance.md](06_legal_compliance.md), метрики — [08_analytics_metrics.md](08_analytics_metrics.md), релизы — [09_roadmap_releases.md](09_roadmap_releases.md). Регуляторная база — [справка](../99_sources/research_legal_integrations.md), раздел A и приложение 2.

---

## 1. Как читать документ

| Колонка | Содержание |
|---|---|
| ID | `NFR-<ОБЛАСТЬ>-NN`, номер внутри области с 01; номера не переиспользуются |
| Требование | Проверяемое свойство системы |
| Метрика / целевое значение | Число или однозначный критерий. **[допущение]** — ориентир аналитика для MVP; подтверждается нагрузочным тестом, юристом или заказчиком до начала разработки модуля |
| Способ проверки | Как приёмка подтверждает выполнение: автотест, нагрузочный тест, аудит конфигурации, внешний аудит, ручная проверка |
| Релиз | С какого релиза требование обязательно; требования MVP действуют и в R2, R3 |
| Источник | Как в [SRS](02_functional_requirements.md), раздел 1.4: `ТЗ п.`, `Бриф п.`, `ДНК`, `Рек.`, нормы права; а также `BR-…`, `Q-…` |

### 1.1. Ориентиры нагрузки

Базис для областей PERF, AVAIL, SCAL, VIDEO. Все значения — **[допущение]**: в материалах заказчика нет статистики текущего сайта (см. [концепцию](01_vision_scope.md), раздел 3).

| Показатель | MVP (первые 6 месяцев) | R2 | R3 |
|---|---|---|---|
| Учётных записей клиентов | 10 000 | 50 000 | 100 000+ (порог УЗ-2) |
| Активных клиентов в месяц | 1 500 | 6 000 | 15 000 |
| Активных психологов | 100 | 300 | 800 (с партнёрами) |
| Проведённых сессий в месяц | 4 000 | 15 000 | 40 000 |
| Одновременных видеосессий в пик | 30 | 120 | 300 |
| Одновременных посетителей сайта в пик | 200 | 800 | 2 000 |
| Тенантов | 1 | 1 | до 10 |

## 2. Сводка по областям

| № раздела | Область | Название | Всего | MVP | R2 | R3 |
|---|---|---|---|---|---|---|
| 3 | SEC | Безопасность приложения | 15 | 15 | 0 | 0 |
| 4 | PD | Персональные данные и 152-ФЗ | 14 | 14 | 0 | 0 |
| 5 | PERF | Производительность | 11 | 11 | 0 | 0 |
| 6 | AVAIL | Доступность и восстановление | 8 | 8 | 0 | 0 |
| 7 | SCAL | Масштабируемость | 7 | 5 | 1 | 1 |
| 8 | VIDEO | Качество видеосвязи | 10 | 9 | 1 | 0 |
| 9 | COMP | Совместимость | 8 | 8 | 0 | 0 |
| 10 | A11Y | Доступность (WCAG 2.2 AA) | 10 | 10 | 0 | 0 |
| 11 | UX | Тон, тексты и состояния | 10 | 10 | 0 | 0 |
````

## docs/06_architecture/sequences_states.md — строки 2680–2716 из 2716 (прочитано 2026-09-11T18:07:48.612865Z)

````text
| ST-18 | Подарочный сертификат | [st_gift_certificate](diagrams/st_gift_certificate.mmd) | PROMO, PAY |
| ST-19 | Участие в корпоративной программе | [st_b2b_enrollment](diagrams/st_b2b_enrollment.mmd) | B2B |
| ST-20 | Партнёрский инстанс | [st_partner_instance](diagrams/st_partner_instance.mmd) | INSTANCE |

Итого в документе 42 диаграммы: 22 последовательности и 20 машин состояний. Исходники `.mmd` совпадают с блоками документа; SVG пересобираются по [инструкции](diagrams/README.md). Исходники версии 1.0, не соответствующие решениям v2, удалены.

## Допущения по открытым вопросам

| Тема | Вопрос | Допущение до ответа | Где применено |
|---|---|---|---|
| Платёжный сервис | Q-43 | Сервис не выбран: деньги идут через абстракцию платёжного сервиса с тестовым эмулятором, контракт одинаков для эмулятора и адаптера (DEC-38) | SEQ-01, SEQ-02, SEQ-08, SEQ-10, SEQ-18, SEQ-20; ST-03, ST-04, ST-07 |
| Баланс личного кабинета клиента | Q-52 | Все возвраты — на баланс; при оплате сначала баланс, затем карта; остаток выводится на карту по заявке клиента [ЮРИСТ] | SEQ-01…SEQ-07, SEQ-18, SEQ-22; ST-01…ST-05, ST-18 |
| Супервизия для супервизоров | Q-53 | Требование действует на супервизоров, которые ведут клиентов; выплаты супервизора без клиентов не блокируются | SEQ-08, SEQ-09; ST-07, ST-09 |
| Второй участник парной сессии | Q-54 | Приглашение по email, регистрация с согласием на обработку ПДн и подтверждением 18+, вход со своей учётной записью; в его кабинете видна только эта сессия | SEQ-01, SEQ-07 |
| Оплата психологу при неявке клиента и отмене после списания | Q-56 | Начисляется 70 %, как за проведённую сессию; при возврате по жалобе начисление сторнируется | SEQ-03, SEQ-06, SEQ-07, SEQ-22; ST-01, ST-05, ST-06 |

## Расхождения с бизнес-правилами версии 2.0

Бизнес-правила дорабатываются до версии 2.1 с сохранением ID. До выхода новой редакции документ следует реестру решений; ниже — места, где редакция 2.0 расходится с ним.

| Тема | Правила и параметры версии 2.0 | Как в документе |
|---|---|---|
| Перенос после списания | BR-CANC-04, `P-LATE-RESCHEDULE-LIMIT` | Разрешён, если новая сессия начинается не раньше чем через 12 ч от момента переноса; без подтверждения психолога (DEC-56) |
| Автопостинг в Дзен | BR-CONTENT-06, `P-DZEN-DELAY` | Статья попадает в RSS-ленту сразу после публикации на сайте (DEC-46) |
| Дежурство администратора | BR-AIBOT-07, BR-SUPPORT-07, `P-SUPPORT-HOURS` | 10:00–20:00 МСК; администратор решает только очень сложные вопросы (DEC-54) |
| White-label | BR-WL-01, BR-WL-04, BR-WL-05 | Отдельный инстанс на партнёра, учёт внутри инстанса, обычные роли RBAC (DEC-52) |
| Отзывы | BR-REVIEW-01, BR-REVIEW-04, BR-REVIEW-07 | Правила DEC-40 без допущений; психолог только запрашивает повторную проверку |
| Возвраты | BR-PAY-11, BR-MATCH-10 | Возвраты зачисляются на баланс личного кабинета, на карту — при выводе остатка (DEC-48, [допущение Q-52]) |
| Оплата психологу при неявке клиента и поздней отмене | BR-PAYOUT-02 | Допущение привязано к открытому вопросу Q-56 |
| Ежемесячная супервизия | BR-PSY-07, BR-PAYOUT-06…BR-PAYOUT-08, BR-SUPERV-12, BR-SUPERV-13 | Решения DEC-37 без допущений |
| Промокоды, «Пригласи друга», сертификаты | BR-PROMO-07, BR-PROMO-11, BR-PROMO-12 | Решения DEC-42, DEC-43, DEC-57; остаток сертификата — на баланс [Рек.] |
| Почта | BR-NOTIF-08, BR-MAIL-05 | Собственный почтовый сервер (DEC-51) |
| Возраст и парная сессия | BR-ACC-12, BR-BOOK-16 | Клиенты только 18+ (DEC-49); второй участник — [допущение Q-54] |
| Корпоративный доступ | BR-B2B-03, BR-PRIV-07 | Решение DEC-53 без допущения |
| Подключение платёжного сервиса | BR-PAY-14 | Завершающим этапом после остальных модулей (DEC-38) |


````

## Вывод Bash: Fix Q-41 row, run final docs check and inventory (2026-09-11T14:35:42.890617Z)

Команда:

````bash
python3 - <<'PYEOF'
import io
p = "docs/01_inputs/open_questions.md"
lines = io.open(p, encoding="utf-8").read().split("\n")
hits = [i for i, l in enumerate(lines) if l.startswith("| Q-41 | P1 |")]
assert len(hits) == 1
i = hits[0]
cells = [c.strip() for c in lines[i].strip().strip("|").split("|")]
print("cells before:", len(cells))
assert len(cells) == 7
qid, pr, q, ctx, variants, rec, affects = cells
lines[i] = "| " + " | ".join([qid, pr, q, ctx, "Варианты: " + variants + ". " + rec, affects]) + " |"
io.open(p, "w", encoding="utf-8").write("\n".join(lines))
print("cells after:", len([c for c in lines[i].strip().strip("|").split("|")]))
PYEOF
python3 docs/_tools/check_docs.py 2>&1 | sed -n '/== 2. Идентификаторы ==/,/Ошибок/p'; python3 docs/_tools/check_docs.py 2>&1 | tail -1; echo "---"; find docs -type f \( -name "*.md" -o -name "*.xlsx" -o -name "*.docx" -o -name "*.html" -o -name "*.mm" -o -name "*.opml" -o -name "*.mmd" -o -name "*.svg" -o -name "*.json" -o -name "*.py" -o -name "*.png" -o -name "*.jpg" \) | sed 's#/[^/]*$##' | sort | uniq -c; du -sh docs; ls -la docs/04_project_docs/TETA_project_documentation.docx
````

Вывод:

````text
cells before: 7
cells after: 6
== 2. Идентификаторы ==
  Тип   Описание                       Определено  Ссылок     Без внешних ссылок
  FR    функциональные требования            426    2938                      0
  NFR   нефункциональные требования          175      94                    124
  BR    бизнес-правила                       114    1837                      2
  INT   интеграции                           192      71                    190
  Q     открытые вопросы                      41     828                      0
  RISK  риски                                 28      73                     13
  US    user stories                         216     680                    216
  xlsx: 02_competitor_analysis/feature_matrix.xlsx — ID в ячейках: 42; 03_product/backlog.xlsx — ID в ячейках: 2699; 03_product/roles_permissions.xlsx — ID в ячейках: 104; 06_architecture/sitemap.xlsx — ID в ячейках: 56
Ошибок: 0, предупреждений: 0
ERROR: 0, WARNING: 0, INFO: 1 → ошибок нет (код 0)
---
   1 docs
   2 docs/00_plan
   2 docs/01_inputs
   4 docs/02_competitor_analysis
   4 docs/03_product
  12 docs/04_project_docs
   6 docs/05_mindmap
   5 docs/06_architecture
 123 docs/06_architecture/diagrams
   5 docs/99_sources
   3 docs/99_sources/teta_sites_screens
  16 docs/99_sources/yasno_appstore_screens
  45 docs/99_sources/yasno_screens
   3 docs/_tools
 22M	docs
-rw-r--r--@ 1 dmitry  staff  1009400 Sep 11 17:34 docs/04_project_docs/TETA_project_documentation.docx
````

## Вывод Bash: Inspect roadmap structure and gantt data (2026-09-11T17:18:40.742768Z)

Команда:

````bash
cd docs/04_project_docs && grep -n "^## \|^### " 09_roadmap_releases.md | head -40; echo ----; python3 - <<'EOF'
import re
t = open("09_roadmap_releases.md", encoding="utf-8").read()
m = re.search(r"```mermaid\n(gantt.*?)```", t, re.S)
print(m.group(1)[:6000] if m else "no gantt")
EOF
````

Вывод:

````text
11:## 1. Исходные данные и допущения
13:### 1.1. Факты
33:### 1.2. Допущения о команде
50:### 1.3. Допущения о планировании
62:## 2. Положение на 11.09.2026
79:## 3. Этапы и треки
81:### 3.1. Таблица этапов
104:### 3.2. Календарный план
159:### 3.3. Загрузка потоков
178:### 3.4. Что сдвигает план
193:## 4. Трек `Доп.`
212:## 5. Трек `11.1`
228:## 6. Критический путь
288:## 7. Зависимости от заказчика
314:## 8. Демонстрации инкрементов и приёмка
316:### 8.1. Демонстрации
324:### 8.2. Приёмка этапа
350:### 8.3. Развёртывание на prod
356:## 9. Передаваемые материалы
370:## 10. Контрольные точки
----
gantt
  title Роадмап по этапам ТЗ v2 — оценка аналитика
  dateFormat YYYY-MM-DD
  axisFormat %d.%m.%y
  section Ядро и дизайн
  Ядро                                   :crit, core, 2026-09-07, 28d
  Э1 Дизайн                              :e1, 2026-09-07, 42d
  section Этапы 2–4
  Э2 Кабинет психолога                   :crit, e2, 2026-10-05, 35d
  Э3 TetaMeet сессии и групповые встречи :meet, 2026-10-05, 56d
  Э3 Клиент Герман оплаты на эмуляторе   :crit, e3, 2026-10-05, 84d
  Праздники                              :hol, 2026-12-28, 14d
  Э4 Админ-панель и RBAC                 :crit, e4, 2026-12-28, 49d
  section Этапы 5–11
  Э5 Супервизия                          :crit, e5, 2027-02-15, 28d
  Э6 Интервизия                          :e6, 2027-02-22, 28d
  Э7 Email-маркетинг                     :crit, e7, 2027-03-15, 35d
  Э8 Промокоды                           :e8, 2027-03-22, 21d
  Э9 Статьи                              :e9, 2027-04-12, 28d
  Э10 База знаний                        :crit, e10, 2027-04-19, 21d
  Э11 Техподдержка                       :crit, e11, 2027-05-10, 21d
  section Платёжный сервис
  Подключение платёжного сервиса         :crit, pay, 2027-05-31, 28d
  section Сквозные задачи
  Уведомления логирование мониторинг     :x1, 2026-09-07, 245d
  Нагрузка безопасность документация     :x2, 2027-05-10, 21d
  Собственный почтовый сервер и прогрев  :x4, 2027-05-31, 42d
  Регрессия prod передача материалов     :crit, x3, 2027-06-28, 14d
  section Доп.
  Экстренная помощь                      :d1, 2026-09-28, 7d
  Центр уведомлений и видео с мобильных  :d2, 2026-11-16, 42d
  Посадочные по запросам                 :d3, 2026-11-23, 21d
  Сертификаты и Пригласи друга           :d4, 2027-03-22, 21d
  Публичные тесты                        :d5, 2027-04-19, 21d
  section 11.1 поток В
  Дизайн HR и мероприятий                :s1, 2027-02-01, 14d
  Корпоративные тарифы и кабинет HR      :s2, 2027-02-15, 42d
  Вебинары курсы эфиры                   :s3, 2027-03-29, 42d
  section Контрольные точки
  КТ-0 документация и расчёт VDS         :milestone, m0, 2026-09-18, 0d
  КТ-2 VDS и stage                       :milestone, m2, 2026-09-28, 0d
  КТ-4 приёмка Э1                        :milestone, m4, 2026-10-16, 0d
  КТ-8 приёмка Э3                        :milestone, m8, 2026-12-25, 0d
  КТ-9 приёмка Э4                        :milestone, m9, 2027-02-12, 0d
  КТ-10 приёмка Э5                       :milestone, m10, 2027-03-12, 0d
  КТ-12 старт подключения платёжного сервиса :milestone, m12, 2027-05-31, 0d
  КТ-13 приёмка подключения платёжного сервиса :milestone, m13, 2027-06-25, 0d
  КТ-14 итоговая приёмка и prod          :milestone, m14, 2027-07-09, 0d
````

## Вывод Bash: Extract critical risks from the risk register (2026-09-11T17:19:22.680861Z)

Команда:

````bash
python3 - <<'EOF'
t = open("10_risks.md", encoding="utf-8").read().splitlines()
hdr = None
for line in t:
    if line.startswith("| ID ") and "Уровень" in line:
        hdr = [c.strip() for c in line.strip("|").split("|")]
        continue
    if line.startswith("| RISK-") and hdr:
        cells = [c.strip() for c in line.strip("|").split("|")]
        row = dict(zip(hdr, cells))
        if "Критич" in row.get("Уровень", ""):
            print(row["ID"], "|", row.get("Риск", "")[:300], "|| МЕРЫ:", row.get("Меры", "")[:420])
            print()
EOF
grep -n "Критический\|Высокий" 10_risks.md | head -5; grep -n "^| Критический\|^| Итого\|^| \*\*Итого" 10_risks.md | head
````

Вывод:

````text
RISK-02 | Объём работ по Договору (ядро, 11 этапов, TetaMeet, сквозные задачи) и решений `Доп.`, направлений `11.1` и завершающего этапа подключения платёжного сервиса не укладывается в сроки, которых ожидает заказчик; по оценке роадмапа — 44 недельных спринта, до 11.07.2027 || МЕРЫ: Роадмап по этапам с критическим путём и двумя потоками разработки; сверка с утверждённым Планом-графиком на КТ-0, при расхождении действует План-график (DEC-47); демонстрации каждую неделю и контроль скорости со спринта 3; `11.1` — отдельным потоком после Э4 [Рек.]; каждая новая просьба заказчика — запись в журнал изменений с оценкой и решением [Рек.]; при отставании больше чем на 2 спринта — перепланирование потоков

RISK-05 | К приёму клиентов на prod мало психологов с подтверждённой квалификацией и свободными слотами, особенно вечерними → пустой подбор, низкая конверсия. На 11.09.2026 в каталоге teta.su 11 психологов, свободные слоты — только у трёх ([аудит](../02_competitor_analysis/teta_current_sites_audit.md)) [Ф] || МЕРЫ: Набор психологов идёт параллельно разработке; SITE-11 публикуется по готовности Э2; целевой размер пула и доля вечерних слотов согласуются с заказчиком до КТ-10 [Рек.]; подтверждение квалификации (Э2) проходит до приёма клиентов (DEC-10); психологи текущего каталога регистрируются на платформе заново — данные текущих систем не переносятся (DEC-45); в подборе — все подходящие альтернативы и запрос «Нет подходящего вре

RISK-30 | Позднее подключение платёжного сервиса (DEC-38): сервис выбирается после реализации остальных модулей, а деньги до этого работают на абстракции с тестовым эмулятором → реальные продажи начинаются только после завершающего этапа; эмулятор может расходиться с API выбранного сервиса (токенизация, автос || МЕРЫ: Контракт адаптера — по типовым возможностям российских платёжных сервисов: токенизация и списания без участия плательщика, полные и частичные возвраты, онлайн-касса с агентскими реквизитами, выплаты на карты физлиц, вебхуки, тестовый контур [Рек.]; эмулятор реализует тот же контракт, включая отказы банка, 3-D Secure, повторы и задержки вебхуков [Рек.]; контрактные тесты адаптера проходят на эмуляторе, а на этапе подк

RISK-32 | Письма с собственного почтового сервера (DEC-51), который настраивается после реализации проекта, не доходят или попадают в спам: у нового IP-адреса нет репутации, адрес VDS мог попасть в чёрные списки раньше, записи SPF, DKIM, DMARC и обратная DNS-запись настроены с ошибками, транзакционные письма  || МЕРЫ: Настройка почтового сервера, SPF, DKIM, DMARC и обратной DNS-записи — в начале подготовки к запуску, спринт 39 ([роадмап](09_roadmap_releases.md), раздел 3.3); проверка IP по чёрным спискам до первой отправки; [ПРОВЕРИТЬ] ограничения Timeweb на исходящий порт 25 и обратную DNS-запись для VDS; прогрев IP и домена не меньше 4 недель до запуска: сначала транзакционные письма малым объёмом, затем рассылки [Рек.]; разные 

RISK-35 | Психологи уходят или становятся неактивными из-за требования ежемесячной оплачиваемой супервизии (DEC-21, DEC-37) → массовое скрытие профилей в начале месяца, закрытые новые записи и пустой подбор || МЕРЫ: Правило DEC-37: требование действует с первого полного календарного месяца после подтверждения квалификации, засчитываются индивидуальная и групповая супервизия, назначенные сессии неактивного психолога проводятся и оплачиваются; групповая супервизия как доступный по цене формат; ёмкость супервизоров — RISK-42; заблаговременные напоминания психологу о требовании месяца (PRO-12) [Рек.]; SITE-11 объясняет условие до ре

5:> Шкала: вероятность и влияние — Н (низкое) · С (среднее) · В (высокое). Уровень: **Критический** — В×В; **Высокий** — В×С, С×В; **Средний** — С×С, В×Н, Н×В; **Низкий** — остальные сочетания.
12:| Критический | 5 | RISK-02, RISK-05, RISK-30, RISK-32, RISK-35 |
13:| Высокий | 18 | RISK-01, RISK-03, RISK-04, RISK-06, RISK-08, RISK-09, RISK-15, RISK-16, RISK-22, RISK-27, RISK-31, RISK-33, RISK-34, RISK-36, RISK-39, RISK-40, RISK-41, RISK-42 |
24:| RISK-01 | Утечка персональных данных клиентов и сведений о состоянии: анкета, дневник эмоций, заметки психолога, журнал сессий, диалоги с Германом | Безопасность, право | С | В | **Высокий** | Серверы VDS Timeweb в РФ (DEC-36) и уровень защищённости ПДн по оценке юриста [ЮРИСТ]; разграничение доступа по ТЗ v2, разд. 8: заметки, дневники и история сессий недоступны другим специалистам; шифрование при передаче и шифрование чувствительных полей при хранении [Рек.]; доступ администраторов через RBAC с журналом аудита; пароли — необратимые хеши, данные карт не хранятся; сведения о состоянии не передаются в Яндекс.Метрику (DEC-24); диалоги Германа обрабатывает российская или локальная LLM на российских серверах (DEC-54) и не используются для обучения модели [Рек.]; данные партнёров white-label — в отдельных базах своих инстансов (DEC-52); проверка безопасности и тест на проникновение до prod [Рек.]; регламент реагирования на инциденты с уведомлением РКН. Ответственность — ст. 13.11 КоАП, за повторную утечку — оборотная ([справка](../99_sources/research_legal_integrations.md)) | Техлид; заказчик как оператор ПДн | Доступ к приватным данным вне роли в журнале аудита; находка проверки безопасности уровня «высокий»; обращение субъекта ПДн или РКН | DEC-24, DEC-36, DEC-52, DEC-54; AUDIT, RBAC, FILES, DIARY, CRM, AIBOT |
25:| RISK-02 | Объём работ по Договору (ядро, 11 этапов, TetaMeet, сквозные задачи) и решений `Доп.`, направлений `11.1` и завершающего этапа подключения платёжного сервиса не укладывается в сроки, которых ожидает заказчик; по оценке роадмапа — 44 недельных спринта, до 11.07.2027 | Сроки | В | В | **Критический** | Роадмап по этапам с критическим путём и двумя потоками разработки; сверка с утверждённым Планом-графиком на КТ-0, при расхождении действует План-график (DEC-47); демонстрации каждую неделю и контроль скорости со спринта 3; `11.1` — отдельным потоком после Э4 [Рек.]; каждая новая просьба заказчика — запись в журнал изменений с оценкой и решением [Рек.]; при отставании больше чем на 2 спринта — перепланирование потоков или расширение команды (роадмап, раздел 3.4) | Руководитель проекта | Фактическая скорость ниже плановой два спринта подряд; приёмка этапа отстаёт от плана больше чем на 1 спринт | DEC-01, DEC-38, DEC-39, DEC-47 |
12:| Критический | 5 | RISK-02, RISK-05, RISK-30, RISK-32, RISK-35 |
````

## Вывод Bash: Assemble FR document and run documentation check (2026-09-11T18:04:56.205099Z)

Команда:

````bash
python3 /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/fr_v2/assemble.py && python3 docs/_tools/check_docs.py 2>&1 | grep -E "ERROR|WARNING" | grep -v "^ERROR:" | head -30; python3 docs/_tools/check_docs.py 2>&1 | tail -1
````

Вывод:

````text
FR total: 738
prio: {'Must': 589, 'Should': 114, "Won't": 25, 'Could': 10}
stages: {'Ядро': 47, 'Э2': 91, 'Э3': 244, 'Э4': 125, '11.1': 49, 'Э5': 43, 'Э7': 27, 'Сквозн.': 21, 'Э11': 27, 'Доп.': 26, 'Э9': 25, 'Э6': 21, 'Э10': 20, 'Э8': 14, 'Э1': 3}
uncovered TZ items: ['11.1.3', '11.1.4']
DEC without FR: ['DEC-01', 'DEC-05', 'DEC-18', 'DEC-33', 'DEC-39', 'DEC-47', 'DEC-58']
sections without FR: ['SITE-04', 'SITE-12']
bytes: 648611
  ERROR   06_architecture/diagrams/README.md:31  битая ссылка «product_candidate_path.mmd»: не найден 06_architecture/diagrams/product_candidate_path.mmd
  ERROR   06_architecture/diagrams/README.md:31  битая ссылка «product_candidate_path.svg»: не найден 06_architecture/diagrams/product_candidate_path.svg
  ERROR   06_architecture/diagrams/README.md:33  битая ссылка «product_hr_wl_cabinets.mmd»: не найден 06_architecture/diagrams/product_hr_wl_cabinets.mmd
  ERROR   06_architecture/diagrams/README.md:33  битая ссылка «product_hr_wl_cabinets.svg»: не найден 06_architecture/diagrams/product_hr_wl_cabinets.svg
  ERROR   06_architecture/diagrams/README.md:71  битая ссылка «seq_01_booking_card.svg»: не найден 06_architecture/diagrams/seq_01_booking_card.svg
  ERROR   06_architecture/diagrams/README.md:72  битая ссылка «seq_02_charge_retries.svg»: не найден 06_architecture/diagrams/seq_02_charge_retries.svg
  ERROR   06_architecture/diagrams/README.md:73  битая ссылка «seq_02b_charge_hold_confirm.mmd»: не найден 06_architecture/diagrams/seq_02b_charge_hold_confirm.mmd
  ERROR   06_architecture/diagrams/README.md:73  битая ссылка «seq_02b_charge_hold_confirm.svg»: не найден 06_architecture/diagrams/seq_02b_charge_hold_confirm.svg
  ERROR   06_architecture/diagrams/README.md:74  битая ссылка «seq_03_client_reschedule_cancel.svg»: не найден 06_architecture/diagrams/seq_03_client_reschedule_cancel.svg
  ERROR   06_architecture/diagrams/README.md:75  битая ссылка «seq_04_psychologist_cancel_refund.mmd»: не найден 06_architecture/diagrams/seq_04_psychologist_cancel_refund.mmd
  ERROR   06_architecture/diagrams/README.md:75  битая ссылка «seq_04_psychologist_cancel_refund.svg»: не найден 06_architecture/diagrams/seq_04_psychologist_cancel_refund.svg
  ERROR   06_architecture/diagrams/README.md:76  битая ссылка «seq_05_video_outcome_accrual.mmd»: не найден 06_architecture/diagrams/seq_05_video_outcome_accrual.mmd
  ERROR   06_architecture/diagrams/README.md:76  битая ссылка «seq_05_video_outcome_accrual.svg»: не найден 06_architecture/diagrams/seq_05_video_outcome_accrual.svg
  ERROR   06_architecture/diagrams/README.md:77  битая ссылка «seq_06_weekly_payout_npd.mmd»: не найден 06_architecture/diagrams/seq_06_weekly_payout_npd.mmd
  ERROR   06_architecture/diagrams/README.md:77  битая ссылка «seq_06_weekly_payout_npd.svg»: не найден 06_architecture/diagrams/seq_06_weekly_payout_npd.svg
  ERROR   06_architecture/diagrams/README.md:78  битая ссылка «seq_07_article_dzen.mmd»: не найден 06_architecture/diagrams/seq_07_article_dzen.mmd
  ERROR   06_architecture/diagrams/README.md:78  битая ссылка «seq_07_article_dzen.svg»: не найден 06_architecture/diagrams/seq_07_article_dzen.svg
  ERROR   06_architecture/diagrams/README.md:79  битая ссылка «seq_08_diary_consent_access.mmd»: не найден 06_architecture/diagrams/seq_08_diary_consent_access.mmd
  ERROR   06_architecture/diagrams/README.md:79  битая ссылка «seq_08_diary_consent_access.svg»: не найден 06_architecture/diagrams/seq_08_diary_consent_access.svg
  ERROR   06_architecture/diagrams/README.md:80  битая ссылка «seq_09_aibot_dialog.mmd»: не найден 06_architecture/diagrams/seq_09_aibot_dialog.mmd
  ERROR   06_architecture/diagrams/README.md:80  битая ссылка «seq_09_aibot_dialog.svg»: не найден 06_architecture/diagrams/seq_09_aibot_dialog.svg
  ERROR   06_architecture/diagrams/README.md:81  битая ссылка «seq_10_b2b_employee_invoice.mmd»: не найден 06_architecture/diagrams/seq_10_b2b_employee_invoice.mmd
  ERROR   06_architecture/diagrams/README.md:81  битая ссылка «seq_10_b2b_employee_invoice.svg»: не найден 06_architecture/diagrams/seq_10_b2b_employee_invoice.svg
  ERROR   06_architecture/diagrams/README.md:82  битая ссылка «seq_11_account_deletion.mmd»: не найден 06_architecture/diagrams/seq_11_account_deletion.mmd
  ERROR   06_architecture/diagrams/README.md:82  битая ссылка «seq_11_account_deletion.svg»: не найден 06_architecture/diagrams/seq_11_account_deletion.svg
  ERROR   06_architecture/diagrams/README.md:83  битая ссылка «seq_12_health_consent_pep.mmd»: не найден 06_architecture/diagrams/seq_12_health_consent_pep.mmd
  ERROR   06_architecture/diagrams/README.md:83  битая ссылка «seq_12_health_consent_pep.svg»: не найден 06_architecture/diagrams/seq_12_health_consent_pep.svg
  ERROR   06_architecture/diagrams/README.md:89  битая ссылка «state_session.mmd»: не найден 06_architecture/diagrams/state_session.mmd
  ERROR   06_architecture/diagrams/README.md:89  битая ссылка «state_session.svg»: не найден 06_architecture/diagrams/state_session.svg
  ERROR   06_architecture/diagrams/README.md:90  битая ссылка «state_payment.mmd»: не найден 06_architecture/diagrams/state_payment.mmd
ERROR: 102, WARNING: 0, INFO: 2 → есть ошибки (код 1)
````

## Вывод Bash: Gather document counts for README and overview (2026-09-11T18:10:35.823175Z)

Команда:

````bash
cd /Users/dmitry/Projects/teta_new/docs && python3 - <<'EOF'
import re, glob, os
def cnt(path, rx):
    t = open(path, encoding="utf-8").read()
    return len(set(re.findall(rx, t, re.M)))
print("LC ids:", cnt("04_project_docs/06_legal_compliance.md", r"^\| (LC-[A-Z0-9-]+)"))
print("ADR defs:", cnt("06_architecture/technical_architecture.md", r"^#{2,4} (ADR-\d+)|^\| (ADR-\d+)"))
print("FR rows:", cnt("04_project_docs/02_functional_requirements.md", r"^\| (FR-[A-Z0-9]+-\d{3})"))
print("BR rows:", cnt("04_project_docs/03_business_rules.md", r"^\| (BR-[A-Z0-9]+-\d{2,3})"))
print("NFR rows:", cnt("04_project_docs/04_nonfunctional_requirements.md", r"^\| (NFR-[A-Z0-9]+-\d{2,3})"))
print("INT rows:", cnt("04_project_docs/05_integrations.md", r"^\| (INT-[A-Z0-9]+-\d{2,3})"))
print("N rows:", cnt("04_project_docs/07_notifications.md", r"^\| (N-[A-Z]+-\d{2})"))
print("MET rows:", cnt("04_project_docs/08_analytics_metrics.md", r"^\| (MET-\d{2})"))
print("RISK rows:", cnt("04_project_docs/10_risks.md", r"^\| (RISK-\d{2}) \|"))
print("SEQ/ST:", cnt("06_architecture/sequences_states.md", r"^## (SEQ-\d{2})"), cnt("06_architecture/sequences_states.md", r"^## (ST-\d{2})"))
print("personas scenarios F:", cnt("03_product/personas_cjm_flows.md", r"\b(F\d{2})\b"))
print("blocks sections:", cnt("03_product/teta_platform_blocks.md", r"^### ((?:SITE|WIZ|CL|ROOM|PRO|HR|ADM|X)-\d{2}[ab]?)"))
d = "06_architecture/diagrams"
mmd = sorted(os.path.basename(p)[:-4] for p in glob.glob(d + "/*.mmd"))
from collections import Counter
print("diagrams:", len(mmd), Counter(re.match(r"^(product|c4|tech|seq|st|dm)?", m).group(1) or "other" for m in mmd))
print("glossary terms rows:", cnt("04_project_docs/11_glossary.md", r"^\| \*\*([^*|]+)\*\*"))
for f in ["04_project_docs/01_vision_scope.md","03_product/personas_cjm_flows.md","02_competitor_analysis/yasno_product_analysis.md","02_competitor_analysis/yasno_screens_teardown.md","02_competitor_analysis/teta_current_sites_audit.md","01_inputs/inputs_digest.md","04_project_docs/11_glossary.md"]:
    print(f, os.path.getsize(f)//1024, "KB")
EOF
python3 _tools/build_mindmap.py 05_mindmap/teta_mindmap.md --check 2>&1 | grep "Узлов всего"
````

Вывод:

````text
LC ids: 50
ADR defs: 23
FR rows: 738
BR rows: 283
NFR rows: 172
INT rows: 172
N rows: 134
MET rows: 57
RISK rows: 33
SEQ/ST: 22 20
personas scenarios F: 23
blocks sections: 116
diagrams: 87 Counter({'product': 22, 'seq': 22, 'st': 20, 'dm': 11, 'tech': 6, 'other': 4, 'c4': 2})
glossary terms rows: 0
04_project_docs/01_vision_scope.md 77 KB
03_product/personas_cjm_flows.md 102 KB
02_competitor_analysis/yasno_product_analysis.md 166 KB
02_competitor_analysis/yasno_screens_teardown.md 56 KB
02_competitor_analysis/teta_current_sites_audit.md 40 KB
01_inputs/inputs_digest.md 69 KB
04_project_docs/11_glossary.md 70 KB
Узлов всего: 425; максимальная глубина: 4
````

## Вывод Bash: Print data model conflicts section 16.3 (2026-09-11T18:15:43.491795Z)

Команда:

````bash
cd /Users/dmitry/Projects/teta_new/docs && python3 - <<'EOF'
t = open("06_architecture/data_model.md", encoding="utf-8").read()
i = t.find("### 16.3")
j = t.find("\n## ", i + 10)
print(t[i:j if j > 0 else i + 20000][:14000])
EOF
````

Вывод:

````text
### 16.3. Решения и несостыковки, выявленные при моделировании

| ID | Наблюдение | Решение в модели | Связь |
|---|---|---|---|
| DM-01 | Срок хранения текста диалога с Германом: в бизнес-правилах — `P-AIBOT-DIALOG-TTL` (30 дней), в правовом контуре (4.3) — 12 месяцев [Рек.] | Срок задаёт параметр; метаданные диалога — 12 месяцев; переданный диалог — как обращение. Значение согласовать в обоих документах | BR-AIBOT-11 |
| DM-02 | Правовой контур (4.3) предлагает хранить чат внутри сессии 30 дней, техническая архитектура (10.2) и ответ v2.1 — не хранить | Сущности для чата нет; срок в правовом контуре удалить | DEC-07, DEC-28 |
| DM-03 | Журнал аудита: правовой контур — 3 года, техническая архитектура (14.4) — рабочее значение 1 год | 3 года, партиции по месяцам | BR-RBAC-05 |
| DM-04 | Центр уведомлений: `P-NOTIF-RETENTION` — 180 дней, CL-14 — 90 дней | Срок задаёт параметр; значение согласовать | BR-NOTIF-07 |
| DM-05 | Оценка поддержки: в BR-SUPPORT-08 — шкала из пяти значений, в CL-12 и PRO-17 — «Помог ли ответ?» да или нет | `rating_scale` и `rating_value` принимают оба варианта; выбрать одну шкалу | BR-SUPPORT-08 |
| DM-06 | События писем: техническая архитектура (12.3) — 13 месяцев, правовой контур — 12 месяцев | ТЕХ: 12 месяцев | BR-MAIL-11 |
| DM-07 | BR-DIARY-04 позволяет клиенту скрыть динамику или открыть всю историю, а DEC-41, PRO-05 и PRO-06 — без переключателя видимости | Поля видимости нет: доступ вычисляется по карточке клиента | DEC-41, BR-DIARY-04 |
| DM-08 | Доступ психолога к динамике дневника: в BR-DIARY-03 ограничен сроком после последней сессии, по DEC-41 — у психолога, к которому клиент записан или у которого проходил сессии; после смены психолога — только за период работы (PRO-05) | `client_card.access_until` — дата смены психолога или отметки «работа завершена»; ограничение сроком не применяется до согласования | DEC-41, BR-DIARY-03 |
| DM-09 | Часы супервизии: BR-SUPERV-10 — плановая длительность посещённых встреч, PRO-12 — фактическая длительность | Хранятся обе величины, засчитывается `counted_hours` по бизнес-правилу | BR-SUPERV-10 |
| DM-10 | Лист ожидания интервизии: BR-INTERV-06 — предложение места с удержанием, PRO-14 — автоматическая запись первого в очереди | Статусы `offered` и `enrolled` поддерживают оба варианта; выбрать один | BR-INTERV-06 |
| DM-11 | Комментарии интервизии: параметр ADM-16 по умолчанию — премодерация, PRO-14 — публикация сразу и модерация по жалобам [Рек.] | Статус `on_moderation` и источник `complaint` в объекте модерации поддерживают оба режима | BR-INTERV-02 |
| DM-12 | Статусы рекомендации: BR-RECO-03 — «новая, просмотрена, выполнена», PRO-07 — «доставлена, просмотрена, выполнена», черновик и отзыв до просмотра | draft, delivered, viewed, done, withdrawn | BR-RECO-03 |
| DM-13 | Часы дежурства администратора задаёт параметр `P-ADMIN-DUTY-HOURS` (10:00–20:00 МСК, DEC-54); в BR 2.0 рабочие часы были шире | Расписание смен — `admin_duty_shift`, по умолчанию в пределах `P-ADMIN-DUTY-HOURS`; расхождение снято в BR 2.1 | DEC-54, BR-AIBOT-07 |
| DM-14 | BR-PROMO-12 погашает сертификат индивидуальным промокодом, DEC-43 — сертификат является предоплатой, а не скидкой | Отдельные `gift_certificate` и `gift_redemption`; погашение — источник оплаты платежа, а не применение промокода; начисление психологу не уменьшается | DEC-43, DEC-57 |
| DM-15 | Кому достаётся удержанная сумма при неявке клиента и отмене после списания — открытый вопрос | [допущение Q-56]: `psychologist_accrual.basis_type` client_no_show и late_cancel_by_client; при возврате по жалобе — сторно | DEC-20, DEC-23, BR-PAYOUT-02 |
| DM-16 | BR-PAY-11 возвращает деньги на карту списания, DEC-48 и допущение по балансу — на баланс личного кабинета | `refund.destination`: для клиента по умолчанию balance [допущение Q-52]; source_card — для вывода остатка и возвратов, требующих карты | DEC-48, BR-PAY-11 |
| DM-17 | Правило переноса после списания в BR 2.0 ограничивает число переносов и требует подтверждения психолога, а DEC-56 — нет | Модель по DEC-56: `session_reschedule.after_charge`, проверка «не раньше чем через 12 ч от момента переноса», `payment_moved` | DEC-56 |
| DM-18 | Параметр задержки RSS-ленты Дзена в BR 2.0 противоречит DEC-46 | `dzen_publication.feed_available_at` — время публикации; поля задержки нет | DEC-46 |
| DM-19 | Правила white-label и уникальности email в BR 2.0 описывают партнёров внутри одной базы | Противоречит DEC-52: колонок принадлежности партнёру нет, email уникален в инстансе, реестр — только технический (1.7) | DEC-52 |
| DM-20 | Порог временной блокировки входа в BR (`P-LOGIN-ATTEMPTS`) и в технической архитектуре (14.2) различается | Счётчики в `user_credential`; порог — параметр бизнес-правил | BR-ACC-05 |
| DM-21 | Напоминания о супервизии: `P-SUPERV-REMINDERS` и ADM-15 указывают разные дни | `supervision_month_requirement.reminders_sent` хранит факт отправки; расписание — параметр | BR-SUPERV-12 |
| DM-22 | BR-BOOK-16 допускает второго участника пары по разовому приглашению с принятием документов перед входом, а допущение по открытому вопросу — регистрацию партнёра с учётной записью | `pair_invitation` и `session_participant` с ролью pair_partner; партнёр — обычный пользователь 18+ [допущение Q-54] | DEC-49, BR-BOOK-16 |
| DM-23 | Не определено, действует ли требование ежемесячной супервизии на супервизоров | `supervisor_profile.leads_clients` и `supervision_month_requirement.applies`: для супервизора без клиентов требование не применяется [допущение Q-53] | DEC-37 |
| DM-24 | Границы ценовых категорий заданы в рублях с «до 5 499 ₽» | Нижняя граница хранится включительно, верхняя — не включительно, в копейках; категория пересчитывается при изменении цены или границ | DEC-55, BR-PRICE-03 |
| DM-25 | Карта для выплат и карта для оплаты супервизии могут совпадать только при поддержке сервиса (PRO-09) | Отдельные `payout_card` и `payment_method` с назначением supervision_payment; объединение — после выбора сервиса | Q-43 |
| DM-26 | Правовой контур предполагает ИНН психолога для агентского чека, а платформа не собирает чеки налогового режима психолога | `psychologist.inn` — необязательное поле, включается, если бухгалтер подтвердит агентские реквизиты [ПРОВЕРИТЬ] | BR-PAY-07, BR-PAYOUT-11 |
| DM-27 | В перечне поддоменов нет сущностей, без которых не выполняются бизнес-правила и разделы интерфейса | Добавлены: ссылки из писем и запросы субъекта ПДн (3), производственный календарь и справочник причин (4), публичный профиль (5), синонимы запросов (6), инцидент качества (7), заявка на вывод остатка (9), заявка компании и пользователь HR (10), отзывы, обратная связь и модерация (12.4), дежурство администратора (13.2), служебные таблицы событий и вебхуков (15) | DEC-17, DEC-23, DEC-54 |

### 16.4. Диаграммы модели данных

Исходники — в [папке диаграмм](diagrams/README.md); блок в документе — источник истины, файл `.mmd` повторяет его содержимое. SVG-рендеры пересобираются по порядку, описанному в README папки.

| Файл | Раздел | Что показывает | Изменение в v2 |
|---|---|---|---|
| [dm_subdomains_map](diagrams/dm_subdomains_map.mmd) | 2 | Поддомены, модули и зависимости данных | Обновлена |
| [dm_er_access_consent](diagrams/dm_er_access_consent.mmd) | 3 | Пользователь, учётные данные, сеансы, RBAC, документы, согласия, печатная форма, приглашение участника пары | Обновлена |
| [dm_er_instance](diagrams/dm_er_instance.mmd) | 4 | Настройки инстанса, параметры правил, реестр партнёрских инстансов | Новая |
| [dm_er_psychologists](diagrams/dm_er_psychologists.mmd) | 5 | Профиль, квалификация, видеовизитка, подходы, цены и категории, активность, профиль супервизора | Обновлена |
| [dm_er_matching](diagrams/dm_er_matching.mmd) | 6 | Запросы, анкета, итог диалога, подборка | Обновлена |
| [dm_er_schedule_sessions](diagrams/dm_er_schedule_sessions.mmd) | 7 | График, слоты, сессия и её события, запрос времени, TetaMeet | Обновлена |
| [dm_er_care](diagrams/dm_er_care.mmd) | 8 | Дневник, карточка клиента, приватные заметки, рекомендации | Обновлена |
| [dm_er_money](diagrams/dm_er_money.mmd) | 9.1 | Карта, списание, платёж, баланс клиента, возврат, жалоба, чек, промокоды, сертификаты | Обновлена |
| [dm_er_payout](diagrams/dm_er_payout.mmd) | 9.2 | Начисления, баланс психолога, реестр и выплаты, оплата супервизии | Новая |
| [dm_er_b2b](diagrams/dm_er_b2b.mmd) | 10 | Компания, программа и условия, участие, лимит, счёт, акт, отчёт HR | Обновлена |
| [dm_er_supervision_intervision](diagrams/dm_er_supervision_intervision.mmd) | 11 | Супервизия, требование месяца, сообщество и группы интервизии | Новая |
| [dm_er_content_moderation](diagrams/dm_er_content_moderation.mmd) | 12 | Статьи и Дзен, база знаний, CMS, отзывы, модерация | Обновлена |
| [dm_er_mailing](diagrams/dm_er_mailing.mmd) | 13.1 | Уведомления, письма, рассылки, доставка, подавление, подписки | Новая |
| [dm_er_support_aibot](diagrams/dm_er_support_aibot.mmd) | 13.2 | Диалог с Германом, база знаний Германа, передача, обращения | Новая, заменяет диаграмму поддержки v1 |
| [dm_er_events](diagrams/dm_er_events.mmd) | 14 | Мероприятия, занятия, регистрации, участие | Новая |
| [dm_er_platform](diagrams/dm_er_platform.mmd) | 15 | Аудит, файлы, выгрузки, интеграции, outbox и вебхуки | Обновлена |
````

## Вывод Bash: Generate diagrams README and verify SVGs and doc check (2026-09-11T18:17:23.188459Z)

Команда:

````bash
tail -3 /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/tasks/be1ztj2t4.output; python3 /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/mmdc/gen_diagrams_readme.py; cd /Users/dmitry/Projects/teta_new/docs/06_architecture/diagrams && for s in *.svg; do [[ -f "${s%.svg}.mmd" ]] || echo "orphan svg: $s"; done; for m in *.mmd; do [[ -f "${m%.mmd}.svg" ]] || echo "no svg: $m"; done; find . -name "*.svg" -newer /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/mmdc/mermaid_brand.json | wc -l; ls *.mmd | wc -l; cd /Users/dmitry/Projects/teta_new && python3 docs/_tools/check_docs.py 2>&1 | grep -E "^\s+(ERROR|WARNING)" | head -12; python3 docs/_tools/check_docs.py 2>&1 | tail -1
````

Вывод:

````text
no failures

[exited with code 0]
diagrams: 92 unmatched to doc blocks: []
      92
      92
  ERROR   ссылка на неопределённый ID BR-CRISIS-01: 03_product/backlog.xlsx [Истории!F94], 03_product/backlog.xlsx [Истории!F183], 03_product/backlog.xlsx [Истории!F185]
  ERROR   ссылка на неопределённый ID BR-CRISIS-02: 03_product/backlog.xlsx [Истории!F183], 03_product/backlog.xlsx [Истории!F184]
  ERROR   ссылка на неопределённый ID BR-CRISIS-03: 03_product/backlog.xlsx [Истории!F185], 03_product/backlog.xlsx [Истории!F186]
  ERROR   ссылка на неопределённый ID BR-CRISIS-04: 03_product/backlog.xlsx [Истории!F29], 03_product/backlog.xlsx [Истории!F186], 03_product/backlog.xlsx [Истории!F187]
  ERROR   ссылка на неопределённый ID BR-CRISIS-05: 03_product/backlog.xlsx [Истории!F46], 03_product/backlog.xlsx [Истории!F184]
  ERROR   ссылка на неопределённый ID FR-COMM-001: 03_product/backlog.xlsx [Истории!D161], 03_product/backlog.xlsx [Истории!L161], 03_product/backlog.xlsx [Трассировка FR→US!A326]
  ERROR   ссылка на неопределённый ID FR-COMM-002: 03_product/backlog.xlsx [Истории!D161], 03_product/backlog.xlsx [Истории!L161], 03_product/backlog.xlsx [Трассировка FR→US!A327]
  ERROR   ссылка на неопределённый ID FR-COMM-003: 03_product/backlog.xlsx [Истории!D161], 03_product/backlog.xlsx [Истории!L161], 03_product/backlog.xlsx [Трассировка FR→US!A328]
  ERROR   ссылка на неопределённый ID FR-COMM-004: 03_product/backlog.xlsx [Истории!D161], 03_product/backlog.xlsx [Трассировка FR→US!A329]
  ERROR   ссылка на неопределённый ID FR-COMM-005: 03_product/backlog.xlsx [Истории!D162], 03_product/backlog.xlsx [Истории!L162], 03_product/backlog.xlsx [Истории!L162], 03_product/backlog.xlsx [Трассировка FR→US!A330]
  ERROR   ссылка на неопределённый ID FR-COMM-006: 03_product/backlog.xlsx [Истории!D162], 03_product/backlog.xlsx [Истории!L162], 03_product/backlog.xlsx [Трассировка FR→US!A331]
  ERROR   ссылка на неопределённый ID FR-COMM-007: 03_product/backlog.xlsx [Истории!D163], 03_product/backlog.xlsx [Истории!L163], 03_product/backlog.xlsx [Истории!L163], 03_product/backlog.xlsx [Трассировка FR→US!A332]
ERROR: 51, WARNING: 0, INFO: 2 → есть ошибки (код 1)
````

## Вывод Bash: Scan docs for removed terms from the checklist (2026-09-11T18:31:10.658384Z)

Команда:

````bash
cd /Users/dmitry/Projects/teta_new/docs && python3 - <<'EOF'
import re, os, glob
terms = r"Oswald|Jost|\bMVP\b|\bR2\b|\bR3\b|iCal|Яндекс ID|VK ID|Apple Health|Health Connect|Google Fit|2FA|двухфактор|Jivo|DashaMail|LiveKit|Jitsi|CloudPayments|CloudKassir|КлаудКассир|NestJS|pg-boss|Консоль\.Про|Solar Staff|Jump\.Finance|только звук|журнал подключений|Цели работы|лимит дохода|Не медицинская помощь|скрининг|CRISIS|[Кк]абинет партнёра|\bWL-\d|сведени\w+ о здоровье|специальн\w+ категори|\bПЭП\b|RISK-28|агрегат\w* по группам|от 5 человек|k-анонимн|AI-помощник|2 альтернатив|13 тем|[Мм]етоды с пояснениями|Темы и состояние|\bVIDEO\b|\bCOMM\b|TENANT|тенант|мультиаренд|Администратор партнёра|SMTP-релей|16\+|ответ[а-я]* психолога на отзыв|по отдельному соглашению"
rx = re.compile(terms)
skip_files = {"01_inputs/tz_v2.md", "01_inputs/decisions_v2.md"}
hits = {}
for p in sorted(glob.glob("**/*.md", recursive=True)):
    if p.startswith(("99_sources", "_tools")) or p in skip_files:
        continue
    for i, line in enumerate(open(p, encoding="utf-8"), 1):
        if "Изменения v2" in line or "check_docs: ignore" in line:
            continue
        for m in rx.finditer(line):
            hits.setdefault((p, m.group(0)), []).append((i, line.strip()[:160]))
by_file = {}
for (p, t), lst in hits.items():
    by_file.setdefault(p, []).append((t, len(lst), lst[0]))
for p, items in by_file.items():
    print("==", p, sum(n for _, n, _ in items))
    for t, n, (ln, s) in sorted(items, key=lambda x: -x[1])[:8]:
        print("   %-28s x%-3d L%d: %s" % (t, n, ln, s))
EOF
````

Вывод:

````text
== 00_plan/work_plan.md 23
   MVP                          x6   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
   R2                           x2   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
   R3                           x2   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
   Jivo                         x2   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
   DashaMail                    x2   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
   мультиаренд                  x2   L35: **v2.1 (11.09.2026, в ходе доработки):** заказчик ответил на открытые вопросы — реестр решений дополнен DEC-36…DEC-58; в реестре вопросов открыты Q-43 и новые в
   iCal                         x1   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
   2FA                          x1   L10: **Главные изменения:** полноценный продукт по этапам ТЗ вместо MVP/R2/R3; стек Laravel + Next.js + PostgreSQL + Redis; видео — TetaMeet; собственные email-рассы
== 01_inputs/inputs_digest.md 13
   Jivo                         x4   L130: | Поддержка | «Чат поддержки через Jivo» | Собственный виджет «по механике, аналогичной Jivo»; обращения обрабатывает администратор, отдельной роли оператора не
   КлаудКассир                  x2   L128: | Оплата | «Оплата через КлаудКассир»; «подписка»: разовое добавление карты и списание за 12 ч | Платёжный сервис определяет заказчик; разовое добавление карты,
   ответа психолога на отзыв    x2   L132: | Отзывы | Профили психологов с отзывами | Отзыв публикуется после модерации; рейтинги не реализуются | DEC-17, DEC-32; отзыв — только после проведённой сессии,
   Apple Health                 x1   L102: | 11.1 | Интеграция с Apple Health и Google Fit; нативные приложения для iOS и Android | Не проектируются (DEC-09, DEC-08) |
   Google Fit                   x1   L102: | 11.1 | Интеграция с Apple Health и Google Fit; нативные приложения для iOS и Android | Не проектируются (DEC-09, DEC-08) |
   NestJS                       x1   L123: | Backend | Node.js (NestJS/Express) | Laravel 13 | DEC-06 |
   Jitsi                        x1   L127: | Видео | WebRTC/Jitsi | TetaMeet на основе DevMeet: 1:1 и групповые сессии, выбор устройств, демонстрация экрана, чат, комната ожидания и допуск, проверка обор
   DashaMail                    x1   L180: | 7.3 Аккаунты | «КлаудКассир и Jivo есть, также предположительно есть DashaMail» | Не используются: платёжный сервис определяет заказчик (DEC-06); техподдержка
== 01_inputs/open_questions.md 5
   ответа психолога на отзыв    x1   L51: | Q-10 | Правила отзывов | Только после проведённой сессии; имя или псевдоним без фамилии; отклонение только по правилам, не за негатив; ответа психолога на отз
   Не медицинская помощь        x1   L67: | Q-26 | Юрлицо и формулировки услуг | ИП на УСН — ИП Иващенко; услуга — психологическое консультирование, формулировки по глоссарию; блок «Не медицинская помощ
   2FA                          x1   L70: | Q-29 | Способ входа | Email и пароль с подтверждением email и восстановлением; без 2FA, одноразовых кодов и соцвходов | Решено по ТЗ v2 | DEC-29 |
   Apple Health                 x1   L73: | Q-32 | Apple Health и Google Fit | Не проектируется | Снят заказчиком | DEC-09 |
   Google Fit                   x1   L73: | Q-32 | Apple Health и Google Fit | Не проектируется | Снят заказчиком | DEC-09 |
== 02_competitor_analysis/teta_current_sites_audit.md 16
   Jivo                         x8   L8: > Разделы 1–4 описывают системы «как есть». Tilda, OnDoc, Jivo, CloudPayments и шрифты текущих сайтов упоминаются как наблюдения, а не как решения ТЕТА. Решения
   CloudPayments                x5   L8: > Разделы 1–4 описывают системы «как есть». Tilda, OnDoc, Jivo, CloudPayments и шрифты текущих сайтов упоминаются как наблюдения, а не как решения ТЕТА. Решения
   CloudKassir                  x1   L142: - Не найдено: CloudKassir и DashaMail на сайте (CloudPayments — только в ЛК OnDoc), GA/GTM, пиксели, мессенджеры.
   DashaMail                    x1   L142: - Не найдено: CloudKassir и DashaMail на сайте (CloudPayments — только в ЛК OnDoc), GA/GTM, пиксели, мессенджеры.
   16+                          x1   L173: | Возраст | 16+ (в правилах teta.su — 18+) |
== 02_competitor_analysis/yasno_product_analysis.md 16
   16+                          x2   L273: | Для пары | Созависимость, развод, измена, рождение детей, усыновление, сексуальные отношения, детско-родительские отношения (16+) | 7 | 7 (№ 37–43): рождение 
   2FA                          x2   L761: **Итог:** 124 функции — «Делаем» 96, «Рекомендация» 1, «Не делаем» 27. Из 89 функций, которые у «Ясно» есть полностью или частично, ТЕТА делает 70. У 20 функций
   Jivo                         x2   L871: | Поддержка | FM-107 | Сторонний виджет онлайн-чата (Jivo и аналоги) | ✔ | **Не делаем** | — | X-06 | DEC-09 | Виджет техподдержки — собственная реализация по м
   сведения о здоровье          x1   L613: | Данные о состоянии клиента | Политика: сведения о здоровье «не обрабатываются»; при этом собираются темы запросов, взгляды и ценности | Позиция уязвима из-за 
   кабинет партнёра             x1   L761: **Итог:** 124 функции — «Делаем» 96, «Рекомендация» 1, «Не делаем» 27. Из 89 функций, которые у «Ясно» есть полностью или частично, ТЕТА делает 70. У 20 функций
   скрининг                     x1   L783: | Подбор | FM-019 | Кризисный скрининг в анкете | ✘ | **Не делаем** | — | SITE-15 | DEC-13 | Кризисный протокол размещён на странице «Экстренная помощь» |
   iCal                         x1   L824: | Кабинет клиента | FM-060 | Добавить в календарь (экспорт iCal) | ✔ | **Не делаем** | — | — | DEC-09 | Экспорт календаря не проектируется; вместо него — письмо
   Кабинет партнёра             x1   L869: | B2B | FM-105 | Кабинет партнёра white-label | ✘ | **Не делаем** | — | ADM-21 | DEC-52, DEC-09 | Отдельного кабинета нет: партнёр работает в админ-панели своег
== 03_product/personas_cjm_flows.md 1
   ответа психолога на отзыв    x1   L514: - Отзыв можно оставить только после проведённой сессии; подпись — имя или псевдоним без фамилии; отклоняют отзыв только по опубликованным правилам, а не за нега
== 04_project_docs/01_vision_scope.md 1
   Кабинет партнёра             x1   L276: | Кабинет партнёра white-label и отдельная роль партнёра; данные партнёров в общей базе | Отдельный инстанс платформы на отдельном сервере для каждого партнёра;
== 04_project_docs/02_functional_requirements.md 19
   WL-0                         x8   L178: | FR-AUTH-020 | Система должна вести учётные записи и сеансы каждого инстанса независимо: учётная запись одного инстанса не аутентифицируется на домене другого 
   VIDEO                        x2   L449: | FR-PSY-014 | Система должна позволять загрузить необязательную видеовизитку по ограничениям `P-VIDEO-CARD-LIMITS`, публиковать её после модерации в ADM-06 и х
   Apple Health                 x2   L1366: | 11.1.3 | Интеграция с Apple Health и Google Fit. | Не делается: интеграции с Apple Health и Google Fit исключены (DEC-09) |
   Google Fit                   x2   L1366: | 11.1.3 | Интеграция с Apple Health и Google Fit. | Не делается: интеграции с Apple Health и Google Fit исключены (DEC-09) |
   только звук                  x1   L589: | FR-MEET-035 | Отдельный режим «только звук» при слабой связи | Won't | Э3 | DEC-07 | INT-MEET-15; ROOM-03 | 1) В интерфейсе нет переключения в звуковой режим.
   Цели работы                  x1   L662: | FR-CRM-015 | Раздел «Цели работы» с клиентом в карточке клиента | Won't | Э2 | DEC-09 | PRO-05 | 1) В карточке клиента нет раздела и полей целей работы. 2) AP
   Jivo                         x1   L1354: | Э11.1 | Виджет чата поддержки в кабинетах клиента и психолога (собственная реализация по механике, аналогичной Jivo). | FR-SUPPORT-001, FR-SUPPORT-004 |
   скрининг                     x1   L1383: | 11 | DEC-13 · В анкете нет скрининга безопасности и кризисного протокола; кризисный протокол размещён на сайте | FR-MATCH-027, FR-AIBOT-016, FR-MEET-027, FR-S
== 04_project_docs/03_business_rules.md 16
   WL-0                         x12  L182: | BR-ACC-01 | Учётная запись идентифицируется email. Один email — одна учётная запись в пределах инстанса платформы: основного инстанса ТЕТА или инстанса партнё
   VIDEO                        x3   L164: | `P-VIDEO-CARD-LIMITS` | MP4, до 2 мин, до 100 МБ | Формат, длительность и размер видеовизитки психолога | ADM-26 | DEC-44; значения — [Рек.] | Э2 |
   ответов психолога на отзыв   x1   L475: | BR-REVIEW-07 | Психолог не может удалить или скрыть отзыв. Он может только запросить повторную проверку отзыва модератором по опубликованным правилам (BR-REVI
== 04_project_docs/05_integrations.md 11
   Jivo                         x1   L80: | Jivo | Собственный виджет техподдержки, модуль SUPPORT (Э11) | DEC-09 |
   DashaMail                    x1   L81: | DashaMail | Собственный модуль email-маркетинга MAILING; отправка по SMTP через собственный почтовый сервер проекта (раздел 8) | DEC-06, DEC-09, DEC-51 |
   CloudPayments                x1   L82: | CloudPayments и CloudKassir | Платёжный сервис заказчика и его касса или отдельная касса, подключаемые завершающим этапом; до этого — тестовый эмулятор (разде
   CloudKassir                  x1   L82: | CloudPayments и CloudKassir | Платёжный сервис заказчика и его касса или отдельная касса, подключаемые завершающим этапом; до этого — тестовый эмулятор (разде
   iCal                         x1   L84: | iCal, Яндекс ID, VK ID, Apple Health, Health Connect | Нет | DEC-09, DEC-29 |
   Яндекс ID                    x1   L84: | iCal, Яндекс ID, VK ID, Apple Health, Health Connect | Нет | DEC-09, DEC-29 |
   VK ID                        x1   L84: | iCal, Яндекс ID, VK ID, Apple Health, Health Connect | Нет | DEC-09, DEC-29 |
   Apple Health                 x1   L84: | iCal, Яндекс ID, VK ID, Apple Health, Health Connect | Нет | DEC-09, DEC-29 |
== 06_architecture/sequences_states.md 2
   WL-0                         x2   L1630: **Связанные правила:** DEC-36, DEC-38, DEC-51, DEC-52, BR-WL-02, BR-WL-03, BR-RBAC-02.
== 06_architecture/technical_architecture.md 11
   только звук                  x2   L779: | Медиа | Камера, микрофон, выбор устройства, демонстрация экрана (ТЗ v2). Отдельного режима «только звук» нет: комната всегда запускается как видеозвонок (DEC-
   NestJS                       x1   L1686: | NestJS | Laravel 13 | DEC-06 |
   pg-boss                      x1   L1687: | pg-boss | Redis, Laravel Horizon и Scheduler | DEC-06 |
   LiveKit                      x1   L1688: | LiveKit, Jitsi | TetaMeet | DEC-06, DEC-07 |
   Jitsi                        x1   L1688: | LiveKit, Jitsi | TetaMeet | DEC-06, DEC-07 |
   CloudPayments                x1   L1689: | CloudPayments, CloudKassir | Абстракция платёжного сервиса с тестовым эмулятором; реальный сервис и касса — на завершающем этапе (Q-43) | DEC-06, DEC-38 |
   CloudKassir                  x1   L1689: | CloudPayments, CloudKassir | Абстракция платёжного сервиса с тестовым эмулятором; реальный сервис и касса — на завершающем этапе (Q-43) | DEC-06, DEC-38 |
   Jivo                         x1   L1690: | Jivo | Собственный виджет техподдержки (SUPPORT) | DEC-09 |
== brand/README.md 2
   Oswald                       x1   L41: **Onest** — единственный шрифт платформы: заголовки, текст, интерфейсы, email-письма, документы, презентации. Файлы шрифта подключаются с серверов проекта, без 
   Jost                         x1   L41: **Onest** — единственный шрифт платформы: заголовки, текст, интерфейсы, email-письма, документы, презентации. Файлы шрифта подключаются с серверов проекта, без
````

## Вывод Bash: Re-render diagrams changed after last render, regenerate README (2026-09-11T18:47:14.669467Z)

Команда:

````bash
cd /Users/dmitry/Projects/teta_new/docs/06_architecture/diagrams && changed=$(for m in *.mmd; do s="${m%.mmd}.svg"; if [[ ! -f "$s" || "$m" -nt "$s" ]]; then echo "${m%.mmd}"; fi; done | tr '\n' '|' | sed 's/|$//'); echo "changed: $changed"; if [[ -n "$changed" ]]; then /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/mmdc/render_v2.sh "^($changed)$" 2>&1 | tail -3; fi; python3 /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/mmdc/gen_diagrams_readme.py
````

Вывод:

````text
changed: dm_er_care|dm_er_money|dm_er_support_aibot|seq_18_gift_certificate|st_client_balance|st_gift_certificate
--- summary (/private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/mmdc/results_v2_214716.txt)
6
no failures
diagrams: 92 unmatched to doc blocks: []
````

## Вывод Bash: Fix broken FR table, rebuild DOCX, run doc check (2026-09-11T19:17:19.588432Z)

Команда:

````bash
python3 - <<'EOF'
p = "docs/04_project_docs/02_functional_requirements.md"
lines = open(p, encoding="utf-8").read().split("\n")
removed = []
out = []
for i, line in enumerate(lines):
    if line.strip() == "" and 0 < i < len(lines) - 1 and lines[i-1].startswith("| FR-") and lines[i+1].startswith("| FR-"):
        removed.append(i + 1)
        continue
    out.append(line)
open(p, "w", encoding="utf-8").write("\n".join(out))
print("removed blank lines inside FR tables at:", removed)
EOF
uv run --with python-docx python3 /private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/docx/v2/build.py 2>&1 | tail -12; ls -la docs/04_project_docs/TETA_project_documentation.docx; python3 docs/_tools/check_docs.py 2>&1 | tail -1
````

Вывод:

````text
removed blank lines inside FR tables at: [520]
Глав: 16 + приложений: 4; H2: 67; H3: 45; таблиц: 102; рисунков: 12; секций: 12 (PLPLPLPLPLPL)
Шрифты в XML: {'Onest': 691}; ссылок на шрифты темы: 0
Старые цвета 423670/6B6385: 0; цвета документа: F4F4F7×5628, 4D427A×1390, FFFFFF×459, 797979×152, E6E5EC×86, 313131×1
Титул: логотип-изображение есть, строки титула все; колонтитул 'ТЕТА · Проектная документация · версия 2.1', PAGE есть, TOC есть
Реестры: FR (прил. А): 738 (в источнике 738); NFR (прил. Б): 172 (в источнике 172); DEC (гл. 3): 58 (в источнике 58); RISK (гл. 14, реестр): 34 (в источнике 34); BR (прил. В): 283 (в источнике 283); Параметры P- (гл. 8): 92 (в источнике 92); Решённые вопросы (прил. Г): 49 (в источнике 49); Открытые вопросы (гл. 2): 6 (в источнике 6)
Запрещённые слова (раздел 8 реестра, 65 терминов): упоминание с отрицанием — 2, цитата реестра решений или вопросов — 47, цитата ТЗ v2 в трассировке — 5, требование Won't (осознанно не делается) — 4
  с отрицанием: 5. Границы продукта: «Кабинет партнёра» — …Кабинет партнёра white-label и отдельная роль…
  с отрицанием: 18. Реестр архитектурных решений (ADR): «только звук» — …Сессии не записываются, отдельного режима «только звук» нет: компоненты записи не ра…
Рисунки: Рисунок 1 — Контуры продукта (L1): интерфейсы, единый API, функциональное ядро, интеграции и инфраструктура | Рисунок 2 — Зависимости функциональных модулей ядра | Рисунок 3 — Дерево разделов публичного сайта | Рисунок 4 — Ежемесячная супервизия и активность профиля психолога | Рисунок 5 — Сквозной цикл денег | Рисунок 6 — White-label: отдельный инстанс платформы для каждого партнёра | Рисунок 7 — Дерево метрик | Рисунок 8 — Календарный план по этапам ТЗ v2 — оценка аналитика | Рисунок 9 — Критический путь роадмапа | Рисунок 10 — C4, уровень 2 — контейнеры инстанса платформы | Рисунок 11 — Топология окружения prod | Рисунок 12 — Последовательность SEQ-02 — автосписание за 12 часов
Пустых разделов: 0; остатков Markdown: нет
Оценка страниц: ~224
ПРОБЛЕМ НЕ НАЙДЕНО
-rw-r--r--@ 1 dmitry  staff  5243998 Sep 11 22:17 docs/04_project_docs/TETA_project_documentation.docx
ERROR: 0, WARNING: 0, INFO: 2 → ошибок нет (код 0)
````
