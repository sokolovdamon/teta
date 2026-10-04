import type { Role } from "@/lib/types";

/**
 * Cabinet navigation. Section IDs follow docs/03_product (CL-xx, PRO-xx, ADM-xx, HR-xx).
 * `permission` hides an item from admins whose RBAC role lacks it (super_admin sees everything).
 */
export type NavItem = { id: string; href: string; label: string; permission?: string; roles?: Role[] };
export type NavGroup = { title?: string; items: NavItem[] };

export const clientNav: NavGroup[] = [
  {
    items: [
      { id: "CL-02", href: "/client", label: "Главная" },
      { id: "CL-03", href: "/client/sessions", label: "Сессии" },
      { id: "CL-04", href: "/client/psychologist", label: "Мой психолог" },
      { id: "CL-05", href: "/client/recommendations", label: "Рекомендации" },
      { id: "CL-06", href: "/client/diary", label: "Дневник эмоций" },
      { id: "CL-07", href: "/client/payments", label: "Платежи и баланс" },
      { id: "CL-15", href: "/client/materials", label: "Материалы" },
      { id: "CL-09", href: "/client/events", label: "Мероприятия" },
      { id: "CL-16", href: "/client/corporate", label: "Корпоративная программа" },
    ],
  },
  {
    items: [
      { id: "CL-10", href: "/client/reviews", label: "Отзывы" },
      { id: "CL-12", href: "/client/chat", label: "Чат: Герман и поддержка" },
      { id: "CL-13", href: "/client/invite", label: "Пригласить друга" },
      { id: "CL-14", href: "/client/notifications", label: "Уведомления" },
      { id: "CL-08", href: "/client/settings", label: "Настройки" },
    ],
  },
];

export const proNav: NavGroup[] = [
  {
    title: "Работа с клиентами",
    items: [
      { id: "PRO-04", href: "/pro", label: "Календарь записей" },
      { id: "PRO-05", href: "/pro/clients", label: "Клиенты" },
      { id: "PRO-03", href: "/pro/schedule", label: "График работы" },
      { id: "PRO-08", href: "/pro/stats", label: "Статистика и доход" },
      { id: "PRO-09", href: "/pro/payouts", label: "Вывод средств" },
    ],
  },
  {
    title: "Профиль",
    items: [
      { id: "PRO-02", href: "/pro/profile", label: "Профиль" },
      { id: "PRO-01", href: "/pro/qualification", label: "Квалификация" },
    ],
  },
  {
    title: "Развитие",
    items: [
      { id: "PRO-12", href: "/pro/supervision", label: "Супервизия" },
      { id: "PRO-13", href: "/pro/supervisor", label: "Рабочее место супервизора", roles: ["supervisor"] },
      { id: "PRO-14", href: "/pro/intervision", label: "Интервизия" },
      { id: "PRO-15", href: "/pro/articles", label: "Статьи" },
      { id: "PRO-16", href: "/pro/knowledge", label: "База знаний" },
      { id: "PRO-18", href: "/pro/events", label: "Мероприятия" },
    ],
  },
  {
    items: [
      { id: "PRO-17", href: "/pro/support", label: "Техподдержка" },
      { id: "PRO-10", href: "/pro/settings", label: "Уведомления и аккаунт" },
    ],
  },
];

export const adminNav: NavGroup[] = [
  {
    title: "Работа",
    items: [
      { id: "ADM-01", href: "/admin", label: "Дашборд", permission: "admin.dashboard.view" },
      { id: "ADM-02", href: "/admin/users", label: "Пользователи", permission: "admin.users.view" },
      { id: "ADM-03", href: "/admin/psychologists", label: "Психологи", permission: "admin.psychologists.view" },
      { id: "ADM-04", href: "/admin/sessions", label: "Сессии", permission: "admin.sessions.view" },
      { id: "ADM-06", href: "/admin/moderation", label: "Модерация", permission: "admin.moderation.view" },
      { id: "ADM-19", href: "/admin/support", label: "Техподдержка", permission: "admin.support.view" },
    ],
  },
  {
    title: "Деньги",
    items: [
      { id: "ADM-07", href: "/admin/finance", label: "Финансы", permission: "admin.finance.view" },
      { id: "ADM-08", href: "/admin/payouts", label: "Выплаты", permission: "admin.payouts.view" },
      { id: "ADM-09", href: "/admin/promo", label: "Промокоды", permission: "admin.promo.view" },
      { id: "ADM-22", href: "/admin/companies", label: "Корпоративные клиенты", permission: "admin.companies.view" },
      { id: "ADM-12", href: "/admin/reports", label: "Отчёты и выгрузки", permission: "admin.reports.view" },
    ],
  },
  {
    title: "Подбор и развитие",
    items: [
      { id: "ADM-14", href: "/admin/matching", label: "Подбор и Герман", permission: "admin.matching.view" },
      { id: "ADM-15", href: "/admin/supervision", label: "Супервизия", permission: "admin.supervision.view" },
      { id: "ADM-16", href: "/admin/intervision", label: "Интервизия", permission: "admin.intervision.view" },
      { id: "ADM-23", href: "/admin/events", label: "Мероприятия", permission: "admin.events.view" },
    ],
  },
  {
    title: "Контент и коммуникации",
    items: [
      { id: "ADM-17", href: "/admin/articles", label: "Статьи", permission: "admin.articles.view" },
      { id: "ADM-18", href: "/admin/knowledge", label: "База знаний", permission: "admin.kb.view" },
      { id: "ADM-20", href: "/admin/content", label: "Контент сайта", permission: "admin.content.view" },
      { id: "ADM-10", href: "/admin/notifications", label: "Email-уведомления", permission: "admin.notifications.view" },
      { id: "ADM-11", href: "/admin/mailing", label: "Email-маркетинг", permission: "admin.mailing.view" },
    ],
  },
  {
    title: "Система",
    items: [
      { id: "ADM-13", href: "/admin/dictionaries", label: "Справочники", permission: "admin.dictionaries.view" },
      { id: "ADM-24", href: "/admin/analytics", label: "Аналитика", permission: "admin.analytics.view" },
      { id: "ADM-05", href: "/admin/roles", label: "Роли и права", permission: "admin.roles.view" },
      { id: "ADM-21", href: "/admin/white-label", label: "White-label", permission: "admin.instance.view" },
      { id: "ADM-26", href: "/admin/settings", label: "Настройки платформы", permission: "admin.settings.view" },
      { id: "ADM-25", href: "/admin/audit", label: "Журнал аудита", permission: "admin.audit.view" },
    ],
  },
];

export const hrNav: NavGroup[] = [
  {
    items: [
      { id: "HR-01", href: "/hr", label: "Обзор программы" },
      { id: "HR-02", href: "/hr/employees", label: "Сотрудники" },
      { id: "HR-03", href: "/hr/program", label: "Условия и лимиты" },
      { id: "HR-04", href: "/hr/invoices", label: "Счета и акты" },
      { id: "HR-05", href: "/hr/reports", label: "Отчёты" },
      { id: "HR-06", href: "/hr/materials", label: "Материалы для сотрудников" },
      { id: "HR-07", href: "/hr/company", label: "Пользователи и реквизиты" },
    ],
  },
];
