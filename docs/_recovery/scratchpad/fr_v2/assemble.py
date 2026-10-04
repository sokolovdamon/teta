# -*- coding: utf-8 -*-
"""Assemble docs/04_project_docs/02_functional_requirements.md (v2.1) from three agent-written parts."""
import collections
import re

P = "/private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/fr_v2/"
DOCS = "/Users/dmitry/Projects/teta_new/docs/"
OUT = DOCS + "04_project_docs/02_functional_requirements.md"
ORDER = ["AUTH", "RBAC", "CONSENT", "INSTANCE", "AUDIT", "PROFILE", "MATCH", "AIBOT", "CATALOG", "PSY", "SCHED",
         "BOOK", "MEET", "DIARY", "RECO", "CRM", "PAY", "PAYOUT", "PROMO", "B2B", "REVIEW", "CONTENT", "KB", "SUPERV",
         "INTERV", "EVENT", "NOTIF", "MAILING", "SUPPORT", "CMS", "SEARCH", "FILES", "ANALYTICS", "ADMIN"]
STAGES = ["Ядро", "Э1", "Э2", "Э3", "Э4", "Э5", "Э6", "Э7", "Э8", "Э9", "Э10", "Э11", "Сквозн.", "11.1", "Доп."]
PRIOS = ["Must", "Should", "Could", "Won't"]
STOP = re.compile(r"^#{2,3} (Покрытие пунктов ТЗ|Трассировка части|Решения DEC|Замечания для сборки)")

# ---------------------------------------------------------------- parts
blocks, names = {}, {}
for part in ("part1.md", "part2.md", "part3.md"):
    text = open(P + part, encoding="utf-8").read().replace("ТЗ TetaMeet.", "ТЗ Meet.")
    cur, buf = None, []
    for line in text.split("\n"):
        m = re.match(r"^### ([A-Z0-9]+) — (.+)$", line)
        if m and m.group(1) in ORDER:
            if cur:
                blocks[cur] = buf
            cur, buf = m.group(1), []
            names[cur] = m.group(2).strip()
            continue
        if STOP.match(line):
            if cur:
                blocks[cur] = buf
            cur, buf = None, []
            continue
        if cur is not None:
            buf.append(line)
    if cur:
        blocks[cur] = buf
missing = [c for c in ORDER if c not in blocks]
assert not missing, "missing modules: %s" % missing
for code in ORDER:
    b = blocks[code]
    while b and b[-1].strip() in ("", "---"):
        b.pop()
    while b and b[0].strip() == "":
        b.pop(0)


def cells(line):
    s = line.strip().replace("\\|", "\x00")
    return [c.replace("\x00", "|").strip() for c in s.strip("|").split("|")]


rows = []
for code in ORDER:
    for line in blocks[code]:
        if line.startswith("| FR-"):
            c = cells(line)
            assert len(c) == 7, (code, c[0], len(c))
            rows.append((code, c))
ids = [c[0] for _, c in rows]
dups = [i for i, n in collections.Counter(ids).items() if n > 1]
assert not dups, dups

# ---------------------------------------------------------------- summary
def stage_codes(cell):
    found = []
    for part in re.split(r"[,;]\s*", cell):
        part = part.strip()
        if part in STAGES:
            found.append(part)
    return found or ["—"]


mod_stats = collections.OrderedDict()
stage_total = collections.Counter()
prio_total = collections.Counter()
for code in ORDER:
    mod_stats[code] = collections.Counter()
for code, c in rows:
    pr = c[2].replace("’", "'").strip()
    mod_stats[code][pr] += 1
    mod_stats[code]["_all"] += 1
    prio_total[pr] += 1
    for st in stage_codes(c[3]):
        stage_total[st] += 1

