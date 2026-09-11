<?php

namespace App\Filament\Resources\Incidents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class IncidentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reference')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('prompt')
                    ->columnSpanFull(),
                Select::make('monitored_endpoint_id')
                    ->relationship('monitoredEndpoint', 'name'),
                TextInput::make('trigger')
                    ->required()
                    ->default('auto'),
                TextInput::make('severity')
                    ->required()
                    ->default('sev3'),
                TextInput::make('status')
                    ->required()
                    ->default('open'),
                DateTimePicker::make('detected_at')
                    ->required(),
                DateTimePicker::make('window_start')
                    ->required(),
                DateTimePicker::make('window_end')
                    ->required(),
                TextInput::make('detection_signal'),
                TextInput::make('scenario_key'),
            ]);
    }
}
