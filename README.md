# Hexaora Voyager to Filament 4 Converter


 **Effortlessly migrate your Voyager BREAD configurations to modern Filament 4 Resources**

This package provides a powerful Artisan command that automatically converts your existing Voyager BREAD (Browse, Read, Edit, Add, Delete) configurations into fully-featured Filament 4 Resources, complete with forms, tables, and advanced field mappings.

## Features

- **Complete Migration**: Converts Voyager BREAD to Filament 4 Resources with proper structure
- **Advanced Field Mapping**: Intelligent mapping of 20+ Voyager field types to Filament 4 components
- **Modern UI Components**: Generates code using Filament 4's latest Schema/Table architecture
- **Relationship Support**: Handles complex relationships between models
- **Batch Conversion**: Convert all BREAD configurations at once or target specific models
- **Dry-run Mode**: Preview what will be generated before making changes
- **Safe Operation**: Built-in force flag protection and file overwrite controls

----

## Installation

You can install the package via Composer:

```bash
composer require hexaora/voyager-to-filament --dev
```

The package will automatically register itself via Laravel's package discovery feature.

## Quick Start

### Prerequisites

Before using this converter, ensure you have:

- **Laravel 10+** or **Laravel 11+**
- **Filament 4.x** installed and configured
- **Voyager** with existing BREAD configurations
- PHP 8.1+

### Basic Usage

1. **Preview what will be converted** (recommended first step):
```bash
php artisan voyager:convert-to-filament --all --dry-run
```

2. **Convert all Voyager BREAD configurations**:
```bash
php artisan voyager:convert-to-filament --all
```

3. **Convert specific models**:
```bash
php artisan voyager:convert-to-filament --model="App\Models\Post" --model="App\Models\Category"
```

4. **Include relationship fields**:
```bash
php artisan voyager:convert-to-filament --all --with-relationships
```

5. **Force overwrite existing resources**:
```bash
php artisan voyager:convert-to-filament --all --force
```

## 📋 Available Commands

### Main Command
```bash
php artisan voyager:convert-to-filament
```

### Command Options

| Option | Description | Example |
|--------|-------------|---------|
| `--model=*` | Convert specific models | `--model="App\Models\Post"` |
| `--all` | Convert all BREAD configurations | `--all` |
| `--dry-run` | Preview without creating files | `--dry-run` |
| `--force` | Overwrite existing resources | `--force` |
| `--with-relationships` | Include relationship fields | `--with-relationships` |
| `--panel=admin` | Specify Filament panel | `--panel=admin` |

## Field Type Mappings

The converter intelligently maps Voyager field types to their Filament 4 equivalents:

| Voyager Type | Filament 4 Component | Column Type |
|-------------|---------------------|-------------|
| `text` | `TextInput` | `TextColumn` |
| `text_area` | `Textarea` | `TextColumn` |
| `rich_text_box` | `RichEditor` | `TextColumn` |
| `checkbox` | `Toggle` | `IconColumn` |
| `select_dropdown` | `Select` | `TextColumn` |
| `image` | `FileUpload` | `ImageColumn` |
| `file` | `FileUpload` | `TextColumn` |
| `date` | `DatePicker` | `TextColumn` |
| `datetime` | `DateTimePicker` | `TextColumn` |
| `timestamp` | `DateTimePicker` | `TextColumn` |
| `number` | `TextInput` | `TextColumn` |
| `password` | `TextInput` | `TextColumn` |
| `color` | `ColorPicker` | `ColorColumn` |
| `hidden` | `Hidden` | `null` |

## Generated File Structure

After conversion, you'll get a complete Filament 4 resource structure:

```
app/Filament/Admin/Resources/
├── PostResource.php                 # Main resource class
├── PostResource/
│   ├── Pages/
│   │   ├── CreatePost.php          # Create page
│   │   ├── EditPost.php            # Edit page
│   │   ├── ListPost.php            # List page
│   │   └── ViewPost.php            # View page
│   ├── Schemas/
│   │   └── PostForm.php            # Form schema (Filament 4)
│   └── Tables/
│       └── PostsTable.php          # Table configuration
```

## Advanced Usage Examples

### Convert Blog System
```bash
# Convert a complete blog system with relationships
php artisan voyager:convert-to-filament \
  --model="App\Models\Post" \
  --model="App\Models\Category" \
  --model="App\Models\Tag" \
  --with-relationships \
  --force
```

### E-commerce Migration
```bash
# Convert e-commerce models
php artisan voyager:convert-to-filament \
  --model="App\Models\Product" \
  --model="App\Models\Order" \
  --model="App\Models\Customer" \
  --with-relationships
```

### User Management
```bash
# Convert user-related models
php artisan voyager:convert-to-filament \
  --model="TCG\Voyager\Models\User" \
  --model="TCG\Voyager\Models\Role" \
  --with-relationships
```

## Customization

After conversion, you can customize the generated resources:

### 1. **Form Customization**
Edit the generated form schemas in `app/Filament/Admin/Resources/{Model}Resource/Schemas/`:

```php
// Example: PostForm.php
public static function configure(Schema $schema): Schema
{
    return $schema
        ->components([
            Section::make('Basic Information')
                ->schema([
                    TextInput::make('title')->required(),
                    Textarea::make('excerpt'),
                ])
                ->columns(2),
            
            Section::make('Content')
                ->schema([
                    RichEditor::make('content')->required(),
                    FileUpload::make('featured_image')->image(),
                ])
        ]);
}
```

### 2. **Table Customization**
Modify table configurations in `app/Filament/Admin/Resources/{Model}Resource/Tables/`:

```php
// Example: Add custom filters and actions
->filters([
    SelectFilter::make('status')
        ->options([
            'draft' => 'Draft',
            'published' => 'Published',
        ]),
    TernaryFilter::make('is_featured'),
])
->actions([
    ViewAction::make(),
    EditAction::make(),
    Action::make('publish')
        ->action(fn (Post $record) => $record->update(['status' => 'published']))
        ->requiresConfirmation(),
])
```

## Troubleshooting

### Common Issues

1. **Command not found**
   - Ensure the package is installed: `composer require hexaora/voyager-to-filament --dev`
   - Clear artisan cache: `php artisan cache:clear`

2. **No BREAD configurations found**
   - Verify Voyager is properly installed with data in `data_types` table
   - Check that your models are correctly specified

3. **File already exists errors**
   - Use `--force` flag to overwrite existing files
   - Or manually delete existing resources before conversion

4. **Missing relationships**
   - Use `--with-relationships` flag
   - Ensure relationship data exists in `data_rows` table

----

## Testing

The package includes comprehensive tests. Run them with:

```bash
vendor/bin/phpunit
```

## Documentation Links

- [Filament 4 Documentation](https://filamentphp.com/docs/4.x)
- [Voyager Documentation](https://voyager.devdojo.com/docs)
- [Laravel Documentation](https://laravel.com/docs)

## 🤝 Contributing

Contributions are welcome! Please see our contributing guidelines:

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## 👥 Credits

- **[Mahmoud Saeed](https://github.com/MahmoudSaeedNST)** - Package creator and maintainer

### Special Thanks
- [Filament Team](https://github.com/filamentphp) - For creating an amazing admin panel
- [Voyager Team](https://github.com/the-control-group/voyager) - For the inspiration
- Laravel Community - For the continuous support

## 🔗 Links

- **YouTube**: [Hexaora Channel](https://www.youtube.com/@HEXAORA)
- **Facebook**: [Hexaora Page](https://www.facebook.com/Hexaora)
- **GitHub**: [Package Repository](https://github.com/MahmoudSaeedNST/voyager-to-filament)