summary = ["| № | Модуль | Название | Всего | Must | Should | Could | Won't |", "|---|---|---|---|---|---|---|---|"]
for n, code in enumerate(ORDER, 1):
    s = mod_stats[code]
    summary.append("| %d | `%s` | %s | %d | %d | %d | %d | %d |" % (n, code, names[code], s["_all"], s["Must"], s["Should"], s["Could"], s["Won't"]))
summary.append("| | | **Итого** | **%d** | **%d** | **%d** | **%d** | **%d** |" % (len(rows), prio_total["Must"], prio_total["Should"], prio_total["Could"], prio_total["Won't"]))
stage_table = ["| Этап ТЗ | Требований |", "|---|---|"]
for st in STAGES:
    if stage_total.get(st):
        stage_table.append("| `%s` | %d |" % (st, stage_total[st]))

# ---------------------------------------------------------------- TZ items
tz = open(DOCS + "01_inputs/tz_v2.md", encoding="utf-8").read().split("\n")
tz_items = collections.OrderedDict()
cur = None
for line in tz:
    m = re.match(r"^### Этап (\d+)\. ", line)
    if m:
        cur = "Э" + m.group(1)
        continue
    if line.startswith("### Ядро Платформы"):
        cur = "Ядро"
        continue
    if line.startswith("### Модуль видеосвязи TetaMeet"):
        cur = "Meet"
        continue
    if line.startswith("### Сквозные задачи"):
        cur = "Сквозн"
        continue
    if line.startswith("11.1."):
        cur = "11.1"
        continue
    if line.startswith("## ") or line.startswith("11.2."):
        cur = None
        continue
    if cur and line.startswith("- "):
        tz_items.setdefault(cur, []).append(line[2:].strip())

ORDER_TZ = ["Ядро", "Э1", "Э2", "Э3", "Meet", "Э4", "Э5", "Э6", "Э7", "Э8", "Э9", "Э10", "Э11", "Сквозн", "11.1"]
ref_re = re.compile(r"ТЗ (Ядро|Meet|Сквозн|Э\d{1,2}|11\.1)\.(\d+)")
cover = collections.defaultdict(list)
for code, c in rows:
    for m in ref_re.finditer(c[4]):
        key = "%s.%s" % (m.group(1), m.group(2))
        if c[0] not in cover[key]:
            cover[key].append(c[0])

NOT_FR = {
    "Ядро.1": "Не функциональное требование: окружения, репозиторий и CI/CD — техническая архитектура, НФТ (MAINT)",
    "Ядро.2": "Не функциональное требование: модель данных и описание API — модель данных, техническая архитектура",
    "Сквозн.3": "Не функциональное требование: тестирование, развёртывание, документация — НФТ (MAINT), роадмап",
    "11.1.3": "Не делается: интеграции с Apple Health и Google Fit исключены (DEC-09)",
    "11.1.4": "Не делается: нативных приложений нет, с мобильных устройств — адаптивный сайт и PWA (DEC-08)",
}
cov_lines = ["| Пункт | Текст пункта ТЗ v2 | Требования |", "|---|---|---|"]
uncovered = []
for sec in ORDER_TZ:
    for i, txt in enumerate(tz_items.get(sec, []), 1):
        key = "%s.%d" % (sec, i)
        label = key.replace("Сквозн", "Сквозн.").replace("Сквозн..", "Сквозн.")
        short = txt if len(txt) <= 150 else txt[:147].rstrip() + "…"
        fr = cover.get(key)
        if fr:
            val = ", ".join(fr)
        elif sec == "Э1":
            val = "Этап дизайна: макеты и дизайн-система; состав экранов — блоки и разделы"
        elif key in NOT_FR:
            val = NOT_FR[key]
        else:
            val = "—"
            uncovered.append(key)
        cov_lines.append("| %s | %s | %s |" % (label, short.replace("|", "/"), val))

