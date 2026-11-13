<?php

namespace App\Http\Controllers;

use App\Calendar\ProviderRegistry;
use App\Inbox\InboxDigest;
use App\Models\BlockedSender;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

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
        $data = $request->validate([
            'status' => ['required', 'in:pending,doing,waiting,done,dismissed'],
            'kind' => ['sometimes', 'in:accion,leer,copia'],
        ]);
        $item->update($data);
        if ($request->wantsJson()) {
            return response()->json(['status' => $item->status]);
        }

        return back()->with('status', match ($data['status']) {
            EmailItem::DONE => 'Marcado como hecho.',
            EmailItem::DISMISSED => 'Descartado.',
            EmailItem::DOING => 'En curso.',
            EmailItem::WAITING => 'Queda en seguimiento.',
            default => 'Vuelve a pendientes.',
        });
    }

    /** Mueve el correo a la Papelera del buzón y lo saca de Pendientes. */
    public function trash(Request $request, EmailItem $item, ProviderRegistry $providers): JsonResponse
    {
        $account = $this->ownMailItem($request, $item);

        try {
            $providers->mail($account)->trashMessage($account, $item->remote_id);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "No se pudo eliminar el correo: {$e->getMessage()}"], 502);
        }
        $item->update(['status' => EmailItem::DISMISSED]);

        return response()->json(['message' => 'Correo enviado a la Papelera.']);
    }

    /** Responde al remitente desde la cuenta de correo y marca el pendiente como hecho. */
    public function reply(Request $request, EmailItem $item, ProviderRegistry $providers): JsonResponse
    {
        $account = $this->ownMailItem($request, $item);
        abort_unless(filled($item->from_email), 422, 'El correo no tiene remitente.');
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        try {
            $providers->mail($account)->reply($account, $item->remote_id, $item->from_email, $item->from_name, (string) $item->subject, $data['body']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "No se pudo enviar la respuesta: {$e->getMessage()}"], 502);
        }
        $item->update(['status' => EmailItem::DONE, 'needs_reply' => false]);

        return response()->json(['message' => "Respuesta enviada a {$item->from_email}."]);
    }

    private function ownMailItem(Request $request, EmailItem $item): CalendarAccount
    {
        $account = $item->account;
        abort_unless($account?->user_id === $request->user()->id, 404);
        abort_unless($account->supportsMail(), 422, 'Esta cuenta no tiene correo.');

        return $account;
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
        $user->emailItems()->open()->get()
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
