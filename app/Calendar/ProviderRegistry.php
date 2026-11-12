<?php

namespace App\Calendar;

use App\Calendar\Providers\CalDavProvider;
use App\Calendar\Providers\GoogleProvider;
use App\Calendar\Providers\ZimbraProvider;
use App\Models\CalendarAccount;
use InvalidArgumentException;

class ProviderRegistry
{
    public function for(CalendarAccount $account): CalendarProvider
    {
        return match ($account->provider) {
            CalendarAccount::CALDAV => app(CalDavProvider::class),
            CalendarAccount::GOOGLE => app(GoogleProvider::class),
            CalendarAccount::ZIMBRA => app(ZimbraProvider::class),
            default => throw new InvalidArgumentException("Proveedor desconocido: {$account->provider}"),
        };
    }
}