# ---------------------------------------------------------------- DEC, sections, Q
reg = open(DOCS + "01_inputs/decisions_v2.md", encoding="utf-8").read().split("\n")
dec_names = collections.OrderedDict()
section_order = []
for line in reg:
    m = re.match(r"^\| (DEC-\d{2}) \| (.+?) \|", line)
    if m:
        dec_names[m.group(1)] = re.sub(r"\*\*", "", m.group(2))
    m = re.match(r"^\| ((?:SITE|WIZ|CL|ROOM|PRO|HR|ADM|X)-\d{2}[ab]?) \|", line)
    if m and m.group(1) not in section_order:
        section_order.append(m.group(1))

dec_fr = collections.defaultdict(list)
sec_fr = collections.defaultdict(list)
q_fr = collections.defaultdict(list)
for code, c in rows:
    row_text = " ".join(c[1:])
    for d in sorted(set(re.findall(r"DEC-\d{2}", row_text))):
        dec_fr[d].append(c[0])
    for s in sorted(set(re.findall(r"(?<![\w-])((?:SITE|WIZ|CL|ROOM|PRO|HR|ADM|X)-\d{2}[ab]?)(?![\w-])", c[5]))):
        sec_fr[s].append(c[0])
    for q in sorted(set(re.findall(r"Q-\d{2}", row_text))):
        q_fr[q].append(c[0])

dec_lines = ["| № | Решение | Требования |", "|---|---|---|"]
n = 0
for d, name in dec_names.items():
    if dec_fr.get(d):
        n += 1
        dec_lines.append("| %d | %s · %s | %s |" % (n, d, name, ", ".join(dec_fr[d])))
dec_without = [d for d in dec_names if not dec_fr.get(d)]

sec_lines = ["| Раздел | Требования |", "|---|---|"]
for s in section_order:
    if sec_fr.get(s):
        sec_lines.append("| %s | %s |" % (s, ", ".join(sec_fr[s])))
sec_without = [s for s in section_order if not sec_fr.get(s)]

q_lines = ["| № | Открытый вопрос | Требования с допущением или связью |", "|---|---|---|"]
for n, q in enumerate(sorted(q_fr), 1):
    q_lines.append("| %d | %s | %s |" % (n, q, ", ".join(q_fr[q])))

