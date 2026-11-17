<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Enums\RecommendationScope;
use App\Models\Product;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Section 11: Recommendation Boundaries, Escalation Rules, Persona & Outlet Weights Editor.
 */
final class RecommendationBoundariesEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'AI Agent & Guardrails';

    protected static ?string $navigationLabel = 'Boundaries & Escalation Rules';

    protected static ?string $title = 'Recommendation Boundaries, Escalation Rules & Persona';

    protected static string $view = 'filament.tenant.pages.recommendation-boundaries-editor';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return;
        }

        $boundaries = (array) ($tenant->boundaries_config ?? []);
        $persona = (array) ($tenant->persona_config ?? []);
        $weights = (array) ($tenant->outlet_ranking_weights ?? []);

        $this->form->fill([
            'recommendation_scope' => $tenant->recommendation_scope->value,
            'usd_to_zig_rate' => (float) $tenant->usd_to_zig_rate,
            'agent_name' => (string) ($persona['agent_name'] ?? 'Mudhumeni AI'),
            'tone' => (string) ($persona['tone'] ?? 'Warm, practical Zimbabwean agronomist'),
            'languages' => (array) ($persona['languages'] ?? ['en', 'sn', 'nd']),
            'allowed_categories' => (array) ($boundaries['allowed_categories'] ?? [
                'pest', 'disease', 'nutrient_deficiency', 'weed', 'livestock_condition',
            ]),
            'high_confidence_threshold' => (float) ($boundaries['high_confidence_threshold'] ?? 0.80),
            'min_confidence_threshold' => (float) ($boundaries['min_confidence_threshold'] ?? 0.55),
            'max_clarifying_questions' => (int) ($boundaries['max_clarifying_questions'] ?? 5),
            'stock_freshness_hours' => (int) ($boundaries['stock_freshness_hours'] ?? 48),
            'outlet_sla_minutes' => (int) ($boundaries['outlet_sla_minutes'] ?? 30),
            'rep_sla_minutes' => (int) ($boundaries['rep_sla_minutes'] ?? 120),
            'restricted_product_ids' => (array) ($boundaries['restricted_product_ids'] ?? []),
            'mandatory_disclaimer' => (string) ($boundaries['mandatory_disclaimer'] ?? 'Always read the product label and wear full PPE.'),
            'weight_stock_confidence' => (float) ($weights['stock_confidence'] ?? 0.35),
            'weight_distance' => (float) ($weights['distance'] ?? 0.30),
            'weight_open_now' => (float) ($weights['open_now'] ?? 0.15),
            'weight_delivery' => (float) ($weights['delivery'] ?? 0.10),
            'weight_price' => (float) ($weights['price'] ?? 0.10),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Agent Persona, Tone, Languages & Exchange Rate')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('agent_name')
                            ->label('AI Representative Name')
                            ->required(),
                        Forms\Components\TextInput::make('tone')
                            ->label('Conversation Tone & Persona')
                            ->required(),
                        Forms\Components\CheckboxList::make('languages')
                            ->label('Enabled Farmer Languages')
                            ->options([
                                'en' => 'English (en)',
                                'sn' => 'ChiShona (sn)',
                                'nd' => 'isiNdebele (nd)',
                            ])
                            ->required(),
                        Forms\Components\Select::make('recommendation_scope')
                            ->options([
                                RecommendationScope::OWN_BRANDS_ONLY->value => RecommendationScope::OWN_BRANDS_ONLY->label(),
                                RecommendationScope::OWN_PLUS_PARTNER_BRANDS->value => RecommendationScope::OWN_PLUS_PARTNER_BRANDS->label(),
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('usd_to_zig_rate')
                            ->label('Daily USD -> ZiG Exchange Rate')
                            ->numeric()
                            ->required(),
                    ]),

                Forms\Components\Section::make('Recommendation Boundaries & Escalation Thresholds')
                    ->columns(3)
                    ->schema([
                        Forms\Components\CheckboxList::make('allowed_categories')
                            ->label('Categories Agent May Advise On (Unchecked Categories Auto-Escalate)')
                            ->options([
                                'pest' => 'Crop Pests',
                                'disease' => 'Crop Diseases',
                                'nutrient_deficiency' => 'Nutrient Deficiencies',
                                'nutrient_toxicity' => 'Nutrient Toxicity',
                                'weed' => 'Weed Management',
                                'abiotic_stress' => 'Abiotic Stress (Frost/Drought/Heat)',
                                'livestock_condition' => 'Livestock Conditions',
                            ])
                            ->columnSpanFull()
                            ->required(),
                        Forms\Components\TextInput::make('high_confidence_threshold')
                            ->label('High Confidence Threshold (Default 0.80)')
                            ->numeric()
                            ->step(0.05)
                            ->minValue(0.60)
                            ->maxValue(0.99)
                            ->required(),
                        Forms\Components\TextInput::make('min_confidence_threshold')
                            ->label('Minimum Advisory Confidence (Default 0.55; below goes to Agronomist)')
                            ->numeric()
                            ->step(0.05)
                            ->minValue(0.30)
                            ->maxValue(0.80)
                            ->required(),
                        Forms\Components\TextInput::make('max_clarifying_questions')
                            ->label('Max Clarifying Questions (Default 5)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(8)
                            ->required(),
                        Forms\Components\TextInput::make('stock_freshness_hours')
                            ->label('Stock Freshness Limit (Hours, Default 48h)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('outlet_sla_minutes')
                            ->label('Outlet Reservation SLA (Minutes, Default 30m)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('rep_sla_minutes')
                            ->label('Sales Rep Lead SLA (Minutes, Default 120m)')
                            ->numeric()
                            ->required(),
                        Forms\Components\Select::make('restricted_product_ids')
                            ->label('Restricted Products (Never Auto-Recommended; Escalate to Agronomist)')
                            ->multiple()
                            ->options(fn () => Product::query()->pluck('name', 'id')->all())
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('mandatory_disclaimer')
                            ->label('Mandatory Pesticide & Agronomy Safety Disclaimer')
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Location-Aware Outlet Finder Ranking Weights')
                    ->columns(5)
                    ->schema([
                        Forms\Components\TextInput::make('weight_stock_confidence')
                            ->label('Stock Confidence & Freshness')
                            ->numeric()
                            ->step(0.05)
                            ->required(),
                        Forms\Components\TextInput::make('weight_distance')
                            ->label('Distance')
                            ->numeric()
                            ->step(0.05)
                            ->required(),
                        Forms\Components\TextInput::make('weight_open_now')
                            ->label('Open Now')
                            ->numeric()
                            ->step(0.05)
                            ->required(),
                        Forms\Components\TextInput::make('weight_delivery')
                            ->label('Delivery Available')
                            ->numeric()
                            ->step(0.05)
                            ->required(),
                        Forms\Components\TextInput::make('weight_price')
                            ->label('Price Competitiveness')
                            ->numeric()
                            ->step(0.05)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return;
        }

        $state = $this->form->getState();

        $tenant->update([
            'recommendation_scope' => $state['recommendation_scope'],
            'usd_to_zig_rate' => (float) $state['usd_to_zig_rate'],
            'persona_config' => [
                'agent_name' => $state['agent_name'],
                'tone' => $state['tone'],
                'languages' => $state['languages'],
            ],
            'boundaries_config' => [
                'allowed_categories' => $state['allowed_categories'],
                'high_confidence_threshold' => (float) $state['high_confidence_threshold'],
                'min_confidence_threshold' => (float) $state['min_confidence_threshold'],
                'max_clarifying_questions' => (int) $state['max_clarifying_questions'],
                'stock_freshness_hours' => (int) $state['stock_freshness_hours'],
                'outlet_sla_minutes' => (int) $state['outlet_sla_minutes'],
                'rep_sla_minutes' => (int) $state['rep_sla_minutes'],
                'restricted_product_ids' => $state['restricted_product_ids'] ?? [],
                'mandatory_disclaimer' => $state['mandatory_disclaimer'],
            ],
            'outlet_ranking_weights' => [
                'stock_confidence' => (float) $state['weight_stock_confidence'],
                'distance' => (float) $state['weight_distance'],
                'open_now' => (float) $state['weight_open_now'],
                'delivery' => (float) $state['weight_delivery'],
                'price' => (float) $state['weight_price'],
            ],
        ]);

        Notification::make()
            ->title('Recommendation boundaries, escalation rules, and outlet weights saved.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Boundaries & Rules')
                ->submit('save'),
        ];
    }
}
