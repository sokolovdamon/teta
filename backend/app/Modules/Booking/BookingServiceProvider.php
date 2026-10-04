<?php

namespace App\Modules\Booking;

use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Contracts\NoCorporateCoverage;
use App\Modules\Booking\Listeners\CancelSessionsOfBlockedParticipant;
use App\Modules\Booking\Models\BookingIntent;
use App\Modules\Booking\Models\QualityIncident;
use App\Modules\Booking\Models\SessionTimeRequest;
use App\Modules\Booking\Purposes\BookingPaymentPurpose;
use App\Modules\Payments\Services\PaymentPurposes;
use App\Support\Events\Outbox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CorporateCoverage::class, NoCorporateCoverage::class);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'booking_intent' => BookingIntent::class,
            'session_time_request' => SessionTimeRequest::class,
            'quality_incident' => QualityIncident::class,
        ]);

        // A late booking paid at once (BR-BOOK-04).
        PaymentPurposes::register('booking', BookingPaymentPurpose::class);

        // BR-CANC-09: upcoming sessions of a blocked participant are cancelled by the platform with a full refund.
        foreach (['account.user.blocked', 'psy.qualification.rejected', 'psy.work_status.blocked', 'psy.rejected', 'psy.blocked'] as $event) {
            Outbox::listen($event, CancelSessionsOfBlockedParticipant::class);
        }
    }
}
