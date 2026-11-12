<?php

namespace App\Http\Controllers;

use App\Inbox\InboxDigest;
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
