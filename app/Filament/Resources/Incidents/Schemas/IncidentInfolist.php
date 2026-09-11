<?php

namespace App\Filament\Resources\Incidents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class IncidentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('reference'),
                TextEntry::make('title'),
                TextEntry::make('prompt')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('monitoredEndpoint.name')
                    ->label('Monitored endpoint')
                    ->placeholder('-'),
                TextEntry::make('trigger'),
                TextEntry::make('severity'),
                TextEntry::make('status'),
                TextEntry::make('detected_at')
                    ->dateTime(),
                TextEntry::make('window_start')
                    ->dateTime(),
                TextEntry::make('window_end')
                    ->dateTime(),
                TextEntry::make('scenario_key')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
