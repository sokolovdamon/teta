<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Instance\InstanceConfig;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\NotificationCatalog;
use App\Modules\Notifications\Renderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** ADM-10: scenarios and texts of email notifications. */
class NotificationTemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = NotificationTemplate::query()
            ->when($request->query('audience'), fn ($q, $v) => $q->where('audience', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'ilike', "%{$v}%")->orWhere('code', 'ilike', "%{$v}%")))
            ->orderBy('code')->get();

        return response()->json(['data' => $templates]);
    }

    public function show(NotificationTemplate $template)
    {
        return response()->json(['data' => $template, 'default' => NotificationCatalog::find($template->code)]);
    }

    public function update(Request $request, NotificationTemplate $template)
    {
        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'center_text' => ['nullable', 'string', 'max:255'],
            'send_email' => ['sometimes', 'boolean'],
            'send_center' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $template->update($data);
        Audit::log('ADM-10', 'notification_template.updated', $template, array_keys($data));

        return response()->json(['data' => $template]);
    }

    public function preview(Request $request, NotificationTemplate $template)
    {
        $vars = $this->sampleVars($template, $request->input('vars', []));

        return response()->json([
            'subject' => Renderer::render($template->subject, $vars),
            'html' => view('emails.layout', [
                'body' => Renderer::render(nl2br($template->body), $vars, escape: true),
                'actionUrl' => config('app.frontend_url'), 'actionText' => 'Открыть', 'footerHtml' => null,
                'brand' => app(InstanceConfig::class)->publicConfig(),
            ])->render(),
        ]);
    }

    public function testSend(Request $request, NotificationTemplate $template)
    {
        $to = $request->validate(['email' => ['required', 'email']])['email'];
        $vars = $this->sampleVars($template, []);
        Mail::to($to)->send(new TemplatedMail('[Тест] '.Renderer::render($template->subject, $vars), Renderer::render(nl2br($template->body), $vars, escape: true), config('app.frontend_url'), 'Открыть'));

        return response()->json(['ok' => true]);
    }

    private function sampleVars(NotificationTemplate $template, array $overrides): array
    {
        $vars = ['name' => 'Анна', 'psychologist' => 'Мария Иванова', 'date' => '15 октября', 'time' => '19:00 (МСК)', 'amount' => '3 500 ₽', 'link' => config('app.frontend_url')];
        foreach ($template->variables ?? [] as $var) {
            $vars[$var] ??= '['.$var.']';
        }

        return [...$vars, ...$overrides];
    }
}
