<?php

namespace App\Modules\Promo\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DEC-42, SEQ-19: the friend gets a first-session code; the inviter gets a code after the friend's first paid session.
 * Statuses: registered (friend signed up, code issued) → rewarded; rejected (self-invite, not a new client, same card).
 */
class ReferralInvite extends Model
{
    use HasUuids, UtcDates;

    public const STATUS_LABELS = [
        'sent' => 'Приглашение отправлено',
        'registered' => 'Друг зарегистрировался',
        'first_paid' => 'Друг оплатил первую сессию',
        'rewarded' => 'Промокод получен',
        'rejected' => 'Не засчитано',
    ];

    public const REJECTED_LABELS = [
        'self_invite' => 'Приглашение самого себя',
        'not_new_client' => 'Друг уже был клиентом платформы',
        'not_client' => 'Приглашённый зарегистрировался не как клиент',
        'same_card' => 'Та же карта, что у пригласившего',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'rewarded_at' => 'datetime'];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id')->withTrashed();
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id')->withTrashed();
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function rewardCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'reward_code_id');
    }
}
