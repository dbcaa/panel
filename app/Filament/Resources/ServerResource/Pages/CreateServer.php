<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Models\Egg;
use App\Models\Node;
use App\Services\Servers\RandomWordService;
use App\Services\Servers\ServerCreationService;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Closure;
use Filament\Forms;
use Illuminate\Support\HtmlString;

class CreateServer extends CreateRecord
{
    protected static string $resource = ServerResource::class;
    protected static bool $canCreateAnother = false;
    public ?Egg $egg = null;
    public array $ports = [];
    public array $eggDefaultPorts = [];

    public function form(Form $form): Form
    {
        return $form
            ->columns([
                'default' => 2,
                'sm' => 2,
                'md' => 4,
                'lg' => 6,
            ])
            ->schema([
                Forms\Components\Select::make('egg_id')
                    ->disabledOn('edit')
                    ->prefixIcon('tabler-egg')
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 2,
                        'md' => 2,
                        'lg' => 3,
                    ])
                    ->relationship('egg', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get, $old) {
                        $this->egg = Egg::query()->find($state);
                        $set('startup', $this->egg->startup);
                        $set('image', '');

                        $this->resetEggVariables($set, $get);

                        $previousEgg = Egg::query()->find($old);
                        if (!$get('name') || $previousEgg?->getKebabName() === $get('name')) {
                            $set('name', $this->egg->getKebabName());
                        }
                    })
                    ->required(),

