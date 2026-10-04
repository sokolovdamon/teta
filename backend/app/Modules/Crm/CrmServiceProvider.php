<?php

namespace App\Modules\Crm;

use App\Modules\Crm\Listeners\CloseAccessOnPsychologistChange;
use App\Modules\Crm\Listeners\DestroyNotesOfAnonymizedUser;
use App\Modules\Crm\Models\ClientCard;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/** CRM (PRO-05): clients of a psychologist, card marks and private notes. Emits crm.work.finished. */
class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap(['client_card' => ClientCard::class]);

        Outbox::listen('book.psychologist.changed', CloseAccessOnPsychologistChange::class);
        Outbox::listen('account.user.anonymized', DestroyNotesOfAnonymizedUser::class);
    }
}
