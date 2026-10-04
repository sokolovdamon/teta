<?php

// PROMO: referral program letters (DEC-42, SEQ-19).
return [
    'promo.referral_friend_code' => [
        'title' => 'Промокод от друга', 'audience' => 'client',
        'subject' => 'Ваш промокод на первую сессию: {{code}}',
        'body' => "Здравствуйте, {{name}}!\n\nВас пригласили в ТЕТА. Промокод {{code}} даёт скидку на первую оплаченную сессию ({{discount}}). Он действует до {{valid_until}}.\n\nВведите промокод при записи к психологу.",
        'center_text' => 'Промокод {{code}} на первую сессию: {{discount}}',
        'action_text' => 'Выбрать психолога',
    ],
    'promo.referral_reward' => [
        'title' => 'Промокод за приглашённого друга', 'audience' => 'client',
        'subject' => 'Спасибо, что пригласили друга: промокод {{code}}',
        'body' => "Здравствуйте, {{name}}!\n\nДруг, которого вы пригласили, оплатил первую сессию. Ваш промокод {{code}} ({{discount}}) действует до {{valid_until}} — примените его при записи или в разделе «Сессии» до списания оплаты.",
        'center_text' => 'Промокод {{code}} за приглашённого друга: {{discount}}',
        'action_text' => 'Открыть',
    ],
];
