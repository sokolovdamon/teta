# Проектная документация платформы ТЕТА

> Версия 2.1 · 11.09.2026 · Статус пакета: **черновик на утверждение заказчиком**
> Изменения v2: пакет переработан по указаниям заказчика, ответам на вопросы и ТЗ v2. Описан полноценный продукт по этапам ТЗ вместо MVP/R2/R3. Все решения собраны в [реестре решений](01_inputs/decisions_v2.md).
> Код платформы на этом этапе не пишется: разработка стартует после утверждения документации.

## С чего начать

| Кому | Читать в первую очередь |
|---|---|
| Заказчику | [Реестр решений](01_inputs/decisions_v2.md) → [Открытые вопросы](01_inputs/open_questions.md) → [Концепция и границы](04_project_docs/01_vision_scope.md) → [Блоки и разделы платформы](03_product/teta_platform_blocks.md) → [Роадмап по этапам ТЗ](04_project_docs/09_roadmap_releases.md) |
| Дизайнеру | [Фирменные материалы](brand/README.md) → [Блоки и разделы](03_product/teta_platform_blocks.md) → [Персоны и сценарии](03_product/personas_cjm_flows.md) → [Разбор экранов «Ясно»](02_competitor_analysis/yasno_screens_teardown.md) → [Карта сайта](06_architecture/sitemap.xlsx) |
| Разработке | [Архитектура продукта](06_architecture/product_architecture.md) → [Техническая архитектура](06_architecture/technical_architecture.md) → [Функциональные требования](04_project_docs/02_functional_requirements.md) → [Бизнес-правила](04_project_docs/03_business_rules.md) → [Модель данных](06_architecture/data_model.md) → [Последовательности и состояния](06_architecture/sequences_states.md) |
| Юристу и бухгалтеру | [Юридический контур](04_project_docs/06_legal_compliance.md) (раздел 7 — пункты на проверку) → [Бизнес-правила: правовые оговорки](04_project_docs/03_business_rules.md) → [Интеграции: касса и выплаты](04_project_docs/05_integrations.md) |
| Маркетингу и контенту | [Каталог запросов](01_inputs/decisions_v2.md#6-каталог-запросов) → [Анализ «Ясно»](02_competitor_analysis/yasno_product_analysis.md) → [Уведомления](04_project_docs/07_notifications.md) → [Метрики](04_project_docs/08_analytics_metrics.md) |

## Состав пакета

### 00 · План
| Документ | Формат | Содержание |
|---|---|---|
| [work_plan.md](00_plan/work_plan.md) | MD | План работ v1 и план доработки v2 со статусами |
| [package_overview.html](00_plan/package_overview.html) | HTML | Визуальная сводка пакета: решения, открытые вопросы, запросы клиентов, позиция против «Ясно», устройство платформы, деньги и супервизия, этапы, риски. Опубликована как приватная страница: https://claude.ai/code/artifact/efcc479e-76de-4b4e-85d5-d702ea3b3f17 |

### 01 · Входные данные и решения
| Документ | Формат | Содержание |
|---|---|---|
| [decisions_v2.md](01_inputs/decisions_v2.md) | MD | **Реестр решений v2.1 — главный источник:** 58 решений заказчика, 34 модуля, 116 разделов интерфейса, коды этапов, каталог 43 запросов, терминология, список удалённого |
| [tz_v2.md](01_inputs/tz_v2.md) | MD | ТЗ v2 по Договору № 07/09/2026 (прежнее ТЗ — `prd.md` в корне проекта, исторический документ) |
| [open_questions.md](01_inputs/open_questions.md) | MD | 6 открытых вопросов (все P1) и 49 решённых или снятых |
| [inputs_digest.md](01_inputs/inputs_digest.md) | MD | Сводка ТЗ v2, брифа, ДНК бренда, фирменного стиля; сравнение ТЗ v1 и v2; ответы заказчика |

### Фирменные материалы
| Документ | Формат | Содержание |
|---|---|---|
| [brand/README.md](brand/README.md) | MD, PNG, JPG | Логотипы заказчика (оригиналы и копии с прозрачным фоном), корпоративные цвета и шкала оттенков, шрифт Onest |

### 02 · Конкурентный анализ и аудит
| Документ | Формат | Содержание |
|---|---|---|
| [yasno_product_analysis.md](02_competitor_analysis/yasno_product_analysis.md) | MD | Продуктовый анализ «Ясно»: бизнес-модель, CJM, подбор, таксономия запросов, оплата, B2B, психологи, SWOT, стратегия дифференциации ТЕТА |
| [yasno_screens_teardown.md](02_competitor_analysis/yasno_screens_teardown.md) | MD | Покадровый разбор 45 скриншотов пути клиента «Ясно» и 33 паттерна с решениями ТЕТА |
| [teta_current_sites_audit.md](02_competitor_analysis/teta_current_sites_audit.md) | MD | Аудит teta.su, lk.teta.su (OnDoc) и прототипа test.teta.su «как есть» |
| [feature_matrix.xlsx](02_competitor_analysis/feature_matrix.xlsx) | XLSX | 124 функции: «Ясно», рынок, текущий сайт, решение ТЕТА v2, этап ТЗ, основание |

### 03 · Продукт
| Документ | Формат | Содержание |
|---|---|---|
| [teta_platform_blocks.md](03_product/teta_platform_blocks.md) | MD | **Главный продуктовый документ:** 116 разделов — сайт, мастер записи, кабинеты клиента, психолога и HR, TetaMeet, админ-панель, сквозные сервисы; блоки, поля, действия, состояния |
| [personas_cjm_flows.md](03_product/personas_cjm_flows.md) | MD | 10 персон, JTBD, CJM клиента и психолога, 23 пользовательских сценария |
| [roles_permissions.xlsx](03_product/roles_permissions.xlsx) | XLSX | Роли RBAC, матрица «роль — раздел — действие» по 116 разделам, доступ к чувствительным данным, жёсткие ограничения |
| [backlog.xlsx](03_product/backlog.xlsx) | XLSX | 48 эпиков и 341 пользовательская история (1 708 story points): каждый из 738 FR привязан к истории, указаны этапы ТЗ и спринты роадмапа |

### 04 · Проектная документация
| Документ | Формат | Содержание |
|---|---|---|
| [01_vision_scope.md](04_project_docs/01_vision_scope.md) | MD | Видение, УТП «Только дипломированные специалисты», цели и метрики, границы продукта, допущения |
| [02_functional_requirements.md](04_project_docs/02_functional_requirements.md) | MD | 738 функциональных требований по 34 модулям с критериями приёмки и трассировкой к пунктам ТЗ, решениям и разделам |
| [03_business_rules.md](04_project_docs/03_business_rules.md) | MD | 283 бизнес-правила и 92 настраиваемых параметра; правовые оговорки |
| [04_nonfunctional_requirements.md](04_project_docs/04_nonfunctional_requirements.md) | MD | 172 нефункциональных требования: безопасность, 152-ФЗ, производительность, TetaMeet, совместимость, почта, бот Герман |
| [05_integrations.md](04_project_docs/05_integrations.md) | MD | 172 требования к 11 интеграциям: платёжный сервис и эмулятор, касса, выплаты, почта, Метрика, Дзен, LLM, TetaMeet, хостинг, DNS |
| [06_legal_compliance.md](04_project_docs/06_legal_compliance.md) | MD | Карта норм (50 требований), реестр документов платформы, матрица согласий, пункты на проверку юристу и бухгалтеру |
| [07_notifications.md](04_project_docs/07_notifications.md) | MD | 134 уведомления для клиента, психолога, супервизора, администратора и HR |
| [08_analytics_metrics.md](04_project_docs/08_analytics_metrics.md) | MD | 57 метрик, воронки, событийная модель, Яндекс.Метрика |
| [09_roadmap_releases.md](04_project_docs/09_roadmap_releases.md) | MD | Роадмап по этапам ТЗ: 44 недельных спринта до 11.07.2027, критический путь, зависимости, приёмка |
| [10_risks.md](04_project_docs/10_risks.md) | MD | Реестр рисков с мерами, владельцами и триггерами |
| [11_glossary.md](04_project_docs/11_glossary.md) | MD | Глоссарий, соглашения об ID, словарь интерфейса |
| [TETA_project_documentation.docx](04_project_docs/TETA_project_documentation.docx) | DOCX | Сводный документ для согласования |

### 05 · Mindmap
| Документ | Формат | Содержание |
|---|---|---|
| [teta_mindmap.md](05_mindmap/teta_mindmap.md) | MD | Источник карты (425 узлов, теги этапов ТЗ) |
| [teta_mindmap.html](05_mindmap/teta_mindmap.html) | HTML | Интерактивная карта в браузере |
| [teta_mindmap.mm](05_mindmap/teta_mindmap.mm) | FreeMind | Импорт в Freeplane, XMind, MindManager |
| [teta_mindmap.opml](05_mindmap/teta_mindmap.opml) | OPML | Универсальный импорт |
| [teta_mindmap.mmd](05_mindmap/teta_mindmap.mmd) | Mermaid | Диаграмма mindmap |

### 06 · Архитектура
| Документ | Формат | Содержание |
|---|---|---|
| [product_architecture.md](06_architecture/product_architecture.md) | MD | Контуры, 34 модуля, структура сайта, кабинетов и админ-панели, сквозные процессы, white-label как отдельные инстансы |
| [technical_architecture.md](06_architecture/technical_architecture.md) | MD | Laravel 13, Next.js 16, PostgreSQL 18, Redis, TetaMeet, VDS Timeweb, безопасность, 23 ADR |
| [data_model.md](06_architecture/data_model.md) | MD | 212 сущностей по поддоменам, связи, классы данных, сроки хранения |
| [sequences_states.md](06_architecture/sequences_states.md) | MD | 22 последовательности и 20 машин состояний |
| [sitemap.xlsx](06_architecture/sitemap.xlsx) | XLSX | 59 страниц сайта, 43 посадочные по запросам и 2 хаба, 109 маршрутов кабинетов, правила редиректов, SEO-шаблоны |
| [diagrams/](06_architecture/diagrams/README.md) | MMD, SVG | 92 диаграммы: исходники и рендеры в фирменном оформлении |

### 99 · Источники
Сырые отчёты исследований, скриншоты «Ясно» (веб и сторы), скриншоты текущих сайтов ТЕТА — [99_sources/](99_sources/).

### Инструменты
| Инструмент | Назначение |
|---|---|
| [_tools/check_docs.py](_tools/check_docs.py) | Проверка ссылок, ID требований и решений, открытых вопросов |
| [_tools/build_mindmap.py](_tools/build_mindmap.py) | Генерация форматов mindmap из одного источника |
| [_tools/render_diagrams.sh](_tools/render_diagrams.sh) | Рендер диаграмм в SVG в фирменном оформлении |
| `.claude/skills/teta-docs/` | Проектный skill для работы с документацией в Claude Code |

Описание утилит — [_tools/README.md](_tools/README.md).

## Соглашения

- **Приоритет источников:** [реестр решений](01_inputs/decisions_v2.md) → ТЗ v2 → правовые оговорки → бизнес-правила → блоки и разделы → требования → архитектура → рекомендации аналитика.
- **ID:** решения `DEC-`; разделы интерфейса `SITE-`, `WIZ-`, `CL-`, `ROOM-`, `PRO-`, `HR-`, `ADM-`, `X-`; требования `FR-`, `NFR-`, `BR-`, `INT-`, `LC-`; уведомления `N-`; метрики `MET-`; вопросы `Q-`; риски `RISK-`; архитектурные решения `ADR-`; истории `US-`. Подробно — [глоссарий](04_project_docs/11_glossary.md).
- **Этапы вместо релизов:** `Ядро`, `Э1`…`Э11`, `Сквозн.`, `11.1`, `Доп.` — [реестр решений, раздел 5](01_inputs/decisions_v2.md#5-коды-этапов).
- **Метки достоверности:** [Ф] факт · [П] интерпретация · [допущение Q-NN] · [Рек.] · [ПРОВЕРИТЬ] · [ЮРИСТ].
- **Оформление:** шрифт Onest, корпоративные цвета и логотип заказчика — [фирменные материалы](brand/README.md).
- **Имена файлов и папок** — латиницей (кириллица в путях macOS хранится в NFD и ломает ссылки); содержание — на русском.
