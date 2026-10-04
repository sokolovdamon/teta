<?php

namespace App\Modules\Promo;

use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Promo\Listeners\ReferralRegistrationListener;
use App\Modules\Promo\Listeners\ReferralRewardListener;
use App\Modules\Promo\Models\PromoBatch;
use App\Modules\Promo\Models\ReferralInvite;
use App\Modules\Promo\Services\PromoService;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/** PROMO (Э8, ST-17) and the referral program (CL-13, DEC-42, SEQ-19). */
class PromoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromoCodes::class, PromoService::class);
    }

    public function boot(): void
    {
        Relation::morphMap(['promo_batch' => PromoBatch::class, 'referral_invite' => ReferralInvite::class]);

        Outbox::listen('auth.user.registered', ReferralRegistrationListener::class);
        Outbox::listen('book.session.paid', ReferralRewardListener::class);
    }
}
