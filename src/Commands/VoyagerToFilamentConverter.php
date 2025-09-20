<?php

namespace Hexaora\VoyagerToFilament\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class VoyagerToFilamentConverter extends Command
{
    protected $signature = 'voyager:convert-to-filament 
                          {--model=* : Specific models to convert}
                          {--all : Convert all Voyager BREAD}
                          {--force : Overwrite existing Filament resources}
                          {--dry-run : Show what would be generated without creating files}
                          {--with-relationships : Include relationship fields}
                          {--panel=admin : Filament panel name}';
    
    protected $description = 'Convert Voyager BREAD configurations to Filament 4 Resources with advanced mapping';

    /**
     * Mapping between Voyager field types and Filament 4 components
     * Updated for Filament 4.x compatibility
     */
    protected $voyagerToFilamentMapping = [
        'text' => ['component' => 'TextInput', 'column' => 'TextColumn'],
        'text_area' => ['component' => 'Textarea', 'column' => 'TextColumn'],
        'rich_text_box' => ['component' => 'RichEditor', 'column' => 'TextColumn'],
        'code_editor' => ['component' => 'Textarea', 'column' => 'TextColumn'],
        'checkbox' => ['component' => 'Toggle', 'column' => 'IconColumn'],
        'radio_btn' => ['component' => 'Radio', 'column' => 'TextColumn'],
        'select_dropdown' => ['component' => 'Select', 'column' => 'TextColumn'],
        'select_multiple' => ['component' => 'Select', 'column' => 'TextColumn'],
        'file' => ['component' => 'FileUpload', 'column' => 'TextColumn'],
        'image' => ['component' => 'FileUpload', 'column' => 'ImageColumn'],
        'date' => ['component' => 'DatePicker', 'column' => 'TextColumn'],
        'time' => ['component' => 'TimePicker', 'column' => 'TextColumn'],
        'datetime' => ['component' => 'DateTimePicker', 'column' => 'TextColumn'],
        'timestamp' => ['component' => 'DateTimePicker', 'column' => 'TextColumn'],
        'number' => ['component' => 'TextInput', 'column' => 'TextColumn'],
        'password' => ['component' => 'TextInput', 'column' => 'TextColumn'],
        'color' => ['component' => 'ColorPicker', 'column' => 'ColorColumn'],
        'hidden' => ['component' => 'Hidden', 'column' => null],
        'relationship' => ['component' => 'Select', 'column' => 'TextColumn'],
    ];

    protected $relationshipCache = [];
    protected $convertedResources = [];

