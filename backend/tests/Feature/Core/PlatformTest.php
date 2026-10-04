<?php

namespace Tests\Feature\Core;

use App\Modules\Instance\Models\PartnerInstance;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Notifications\Notifier;
use App\Support\Settings\Settings;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    public function test_public_dictionaries_include_43_requests_in_5_groups(): void
    {
        $res = $this->getJson('/api/v1/dictionaries')->assertOk();
        $groups = $res->json('data.request_groups');
        $this->assertCount(5, $groups);
        $this->assertSame(43, collect($groups)->sum(fn ($g) => count($g['requests'])));
        $this->assertSame('/help/para/izmena', collect($groups)->firstWhere('slug', 'dlya-pary')['requests'][1]['path']);
        $this->assertCount(3, $res->json('data.price_categories'));
    }

    public function test_request_landing_by_format(): void
    {
        $this->getJson('/api/v1/dictionaries/requests/seksualnye-otnosheniya')->assertOk()->assertJsonPath('data.format', 'individual');
        $this->getJson('/api/v1/dictionaries/requests/seksualnye-otnosheniya?format=pair')->assertOk()->assertJsonPath('data.path', '/help/para/seksualnye-otnosheniya');
    }

    public function test_legal_documents_are_public(): void
    {
        $this->getJson('/api/v1/legal')->assertOk()->assertJsonFragment(['slug' => 'review-consent']);
        $this->getJson('/api/v1/legal/personal-data')->assertOk()->assertJsonPath('data.version', '1.0');
    }

    public function test_settings_overrides_and_admin_audit(): void
    {
        $this->assertSame(30, Settings::int('P-COMMISSION'));
        Settings::set('P-COMMISSION', 25);
        $this->assertSame(25, Settings::int('P-COMMISSION'));
        Settings::reset('P-COMMISSION');
        $this->assertSame(30, Settings::int('P-COMMISSION'));
    }

    public function test_notification_centre(): void
    {
        $user = $this->actingAsRole('client');
        app(Notifier::class)->send($user, 'auth.account_unblocked');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 1);
        $id = UserNotification::where('user_id', $user->id)->value('id');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('unread', 0);
    }

    public function test_partner_instance_lifecycle_and_heartbeat(): void
    {
        $this->actingAsRole('super_admin');
        $res = $this->postJson('/api/v1/admin/instance/partners', ['name' => 'Партнёр', 'domain' => 'partner.example'])->assertCreated();
        $id = $res->json('data.id');
        $token = $res->json('heartbeat_token');

        foreach (['deploying', 'configuring', 'operational'] as $status) {
            $this->postJson("/api/v1/admin/instance/partners/{$id}/transition", ['status' => $status])->assertOk();
        }
        $this->postJson("/api/v1/admin/instance/partners/{$id}/transition", ['status' => 'registered'])->assertStatus(409);

        $this->artisan('instance:check-partners');
        $this->assertSame('unavailable', PartnerInstance::find($id)->status);
        $this->postJson('/api/v1/instance/heartbeat', ['token' => $token, 'version' => '1.0.0'])->assertOk();
        $this->assertSame('operational', PartnerInstance::find($id)->status);
    }

    public function test_optional_consents_can_be_toggled(): void
    {
        $this->actingAsRole('client');
        $this->postJson('/api/v1/account/consents', ['purpose' => 'mailing', 'accepted' => true])->assertOk();
        $this->getJson('/api/v1/account/consents')->assertOk()->assertJsonFragment(['purpose' => 'mailing', 'revoked_at' => null]);
        $this->postJson('/api/v1/account/consents', ['purpose' => 'mailing', 'accepted' => false])->assertOk();
        $this->postJson('/api/v1/account/consents', ['purpose' => 'personal_data', 'accepted' => false])->assertUnprocessable();
    }
}
