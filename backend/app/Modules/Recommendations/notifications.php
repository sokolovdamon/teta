<?php

// RECO: letters carry no content of the recommendation (BR-NOTIF-04) — only the fact and a link to the cabinet.
return [
    'reco.recommendation_sent' => [
        'title' => 'Новая рекомендация от психолога', 'audience' => 'client',
        'subject' => 'Новая рекомендация в личном кабинете ТЕТА',
        'body' => "Здравствуйте, {{name}}!\n\nВаш психолог оставил для вас рекомендацию после сессии. Посмотреть её можно в личном кабинете.",
        'center_text' => 'Новая рекомендация от психолога',
        'action_text' => 'Открыть рекомендацию',
    ],
];