    public function handle()
    {
        $this->displayHeader();
        
        if (!$this->validateEnvironment()) {
            return Command::FAILURE;
        }

        $dataTypes = $this->getVoyagerDataTypes();
        
        if ($dataTypes->isEmpty()) {
            $this->warn('⚠️  No Voyager BREAD configurations found.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$dataTypes->count()} Voyager BREAD configurations");

        if ($this->option('with-relationships')) {
            $this->cacheRelationships($dataTypes);
        }

        $progressBar = $this->output->createProgressBar($dataTypes->count());
        $progressBar->start();

        foreach ($dataTypes as $dataType) {
            $this->convertDataType($dataType);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayConversionSummary();
        $this->displayPostConversionInstructions();
        
        return Command::SUCCESS;
    }

    protected function displayHeader(): void
    {
        $this->info('');
        $this->info('🚀 Voyager to Filament 4 Migration Tool');
        $this->info('==========================================');
        $this->info('Converting BREAD configurations to modern Filament 4 resources');
        $this->info('');
    }

    protected function validateEnvironment(): bool
    {
        if (!$this->checkVoyagerTables()) {
            $this->error('❌ Voyager tables not found. Ensure Voyager is properly installed.');
            $this->line('   Required tables: data_types, data_rows');
            return false;
        }

        if (!$this->checkFilamentInstallation()) {
            $this->error('❌ Filament 4 not detected. Please install Filament first:');
            $this->line('   composer require filament/filament:"^4.0" -W');
            $this->line('   php artisan filament:install --panels');
            return false;
        }

        return true;
    }

    protected function checkVoyagerTables(): bool
    {
        return DB::getSchemaBuilder()->hasTable('data_types') && 
               DB::getSchemaBuilder()->hasTable('data_rows');
    }

    protected function checkFilamentInstallation(): bool
    {
        return class_exists('Filament\\Resources\\Resource');
    }

    protected function getVoyagerDataTypes(): Collection
    {
        $query = DB::table('data_types');
        
        if ($this->option('model')) {
            $models = collect($this->option('model'))->flatMap(function ($model) {
                $possibleModels = [];
                
                // Support various model name formats
                if (Str::startsWith($model, 'App\\') || Str::startsWith($model, 'TCG\\')) {
                    $possibleModels[] = $model;
                } else {
                    $possibleModels[] = "App\\Models\\{$model}";
                    $possibleModels[] = "TCG\\Voyager\\Models\\{$model}";
                }
                
                return $possibleModels;
            });
            
            $query->whereIn('model_name', $models->toArray());
        }
        
        return $query->get();
    }

    protected function cacheRelationships(Collection $dataTypes): void
    {
        $this->info('🔍 Analyzing relationships...');
        
        foreach ($dataTypes as $dataType) {
            $relationships = DB::table('data_rows')
                ->where('data_type_id', $dataType->id)
                ->where('type', 'relationship')
                ->get();
                
            foreach ($relationships as $relation) {
                $details = json_decode($relation->details, true) ?? [];
                $this->relationshipCache[$dataType->model_name][$relation->field] = $details;
            }
        }
    }

    protected function convertDataType($dataType): void
    {
        $modelName = class_basename($dataType->model_name);
        $resourceName = $modelName . 'Resource';
        
        $this->line("🔄 Converting: {$dataType->display_name_singular} ({$modelName})");
        
        if ($this->option('dry-run')) {
            $this->line("   📋 Would create: {$resourceName}");
            $this->showFieldPreview($dataType);
            return;
        }

        try {
            $dataRows = $this->getDataRows($dataType->id);
            $this->generateFilamentResource($dataType, $dataRows, $modelName, $resourceName);
            $this->convertedResources[] = $resourceName;
            $this->line("   ✅ Created: {$resourceName}");
        } catch (\Exception $e) {
            $this->error("   ❌ Failed to convert {$resourceName}: " . $e->getMessage());
        }
    }

    protected function getDataRows($dataTypeId): Collection
    {
        return DB::table('data_rows')
            ->where('data_type_id', $dataTypeId)
            ->orderBy('"order"')
            ->get();
    }

    protected function showFieldPreview($dataType): void
    {
        $dataRows = $this->getDataRows($dataType->id);
        $this->line("   📝 Fields to convert:");
        
        foreach ($dataRows as $row) {
            $filamentType = $this->voyagerToFilamentMapping[$row->type]['component'] ?? 'TextInput';
            $this->line("      • {$row->field} ({$row->type} → {$filamentType})");
        }
    }

    protected function generateFilamentResource($dataType, $dataRows, $modelName, $resourceName): void
    {
        $panel = $this->option('panel');
        $resourcePath = app_path("Filament/" . ucfirst($panel) . "/Resources/{$resourceName}.php");
        
        if (File::exists($resourcePath) && !$this->option('force')) {
            $this->warn("   ⚠️  {$resourceName} already exists. Use --force to overwrite.");
            return;
        }

        File::ensureDirectoryExists(dirname($resourcePath));

        // Generate the main resource file
        $resourceContent = $this->getResourceTemplate($resourceName, $modelName, $dataType, $panel);
        File::put($resourcePath, $resourceContent);

        // Generate the schema form file
        $this->generateFormSchema($resourceName, $modelName, $dataRows, $dataType, $panel);

        // Generate the table file
        $this->generateTableFile($resourceName, $modelName, $dataRows, $dataType, $panel);

        // Generate resource pages
        $this->generateResourcePages($resourceName, $modelName, $panel);
    }

    protected function generateFormFields($dataRows, $modelName): string
    {
        $fields = [];
        
        foreach ($dataRows as $row) {
            if (!$this->shouldIncludeInForm($row)) {
                continue;
            }

            $field = $this->generateSingleFormField($row, $modelName);
            if ($field) {
                $fields[] = $field;
            }
        }

        // Group fields into sections if we have many fields (Filament 4 best practice)
        if (count($fields) > 8) {
            return $this->wrapFieldsInSections($fields);
        }

        return implode("\n", $fields);
    }

    protected function wrapFieldsInSections(array $fields): string
    {
        $chunks = array_chunk($fields, 6);
        $sections = [];
        
        foreach ($chunks as $index => $chunk) {
            $sectionTitle = match($index) {
                0 => 'Basic Information',
                1 => 'Additional Details',
                default => 'Extra Information'
            };
            
            $sections[] = "            Schemas\\Components\\Section::make('{$sectionTitle}')
                ->schema([
" . implode("\n", $chunk) . "
                ])
                ->collapsible(),";
        }
        
        return implode("\n", $sections);
    }

    protected function shouldIncludeInForm($row): bool
    {
        return ($row->add == 1 || $row->edit == 1) && 
               !in_array($row->field, ['id', 'created_at', 'updated_at']);
    }

    protected function generateSingleFormField($row, $modelName): ?string
    {
        $details = json_decode($row->details, true) ?? [];
        $mapping = $this->voyagerToFilamentMapping[$row->type] ?? ['component' => 'TextInput'];
        
        $component = $mapping['component'];
        $fieldName = $row->field;
        $displayName = $row->display_name;
        
        $field = "                {$component}::make('{$fieldName}')
                    ->label('{$displayName}')";
        
        // Add field-specific configurations for Filament 4
        $field .= $this->addFormFieldOptions($row->type, $details, $row, $modelName);
        
        // Add validation
        if ($row->required) {
            $field .= "\n                    ->required()";
        }
        
        $field .= ',';
        return $field;
    }

    protected function addFormFieldOptions($type, $details, $row, $modelName): string
    {
        $options = '';
        
        switch ($type) {
            case 'image':
                $options .= "\n                    ->image()
                    ->directory('images')
                    ->visibility('public')";
                if (isset($details['resize'])) {
                    $resize = $details['resize'];
                    if (isset($resize['width']) && isset($resize['height'])) {
                        $options .= "\n                    ->imageResizeTargetWidth('{$resize['width']}')
                    ->imageResizeTargetHeight('{$resize['height']}')";
                    }
                }
                if (isset($details['quality'])) {
                    $options .= "\n                    ->imagePreviewHeight('250')";
                }
                break;
                
            case 'file':
                $options .= "\n                    ->directory('files')
                    ->visibility('public')";
                if (isset($details['allowed']) && is_array($details['allowed'])) {
                    $allowed = array_map(fn($ext) => ".{$ext}", $details['allowed']);
                    $allowedString = "'" . implode("','", $allowed) . "'";
                    $options .= "\n                    ->acceptedFileTypes([{$allowedString}])";
                }
                break;
                
            case 'select_dropdown':
            case 'radio_btn':
                if (isset($details['options']) && is_array($details['options'])) {
                    $optionsArray = [];
                    foreach ($details['options'] as $key => $value) {
                        $optionsArray[] = "'{$key}' => '{$value}'";
                    }
                    $optionsString = '[' . implode(', ', $optionsArray) . ']';
                    $options .= "\n                    ->options({$optionsString})";
                }
                break;
                
            case 'select_multiple':
                $options .= "\n                    ->multiple()";
                if (isset($details['options']) && is_array($details['options'])) {
                    $optionsArray = [];
                    foreach ($details['options'] as $key => $value) {
                        $optionsArray[] = "'{$key}' => '{$value}'";
                    }
                    $optionsString = '[' . implode(', ', $optionsArray) . ']';
                    $options .= "\n                    ->options({$optionsString})";
                }
                break;
                
            case 'number':
                $options .= "\n                    ->numeric()";
                if (isset($details['min'])) {
                    $options .= "\n                    ->minValue({$details['min']})";
                }
                if (isset($details['max'])) {
                    $options .= "\n                    ->maxValue({$details['max']})";
                }
                if (isset($details['step'])) {
                    $options .= "\n                    ->step({$details['step']})";
                }
                break;
                
            case 'password':
                $options .= "\n                    ->password()
                    ->revealable()
                    ->dehydrateStateUsing(fn (\$state) => Hash::make(\$state))
                    ->dehydrated(fn (\$state) => filled(\$state))
                    ->required(fn (string \$operation): bool => \$operation === 'create')";
                break;
                
            case 'text_area':
                $rows = $details['rows'] ?? 4;
                $options .= "\n                    ->rows({$rows})";
                if (isset($details['max_length'])) {
                    $options .= "\n                    ->maxLength({$details['max_length']})";
                }
                break;
                
            case 'rich_text_box':
                $options .= "\n                    ->toolbarButtons([
                        'attachFiles',
                        'blockquote',
                        'bold',
                        'bulletList',
                        'codeBlock',
                        'h2',
                        'h3',
                        'italic',
                        'link',
                        'orderedList',
                        'redo',
                        'strike',
                        'underline',
                        'undo',
                    ])
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('attachments')";
                break;
                
            case 'checkbox':
                $options .= "\n                    ->inline(false)";
                break;
                
            case 'text':
                if (isset($details['validation']['rule'])) {
                    if (str_contains($details['validation']['rule'], 'email')) {
                        $options .= "\n                    ->email()";
                    }
                    if (str_contains($details['validation']['rule'], 'url')) {
                        $options .= "\n                    ->url()";
                    }
                }
                break;
                
            case 'relationship':
                if ($this->option('with-relationships') && isset($this->relationshipCache[$modelName][$row->field])) {
                    $relationDetails = $this->relationshipCache[$modelName][$row->field];
                    $options .= $this->generateRelationshipOptions($relationDetails);
                }
                break;
        }

        return $options;
    }

    protected function generateRelationshipOptions($relationDetails): string
    {
        $options = '';
        
        if (isset($relationDetails['model'])) {
            $titleAttribute = $relationDetails['label'] ?? 'name';
            $relationshipName = $relationDetails['method'] ?? str_replace('_relationship', '', $relationDetails['column'] ?? 'relation');
            
            $options .= "\n                    ->relationship(name: '{$relationshipName}', titleAttribute: '{$titleAttribute}')";
            
            if (isset($relationDetails['type']) && $relationDetails['type'] === 'belongsToMany') {
                $options .= "\n                    ->multiple()
                    ->preload()";
            }
            
            $options .= "\n                    ->searchable()
                    ->preload()";
        }
        
        return $options;
    }

    protected function generateTableColumns($dataRows): string
    {
        $columns = [];
        
        foreach ($dataRows as $row) {
            if (!$row->browse) {
                continue;
            }

            $mapping = $this->voyagerToFilamentMapping[$row->type] ?? ['column' => 'TextColumn'];
            $columnType = $mapping['column'];
            
            if (!$columnType) {
                continue; // Skip hidden fields
            }
            
            $fieldName = $row->field;
            $displayName = $row->display_name;
            
            $column = "                {$columnType}::make('{$fieldName}')
                    ->label('{$displayName}')";
            
            $column .= $this->addTableColumnOptions($row->type, $row);
            $column .= ',';
            $columns[] = $column;
        }

        return implode("\n", $columns);
    }

    protected function addTableColumnOptions($type, $row): string
    {
        $options = '';
        
        switch ($type) {
            case 'checkbox':
                $options .= "\n                ->boolean()
                ->trueIcon('heroicon-o-check-badge')
                ->falseIcon('heroicon-o-x-circle')";
                break;
                
            case 'date':
                $options .= "\n                ->date()
                ->sortable()";
                break;
                
            case 'datetime':
            case 'timestamp':
                $options .= "\n                ->dateTime()
                ->sortable()";
                break;
                
            case 'image':
                $options .= "\n                ->circular()
                ->height(50)";
                break;
                
            case 'text':
            case 'text_area':
                $options .= "\n                    ->limit(50)
                    ->tooltip(function (Model \$record): ?string {
                        return \$record->{$row->field};
                    })
                    ->searchable()
                    ->sortable()";
                break;
                
            case 'rich_text_box':
                $options .= "\n                ->html()
                ->limit(100)";
                break;
                
            default:
                $options .= "\n                ->searchable()
                ->sortable()";
                break;
        }
        
        return $options;
    }

    protected function generateFilters($dataRows): string
    {
        $filters = [];
        
        foreach ($dataRows as $row) {
            if (!$row->browse || in_array($row->field, ['id', 'created_at', 'updated_at'])) {
                continue;
            }

            if (in_array($row->type, ['select_dropdown', 'checkbox', 'date', 'datetime'])) {
                $filterType = match($row->type) {
                    'select_dropdown' => 'SelectFilter',
                    'checkbox' => 'TernaryFilter',
                    'date', 'datetime' => 'Filter',
                    default => 'Filter'
                };
                
                $filter = "                {$filterType}::make('{$row->field}')";
                
                if ($row->type === 'select_dropdown') {
                    $details = json_decode($row->details, true) ?? [];
                    if (isset($details['options'])) {
                        $optionsArray = [];
                        foreach ($details['options'] as $key => $value) {
                            $optionsArray[] = "'{$key}' => '{$value}'";
                        }
                        $optionsString = '[' . implode(', ', $optionsArray) . ']';
                        $filter .= "\n                    ->options({$optionsString})";
                    }
                }
                
                $filter .= ',';
                $filters[] = $filter;
            }
        }

        return implode("\n", $filters);
    }

    protected function getResourceTemplate($resourceName, $modelName, $dataType, $panel): string
    {
        $namespace = "App\\Filament\\" . ucfirst($panel) . "\\Resources";
        $modelNamespace = $this->getModelNamespace($dataType->model_name);
        $recordTitle = $dataType->display_name_singular;
        $pluralLabel = $dataType->display_name_plural;
        $navigationIcon = $this->getNavigationIcon($dataType);
        $navigationGroup = $this->getNavigationGroup($dataType);
        
        return "<?php

namespace {$namespace};

use {$namespace}\\{$resourceName}\\Pages\\Create{$modelName};
use {$namespace}\\{$resourceName}\\Pages\\Edit{$modelName};
use {$namespace}\\{$resourceName}\\Pages\\List{$modelName};
use {$namespace}\\{$resourceName}\\Pages\\View{$modelName};
use {$modelNamespace};
use {$namespace}\\{$resourceName}\\Schemas\\{$modelName}Form;
use {$namespace}\\{$resourceName}\\Tables\\{$pluralLabel}Table;
use Filament\\Resources\\Resource;
use Filament\\Schemas\\Schema;
use Filament\\Tables\\Table;
use BackedEnum;
use UnitEnum;

class {$resourceName} extends Resource
{
    protected static ?string \$model = {$modelName}::class;

    protected static string | BackedEnum | null \$navigationIcon = '{$navigationIcon}';
    
    protected static string | UnitEnum | null \$navigationGroup = '{$navigationGroup}';
    
    protected static ?string \$recordTitleAttribute = 'name';
    
    protected static ?string \$navigationLabel = '{$pluralLabel}';
    
    protected static ?string \$modelLabel = '{$recordTitle}';
    
    protected static ?string \$pluralModelLabel = '{$pluralLabel}';
    
    protected static ?int \$navigationSort = 1;

    public static function form(Schema \$schema): Schema
    {
        return {$modelName}Form::configure(\$schema);
    }

    public static function table(Table \$table): Table
    {
        return {$pluralLabel}Table::configure(\$table);
    }
    
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => List{$modelName}::route('/'),
            'create' => Create{$modelName}::route('/create'),
            'view' => View{$modelName}::route('/{record}'),
            'edit' => Edit{$modelName}::route('/{record}/edit'),
        ];
    }
}";
    }

    protected function getModelNamespace($voyagerModelName): string
    {
        // Convert Voyager model names to app models
        if (str_starts_with($voyagerModelName, 'TCG\\Voyager\\Models\\')) {
            $modelName = class_basename($voyagerModelName);
            return "App\\Models\\{$modelName}";
        }
        
        return $voyagerModelName;
    }

    protected function getNavigationIcon($dataType): string
    {
        $iconMap = [
            'voyager-people' => 'heroicon-o-users',
            'voyager-person' => 'heroicon-o-user',
            'voyager-settings' => 'heroicon-o-cog-6-tooth',
            'voyager-news' => 'heroicon-o-newspaper',
            'voyager-photos' => 'heroicon-o-photo',
            'voyager-file-text' => 'heroicon-o-document-text',
            'voyager-categories' => 'heroicon-o-tag',
            'voyager-dashboard' => 'heroicon-o-home',
            'voyager-mail' => 'heroicon-o-envelope',
            'voyager-lock' => 'heroicon-o-lock-closed',
            'voyager-list' => 'heroicon-o-list-bullet',
        ];

        $icon = $dataType->icon ?? 'voyager-list';
        return $iconMap[$icon] ?? 'heroicon-o-rectangle-stack';
    }

    protected function getNavigationGroup($dataType): string
    {
        $modelName = class_basename($dataType->model_name);
        
        $groupMap = [
            'User' => 'User Management',
            'Role' => 'User Management', 
            'Permission' => 'User Management',
            'Post' => 'Content',
            'Page' => 'Content',
            'Category' => 'Content',
            'Setting' => 'Settings',
            'Menu' => 'Navigation',
        ];

        return $groupMap[$modelName] ?? 'General';
    }

    protected function generateFormSchema($resourceName, $modelName, $dataRows, $dataType, $panel): void
    {
        $formPath = app_path("Filament/" . ucfirst($panel) . "/Resources/{$resourceName}/Schemas/{$modelName}Form.php");
        
        if (File::exists($formPath) && !$this->option('force')) {
            return;
        }

        File::ensureDirectoryExists(dirname($formPath));

        $formFields = $this->generateFormFields($dataRows, $dataType->model_name);
        $namespace = "App\\Filament\\" . ucfirst($panel) . "\\Resources\\{$resourceName}\\Schemas";
        
        $content = "<?php

namespace {$namespace};

use Filament\\Forms\\Components\\ColorPicker;
use Filament\\Forms\\Components\\DatePicker;
use Filament\\Forms\\Components\\DateTimePicker;
use Filament\\Forms\\Components\\FileUpload;
use Filament\\Forms\\Components\\Hidden;
use Filament\\Forms\\Components\\Radio;
use Filament\\Forms\\Components\\RichEditor;
use Filament\\Forms\\Components\\Select;
use Filament\\Forms\\Components\\Textarea;
use Filament\\Forms\\Components\\TextInput;
use Filament\\Forms\\Components\\TimePicker;
use Filament\\Forms\\Components\\Toggle;
use Filament\\Schemas\\Components\\Section;
use Filament\\Schemas\\Schema;
use Illuminate\\Support\\Facades\\Hash;

class {$modelName}Form
{
    public static function configure(Schema \$schema): Schema
    {
        return \$schema
            ->components([
{$formFields}
            ]);
    }
}";

        File::put($formPath, $content);
    }

    protected function generateTableFile($resourceName, $modelName, $dataRows, $dataType, $panel): void
    {
        $pluralLabel = $dataType->display_name_plural;
        $tablePath = app_path("Filament/" . ucfirst($panel) . "/Resources/{$resourceName}/Tables/{$pluralLabel}Table.php");
        
        if (File::exists($tablePath) && !$this->option('force')) {
            return;
        }

        File::ensureDirectoryExists(dirname($tablePath));

        $tableColumns = $this->generateTableColumns($dataRows);
        $filters = $this->generateFilters($dataRows);
        $namespace = "App\\Filament\\" . ucfirst($panel) . "\\Resources\\{$resourceName}\\Tables";
        
        $content = "<?php

namespace {$namespace};

use Filament\\Actions\\BulkActionGroup;
use Filament\\Actions\\DeleteAction;
use Filament\\Actions\\DeleteBulkAction;
use Filament\\Actions\\EditAction;
use Filament\\Actions\\ViewAction;
use Filament\\Tables\\Columns\\ColorColumn;
use Filament\\Tables\\Columns\\IconColumn;
use Filament\\Tables\\Columns\\ImageColumn;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Filters\\Filter;
use Filament\\Tables\\Filters\\SelectFilter;
use Filament\\Tables\\Filters\\TernaryFilter;
use Filament\\Tables\\Table;
use Illuminate\\Database\\Eloquent\\Model;

class {$pluralLabel}Table
{
    public static function configure(Table \$table): Table
    {
        return \$table
            ->columns([
{$tableColumns}
            ])
            ->filters([
{$filters}
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}";

        File::put($tablePath, $content);
    }

    protected function generateResourcePages($resourceName, $modelName, $panel): void
    {
        $resourceDir = str_replace('Resource', '', $resourceName);
        $pagesPath = app_path("Filament/" . ucfirst($panel) . "/Resources/{$resourceDir}Resource/Pages");
        
        File::makeDirectory($pagesPath, 0755, true);

        $pages = [
            'List' => 'ListRecords',
            'Create' => 'CreateRecord',
            'View' => 'ViewRecord',
            'Edit' => 'EditRecord'
        ];

        foreach ($pages as $prefix => $baseClass) {
            $this->generatePageFile($pagesPath, $prefix . $modelName, $resourceName, $baseClass, $panel);
        }
    }

    protected function generatePageFile($path, $className, $resourceName, $baseClass, $panel): void
    {
        $filePath = $path . '/' . $className . '.php';
        
        if (File::exists($filePath) && !$this->option('force')) {
            return;
        }

        $resourceDir = str_replace('Resource', '', $resourceName);
        $namespace = "App\\Filament\\" . ucfirst($panel) . "\\Resources\\{$resourceDir}Resource\\Pages";
        $resourceNamespace = "App\\Filament\\" . ucfirst($panel) . "\\Resources\\{$resourceName}";

        $content = "<?php

namespace {$namespace};

use {$resourceNamespace};
use Filament\\Actions;
use Filament\\Resources\\Pages\\{$baseClass};

class {$className} extends {$baseClass}
{
    protected static string \$resource = {$resourceName}::class;";

        if ($baseClass === 'ListRecords') {
            $content .= "
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\\CreateAction::make(),
        ];
    }";
        } elseif ($baseClass === 'ViewRecord') {
            $content .= "
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\\EditAction::make(),
        ];
    }";
        } elseif ($baseClass === 'EditRecord') {
            $content .= "
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\\ViewAction::make(),
            Actions\\DeleteAction::make(),
        ];
    }";
        }

        $content .= "
}";

        File::put($filePath, $content);
    }

    protected function displayConversionSummary(): void
    {
        $this->info('');
        $this->info('📊 Conversion Summary:');
        $this->line('');
        $this->line("✅ Successfully converted: " . count($this->convertedResources) . " resources");
        
        if (!empty($this->convertedResources)) {
            foreach ($this->convertedResources as $resource) {
                $this->line("   • {$resource}");
            }
        }
    }

    protected function displayPostConversionInstructions(): void
    {
        $panel = $this->option('panel');
        
        $this->info('');
        $this->info('🎯 Next Steps:');
        $this->line('');
        $this->line("1. 📁 Review generated resources in app/Filament/" . ucfirst($panel) . "/Resources/");
        $this->line('2. 🎨 Customize form layouts and add custom fields');
        $this->line('3. 📋 Review table columns and add custom filters');
        $this->line('4. 🔗 Set up relationship managers for complex relationships');
        $this->line('5. 🧭 Customize navigation groups and icons');
        $this->line('6. 🧪 Test all CRUD operations thoroughly');
        $this->line('7. 🗑️  Consider removing Voyager dependencies when satisfied');
        $this->line('');
        $this->info('💡 Pro Tips:');
        $this->line('   • Use --dry-run first to preview changes');
        $this->line('   • Use --with-relationships to include relationship fields');
        $this->line('   • Visit /admin to see your new Filament admin panel');
        $this->line('');
        $this->info('📚 Documentation: https://filamentphp.com/docs/4.x');
    }
}