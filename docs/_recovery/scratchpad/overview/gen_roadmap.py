# -*- coding: utf-8 -*-
"""Build roadmap.html fragment for the overview page from the mermaid gantt in 09_roadmap_releases.md."""
import datetime as dt
import html
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = "/Users/dmitry/Projects/teta_new/docs/04_project_docs/09_roadmap_releases.md"
TODAY = dt.date(2026, 9, 11)
MONTHS = ["янв", "фев", "мар", "апр", "май", "июн", "июл", "авг", "сен", "окт", "ноя", "дек"]

text = open(SRC, encoding="utf-8").read()
block = re.search(r"```mermaid\n(gantt.*?)```", text, re.S).group(1)

sections = []  # [name, [tasks]]
milestones = []
for raw in block.splitlines():
    line = raw.strip()
    if line.startswith("section "):
        sections.append([line[len("section "):].strip(), []])
        continue
    m = re.match(r"^(.*?)\s*:(.*)$", line)
    if not m or not sections or line.startswith(("title", "dateFormat", "axisFormat", "gantt")):
        continue
    name, spec = m.group(1).strip(), [p.strip() for p in m.group(2).split(",")]
    date_i = next(i for i, p in enumerate(spec) if re.match(r"\d{4}-\d{2}-\d{2}$", p))
    start = dt.date.fromisoformat(spec[date_i])
    days = int(spec[date_i + 1].rstrip("d"))
    tags = spec[:date_i - 1] if date_i >= 2 else []
    if "milestone" in tags:
        milestones.append((name, start))
        continue
    sections[-1][1].append((name, start, start + dt.timedelta(days=days), "crit" in tags))

T0 = dt.date(2026, 9, 7)
T1 = max(end for _, tasks in sections for _, _, end, _ in tasks)
SPAN = (T1 - T0).days


def pct(d):
    return round((d - T0).days / SPAN * 100, 2)


def fmt(d):
    return d.strftime("%d.%m")


GROUP_NAMES = {
    "Ядро и дизайн": "Ядро и дизайн",
    "Этапы 2–4": "Этапы 2–4",
    "Этапы 5–11": "Этапы 5–11",
    "Платёжный сервис": "Завершающий этап",
    "Сквозные задачи": "Сквозные задачи",
    "Доп.": "Решения сверх текста ТЗ",
    "11.1 поток В": "Направления п. 11.1",
}


def bar_class(section, name):
    if section == "Платёжный сервис":
        return "late"
    if section in ("Доп.", "11.1 поток В"):
        return "origin"
    if "Дизайн" in name or section == "Сквозные задачи":
        return "design"
    return "core"


rows = []
for section, tasks in sections:
    if section == "Контрольные точки":
        continue
    visible = [t for t in tasks if not t[0].startswith("Праздники")]
    if not visible:
        continue
    rows.append('<div class="group">%s</div><div class="track"></div>' % html.escape(GROUP_NAMES.get(section, section)))
    for name, start, end, _crit in visible:
        label = re.sub(r"\s+", " ", name)
        label = label.replace("Э3 Клиент Герман оплаты на эмуляторе", "Э3 Кабинет клиента, Герман, оплаты на эмуляторе")
        label = label.replace("Э3 TetaMeet сессии и групповые встречи", "Э3 TetaMeet: сессии и групповые встречи")
        label = label.replace("Уведомления логирование мониторинг", "Уведомления, логирование, мониторинг")
        label = label.replace("Нагрузка безопасность документация", "Нагрузка, безопасность, документация")
        label = label.replace("Регрессия prod передача материалов", "Регрессия, prod, передача материалов")
        label = label.replace("Вебинары курсы эфиры", "Вебинары, курсы, эфиры")
        label = label.replace("Центр уведомлений и видео с мобильных", "Центр уведомлений, видео с мобильных")
        label = label.replace("Сертификаты и Пригласи друга", "Сертификаты и «Пригласи друга»")
        last = end - dt.timedelta(days=1)
        rows.append(
            '<div class="label">%s <small>%s–%s</small></div>'
            '<div class="track"><span class="bar %s" style="left:%s%%;width:%s%%"></span></div>'
            % (html.escape(label), fmt(start), fmt(last), bar_class(section, name), pct(start), round(pct(end) - pct(start), 2))
        )

ticks, lines = [], []
d = dt.date(T0.year, T0.month, 1)
while d <= T1:
    if d >= T0:
        p = pct(d)
        lab = MONTHS[d.month - 1] + (" %d" % d.year if d.month == 1 else "")
        cls = "tick"
        if p > 97:
            cls += " last"
        ticks.append('<span class="%s" style="left:%s%%">%s</span>' % (cls, p, lab))
        lines.append('<i style="left:%s%%"></i>' % p)
    d = dt.date(d.year + (d.month // 12), d.month % 12 + 1, 1)
ticks.insert(0, '<span class="tick first" style="left:0%">07.09.26</span>')
lines.append('<i class="today" style="left:%s%%"></i>' % pct(TODAY))

keep = ("КТ-4", "КТ-8", "КТ-9", "КТ-13", "КТ-14")
ms = []
for name, date in milestones:
    code = name.split()[0]
    if code in keep:
        title = name[len(code):].strip()
        title = title[0].upper() + title[1:]
        ms.append('<div><dt>%s</dt><dd>%s</dd></div>' % (date.strftime("%d.%m.%Y"), html.escape(title)))

sprints = ((T1 - T0).days + 6) // 7
out = """<section id="roadmap" aria-labelledby="h-roadmap">
      <div class="section-head">
        <p class="kicker">Роадмап · оценка аналитика · %(sprints)d недельных спринта</p>
        <h2 id="h-roadmap">Этапы ТЗ во времени</h2>
        <p class="section-lead">Спринт 1 начался 7 сентября 2026 года. Команде из двух бэкенд- и двух фронтенд-разработчиков нужно %(sprints)d спринта — до %(end)s. Платёжный сервис подключается последним, до этого деньги работают на эмуляторе. Если утверждённый План-график расходится с этой оценкой, действует План-график.</p>
      </div>
      <div class="chart-wrap">
        <div class="gantt" role="img" aria-label="Диаграмма этапов по месяцам с сентября 2026 по июль 2027: ядро и дизайн, этапы 2–11, подключение платёжного сервиса в конце">
          <div></div>
          <div class="scale" aria-hidden="true">%(ticks)s</div>
          <div class="grid-overlay" aria-hidden="true">%(lines)s</div>
          %(rows)s
        </div>
      </div>
      <div class="legend" aria-hidden="true">
        <span><i style="background:var(--bar-core)"></i>Этапы ТЗ</span>
        <span><i style="background:var(--bar-design)"></i>Дизайн и сквозные задачи</span>
        <span><i style="background:var(--bar-late)"></i>Подключение платёжного сервиса</span>
        <span><i style="background:var(--bar-origin)"></i>Сверх текста ТЗ и п. 11.1</span>
        <span><i class="today-key"></i>Сегодня, 11.09.2026</span>
      </div>
      <dl class="milestones">%(ms)s</dl>
    </section>""" % {
    "sprints": sprints,
    "end": (T1 - dt.timedelta(days=1)).strftime("%d.%m.%Y"),
    "ticks": "".join(ticks),
    "lines": "".join(lines),
    "rows": "\n          ".join(rows),
    "ms": "".join(ms),
}
open(os.path.join(HERE, "roadmap.html"), "w", encoding="utf-8").write(out)
print("roadmap.html:", len(rows), "rows;", sprints, "sprints; end", T1)
