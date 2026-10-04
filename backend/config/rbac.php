<?php

/*
 * Permission catalogue for the "role — section — action" matrix (ADM-05, X-02).
 * Permission code = "{code}.{action}". The matrix itself lives in the DB and is edited without code changes.
 *
 * Hard restrictions are NOT permissions and cannot be granted to any role (TZ v2, section 8):
 * a psychologist's private notes are visible only to their author; a client's diary and session history
 * are visible only to that client's psychologist. They are enforced in policies.
 */
return [
    'sections' => [
        'ADM-01' => ['code' => 'admin.dashboard', 'title' => 'Дашборд', 'actions' => ['view']],
        'ADM-02' => ['code' => 'admin.users', 'title' => 'Пользователи', 'actions' => ['view', 'update', 'block', 'reset_password', 'assign_roles', 'export']],
        'ADM-03' => ['code' => 'admin.psychologists', 'title' => 'Психологи', 'actions' => ['view', 'update', 'verify', 'moderate_profile', 'block']],
        'ADM-04' => ['code' => 'admin.sessions', 'title' => 'Сессии и журнал сессий', 'actions' => ['view', 'update', 'set_outcome', 'cancel']],
        'ADM-05' => ['code' => 'admin.roles', 'title' => 'Роли и права', 'actions' => ['view', 'manage']],
        'ADM-06' => ['code' => 'admin.moderation', 'title' => 'Модерация', 'actions' => ['view', 'moderate', 'print_consents']],
        'ADM-07' => ['code' => 'admin.finance', 'title' => 'Финансы', 'actions' => ['view', 'refund', 'complaints', 'export']],
        'ADM-08' => ['code' => 'admin.payouts', 'title' => 'Выплаты', 'actions' => ['view', 'approve', 'manage']],
        'ADM-09' => ['code' => 'admin.promo', 'title' => 'Промокоды', 'actions' => ['view', 'manage', 'export']],
        'ADM-10' => ['code' => 'admin.notifications', 'title' => 'Email-уведомления', 'actions' => ['view', 'manage']],
        'ADM-11' => ['code' => 'admin.mailing', 'title' => 'Email-маркетинг', 'actions' => ['view', 'manage', 'send']],
        'ADM-12' => ['code' => 'admin.reports', 'title' => 'Отчёты и выгрузки', 'actions' => ['view', 'export']],
        'ADM-13' => ['code' => 'admin.dictionaries', 'title' => 'Справочники', 'actions' => ['view', 'manage']],
        'ADM-14' => ['code' => 'admin.matching', 'title' => 'Подбор и Герман', 'actions' => ['view', 'manage', 'handle_dialogs']],
        'ADM-15' => ['code' => 'admin.supervision', 'title' => 'Супервизия', 'actions' => ['view', 'manage', 'credit_month']],
        'ADM-16' => ['code' => 'admin.intervision', 'title' => 'Интервизия', 'actions' => ['view', 'manage', 'moderate']],
        'ADM-17' => ['code' => 'admin.articles', 'title' => 'Статьи', 'actions' => ['view', 'moderate', 'manage']],
        'ADM-18' => ['code' => 'admin.kb', 'title' => 'База знаний', 'actions' => ['view', 'manage']],
        'ADM-19' => ['code' => 'admin.support', 'title' => 'Техподдержка', 'actions' => ['view', 'reply', 'assign', 'manage_templates']],
        'ADM-20' => ['code' => 'admin.content', 'title' => 'Контент сайта', 'actions' => ['view', 'manage']],
        'ADM-21' => ['code' => 'admin.instance', 'title' => 'White-label', 'actions' => ['view', 'manage']],
        'ADM-22' => ['code' => 'admin.companies', 'title' => 'Корпоративные клиенты', 'actions' => ['view', 'manage', 'invoices']],
        'ADM-23' => ['code' => 'admin.events', 'title' => 'Мероприятия', 'actions' => ['view', 'manage']],
        'ADM-24' => ['code' => 'admin.analytics', 'title' => 'Аналитика', 'actions' => ['view', 'export']],
        'ADM-25' => ['code' => 'admin.audit', 'title' => 'Журнал аудита', 'actions' => ['view', 'export']],
        'ADM-26' => ['code' => 'admin.settings', 'title' => 'Настройки платформы', 'actions' => ['view', 'manage']],
    ],

    'action_titles' => [
        'view' => 'Просмотр', 'update' => 'Изменение', 'manage' => 'Управление', 'block' => 'Блокировка',
        'reset_password' => 'Сброс пароля', 'assign_roles' => 'Смена роли', 'export' => 'Выгрузка', 'verify' => 'Проверка квалификации',
        'moderate_profile' => 'Модерация профиля', 'set_outcome' => 'Итог сессии', 'cancel' => 'Отмена', 'moderate' => 'Модерация',
        'print_consents' => 'Печать согласий', 'refund' => 'Возвраты', 'complaints' => 'Жалобы на списание', 'approve' => 'Утверждение',
        'send' => 'Отправка', 'handle_dialogs' => 'Диалоги Германа', 'credit_month' => 'Зачёт супервизии', 'reply' => 'Ответы',
        'assign' => 'Назначение', 'manage_templates' => 'Шаблоны ответов', 'invoices' => 'Счета и акты',
    ],

    // Default grants; super_admin implicitly has everything.
    'defaults' => [
        'admin' => [
            'except' => ['admin.roles.manage', 'admin.settings.manage', 'admin.instance.manage'],
        ],
    ],

    'roles' => [
        'client' => ['title' => 'Клиент', 'description' => 'Получатель психологических услуг'],
        'psychologist' => ['title' => 'Психолог', 'description' => 'Специалист, оказывающий услуги на платформе'],
        'supervisor' => ['title' => 'Супервизор', 'description' => 'Психолог с расширенными правами, проводящий супервизию'],
        'admin' => ['title' => 'Администратор', 'description' => 'Модерация, техподдержка, пользователи, контент и выплаты'],
        'super_admin' => ['title' => 'Супер-администратор', 'description' => 'Полный доступ, роли и настройки платформы'],
        'hr' => ['title' => 'HR-менеджер компании', 'description' => 'Корпоративная программа (п. 11.1 ТЗ)'],
    ],
];
