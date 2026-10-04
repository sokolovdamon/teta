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
      { id: "CL-01", href: "/client", label: "Главная" },
      { id: "CL-03", href: "/client/sessions", label: "Сессии" },
      { id: "CL-04", href: "/client/psychologist", label: "Мой психолог" },
      { id: "CL-05", href: "/client/recommendations", label: "Рекомендации" },
      { id: "CL-06", href: "/client/diary", label: "Дневник эмоций" },
      { id: "CL-07", href: "/client/wallet", label: "Баланс и оплата" },
      { id: "CL-10", href: "/client/reviews", label: "Отзывы" },
      { id: "CL-12", href: "/client/chat", label: "Чат и поддержка" },
      { id: "CL-13", href: "/client/invite", label: "Пригласить друга" },
      { id: "CL-14", href: "/client/notifications", label: "Уведомления" },
      { id: "CL-15", href: "/client/settings", label: "Профиль и согласия" },
    ],
  },
];

export const proNav: NavGroup[] = [
  {
    title: "Работа с клиентами",
    items: [
      { id: "PRO-04", href: "/pro", label: "Календарь записей" },
      { id: "PRO-05", href: "/pro/clients", label: "Клиенты" },
      { id: "PRO-03", href: "/pro/schedule", label: "График и отпуска" },
      { id: "PRO-08", href: "/pro/income", label: "Доход и выплаты" },
    ],
  },
  {
    title: "Профиль",
    items: [
      { id: "PRO-02", href: "/pro/profile", label: "Профиль специалиста" },
      { id: "PRO-01", href: "/pro/documents", label: "Дипломы и проверка" },
    ],
  },
  {
    title: "Развитие",
    items: [
      { id: "PRO-12", href: "/pro/supervision", label: "Супервизия" },
      { id: "PRO-13", href: "/pro/supervisor", label: "Рабочее место супервизора", roles: ["supervisor"] },
      { id: "PRO-14", href: "/pro/intervision", label: "Интервизия" },
      { id: "PRO-15", href: "/pro/articles", label: "Мои статьи" },
      { id: "PRO-16", href: "/pro/knowledge", label: "База знаний" },
    ],
  },
  {
    items: [
      { id: "PRO-17", href: "/pro/support", label: "Поддержка" },
      { id: "PRO-18", href: "/pro/notifications", label: "Уведомления" },
      { id: "PRO-19", href: "/pro/settings", label: "Настройки и согласия" },
    ],
  },
];

export const adminNav: NavGroup[] = [
  {
    title: "Люди",
    items: [
      { id: "ADM-01", href: "/admin", label: "Сводка", permission: "admin.dashboard.view" },
      { id: "ADM-02", href: "/admin/users", label: "Пользователи", permission: "admin.users.view" },
      { id: "ADM-03", href: "/admin/psychologists", label: "Психологи и проверка", permission: "admin.psychologists.view" },
      { id: "ADM-04", href: "/admin/sessions", label: "Сессии и сложные случаи", permission: "admin.sessions.view" },
      { id: "ADM-05", href: "/admin/roles", label: "Роли и права", permission: "admin.roles.manage" },
    ],
  },
  {
    title: "Деньги",
    items: [
      { id: "ADM-07", href: "/admin/finance", label: "Финансы", permission: "admin.finance.view" },
      { id: "ADM-08", href: "/admin/complaints", label: "Жалобы и возвраты", permission: "admin.complaints.view" },
      { id: "ADM-08b", href: "/admin/payouts", label: "Выплаты", permission: "admin.payouts.view" },
      { id: "ADM-09", href: "/admin/promo", label: "Промокоды и сертификаты", permission: "admin.promo.manage" },
      { id: "ADM-12", href: "/admin/companies", label: "Компании (B2B)", permission: "admin.companies.manage" },
    ],
  },
  {
    title: "Контент и коммуникации",
    items: [
      { id: "ADM-06", href: "/admin/reviews", label: "Модерация отзывов", permission: "admin.reviews.moderate" },
      { id: "ADM-17", href: "/admin/articles", label: "Статьи", permission: "admin.articles.manage" },
      { id: "ADM-13", href: "/admin/pages", label: "Страницы и посадочные", permission: "admin.cms.manage" },
      { id: "ADM-14", href: "/admin/events", label: "Мероприятия", permission: "admin.events.manage" },
      { id: "ADM-10", href: "/admin/notifications", label: "Уведомления", permission: "admin.notifications.manage" },
      { id: "ADM-11", href: "/admin/mailing", label: "Email-маркетинг", permission: "admin.mailing.manage" },
      { id: "ADM-19", href: "/admin/support", label: "Техподдержка", permission: "admin.support.view" },
    ],
  },
  {
    title: "Профессиональное развитие",
    items: [
      { id: "ADM-15", href: "/admin/supervision", label: "Супервизия", permission: "admin.supervision.manage" },
      { id: "ADM-16", href: "/admin/intervision", label: "Интервизия", permission: "admin.intervision.manage" },
      { id: "ADM-18", href: "/admin/knowledge", label: "База знаний", permission: "admin.kb.manage" },
    ],
  },
  {
    title: "Система",
    items: [
      { id: "ADM-20", href: "/admin/dictionaries", label: "Справочники", permission: "admin.dictionaries.manage" },
      { id: "ADM-22", href: "/admin/analytics", label: "Аналитика", permission: "admin.analytics.view" },
      { id: "ADM-23", href: "/admin/settings", label: "Параметры платформы", permission: "admin.settings.manage" },
      { id: "ADM-24", href: "/admin/documents", label: "Документы и согласия", permission: "admin.documents.manage" },
      { id: "ADM-21", href: "/admin/instances", label: "Партнёрские инстансы", permission: "admin.instances.manage" },
      { id: "ADM-25", href: "/admin/audit", label: "Журнал аудита", permission: "admin.audit.view" },
    ],
  },
];

export const hrNav: NavGroup[] = [
  {
    items: [
      { id: "HR-01", href: "/hr", label: "Сводка" },
      { id: "HR-02", href: "/hr/employees", label: "Сотрудники" },
      { id: "HR-03", href: "/hr/program", label: "Условия программы" },
      { id: "HR-04", href: "/hr/invoices", label: "Счета и акты" },
      { id: "HR-05", href: "/hr/reports", label: "Отчёты" },
    ],
  },
];