# ---------------------------------------------------------------- document
head = """# Функциональные требования (SRS)

> Версия 2.1 · 11.09.2026 · Статус: черновик на утверждение
> Изменения v2: документ пересобран по [реестру решений 2.1](../01_inputs/decisions_v2.md) и ТЗ v2 — %(total)d требований по 34 модулям; нумерация назначена заново; вместо релизов — этапы ТЗ; учтены ответы заказчика (DEC-36…DEC-58).
> Документ фиксирует, **что должна делать система ТЕТА**. Источники: [ТЗ v2](../01_inputs/tz_v2.md), [реестр решений](../01_inputs/decisions_v2.md), [бизнес-правила](03_business_rules.md), [блоки и разделы](../03_product/teta_platform_blocks.md), [архитектура продукта](../06_architecture/product_architecture.md), [открытые вопросы](../01_inputs/open_questions.md). Связанные документы: [НФТ](04_nonfunctional_requirements.md), [интеграции](05_integrations.md), [юридический контур](06_legal_compliance.md), [уведомления](07_notifications.md), [метрики](08_analytics_metrics.md), [роадмап](09_roadmap_releases.md), [техническая архитектура](../06_architecture/technical_architecture.md), [модель данных](../06_architecture/data_model.md), [последовательности и состояния](../06_architecture/sequences_states.md).

## Содержание

1. [Как читать документ](#1-как-читать-документ)
2. [Сводка](#2-сводка)
3. [Требования по модулям](#3-требования-по-модулям)
4. [Трассировка](#4-трассировка)
5. [Расхождения источников, учтённые в требованиях](#5-расхождения-источников-учтённые-в-требованиях)

---

## 1. Как читать документ

### 1.1. Формат требования

| Колонка | Содержание |
|---|---|
| ID | `FR-<КОД>-NNN`: `<КОД>` — код модуля из [реестра решений](../01_inputs/decisions_v2.md), раздел 3.2; `NNN` — номер внутри модуля с 001 |
| Требование | «Система должна …» — одно проверяемое поведение |
| Приоритет | MoSCoW внутри этапа (1.2) |
| Этап ТЗ | Код этапа (1.3) |
| Источник | Пункт ТЗ, решение заказчика, норма права или рекомендация (1.4) |
| Связи | Бизнес-правила `BR-…`, требования к интеграциям `INT-…`, разделы интерфейса, параметры `P-…`, открытые вопросы `Q-…` |
| Критерии приёмки | 1–3 проверяемых условия «дано / когда / тогда» |

### 1.2. Приоритет

| Значение | Смысл |
|---|---|
| **Must** | Без этого этап не принимается |
| **Should** | Важно; перенос внутри этапа — только по согласованию с заказчиком |
| **Could** | Желательно при наличии ресурса в этапе |
| **Won't** | Осознанно не делается по решению заказчика — ссылка на DEC в колонке «Источник» |

### 1.3. Этапы ТЗ

| Код | Смысл |
|---|---|
| `Ядро` | Ядро платформы, параллельно этапу 1 |
| `Э1`…`Э11` | Этапы разд. 7 ТЗ v2 |
| `Сквозн.` | Сквозные задачи ТЗ v2 |
| `11.1` | Направления п. 11.1 ТЗ v2, учтены заказчиком в объёме работ (DEC-39) |
| `Доп.` | Решения заказчика сверх текста ТЗ v2, учтены в объёме работ (DEC-39) |

### 1.4. Источники

| Обозначение | Что означает |
|---|---|
| `ТЗ Э3.5` | Пятый пункт списка этапа 3 в разд. 7 [ТЗ v2](../01_inputs/tz_v2.md); так же `ТЗ Ядро.N`, `ТЗ Meet.N` (модуль видеосвязи TetaMeet), `ТЗ Сквозн.N` |
| `ТЗ 8`, `ТЗ 8.N` | Нефункциональные требования ТЗ v2 (разд. 8) |
| `ТЗ 11.1.N` | Пункт списка п. 11.1 ТЗ v2 |
| `ТЗ 2.1`, `ТЗ 5.3` | Нумерованные подпункты ТЗ v2 |
| `DEC-NN` | Решение заказчика из [реестра](../01_inputs/decisions_v2.md) |
| `Бриф`, `ДНК`, `Ясно` | Бриф, ДНК бренда, паттерн конкурента |
| `152-ФЗ`, `54-ФЗ`, `38-ФЗ`, `376-ФЗ`, `ЗоЗПП` | Нормы права — [юридический контур](06_legal_compliance.md) |
| `[Рек.]`, `[П]` | Рекомендация аналитика; толкование решения |
| `[допущение Q-NN]` | До ответа на открытый вопрос требование следует рекомендации аналитика |

### 1.5. Приоритет источников

При расхождении действует порядок: [реестр решений](../01_inputs/decisions_v2.md) → ТЗ v2 → правовые оговорки в начале [бизнес-правил](03_business_rules.md) → строки бизнес-правил → [блоки и разделы](../03_product/teta_platform_blocks.md) → паттерны «Ясно». Учтённые расхождения — раздел 5.

### 1.6. Общие соглашения

1. **Время** хранится в UTC и показывается в часовом поясе просматривающего с указанием пояса.
2. **Параметры** `P-…` меняются в ADM-26 без изменения кода; значения в критериях приёмки — значения по умолчанию, приведены для примера.
3. **Инстанс.** Требования выполняются в пределах одного инстанса платформы; каждый партнёр white-label получает отдельный инстанс на отдельном сервере со своей базой данных (DEC-52).
4. **Сведения о состоянии** — ответы анкеты, диалог с Германом, дневник эмоций, запрос клиента, заметки психолога (DEC-24). Доступ — по ролям RBAC; заметки психолога видит только автор; сведения о состоянии не передаются в Яндекс.Метрику.
5. **Деньги** до завершающего этапа подключения платёжного сервиса проходят через абстракцию платёжного сервиса и тестовый эмулятор (DEC-38); реальные списания, чеки и выплаты начинаются после подключения.
6. **Каналов связи клиента с психологом вне сессий нет** (ТЗ 2.1): организационные вопросы решаются структурированными действиями, через бота Германа и техподдержку (DEC-28).
7. **Интерфейс** — русский язык, шрифт Onest, корпоративные цвета и логотип заказчика (DEC-02…DEC-04); тексты без давления и медицинских формулировок.

---

## 2. Сводка

### 2.1. Требования по модулям

%(summary)s

### 2.2. Требования по этапам ТЗ

Требование с несколькими этапами учитывается в каждом из них.

%(stages)s

---

## 3. Требования по модулям

Порядок модулей — как в [реестре решений](../01_inputs/decisions_v2.md), раздел 3.2.
"""