                Forms\Components\TextInput::make('name')
                    ->prefixIcon('tabler-server')
                    ->label('Display Name')
                    ->suffixAction(Forms\Components\Actions\Action::make('random')
                        ->icon('tabler-dice-' . random_int(1, 6))
                        ->action(function (Forms\Set $set, Forms\Get $get) {
                            $prefix = $this->egg ? str($this->egg->name)->lower()->kebab() . '-' : '';

                            $word = (new RandomWordService())->word();

                            $set('name', $prefix . $word);
                        }))
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 4,
                        'md' => 2,
                        'lg' => 3,
                    ])
                    ->required()
                    ->maxLength(191),

                Forms\Components\Select::make('node_id')
                    ->disabledOn('edit')
                    ->prefixIcon('tabler-server-2')
                    ->default(fn () => Node::query()->latest()->first()?->id)
                    ->columnSpan(3)
                    ->live()
                    ->relationship('node', 'name')
                    ->searchable()
                    ->preload()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('allocation_id', null))
                    ->required(),

                Forms\Components\Select::make('owner_id')
                    ->prefixIcon('tabler-user')
                    ->default(auth()->user()->id)
                    ->label('Owner')
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 4,
                        'md' => 2,
                        'lg' => 3,
                    ])
                    ->relationship('user', 'username')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Textarea::make('description')
                    ->hidden()
                    ->default('')
                    ->required()
                    ->columnSpanFull(),

                Forms\Components\TagsInput::make('ports')
                    ->columnSpan(3)
                    ->placeholder('Example: 25565, 8080, 1337-1340')
                    ->splitKeys(['Tab', ' ', ','])
                    ->helperText(new HtmlString('
                                These are the ports that users can connect to this Server through.
                                <br />
                                You would typically port forward these on your home network.
                            '))
                    ->label('Ports')
                    ->afterStateUpdated(self::ports(...))
                    ->live(),

                Forms\Components\Repeater::make('assignments')
                    ->columnSpan(3)
                    ->defaultItems(fn () => count($this->eggDefaultPorts))
                    ->label('Port Assignments')
                    ->helperText(fn (Forms\Get $get) => empty($get('ports')) ? 'You must add ports to assign them!' : '')
                    ->live()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->simple(
                        Forms\Components\Select::make('port')
                            ->live()
                            ->disabled(fn (Forms\Get $get) => empty($get('../../ports')) || empty($get('../../assignments')))
                            ->prefix(function (Forms\Components\Component $component) {
                                $key = str($component->getStatePath())->beforeLast('.')->afterLast('.')->toString();

                                return $key;
                            })
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->options(fn (Forms\Get $get) => $this->ports)
                            ->required(),
                    ),

                Forms\Components\Textarea::make('startup')
                    ->hintIcon('tabler-code')
                    ->label('Startup Command')
                    ->required()
                    ->live()
                    ->disabled(fn (Forms\Get $get) => $this->egg === null)
                    ->afterStateUpdated($this->resetEggVariables(...))
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 4,
                        'md' => 4,
                        'lg' => 6,
                    ])
                    ->rows(function ($state) {
                        return str($state)->explode("\n")->reduce(
                            fn (int $carry, $line) => $carry + floor(strlen($line) / 125),
                            0
                        );
                    }),

                Forms\Components\Hidden::make('environment')->default([]),

                Forms\Components\Hidden::make('start_on_completion')->default(true),

                Forms\Components\Section::make('Egg Variables')
                    ->icon('tabler-eggs')
                    ->iconColor('primary')
                    ->collapsible()
                    ->collapsed()
                    ->columnSpan(([
                        'default' => 2,
                        'sm' => 4,
                        'md' => 4,
                        'lg' => 6,
                    ]))
                    ->schema([
                        Forms\Components\Placeholder::make('Select an egg first to show its variables!')
                            ->hidden(fn (Forms\Get $get) => $this->egg),

                        Forms\Components\Placeholder::make('The selected egg has no variables!')
                            ->hidden(fn (Forms\Get $get) => $this->egg?->variables()?->count() !== 0),

                        Forms\Components\Repeater::make('server_variables')
                            ->relationship('serverVariables')
                            ->saveRelationshipsBeforeChildrenUsing(null)
                            ->saveRelationshipsUsing(null)
                            ->grid(2)
                            ->reorderable(false)
                            ->addable(false)
                            ->deletable(false)
                            ->default([])
                            ->hidden(fn ($state) => empty($state))
                            ->schema(function () {

                                $text = Forms\Components\TextInput::make('variable_value')
                                    ->hidden($this->shouldHideComponent(...))
                                    ->maxLength(191)
                                    ->rules([
                                        fn (Forms\Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                            $validator = Validator::make(['validatorkey' => $value], [
                                                'validatorkey' => $get('rules'),
                                            ]);

                                            if ($validator->fails()) {
                                                $message = str($validator->errors()->first())->replace('validatorkey', $get('name'));

                                                $fail($message);
                                            }
                                        },
                                    ]);

                                $select = Forms\Components\Select::make('variable_value')
                                    ->hidden($this->shouldHideComponent(...))
                                    ->options($this->getSelectOptionsFromRules(...))
                                    ->selectablePlaceholder(false);

                                $components = [$text, $select];

                                /** @var Forms\Components\Component $component */
                                foreach ($components as &$component) {
                                    $component = $component
                                        ->live(onBlur: true)
                                        ->hintIcon('tabler-code')
                                        ->label(fn (Forms\Get $get) => $get('name'))
                                        ->hintIconTooltip(fn (Forms\Get $get) => $get('rules'))
                                        ->prefix(fn (Forms\Get $get) => '{{' . $get('env_variable') . '}}')
                                        ->helperText(fn (Forms\Get $get) => empty($get('description')) ? '—' : $get('description'))
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                            $environment = $get($envPath = '../../environment');
                                            $environment[$get('env_variable')] = $state;
                                            $set($envPath, $environment);
                                        });
                                }

                                return $components;
                            })
                            ->columnSpan(2),
                    ]),

                Forms\Components\Section::make('Environment Management')
                    ->collapsed()
                    ->icon('tabler-server-cog')
                    ->iconColor('primary')
                    ->columns([
                        'default' => 2,
                        'sm' => 4,
                        'md' => 4,
                        'lg' => 4,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Fieldset::make('Resource Limits')
                            ->columnSpan([
                                'default' => 2,
                                'sm' => 4,
                                'md' => 4,
                                'lg' => 6,
                            ])
                            ->columns([
                                'default' => 1,
                                'sm' => 2,
                                'md' => 3,
                                'lg' => 3,
                            ])
                            ->schema([
                                Forms\Components\Grid::make()
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('unlimited_mem')
                                            ->label('Memory')->inlineLabel()->inline()
                                            ->default(true)
                                            ->afterStateUpdated(fn (Forms\Set $set) => $set('memory', 0))
                                            ->live()
                                            ->options([
                                                true => 'Unlimited',
                                                false => 'Limited',
                                            ])
                                            ->colors([
                                                true => 'primary',
                                                false => 'warning',
                                            ])
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('memory')
                                            ->dehydratedWhenHidden()
                                            ->hidden(fn (Forms\Get $get) => $get('unlimited_mem'))
                                            ->label('Memory Limit')->inlineLabel()
                                            ->suffix('MiB')
                                            ->default(0)
                                            ->required()
                                            ->columnSpan(2)
                                            ->numeric()
                                            ->minValue(0),
                                    ]),

                                Forms\Components\Grid::make()
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('unlimited_disk')
                                            ->label('Disk Space')->inlineLabel()->inline()
                                            ->default(true)
                                            ->live()
                                            ->afterStateUpdated(fn (Forms\Set $set) => $set('disk', 0))
                                            ->options([
                                                true => 'Unlimited',
                                                false => 'Limited',
                                            ])
                                            ->colors([
                                                true => 'primary',
                                                false => 'warning',
                                            ])
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('disk')
                                            ->dehydratedWhenHidden()
                                            ->hidden(fn (Forms\Get $get) => $get('unlimited_disk'))
                                            ->label('Disk Space Limit')->inlineLabel()
                                            ->suffix('MiB')
                                            ->default(0)
                                            ->required()
                                            ->columnSpan(2)
                                            ->numeric()
                                            ->minValue(0),
                                    ]),

                                Forms\Components\Grid::make()
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('unlimited_cpu')
                                            ->label('CPU')->inlineLabel()->inline()
                                            ->default(true)
                                            ->afterStateUpdated(fn (Forms\Set $set) => $set('cpu', 0))
                                            ->live()
                                            ->options([
                                                true => 'Unlimited',
                                                false => 'Limited',
                                            ])
                                            ->colors([
                                                true => 'primary',
                                                false => 'warning',
                                            ])
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('cpu')
                                            ->dehydratedWhenHidden()
                                            ->hidden(fn (Forms\Get $get) => $get('unlimited_cpu'))
                                            ->label('CPU Limit')->inlineLabel()
                                            ->suffix('%')
                                            ->default(0)
                                            ->required()
                                            ->columnSpan(2)
                                            ->numeric()
                                            ->minValue(0)
                                            ->helperText('100% equals one CPU core.'),
                                    ]),

                                Forms\Components\Grid::make()
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('swap_support')
                                            ->live()
                                            ->label('Enable Swap Memory')
                                            ->inlineLabel()
                                            ->inline()
                                            ->columnSpan(2)
                                            ->default('disabled')
                                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                                $value = match ($state) {
                                                    'unlimited' => -1,
                                                    'disabled' => 0,
                                                    'limited' => 128,
                                                };

                                                $set('swap', $value);
                                            })
                                            ->options([
                                                'unlimited' => 'Unlimited',
                                                'limited' => 'Limited',
                                                'disabled' => 'Disabled',
                                            ])
                                            ->colors([
                                                'unlimited' => 'primary',
                                                'limited' => 'warning',
                                                'disabled' => 'danger',
                                            ]),

                                        Forms\Components\TextInput::make('swap')
                                            ->dehydratedWhenHidden()
                                            ->hidden(fn (Forms\Get $get) => match ($get('swap_support')) {
                                                'disabled', 'unlimited' => true,
                                                'limited' => false,
                                            })
                                            ->label('Swap Memory')
                                            ->default(0)
                                            ->suffix('MiB')
                                            ->minValue(-1)
                                            ->columnSpan(2)
                                            ->inlineLabel()
                                            ->required()
                                            ->integer(),
                                    ]),

                                Forms\Components\Hidden::make('io')
                                    ->helperText('The IO performance relative to other running containers')
                                    ->label('Block IO Proportion')
                                    ->default(500),

                                Forms\Components\Grid::make()
                                    ->columns(4)
                                    ->columnSpanFull()
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('oom_killer')
                                            ->label('OOM Killer')
                                            ->inlineLabel()->inline()
                                            ->default(false)
                                            ->columnSpan(2)
                                            ->options([
                                                false => 'Disabled',
                                                true => 'Enabled',
                                            ])
                                            ->colors([
                                                false => 'success',
                                                true => 'danger',
                                            ]),

                                        Forms\Components\TextInput::make('oom_disabled_hidden')
                                            ->hidden(),
                                    ]),
                            ]),

                        Forms\Components\Fieldset::make('Feature Limits')
                            ->inlineLabel()
                            ->columnSpan([
                                'default' => 2,
                                'sm' => 4,
                                'md' => 4,
                                'lg' => 6,
                            ])
                            ->columns([
                                'default' => 1,
                                'sm' => 2,
                                'md' => 3,
                                'lg' => 3,
                            ])
                            ->schema([
                                Forms\Components\TextInput::make('allocation_limit')
                                    ->suffixIcon('tabler-network')
                                    ->required()
                                    ->numeric()
                                    ->default(0),
                                Forms\Components\TextInput::make('database_limit')
                                    ->suffixIcon('tabler-database')
                                    ->required()
                                    ->numeric()
                                    ->default(0),
                                Forms\Components\TextInput::make('backup_limit')
                                    ->suffixIcon('tabler-copy-check')
                                    ->required()
                                    ->numeric()
                                    ->default(0),
                            ]),
                        Forms\Components\Fieldset::make('Docker Settings')
                            ->columnSpan([
                                'default' => 2,
                                'sm' => 4,
                                'md' => 4,
                                'lg' => 6,
                            ])
                            ->columns([
                                'default' => 1,
                                'sm' => 2,
                                'md' => 3,
                                'lg' => 3,
                            ])
                            ->schema([
                                Forms\Components\Select::make('select_image')
                                    ->label('Image Name')
                                    ->afterStateUpdated(fn (Forms\Set $set, $state) => $set('image', $state))
                                    ->options(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $images = $this->egg->docker_images ?? [];

                                        $currentImage = $get('image');
                                        if (!$currentImage && $images) {
                                            $defaultImage = collect($images)->first();
                                            $set('image', $defaultImage);
                                            $set('select_image', $defaultImage);
                                        }

                                        return array_flip($images) + ['ghcr.io/custom-image' => 'Custom Image'];
                                    })
                                    ->selectablePlaceholder(false)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('image')
                                    ->label('Image')
                                    ->debounce(500)
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $images = $this->egg->docker_images ?? [];

                                        if (in_array($state, $images)) {
                                            $set('select_image', $state);
                                        } else {
                                            $set('select_image', 'ghcr.io/custom-image');
                                        }
                                    })
                                    ->placeholder('Enter a custom Image')
                                    ->columnSpan(1),

                                Forms\Components\KeyValue::make('docker_labels')
                                    ->label('Container Labels')
                                    ->keyLabel('Title')
                                    ->valueLabel('Description')
                                    ->columnSpan(3),
                            ]),
                    ]),
            ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['allocation_additional'] = collect($data['allocation_additional'])->filter()->all();

        /** @var ServerCreationService $service */
        $service = resolve(ServerCreationService::class);

        return $service->handle($data);
    }

    private function shouldHideComponent(Forms\Get $get, Forms\Components\Component $component): bool
    {
        $containsRuleIn = str($get('rules'))->explode('|')->reduce(
            fn ($result, $value) => $result === true && !str($value)->startsWith('in:'), true
        );

        if ($component instanceof Forms\Components\Select) {
            return $containsRuleIn;
        }

        if ($component instanceof Forms\Components\TextInput) {
            return !$containsRuleIn;
        }

        throw new \Exception('Component type not supported: ' . $component::class);
    }

    private function getSelectOptionsFromRules(Forms\Get $get): array
    {
        $inRule = str($get('rules'))->explode('|')->reduce(
            fn ($result, $value) => str($value)->startsWith('in:') ? $value : $result, ''
        );

        return str($inRule)
            ->after('in:')
            ->explode(',')
            ->each(fn ($value) => str($value)->trim())
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }

    public function ports($state, Forms\Set $set) {
        $ports = collect();
        $update = false;
        foreach ($state as $portEntry) {
            if (!str_contains($portEntry, '-')) {
                if (is_numeric($portEntry)) {
                    $ports->push((int) $portEntry);

                    continue;
                }

                continue;
            }

            [$start, $end] = explode('-', $portEntry);
            if (!is_numeric($start) || !is_numeric($end)) {
                continue;
            }

            $start = max((int) $start, 0);
            $end = min((int) $end, 2 ** 16 - 1);
            for ($i = $start; $i <= $end; $i++) {
                $ports->push($i);
            }
        }

        $uniquePorts = $ports->unique()->values();
        if ($ports->count() > $uniquePorts->count()) {
            $ports = $uniquePorts;
        }

        $ports = $ports->filter(fn ($port) => $port > 1024 && $port < 65535)->values();

        $set('ports', $ports->all());
        $this->ports = $ports->all();
    }

    public function resetEggVariables(Forms\Set $set, Forms\Get $get)
    {
        $set('assignments', []);

        $i = 0;
        $this->eggDefaultPorts = [];
        if (str_contains($get('startup'), '{{SERVER_PORT}}')) {
            $this->eggDefaultPorts['SERVER_PORT'] = null;
            $set('assignments.SERVER_PORT', ['port' => null]);
        }

        $variables = $this->egg->variables ?? [];
        $serverVariables = collect();
        $this->ports = [];
        foreach ($variables as $variable) {
            if (str_contains($variable->rules, 'port')) {
                $this->eggDefaultPorts[$variable->env_variable] = $variable->default_value;
                $this->ports[] = (int) $variable->default_value;

                // $set("assignments.$i", ['port' => $i++]);
                $set("assignments.$variable->env_variable", ['port' => $i++]);

                continue;
            }

            $serverVariables->add($variable->toArray());
        }

        $set('ports', $this->ports);

        $variables = [];
        $set($path = 'server_variables', $serverVariables->sortBy(['sort'])->all());
        for ($i = 0; $i < $serverVariables->count(); $i++) {
            $set("$path.$i.variable_value", $serverVariables[$i]['default_value']);
            $set("$path.$i.variable_id", $serverVariables[$i]['id']);
            $variables[$serverVariables[$i]['env_variable']] = $serverVariables[$i]['default_value'];
        }

        $set('environment', $variables);
    }
}
