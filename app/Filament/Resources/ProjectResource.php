<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ProjectBillingType;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectMembersRelationManager;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Timetracking';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                ColorPicker::make('color')
                    ->label('Color')
                    ->required(),
                Forms\Components\Select::make('billing_type')
                    ->label('Billing type')
                    ->options([
                        ProjectBillingType::Hourly->value => 'Hourly',
                        ProjectBillingType::Fixed->value => 'Fixed price',
                    ])
                    ->required()
                    ->default(ProjectBillingType::Hourly->value)
                    ->live(),
                Forms\Components\TextInput::make('billable_rate')
                    ->label('Billable rate (in Cents)')
                    ->nullable()
                    ->visible(fn (Get $get): bool => $get('billing_type') === ProjectBillingType::Hourly->value)
                    ->rules([
                        'nullable',
                        'integer',
                        'gt:0',
                        'max:2147483647',
                    ])
                    ->numeric(),
                Forms\Components\TextInput::make('fixed_price')
                    ->label('Fixed price (minor units)')
                    ->nullable()
                    ->visible(fn (Get $get): bool => $get('billing_type') === ProjectBillingType::Fixed->value)
                    ->rules([
                        'nullable',
                        'integer',
                        'gt:0',
                        'max:9223372036854775807',
                    ])
                    ->numeric(),
                Forms\Components\Select::make('organization_id')
                    ->relationship(name: 'organization', titleAttribute: 'name')
                    ->searchable(['name'])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color'),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('organization')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable(),
                SelectFilter::make('organization_id')
                    ->label('Organization ID')
                    ->relationship('organization', 'id')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ProjectMembersRelationManager::make(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
