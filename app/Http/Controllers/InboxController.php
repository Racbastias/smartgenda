<?php

namespace App\Http\Controllers;

use App\Inbox\InboxDigest;
use App\Models\BlockedSender;
use App\Models\EmailItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Pendientes": lo que piden los correos (responder, acciones y compromisos). */
class InboxController extends Controller
{
    /** La lista de pendientes ahora vive en "Hoy". */
    public function index(): RedirectResponse
    {
        return redirect()->route('today');
    }

    public function update(Request $request, EmailItem $item): RedirectResponse|JsonResponse
    {
        abort_unless($item->account?->user_id === $request->user()->id, 404);
        $data = $request->validate(['status' => ['required', 'in:pending,done,dismissed']]);
        $item->update($data);
        if ($request->wantsJson()) {
            return response()->json(['status' => $item->status]);
        }

        return back()->with('status', match ($data['status']) {
            EmailItem::DONE => 'Marcado como hecho.',
            EmailItem::DISMISSED => 'Descartado.',
            default => 'Vuelve a pendientes.',
        });
    }

    /** No volver a mostrar correos de este remitente (o de su dominio) y descartar los pendientes que ya tiene. */
    public function block(Request $request, EmailItem $item): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($item->account?->user_id === $user->id && filled($item->from_email), 404);
        $data = $request->validate(['scope' => ['required', 'in:sender,domain']]);

        $email = mb_strtolower($item->from_email);
        $pattern = $data['scope'] === 'domain' ? '@'.substr(strrchr($email, '@') ?: '@', 1) : $email;
        abort_if($pattern === '@', 422);
        $user->blockedSenders()->firstOrCreate(['pattern' => $pattern]);

        $hidden = 0;
        $user->emailItems()->where('status', EmailItem::PENDING)->get()
            ->filter(fn (EmailItem $i) => BlockedSender::matches([$pattern], $i->from_email))
            ->each(function (EmailItem $i) use (&$hidden) {
                $i->update(['status' => EmailItem::DISMISSED]);
                $hidden++;
            });

        $message = "No verás más correos de {$pattern}. Se ocultaron {$hidden}. Puedes deshacerlo en Integraciones.";

        return $request->wantsJson()
            ? response()->json(['message' => $message, 'hidden' => $hidden])
            : back()->with('status', $message);
    }

    public function unblock(Request $request, BlockedSender $blocked): RedirectResponse
    {
        abort_unless($blocked->user_id === $request->user()->id, 404);
        $blocked->delete();

        return back()->with('status', "Volverás a ver correos de {$blocked->pattern}.");
    }

    /** Revisar el correo ahora, sin esperar la tarea programada. */
    public function refresh(Request $request, InboxDigest $digest): RedirectResponse
    {
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
