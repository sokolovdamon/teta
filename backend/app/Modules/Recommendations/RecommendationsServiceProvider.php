<?php

namespace App\Modules\Recommendations;

use App\Modules\Recommendations\Listeners\DestroyRecommendationsOfAnonymizedClient;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/** RECO (PRO-07, CL-05): recommendations after a held session. */
class RecommendationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap(['recommendation' => Recommendation::class]);

        Outbox::listen('account.user.anonymized', DestroyRecommendationsOfAnonymizedClient::class);
    }
}
