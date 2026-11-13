<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Avisos al teléfono o al navegador (Web Push), sin servicios de terceros.
 * Las claves VAPID se crean solas la primera vez y quedan en storage (fuera del deploy).
 */
class Push
{
    /** @return array{publicKey: string, privateKey: string} */
    public static function keys(): array
    {
        $path = storage_path('app/private/vapid.json');
        if (File::exists($path) && ($keys = json_decode(File::get($path), true)) && isset($keys['publicKey'], $keys['privateKey'])) {
            return $keys;
        }
        $keys = VAPID::createVapidKeys();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($keys));
        @chmod($path, 0600);

        return $keys;
    }

    public static function publicKey(): string
    {
        return self::keys()['publicKey'];
    }

    /**
     * Envía un aviso a todos los dispositivos del usuario. Borra las suscripciones vencidas.
     *
     * @return int avisos entregados
     */
    public function send(User $user, string $title, string $body, string $url = '/', ?string $tag = null): int
    {
        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        try {
            // Con logger, los avisos de rendimiento (falta GMP/BCMath) van al log y no cortan el envío
            $webPush = new WebPush(['VAPID' => ['subject' => config('app.url') ?: 'https://smartgenda.app'] + self::keys()], ['TTL' => 3600], logger: Log::channel());
            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag], JSON_UNESCAPED_UNICODE);
            foreach ($subs as $sub) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding,
                ]), $payload);
            }

            $ok = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $ok++;
                } elseif ($report->isSubscriptionExpired()) {
                    $user->pushSubscriptions()->where('endpoint', $report->getEndpoint())->delete();
                }
            }

            return $ok;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
