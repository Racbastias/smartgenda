<?php

namespace App\Support;

/** Firma HTML de correo: limpieza básica y armado del cuerpo de la respuesta. */
class Signature
{
    /** Quita lo que no corresponde en una firma (scripts, eventos, javascript:). */
    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }
        $html = preg_replace('#<\s*(script|iframe|object|embed|form|input|button|textarea|select|meta|link|base)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*/?\s*(script|iframe|object|embed|form|input|button|textarea|select|meta|link|base)\b[^>]*>#i', '', $html);
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data:text/html)[^"\'>\s]*\2#i', '$1="#"', $html);

        return trim($html) ?: null;
    }

    /** Cuerpo HTML de la respuesta: el texto escrito + la firma. */
    public static function html(string $body, ?string $signature): ?string
    {
        if (! $signature) {
            return null;
        }

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">'.nl2br(e($body)).'</div>'
            .'<br><div class="smartgenda-firma">'.$signature.'</div>';
    }

    /** Versión texto de la firma, para clientes que no muestran HTML. */
    public static function text(string $body, ?string $signature): string
    {
        if (! $signature) {
            return $body;
        }
        $plain = html_entity_decode(strip_tags(preg_replace('#<\s*(br|/p|/div|/tr|/li)\b[^>]*>#i', "\n", $signature)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $plain)));

        return $body."\n\n-- \n".$plain;
    }
}
