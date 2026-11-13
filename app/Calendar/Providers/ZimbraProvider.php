<?php

namespace App\Calendar\Providers;

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSyncException;
use App\Calendar\EventData;
use App\Calendar\RemoteCalendar;
use App\Inbox\MailMessage;
use App\Inbox\MailProvider;
use App\Inbox\SentMessage;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Zimbra por su API (SOAP + REST), para cuentas con doble factor.
 *
 * El primer ingreso usa contraseña + código de la app autenticadora y registra a Smartgenda como
 * "dispositivo de confianza". Desde ahí la sesión se renueva sola con contraseña + token de confianza,
 * hasta que ese token vence (lo define el administrador de Zimbra) y hay que ingresar un código nuevo.
 *
 * El secreto de la cuenta guarda (cifrado) un JSON con: password, authToken, expiresAt, trustedToken, deviceId.
 */
class ZimbraProvider implements CalendarProvider, MailProvider
{
    public const USER_AGENT = 'Smartgenda/1.0';

    public const NEEDS_CODE = 'Zimbra pide un código nuevo de tu app autenticadora.';

    /** Inicia sesión. Con $code (6 dígitos) registra a Smartgenda como dispositivo de confianza. */
    public function authenticate(CalendarAccount $account, ?string $code = null): void
    {
        $state = $this->state($account);

        $request = [
            '_jsns' => 'urn:zimbraAccount',
            'account' => ['by' => 'name', '_content' => $account->username],
            'password' => ['_content' => $state['password'] ?? ''],
        ];
        if ($code !== null) {
            $request['twoFactorCode'] = ['_content' => preg_replace('/\D/', '', $code)];
            $request['deviceTrusted'] = '1';
            if (empty($state['deviceId'])) {
                $request['generateDeviceId'] = '1';
            }
        }
        if (! empty($state['trustedToken'])) {
            $request['trustedToken'] = ['_content' => $state['trustedToken']];
        }
        if (! empty($state['deviceId'])) {
            $request['deviceId'] = ['_content' => $state['deviceId']];
        }

        $response = $this->soap($account, 'AuthRequest', $request, authToken: null);

        if ($this->text($response['twoFactorAuthRequired'] ?? null) === 'true') {
            throw new CalendarSyncException(self::NEEDS_CODE);
        }
        $token = $this->text($response['authToken'] ?? null);
        if (! $token) {
            throw new CalendarSyncException('Zimbra no entregó una sesión.');
        }

        $lifetimeMs = (int) $this->text($response['lifetime'] ?? null) ?: 3600_000;
        $state['authToken'] = $token;
        $state['expiresAt'] = now()->addMilliseconds($lifetimeMs)->subMinutes(5)->getTimestamp();
        $state['trustedToken'] = $this->text($response['trustedToken'] ?? null) ?: ($state['trustedToken'] ?? null);
        $state['deviceId'] = $this->text($response['deviceId'] ?? null) ?: ($state['deviceId'] ?? null);

        $this->saveState($account, $state);
    }

    public function listCalendars(CalendarAccount $account): array
    {
        $response = $this->call($account, fn (string $token) => $this->soap($account, 'GetFolderRequest', [
            '_jsns' => 'urn:zimbraMail',
            'view' => 'appointment',
        ], $token));

        $calendars = [];
        $walk = function (array $folders) use (&$walk, &$calendars) {
            foreach ($folders as $folder) {
                if (($folder['view'] ?? null) === 'appointment' && isset($folder['id'])) {
                    $path = ltrim($folder['absFolderPath'] ?? $folder['name'] ?? '', '/');
                    $calendars[] = new RemoteCalendar((string) $folder['id'], $path ?: (string) $folder['name']);
                }
                $walk($folder['folder'] ?? []);
            }
        };
        $walk($response['folder'] ?? []);

        return $calendars;
    }

    public function fetchEvents(CalendarAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $response = $this->call($account, fn (string $token) => $this->rest($account, $token)
            ->get($this->origin($account).'/home/~/', [
                'id' => $account->calendar_ref,
                'fmt' => 'ics',
                'start' => $from->getTimestampMs(),
                'end' => $to->getTimestampMs(),
            ]));

        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }
        if (! $response->successful()) {
            throw new CalendarSyncException("Zimbra respondió HTTP {$response->status()} al leer el calendario.");
        }

