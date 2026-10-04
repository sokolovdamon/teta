<?php

/*
 * BOOK letters (DEC-16, PRO-10, SEQ-01…SEQ-05, SEQ-07). Times are given in the recipient's timezone with the zone
 * stated. Letters never contain the client's requests or "сведения о состоянии" (BR-NOTIF-04) and never contain a
 * link to the video room: entry is only from the cabinet (BR-BOOK-06).
 */
return [
    // ── Client ────────────────────────────────────────────────────────────────────────────────────────────────
    'book.session_booked' => [
        'title' => 'Запись подтверждена', 'audience' => 'client',
        'subject' => 'Запись подтверждена: {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nВы записаны на {{format}} с психологом {{psychologist}}.\nКогда: {{date}}.\n\n{{payment_note}}\n\nВойти в видеосессию TetaMeet можно из личного кабинета за {{room_open}} мин до начала. Перенести или отменить запись — в разделе «Сессии».",
        'center_text' => 'Запись подтверждена: {{date}}',
        'link' => '/client/sessions',
        'action_text' => 'Открыть кабинет',
    ],
    'book.session_rescheduled' => [
        'title' => 'Сессия перенесена', 'audience' => 'client',
        'subject' => 'Сессия перенесена на {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nСессия с психологом {{psychologist}} перенесена.\nБыло: {{old_date}}.\nСтало: {{date}}.\n\n{{payment_note}}",
        'center_text' => 'Сессия перенесена на {{date}}',
        'link' => '/client/sessions',
    ],
    'book.session_cancelled_client' => [
        'title' => 'Сессия отменена вами', 'audience' => 'client',
        'subject' => 'Сессия {{date}} отменена',
        'body' => "Здравствуйте, {{name}}!\n\nСессия с психологом {{psychologist}} ({{date}}) отменена.\n\n{{refund_note}}",
        'center_text' => 'Сессия {{date}} отменена',
        'link' => '/client/sessions',
    ],
    'book.psy_cancelled_choice' => [
        'title' => 'Психолог отменил оплаченную сессию', 'audience' => 'client',
        'subject' => 'Психолог отменил сессию {{date}} — выберите возврат или перенос',
        'body' => "Здравствуйте, {{name}}!\n\nК сожалению, психолог {{psychologist}} отменил сессию {{date}}. Приносим извинения.\n\nВыберите в разделе «Сессии»: вернуть {{amount}} на баланс личного кабинета или бесплатно перенести сессию на другое свободное время этого психолога. Если не выбрать до {{deadline}}, оплата вернётся на баланс автоматически.",
        'center_text' => 'Психолог отменил сессию {{date}}: выберите возврат или перенос',
        'link' => '/client/sessions',
        'action_text' => 'Выбрать',
    ],
    'book.psy_cancelled_free' => [
        'title' => 'Психолог отменил сессию', 'audience' => 'client',
        'subject' => 'Психолог отменил сессию {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nК сожалению, психолог {{psychologist}} отменил сессию {{date}}. Оплата за неё не списывалась.\n\nВы можете выбрать другое время у этого психолога или подобрать другого специалиста.",
        'center_text' => 'Психолог отменил сессию {{date}}',
        'link' => '/client/sessions',
    ],
    'book.session_cancelled_platform' => [
        'title' => 'Сессия отменена платформой', 'audience' => 'client',
        'subject' => 'Сессия {{date}} отменена',
        'body' => "Здравствуйте, {{name}}!\n\nСессия с психологом {{psychologist}} ({{date}}) отменена по решению платформы.\n\n{{refund_note}}\n\nВы можете записаться на другое время или подобрать другого специалиста.",
        'center_text' => 'Сессия {{date}} отменена платформой',
        'link' => '/client/sessions',
    ],
    'book.session_cancelled_unpaid' => [
        'title' => 'Сессия отменена: оплата не прошла', 'audience' => 'client',
        'subject' => 'Сессия {{date}} отменена: оплата не прошла',
        'body' => "Здравствуйте, {{name}}!\n\nМы не смогли списать оплату за сессию с психологом {{psychologist}} ({{date}}) до крайнего срока, поэтому запись отменена. Никаких удержаний нет.\n\nВы можете записаться снова — проверьте карту в разделе «Платежи и баланс».",
        'center_text' => 'Сессия {{date}} отменена: оплата не прошла',
        'link' => '/client/payments',
    ],
    'book.psy_no_show_choice' => [
        'title' => 'Психолог не подключился', 'audience' => 'client',
        'subject' => 'Психолог не подключился к сессии {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nПсихолог {{psychologist}} не подключился к сессии {{date}}. Приносим извинения.\n\nВыберите в разделе «Сессии»: вернуть {{amount}} на баланс личного кабинета или бесплатно перенести сессию. Если не выбрать до {{deadline}}, оплата вернётся на баланс автоматически.",
        'center_text' => 'Психолог не подключился: выберите возврат или перенос',
        'link' => '/client/sessions',
        'action_text' => 'Выбрать',
    ],
    'book.tech_issue_choice' => [
        'title' => 'Техническая проблема на сессии', 'audience' => 'client',
        'subject' => 'Сессия {{date}} прервалась — выберите возврат или перенос',
        'body' => "Здравствуйте, {{name}}!\n\nСессия {{date}} с психологом {{psychologist}} не состоялась из-за технической проблемы.\n\nВыберите в разделе «Сессии»: вернуть {{amount}} на баланс личного кабинета или бесплатно перенести сессию. Если не выбрать до {{deadline}}, оплата вернётся на баланс автоматически.",
        'center_text' => 'Техническая проблема на сессии: выберите возврат или перенос',
        'link' => '/client/sessions',
        'action_text' => 'Выбрать',
    ],
    'book.client_no_show' => [
        'title' => 'Неявка на сессию', 'audience' => 'client',
        'subject' => 'Сессия {{date}} отмечена как неявка',
        'body' => "Здравствуйте, {{name}}!\n\nВы не подключились к сессии {{date}} с психологом {{psychologist}}, поэтому она отмечена как неявка. Время специалиста было зарезервировано, оплата не возвращается.\n\nЕсли вы не согласны, подайте жалобу на списание в разделе «Платежи и баланс» — мы рассмотрим её в течение 14 рабочих дней.",
        'center_text' => 'Сессия {{date}} отмечена как неявка',
        'link' => '/client/payments',
    ],
    'book.refund_credited' => [
        'title' => 'Возврат на баланс', 'audience' => 'client',
        'subject' => '{{amount}} зачислено на баланс личного кабинета',
        'body' => "Здравствуйте, {{name}}!\n\nОплата сессии {{date}} ({{amount}}) зачислена на баланс личного кабинета. Баланс расходуется первым при следующей записи; остаток можно вывести на карту в разделе «Платежи и баланс».",
        'center_text' => '{{amount}} зачислено на баланс',
        'link' => '/client/payments',
    ],
    'book.change_psychologist_done' => [
        'title' => 'Смена психолога', 'audience' => 'client',
        'subject' => 'Записи к психологу {{psychologist}} отменены',
        'body' => "Здравствуйте, {{name}}!\n\nОтменено сессий: {{count}}. На баланс личного кабинета зачислено: {{amount}}.\n\nВы можете заново пройти анкету и выбрать нового психолога — баланс будет использован при оплате.",
        'center_text' => 'Записи отменены, на баланс зачислено {{amount}}',
        'link' => '/client/payments',
    ],
    'book.session_reminder' => [
        'title' => 'Напоминание о сессии', 'audience' => 'client',
        'subject' => 'Напоминание: сессия {{when}}',
        'body' => "Здравствуйте, {{name}}!\n\nНапоминаем о сессии с психологом {{psychologist}}: {{date}}.\n\nВход в TetaMeet — из личного кабинета, раздел «Сессии», за {{room_open}} мин до начала. Проверьте камеру и микрофон заранее.",
        'center_text' => 'Сессия {{when}}: {{date}}',
        'link' => '/client/sessions',
    ],
    'book.pair_invitation' => [
        'title' => 'Приглашение на парную сессию', 'audience' => 'any',
        'subject' => '{{inviter}} приглашает вас на парную сессию',
        'body' => "Здравствуйте!\n\n{{inviter}} приглашает вас на парную сессию с психологом {{psychologist}}: {{date}}.\n\nЧтобы участвовать, войдите или зарегистрируйтесь на ТЕТА с этим адресом email и примите приглашение. В вашем кабинете будет видна только эта сессия; оплату вносит пригласивший.",
        'center_text' => 'Приглашение на парную сессию {{date}}',
        'action_text' => 'Принять приглашение',
    ],
    'book.pair_accepted' => [
        'title' => 'Партнёр принял приглашение', 'audience' => 'client',
        'subject' => 'Партнёр принял приглашение на сессию {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nВторой участник принял приглашение на парную сессию {{date}}.",
        'link' => '/client/sessions',
    ],
    'book.time_request_offered' => [
        'title' => 'Психолог предложил время', 'audience' => 'client',
        'subject' => 'Психолог {{psychologist}} ответил на запрос времени',
        'body' => "Здравствуйте, {{name}}!\n\nПсихолог {{psychologist}} ответил на ваш запрос «Нет подходящего времени».\n\n{{offer}}\n\nЗаписаться можно в кабинете.",
        'center_text' => 'Психолог {{psychologist}} предложил время',
        'link' => '/client/sessions',
    ],
    'book.time_request_closed' => [
        'title' => 'Запрос времени закрыт', 'audience' => 'client',
        'subject' => 'Запрос времени у психолога {{psychologist}} закрыт',
        'body' => "Здравствуйте, {{name}}!\n\nПсихолог {{psychologist}} не сможет предложить другое время. {{comment}}\n\nВы можете подобрать другого специалиста.",
        'link' => '/client/sessions',
    ],

    // ── Psychologist (PRO-10) ────────────────────────────────────────────────────────────────────────────────────
    'book.psy_new_booking' => [
        'title' => 'Новая запись', 'audience' => 'psychologist',
        'subject' => 'Новая запись: {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nК вам записался клиент {{client}}: {{format}}, {{date}}.\n\nПодробности — в календаре записей.",
        'center_text' => 'Новая запись: {{client}}, {{date}}',
        'link' => '/pro',
    ],
    'book.psy_session_rescheduled' => [
        'title' => 'Перенос записи', 'audience' => 'psychologist',
        'subject' => 'Перенос: {{client}}, {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nСессия с клиентом {{client}} перенесена.\nБыло: {{old_date}}.\nСтало: {{date}}.",
        'center_text' => 'Перенос: {{client}} → {{date}}',
        'link' => '/pro',
    ],
    'book.psy_session_cancelled' => [
        'title' => 'Отмена записи', 'audience' => 'psychologist',
        'subject' => 'Отмена: {{client}}, {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nСессия с клиентом {{client}} ({{date}}) отменена. Время снова свободно для записи.",
        'center_text' => 'Отмена: {{client}}, {{date}}',
        'link' => '/pro',
    ],
    'book.psy_session_reminder' => [
        'title' => 'Напоминание о сессии', 'audience' => 'psychologist',
        'subject' => 'Напоминание: сессия {{when}} с клиентом {{client}}',
        'body' => "Здравствуйте, {{name}}!\n\nНапоминаем о сессии с клиентом {{client}}: {{date}}. Запуск TetaMeet — из календаря записей.",
        'center_text' => 'Сессия {{when}}: {{client}}, {{date}}',
        'link' => '/pro',
    ],
    'book.psy_time_request' => [
        'title' => 'Запрос времени от клиента', 'audience' => 'psychologist',
        'subject' => 'Клиент {{client}} просит другое время',
        'body' => "Здравствуйте, {{name}}!\n\nКлиенту {{client}} не подошло ни одно свободное время. Удобное время клиента: {{preferred}}.\n\nОткройте подходящие слоты в графике и ответьте на запрос в календаре записей или закройте его.",
        'center_text' => 'Запрос времени от клиента {{client}}',
        'link' => '/pro?tab=requests',
    ],
    'book.psy_no_show_recorded' => [
        'title' => 'Зафиксирована неявка психолога', 'audience' => 'psychologist',
        'subject' => 'Неявка на сессию {{date}}',
        'body' => "Здравствуйте, {{name}}!\n\nПо журналу сессий вы не подключились к сессии с клиентом {{client}} ({{date}}). Клиенту предложен возврат или бесплатный перенос. Если это ошибка, обратитесь в техподдержку.",
        'link' => '/pro',
    ],

    // ── Administrators ────────────────────────────────────────────────────────────────────────────────────────────
    'book.quality_threshold' => [
        'title' => 'Порог инцидентов качества', 'audience' => 'admin',
        'subject' => 'Психолог {{psychologist}}: {{count}} инцидентов качества за 30 дней',
        'body' => 'У психолога {{psychologist}} за последние 30 дней {{count}} поздних отмен или неявок. Нужен разбор (BR-CANC-08).',
        'center_text' => 'Инциденты качества: {{psychologist}} ({{count}})',
        'link' => '/admin/sessions',
    ],
    'book.psy_no_show_admin' => [
        'title' => 'Неявка психолога', 'audience' => 'admin',
        'subject' => 'Неявка психолога {{psychologist}} на сессию {{date}}',
        'body' => 'Психолог {{psychologist}} не подключился к сессии {{date}}. Клиенту предложен возврат на баланс или бесплатный перенос. Проверьте журнал сессий.',
        'center_text' => 'Неявка психолога {{psychologist}}',
        'link' => '/admin/sessions/{{session_id}}',
    ],
];
