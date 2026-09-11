<?php

namespace App\Filament\Resources\MonitoredEndpoints\Pages;

use App\Filament\Resources\MonitoredEndpoints\MonitoredEndpointResource;
use Filament\Resources\Pages\ListRecords;

class ListMonitoredEndpoints extends ListRecords
{
    protected static string $resource = MonitoredEndpointResource::class;

    public function getSubheading(): ?string
    {
        return 'Every service polled for incidents. The bundled /healthz is here too, '
            .'so the demo and your real services run through the same path.';
    }
}
