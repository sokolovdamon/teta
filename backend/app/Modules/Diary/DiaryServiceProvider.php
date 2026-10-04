<?php

namespace App\Modules\Diary;

use App\Modules\Diary\Listeners\DestroyDiaryOfAnonymizedUser;
use App\Modules\Diary\Models\EmotionTag;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/** DIARY (DEC-41): emotion diary of clients; dynamics for their psychologist only. */
class DiaryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap(['emotion_tag' => EmotionTag::class]);

        Outbox::listen('account.user.anonymized', DestroyDiaryOfAnonymizedUser::class);
    }
}
