<?php

/*
 * Rule parameters P-* (ADM-26). Values here are defaults; admins override them in platform_settings.
 * Durations are in minutes unless the key says otherwise; money in kopecks; percents as integers.
 * Sources: business rules v1 parameter table, decisions registry v2.1 (DEC-xx), open-question assumptions (Q-xx).
 */
return [
    'timezone' => 'Europe/Moscow',

    'parameters' => [
        // Booking and sessions
        'P-CHARGE-OFFSET' => ['value' => 720, 'unit' => 'min', 'group' => 'booking', 'title' => 'Автосписание до начала сессии'],
        'P-CHARGE-RETRY' => ['value' => [120, 360, 540], 'unit' => 'min[]', 'group' => 'booking', 'title' => 'Повторы списания после первой попытки'],
        'P-CHARGE-DEADLINE' => ['value' => 120, 'unit' => 'min', 'group' => 'booking', 'title' => 'Крайний срок оплаты до начала'],
        'P-SLOT-HOLD' => ['value' => 15, 'unit' => 'min', 'group' => 'booking', 'title' => 'Удержание слота при записи'],
        'P-BOOK-MIN-LEAD' => ['value' => 180, 'unit' => 'min', 'group' => 'booking', 'title' => 'Минимум от записи до начала'],
        'P-BOOK-HORIZON' => ['value' => 28, 'unit' => 'days', 'group' => 'booking', 'title' => 'Горизонт записи'],
        'P-BOOK-MAX-UPCOMING' => ['value' => 3, 'unit' => 'count', 'group' => 'booking', 'title' => 'Предстоящих сессий у одного психолога'],
        'P-SESSION-DURATION-IND' => ['value' => 50, 'unit' => 'min', 'group' => 'booking', 'title' => 'Индивидуальная сессия (DEC-19)'],
        'P-SESSION-DURATION-PAIR' => ['value' => 90, 'unit' => 'min', 'group' => 'booking', 'title' => 'Парная сессия (DEC-19)'],
        'P-BUFFER' => ['value' => 10, 'unit' => 'min', 'group' => 'booking', 'title' => 'Перерыв между сессиями'],
        'P-LATE-CANCEL-REFUND' => ['value' => 0, 'unit' => '%', 'group' => 'booking', 'title' => 'Возврат при отмене после списания (DEC-23)'],
        'P-LATE-RESCHEDULE-LIMIT' => ['value' => 1, 'unit' => 'count', 'group' => 'booking', 'title' => 'Переносов после списания на сессию'],
        'P-REMINDERS' => ['value' => [1440, 60], 'unit' => 'min[]', 'group' => 'booking', 'title' => 'Напоминания о сессии'],
        'P-RECO-WINDOW' => ['value' => 7, 'unit' => 'days', 'group' => 'booking', 'title' => 'Окно рекомендаций после сессии'],
        'P-COMPLAINT-REVIEW' => ['value' => 14, 'unit' => 'working_days', 'group' => 'booking', 'title' => 'Срок рассмотрения жалобы (DEC-23)'],
        'P-QUALITY-INCIDENT-THRESHOLD' => ['value' => 2, 'unit' => 'count', 'group' => 'booking', 'title' => 'Инцидентов качества за 30 дней'],

        // TetaMeet
        'P-ROOM-OPEN' => ['value' => 10, 'unit' => 'min', 'group' => 'meet', 'title' => 'Комната открывается до начала'],
        'P-ROOM-CLOSE' => ['value' => 15, 'unit' => 'min', 'group' => 'meet', 'title' => 'Комната закрывается после окончания'],
        'P-NOSHOW-WAIT' => ['value' => 15, 'unit' => 'min', 'group' => 'meet', 'title' => 'Ожидание опоздавшего'],
        'P-OUTCOME-DEADLINE' => ['value' => 1440, 'unit' => 'min', 'group' => 'meet', 'title' => 'Срок отметки итога сессии'],
        'P-AUTO-COMPLETE-MIN' => ['value' => 30, 'unit' => 'min', 'group' => 'meet', 'title' => 'Совместное время для статуса «Проведена»'],
        'P-SESSION-END-WARNING' => ['value' => 5, 'unit' => 'min', 'group' => 'meet', 'title' => 'Предупреждение о конце сессии'],

        // Money and payouts
        'P-COMMISSION' => ['value' => 30, 'unit' => '%', 'group' => 'money', 'title' => 'Комиссия платформы (DEC-20)'],
        'P-PAYOUT-PERIOD' => ['value' => 'weekly_monday', 'unit' => 'enum', 'group' => 'money', 'title' => 'Период выплат'],
        'P-PAYOUT-MIN' => ['value' => 100000, 'unit' => 'kopecks', 'group' => 'money', 'title' => 'Минимальная выплата'],
        'P-PAYOUT-AUTO-APPROVE' => ['value' => true, 'unit' => 'bool', 'group' => 'money', 'title' => 'Автоутверждение реестра выплат'],
        'P-GIFT-VALIDITY' => ['value' => 365, 'unit' => 'days', 'group' => 'money', 'title' => 'Срок действия сертификата'],
        'P-GIFT-NOMINALS' => ['value' => [300000, 500000, 1000000], 'unit' => 'kopecks[]', 'group' => 'money', 'title' => 'Номиналы сертификатов'],

        // Supervision (DEC-21, DEC-37)
        'P-SUPERV-MONTHLY' => ['value' => 1, 'unit' => 'count', 'group' => 'supervision', 'title' => 'Супервизий в календарный месяц'],
        'P-SUPERV-GRACE' => ['value' => 'approval_month', 'unit' => 'enum', 'group' => 'supervision', 'title' => 'Льготный период'],
        'P-SUPERV-COMMISSION' => ['value' => 30, 'unit' => '%', 'group' => 'supervision', 'title' => 'Комиссия со стоимости супервизии'],
        'P-SUPERV-MODE' => ['value' => 'payment', 'unit' => 'enum', 'group' => 'supervision', 'title' => 'Режим: payment (оплата) или accounting (учёт)'],
        'P-SUPERV-REMINDERS' => ['value' => ['day_15', 'days_before_end_7', 'days_before_end_3'], 'unit' => 'enum[]', 'group' => 'supervision', 'title' => 'Напоминания о супервизии'],
        'P-SUPERV-CONFIRM-TTL' => ['value' => 2880, 'unit' => 'min', 'group' => 'supervision', 'title' => 'Срок подтверждения заявки супервизором'],
        'P-SUPERV-FREE-CANCEL' => ['value' => 1440, 'unit' => 'min', 'group' => 'supervision', 'title' => 'Бесплатная отмена супервизии до начала'],
        'P-SUPERV-PROTOCOL-DEADLINE' => ['value' => 4320, 'unit' => 'min', 'group' => 'supervision', 'title' => 'Срок протокола супервизии'],

        // Intervision
        'P-INTERV-GROUP-LIMIT' => ['value' => 12, 'unit' => 'count', 'group' => 'intervision', 'title' => 'Лимит участников группы'],
        'P-INTERV-WAITLIST-LIMIT' => ['value' => 10, 'unit' => 'count', 'group' => 'intervision', 'title' => 'Лимит листа ожидания'],
        'P-INTERV-WAITLIST-OFFER' => ['value' => 1440, 'unit' => 'min', 'group' => 'intervision', 'title' => 'Удержание места из листа ожидания'],
        'P-INTERV-SIGNUP-CLOSE' => ['value' => 120, 'unit' => 'min', 'group' => 'intervision', 'title' => 'Закрытие записи до встречи'],
        'P-INTERV-PROTOCOL-DEADLINE' => ['value' => 4320, 'unit' => 'min', 'group' => 'intervision', 'title' => 'Срок протокола встречи'],
        'P-INTERV-COMMENTS-PREMODERATION' => ['value' => false, 'unit' => 'bool', 'group' => 'intervision', 'title' => 'Премодерация комментариев (DM-11)'],
        'P-PARTICIPATION-MIN-SHARE' => ['value' => 50, 'unit' => '%', 'group' => 'intervision', 'title' => 'Доля времени для учёта участия'],

        // Content and moderation
        'P-REVIEW-SLA' => ['value' => 2, 'unit' => 'working_days', 'group' => 'content', 'title' => 'Срок модерации отзывов'],
        'P-CONTENT-MODERATION-SLA' => ['value' => 2, 'unit' => 'working_days', 'group' => 'content', 'title' => 'Срок модерации статей и профилей'],
        'P-DZEN-DELAY' => ['value' => 0, 'unit' => 'hours', 'group' => 'content', 'title' => 'Задержка статьи в RSS для Дзена (DEC-46)'],
        'P-VIDEO-CARD-LIMITS' => ['value' => ['seconds' => 90, 'megabytes' => 100], 'unit' => 'object', 'group' => 'content', 'title' => 'Видеовизитка: длительность и размер'],
        'P-FILE-MAX-SIZE' => ['value' => 20, 'unit' => 'MB', 'group' => 'content', 'title' => 'Максимальный размер файла'],

        // Bot German and support (DEC-14, DEC-54)
        'P-ADMIN-DUTY-HOURS' => ['value' => ['from' => '10:00', 'to' => '20:00'], 'unit' => 'object', 'group' => 'aibot', 'title' => 'Дежурство администратора, МСК'],
        'P-AIBOT-DIALOG-TTL' => ['value' => 30, 'unit' => 'days', 'group' => 'aibot', 'title' => 'Хранение текста диалога с Германом'],
        'P-AIBOT-RATE-LIMIT' => ['value' => 30, 'unit' => 'per_hour', 'group' => 'aibot', 'title' => 'Сообщений Герману в час'],
        'P-AIBOT-FALLBACK-COUNT' => ['value' => 2, 'unit' => 'count', 'group' => 'aibot', 'title' => 'Непонятых ответов до предложения администратора'],
        'P-SUPPORT-FIRST-RESPONSE' => ['value' => 60, 'unit' => 'min', 'group' => 'support', 'title' => 'Первый ответ в часы дежурства'],
        'P-SUPPORT-AUTOCLOSE' => ['value' => 4320, 'unit' => 'min', 'group' => 'support', 'title' => 'Автозакрытие без ответа клиента'],
        'P-SUPPORT-REOPEN' => ['value' => 7, 'unit' => 'days', 'group' => 'support', 'title' => 'Переоткрытие после закрытия'],
        'P-SUPPORT-RATING-WINDOW' => ['value' => 7, 'unit' => 'days', 'group' => 'support', 'title' => 'Окно оценки ответа'],
        'P-SUPPORT-ATTACH' => ['value' => ['files' => 5, 'megabytes' => 10], 'unit' => 'object', 'group' => 'support', 'title' => 'Вложения в обращении'],

        // Matching
        'P-MATCH-AVAILABILITY-DAYS' => ['value' => 7, 'unit' => 'days', 'group' => 'matching', 'title' => 'Окно доступности для подбора'],

        // Mailing (Э7)
        'P-MAIL-RATE' => ['value' => 60, 'unit' => 'per_minute', 'group' => 'mailing', 'title' => 'Скорость отправки рассылок'],
        'P-MAIL-RETRY' => ['value' => [5, 30, 120], 'unit' => 'min[]', 'group' => 'mailing', 'title' => 'Повторы при временных ошибках'],
        'P-MAIL-SOFT-BOUNCE-LIMIT' => ['value' => 3, 'unit' => 'count', 'group' => 'mailing', 'title' => 'Мягких отказов до исключения'],
        'P-MAIL-HARD-BOUNCE' => ['value' => 1, 'unit' => 'count', 'group' => 'mailing', 'title' => 'Жёстких отказов до исключения'],
        'P-MAIL-COMPLAINT-LIMIT' => ['value' => 1, 'unit' => 'count', 'group' => 'mailing', 'title' => 'Жалоб на спам до исключения'],
        'P-MAIL-FREQUENCY-CAP' => ['value' => 2, 'unit' => 'per_week', 'group' => 'mailing', 'title' => 'Маркетинговых писем в неделю'],
        'P-MAIL-INACTIVITY' => ['value' => 30, 'unit' => 'days', 'group' => 'mailing', 'title' => 'Неактивность для триггера'],
        'P-MAIL-STOP-THRESHOLD' => ['value' => 5, 'unit' => '%', 'group' => 'mailing', 'title' => 'Доля отказов для остановки рассылки'],

        // Accounts and retention
        'P-LOGIN-ATTEMPTS' => ['value' => 5, 'unit' => 'count', 'group' => 'account', 'title' => 'Неудачных входов до блокировки'],
        'P-LOGIN-LOCK' => ['value' => 15, 'unit' => 'min', 'group' => 'account', 'title' => 'Блокировка входа'],
        'P-EMAIL-LINK-TTL' => ['value' => 1440, 'unit' => 'min', 'group' => 'account', 'title' => 'Срок ссылки подтверждения email'],
        'P-EMAIL-RESEND-INTERVAL' => ['value' => 60, 'unit' => 'sec', 'group' => 'account', 'title' => 'Интервал повторной отправки письма'],
        'P-DELETE-GRACE' => ['value' => 30, 'unit' => 'days', 'group' => 'account', 'title' => 'Отсрочка удаления аккаунта'],
        'P-NOTIF-RETENTION' => ['value' => 180, 'unit' => 'days', 'group' => 'account', 'title' => 'Хранение центра уведомлений'],
        'P-NOTES-RETENTION' => ['value' => 3, 'unit' => 'years', 'group' => 'account', 'title' => 'Хранение заметок психолога'],

        // B2B (11.1)
        'P-B2B-OFFBOARD-NOTICE' => ['value' => 2880, 'unit' => 'min', 'group' => 'b2b', 'title' => 'Уведомление при отключении сотрудника'],
        'P-B2B-INVOICE-DAY' => ['value' => 1, 'unit' => 'day_of_month', 'group' => 'b2b', 'title' => 'День выставления счёта'],
    ],
];