        return app(CalDavProvider::class)->parseCalendar($response->body(), $from, $to);
    }

    public function createEvent(CalendarAccount $account, EventData $event): EventData
    {
        $uid = (string) Str::uuid();
        $date = fn (CarbonImmutable $d) => $event->allDay ? ['d' => $d->format('Ymd')] : ['d' => $d->utc()->format('Ymd\THis\Z')];

        $comp = [
            'uid' => $uid,
            'name' => $event->title,
            'loc' => $event->location ?? '',
            'fb' => $event->busy ? 'B' : 'F',
            'transp' => $event->busy ? 'O' : 'T',
            'allDay' => $event->allDay ? '1' : '0',
            's' => $date($event->startsAt),
            // En Zimbra el fin de un evento de día completo es el último día (inclusive).
            'e' => $date($event->allDay ? $event->endsAt->subDay() : $event->endsAt),
        ];

        $this->call($account, fn (string $token) => $this->soap($account, 'CreateAppointmentRequest', [
            '_jsns' => 'urn:zimbraMail',
            'm' => [
                'l' => $account->calendar_ref,
                'su' => $event->title,
                'inv' => ['comp' => [$comp]],
                'mp' => ['ct' => 'text/plain', 'content' => $event->description ?? ''],
            ],
        ], $token));

        return new EventData($uid, $event->title, $event->startsAt, $event->endsAt, $event->allDay,
            $event->description, $event->location, $event->busy);
    }

    /**
     * Elimina una cita: la mueve a la Papelera de Zimbra (se puede recuperar) sin enviar avisos a los invitados.
     */
    public function deleteEvent(CalendarAccount $account, string $remoteId): void
    {
        if (preg_match('/@\d{8}T\d{6}Z$/', $remoteId)) {
            throw new CalendarSyncException('Es una repetición de un evento periódico: elimínala desde Zimbra.');
        }
        $appt = $this->call($account, fn (string $token) => $this->soap($account, 'GetAppointmentRequest', [
            '_jsns' => 'urn:zimbraMail', 'uid' => $remoteId, 'sync' => '0', 'includeContent' => '0',
        ], $token));
        $id = $appt['appt'][0]['id'] ?? $appt['appt']['id'] ?? null;
        if (! $id) {
            return; // ya no existe
        }
        $this->itemAction($account, 'ItemActionRequest', (string) $id, 'trash');
    }

    /** Mueve un correo a la Papelera. */
    public function trashMessage(CalendarAccount $account, string $messageId): void
    {
        $this->itemAction($account, 'MsgActionRequest', $messageId, 'trash');
    }

    /** Responde un correo desde la cuenta (queda en Enviados y el original se marca como respondido). */
    public function reply(CalendarAccount $account, string $messageId, string $toEmail, ?string $toName, string $subject, string $body, ?string $html = null): void
    {
        $subject = preg_match('/^\s*(re|rv|fw|fwd)\s*:/i', $subject) ? $subject : 'RE: '.$subject;
        $this->call($account, fn (string $token) => $this->soap($account, 'SendMsgRequest', [
            '_jsns' => 'urn:zimbraMail',
            'm' => [
                'origid' => $messageId,
                'rt' => 'r',
                'e' => [array_filter(['t' => 't', 'a' => $toEmail, 'p' => $toName])],
                'su' => ['_content' => $subject],
                'mp' => [$html === null
                    ? ['ct' => 'text/plain', 'content' => ['_content' => $body]]
                    : ['ct' => 'multipart/alternative', 'mp' => [
                        ['ct' => 'text/plain', 'content' => ['_content' => $body]],
                        ['ct' => 'text/html', 'content' => ['_content' => $html]],
                    ]]],
            ],
        ], $token));
    }

    private function itemAction(CalendarAccount $account, string $method, string $id, string $op): void
    {
        $this->call($account, fn (string $token) => $this->soap($account, $method, [
            '_jsns' => 'urn:zimbraMail', 'action' => ['op' => $op, 'id' => $id],
        ], $token));
    }

    /**
     * Correos recibidos en la bandeja de entrada desde $since (los más nuevos primero).
     *
     * @return list<MailMessage>
     */
    public function fetchMessages(CalendarAccount $account, CarbonImmutable $since, int $limit = 50): array
    {
        $response = $this->call($account, fn (string $token) => $this->soap($account, 'SearchRequest', [
            '_jsns' => 'urn:zimbraMail',
            'query' => 'in:inbox',
            'types' => 'message',
            'sortBy' => 'dateDesc',
            'limit' => $limit,
            'fetch' => 'all',   // incluye el cuerpo de cada mensaje
            'html' => '0',      // texto plano cuando existe
            'max' => 6000,      // bytes por cuerpo
            'needExp' => '1',
            // Encabezados que delatan envíos masivos (boletines, publicidad)
            'header' => [['n' => 'List-Unsubscribe'], ['n' => 'List-Id'], ['n' => 'Precedence']],
        ], $token));

        $messages = [];
        foreach ($response['m'] ?? [] as $m) {
            $received = CarbonImmutable::createFromTimestampMs((int) ($m['d'] ?? 0), 'UTC');
            if ($received->lessThan($since)) {
                break; // vienen ordenados del más nuevo al más antiguo
            }
            $from = collect($m['e'] ?? [])->firstWhere('t', 'f') ?? [];
            $body = $this->bodyOf($m['mp'] ?? []) ?? $this->text($m['fr'] ?? null) ?? '';

            $messages[] = new MailMessage(
                remoteId: (string) $m['id'],
                conversationId: isset($m['cid']) ? (string) $m['cid'] : null,
                fromName: $from['p'] ?? $from['d'] ?? null,
                fromEmail: $from['a'] ?? null,
                subject: $this->text($m['su'] ?? null) ?? '',
                receivedAt: $received,
                body: $body,
                replied: str_contains((string) ($m['f'] ?? ''), 'r'),
                directlyAddressed: $this->isAddressedTo($m['e'] ?? [], (string) $account->username),
                bulk: $this->isBulk($m),
            );
        }

        return $messages;
    }

    public function fetchSent(CalendarAccount $account, CarbonImmutable $since, int $limit = 100): array
    {
        $response = $this->call($account, fn (string $token) => $this->soap($account, 'SearchRequest', [
            '_jsns' => 'urn:zimbraMail',
            'query' => 'in:sent',
            'types' => 'message',
            'sortBy' => 'dateDesc',
            'limit' => $limit,
            'recip' => '2', // remitente y destinatarios
        ], $token));

        $sent = [];
        foreach ($response['m'] ?? [] as $m) {
            $at = CarbonImmutable::createFromTimestampMs((int) ($m['d'] ?? 0), 'UTC');
            if ($at->lessThan($since)) {
                break;
            }
            $recipients = collect($m['e'] ?? [])->whereIn('t', ['t', 'c'])->pluck('a')->filter()->map(fn ($a) => mb_strtolower($a))->values()->all();
            $sent[] = new SentMessage(isset($m['cid']) ? (string) $m['cid'] : null, $this->text($m['su'] ?? null) ?? '', $recipients, $at,
                snippet: $this->text($m['fr'] ?? null) ?? '', hasAttachment: str_contains((string) ($m['f'] ?? ''), 'a'));
        }

        return $sent;
    }

    /** Encabezados de lista o Precedence: bulk/list. Zimbra los entrega en _attrs (o como lista de header). */
    private function isBulk(array $m): bool
    {
        $headers = array_change_key_case($m['_attrs'] ?? [], CASE_LOWER);
        foreach ($m['header'] ?? [] as $h) {
            $headers[mb_strtolower((string) ($h['n'] ?? ''))] = $this->text($h['_content'] ?? $h);
        }

        return filled($headers['list-unsubscribe'] ?? null) || filled($headers['list-id'] ?? null)
            || in_array(mb_strtolower(trim((string) ($headers['precedence'] ?? ''))), ['bulk', 'list', 'junk'], true);
    }

    /** true si la cuenta está en "Para", o si no se sabe (sin destinatarios visibles, ej. listas). */
    private function isAddressedTo(array $addresses, string $me): bool
    {
        $to = collect($addresses)->where('t', 't')->pluck('a')->map(fn ($a) => mb_strtolower((string) $a));

        return $to->isEmpty() || $to->contains(mb_strtolower($me));
    }

    /** Texto del cuerpo: la parte marcada como cuerpo, o la primera text/plain (o text/html sin etiquetas). */
    private function bodyOf(array $parts): ?string
    {
        $flat = [];
        $walk = function (array $parts) use (&$walk, &$flat) {
            foreach ($parts as $part) {
                $flat[] = $part;
                $walk($part['mp'] ?? []);
            }
        };
        $walk($parts);

        $pick = collect($flat)->first(fn ($p) => ! empty($p['body']) && isset($p['content']))
            ?? collect($flat)->first(fn ($p) => ($p['ct'] ?? '') === 'text/plain' && isset($p['content']))
            ?? collect($flat)->first(fn ($p) => ($p['ct'] ?? '') === 'text/html' && isset($p['content']));
        if (! $pick) {
            return null;
        }
        $content = $this->text($pick['content']) ?? '';
        if (($pick['ct'] ?? '') === 'text/html') {
            $content = html_entity_decode(strip_tags(preg_replace('#<(style|script)\b.*?</\1>#is', '', $content)), ENT_QUOTES | ENT_HTML5);
        }

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $content)));
    }

    /**
     * Ejecuta una llamada con sesión válida; si la sesión venció, la renueva una vez.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function call(CalendarAccount $account, callable $callback): mixed
    {
        $state = $this->state($account);
        if (empty($state['authToken']) || ($state['expiresAt'] ?? 0) < time()) {
            $this->authenticate($account);
            $state = $this->state($account);
        }

        try {
            $result = $callback($state['authToken']);
            if ($result instanceof Response && in_array($result->status(), [401, 403], true)) {
                throw new SessionExpired;
            }

            return $result;
        } catch (SessionExpired) {
            $this->authenticate($account);

            return $callback($this->state($account)['authToken']);
        }
    }

    /** @return array<string, mixed> Cuerpo de la respuesta SOAP (ej. AuthResponse). */
    private function soap(CalendarAccount $account, string $method, array $body, ?string $authToken): array
    {
        $context = ['_jsns' => 'urn:zimbra', 'userAgent' => ['name' => 'Smartgenda', 'version' => '1.0']];
        if ($authToken) {
            $context['authToken'] = ['_content' => $authToken];
        }

        $response = $this->http()->post($this->origin($account).'/service/soap/'.$method, [
            'Header' => ['context' => $context],
            'Body' => [$method => $body],
        ]);

        $fault = $response->json('Body.Fault');
        if ($fault) {
            $code = $fault['Detail']['Error']['Code'] ?? '';
            if ($authToken && in_array($code, ['service.AUTH_EXPIRED', 'service.AUTH_REQUIRED'], true)) {
                throw new SessionExpired;
            }
            throw new CalendarSyncException(match ($code) {
                'account.AUTH_FAILED' => 'Usuario, contraseña o código incorrectos.',
                'account.TWO_FACTOR_AUTH_FAILED' => 'El código de verificación no es válido o ya expiró.',
                'account.TWO_FACTOR_SETUP_REQUIRED' => 'Tu cuenta de Zimbra pide configurar el doble factor primero.',
                default => 'Zimbra respondió: '.($fault['Reason']['Text'] ?? $code ?: "HTTP {$response->status()}"),
            });
        }
        if (! $response->successful()) {
            throw new CalendarSyncException("No pude comunicarme con Zimbra (HTTP {$response->status()}).");
        }

        return $response->json('Body.'.Str::replaceLast('Request', 'Response', $method)) ?? [];
    }

    private function rest(CalendarAccount $account, string $token): PendingRequest
    {
        return $this->http()->withCookies(['ZM_AUTH_TOKEN' => $token], (string) parse_url($this->origin($account), PHP_URL_HOST));
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->withUserAgent(self::USER_AGENT)->acceptJson();
    }

    private function origin(CalendarAccount $account): string
    {
        $url = trim((string) $account->server_url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** Valor de un nodo JSON de Zimbra: "x", {"_content": "x"} o [{"_content": "x"}]. */
    private function text(mixed $node): ?string
    {
        if (is_array($node) && array_is_list($node)) {
            $node = $node[0] ?? null;
        }
        if (is_array($node)) {
            $node = $node['_content'] ?? null;
        }

        return $node === null ? null : (string) $node;
    }

    /** @return array<string, mixed> */
    private function state(CalendarAccount $account): array
    {
        return json_decode((string) $account->secret, true) ?: [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(CalendarAccount $account, array $state): void
    {
        $account->secret = json_encode($state);
        if ($account->exists) {
            $account->save();
        }
    }
}
