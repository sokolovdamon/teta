<?php

namespace App\Modules\Instance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Instance\InstanceConfig;
use App\Modules\Instance\Models\PartnerInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InstanceController extends Controller
{
    public function __construct(private InstanceConfig $config) {}

    /** Public branding of this instance for the frontend. */
    public function public()
    {
        return response()->json(['data' => $this->config->publicConfig()]);
    }

    public function show()
    {
        return response()->json(['data' => $this->config->all()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'requisites' => ['sometimes', 'array'],
            'domain' => ['sometimes', 'string', 'max:255'],
            'site_url' => ['sometimes', 'url'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'logo_dark_url' => ['nullable', 'string', 'max:500'],
            'palette' => ['sometimes', 'array'],
            'palette.brand' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'palette.graphite' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['sometimes', 'email'],
            'mail_from_address' => ['sometimes', 'email'],
            'mail_from_name' => ['sometimes', 'string', 'max:120'],
            'metrika_counter_id' => ['nullable', 'string', 'max:32'],
            'dzen_enabled' => ['sometimes', 'boolean'],
            'emergency_phone' => ['sometimes', 'string', 'max:32'],
        ]);
        $this->config->update($data);
        Audit::log('ADM-21', 'instance.settings_updated', null, array_keys($data));

        return response()->json(['data' => $this->config->all()]);
    }

    // ---- Registry of partner instances (SEQ-21, ST-20) ---------------------------------------

    public function partners()
    {
        return response()->json(['data' => PartnerInstance::latest()->get()]);
    }

    public function storePartner(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'server' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'branding' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);
        $partner = new PartnerInstance($data);
        $partner->status = 'registered';
        $partner->heartbeat_token = Str::random(48);
        $partner->save();
        $partner->recordInitialState($request->user()->id);
        Audit::log('ADM-21', 'instance.partner_registered', $partner);

        return response()->json(['data' => $partner, 'heartbeat_token' => $partner->heartbeat_token], 201);
    }

    public function updatePartner(Request $request, PartnerInstance $partner)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'server' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'branding' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);
        $partner->update($data);
        Audit::log('ADM-21', 'instance.partner_updated', $partner, array_keys($data));

        return response()->json(['data' => $partner]);
    }

    public function transitionPartner(Request $request, PartnerInstance $partner)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['registered', 'deploying', 'configuring', 'operational', 'unavailable', 'updating', 'offboarding', 'archived'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $extra = $data['status'] === 'operational' && ! $partner->launched_at ? ['launched_at' => now()] : [];
        $partner->transitionTo($data['status'], $request->user()->id, $data['reason'] ?? null, $extra);
        Audit::log('ADM-21', 'instance.partner_status', $partner, ['status' => $data['status']], $data['reason'] ?? null);

        return response()->json(['data' => $partner->fresh()]);
    }

    /** Heartbeat from a partner instance: version and availability only. */
    public function heartbeat(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string'], 'version' => ['nullable', 'string', 'max:32']]);
        $partner = PartnerInstance::where('heartbeat_token', $data['token'])->firstOrFail();
        $partner->forceFill(['last_heartbeat_at' => now(), 'platform_version' => $data['version'] ?? $partner->platform_version])->save();
        if ($partner->status === 'unavailable') {
            $partner->transitionTo('operational', reason: 'heartbeat restored');
        }

        return response()->json(['ok' => true]);
    }
}
