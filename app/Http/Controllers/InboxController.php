<?php

namespace App\Http\Controllers;

use App\Inbox\InboxDigest;
use App\Models\EmailItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "Pendientes": lo que piden los correos (responder, acciones y compromisos). */
class InboxController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $base = fn () => $user->emailItems()->with('account')->where('status', EmailItem::PENDING);

        $pending = $base()->actionable()
            ->orderByRaw("case priority when 'alta' then 0 when 'media' then 1 else 2 end")
            ->orderByRaw('due_on is null')->orderBy('due_on')
            ->orderByDesc('received_at')
            ->get();

        $others = $base()->whereNotIn('email_items.id', $pending->modelKeys())
            ->orderByDesc('received_at')->limit(30)->get();

        return view('inbox.index', [
            'pending' => $pending,
            'others' => $others,
            'mailAccounts' => $user->calendarAccounts()->where('mail_enabled', true)->get(),
            'canSummarize' => (bool) config('services.anthropic.key'),
        ]);
    }

    public function update(Request $request, EmailItem $item): RedirectResponse
    {
        abort_unless($item->account?->user_id === $request->user()->id, 404);
        $data = $request->validate(['status' => ['required', 'in:pending,done,dismissed']]);
        $item->update($data);

        return back()->with('status', match ($data['status']) {
            EmailItem::DONE => 'Marcado como hecho.',
            EmailItem::DISMISSED => 'Descartado.',
            default => 'Vuelve a pendientes.',
        });
    }

    /** Revisar el correo ahora, sin esperar la tarea programada. */
    public function refresh(Request $request, InboxDigest $digest): RedirectResponse
    {
        abort_unless(config('services.anthropic.key'), 503, 'Falta configurar la clave de la IA.');

        $count = 0;
        $errors = [];
        foreach ($request->user()->calendarAccounts()->where('mail_enabled', true)->get() as $account) {
            $count += $digest->run($account);
            if ($account->mail_error) {
                $errors[] = "{$account->name}: {$account->mail_error}";
            }
        }

        $redirect = back()->with('status', $count ? "{$count} correos nuevos resumidos." : 'No hay correos nuevos.');

        return $errors ? $redirect->withErrors(['mail' => implode(' · ', $errors)]) : $redirect;
    }
}
