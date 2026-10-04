import io, sys
p = "/Users/dmitry/Projects/teta_new/docs/06_architecture/technical_architecture.md"
s = io.open(p, encoding="utf-8").read()
pairs = [
("| Порог входа | Выше | Ниже | Низкий |\n\n**Решение:** React. Next.js — только для публичной части (SITE, WIZ); кабинеты — SPA на Vite, чтобы не держать Node-сервер рядом с интерфейсами ПДн. Next.js разворачивается self-hosted (контейнер), без облачных функций зарубежных провайдеров.",
 "| Порог входа | Выше | Ниже | Низкий |\n"
 "| Существующий прототип test.teta.su | Код прототипа не переиспользуется; дизайн, тексты и структура страниц переносятся | Nuxt 3 + Nuxt UI + Strapi: готовы только маркетинговые страницы (главная, B2B-калькулятор, сертификаты, «Для психологов», FAQ); транзакционного ядра нет; дефекты — HTTP, 404/500, заглушки ([аудит](../02_competitor_analysis/teta_current_sites_audit.md)) | Средний — решается в Q-37 |\n\n"
 "**Решение (предложено, требует ответа на Q-37):** React. Next.js — только для публичной части (SITE, WIZ); кабинеты — SPA на Vite, чтобы не держать Node-сервер рядом с интерфейсами ПДн. Next.js разворачивается self-hosted (контейнер), без облачных функций зарубежных провайдеров.\n\n"
 "Прототип на Nuxt содержит только маркетинговые страницы без ядра, поэтому выигрыш от переиспользования его кода меньше, чем выигрыш React в видеокомнате и доступности; наработки дизайна и контента переносятся при любом стеке. Если заказчик решит сохранить Vue/Nuxt, архитектура ядра (NestJS, API, модули, данные) не меняется — пересматриваются только ADR-02 и реализация видеокомнаты на LiveKit JS SDK без готовых компонентов."),
("| ADR-02 | React: Next.js (SSR/ISR, self-hosted) для SITE и WIZ; SPA на Vite + PWA для CL, PRO, ADM, HR, WL | Vue/Nuxt; Next.js для всех контуров | Компоненты LiveKit для React, экосистема доступности, кабинеты без Node-сервера | Предложено |",
 "| ADR-02 | React: Next.js (SSR/ISR, self-hosted) для SITE и WIZ; SPA на Vite + PWA для CL, PRO, ADM, HR, WL | Vue/Nuxt (стек прототипа test.teta.su); Next.js для всех контуров | Компоненты LiveKit для React, экосистема доступности, кабинеты без Node-сервера; прототип на Nuxt — только маркетинговые страницы | Требует Q-37 |"),
]
bad = [o[:80] for o, n in pairs if s.count(o) != 1]
if bad:
    print("FAIL", bad); sys.exit(1)
for o, n in pairs:
    s = s.replace(o, n)
io.open(p, "w", encoding="utf-8").write(s)
print("OK technical_architecture.md (2 replacements)")
