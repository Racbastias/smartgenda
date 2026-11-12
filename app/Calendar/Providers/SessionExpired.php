<?php

namespace App\Calendar\Providers;

use RuntimeException;

/** La sesión de Zimbra venció y hay que renovarla. Uso interno de ZimbraProvider. */
class SessionExpired extends RuntimeException {}
