<?php

namespace App\Modules\Psychologists\Services;

use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Events\Outbox;

/**
 * BR-PSY-04 (SEQ-12): the profile is published when the qualification is approved, the profile is complete and
 * there is at least one working interval; it is unpublished when any of these stops being true (for example,
 * the qualification is revoked). Pause, block and supervision inactivity do not unpublish — they only close
 * new bookings (Psychologist::scopeBookable), so the page shows its inactive variant.
 */
class PublicationService
{
    public function __construct(private ProfileCompleteness $completeness) {}

    public function shouldBePublished(Psychologist $p): bool
    {
        return $p->qualification_status === 'approved'
            && $p->scheduleIntervals()->exists()
            && $this->completeness->isPublishable($p);
    }

    public function sync(Psychologist $p, ?string $actorId = null): bool
    {
        $should = $this->shouldBePublished($p);
        if ($should && ! $p->is_published) {
            $p->forceFill(['is_published' => true, 'published_at' => now()])->save();
            Outbox::record('psy.profile.published', $p, ['slug' => $p->slug], $actorId);
        } elseif (! $should && $p->is_published) {
            $p->forceFill(['is_published' => false])->save();
            Outbox::record('psy.profile.unpublished', $p, ['slug' => $p->slug], $actorId);
        }

        return $should;
    }
}