parts = [head % {"total": len(rows), "summary": "\n".join(summary), "stages": "\n".join(stage_table)}]
for n, code in enumerate(ORDER, 1):
    parts.append("\n### 3.%d. %s — %s\n" % (n, code, names[code]))
    parts.append("\n".join(blocks[code]) + "\n")

parts.append("""
---

## 4. Трассировка

### 4.1. Пункты ТЗ v2 → требования

Пункты этапа 1 (дизайн) реализуются макетами; пункты ядра о среде и базе данных — архитектурой. Остальные пункты разд. 7 ТЗ v2 покрыты требованиями.

%s

### 4.2. Решения заказчика → требования

%s

### 4.3. Разделы интерфейса → требования

%s

### 4.4. Открытые вопросы → требования

%s

---

## 5. Расхождения источников, учтённые в требованиях

| № | Расхождение | Как учтено |
|---|---|---|
| 1 | Бизнес-правило BR-ACC-08 закрывает привязку карты и запись до подтверждения email, а текст раздела WIZ-05 говорил, что подтверждение запись не блокирует | Требования следуют BR-ACC-08 (FR-AUTH-006); раздел WIZ-05 выровнен при финальной сверке |
| 2 | Правило BR-DIARY-04 предусматривало переключатель «скрыть дневник от психолога», которого нет в DEC-41 и разделе CL-06 | Действует DEC-41: переключателя нет; правило выровнено при финальной сверке |
| 3 | Срок хранения журнала аудита: 1 год в технической архитектуре и 3 года в юридическом контуре | FR-AUDIT-009 — 3 года по юридическому контуру |
| 4 | Удаление аккаунта при будущих сессиях с уже списанной оплатой | FR-PROFILE-008 уточняет BR-ACC-15: удаление недоступно, пока клиент не проведёт или не отменит такие сессии |
| 5 | Сертификат и баланс | Номинал сертификата зачисляется на баланс целиком и на карту не выводится (BR-PROMO-17, BR-PAY-17) |
| 6 | Промокоды и корпоративный лимит | Промокод к сессиям из корпоративного лимита не применяется [Рек.] |
| 7 | Адрес запуска платформы (Q-55) | Может изменить FR-CMS-012 и FR-CMS-021 после ответа заказчика |
""" % ("\n".join(cov_lines), "\n".join(dec_lines), "\n".join(sec_lines), "\n".join(q_lines)))

doc = "".join(parts)
open(OUT, "w", encoding="utf-8").write(doc)
print("FR total:", len(rows))
print("prio:", dict(prio_total))
print("stages:", dict(stage_total))
print("uncovered TZ items:", uncovered)
print("DEC without FR:", dec_without)
print("sections without FR:", sec_without)
print("bytes:", len(doc.encode("utf-8")))
