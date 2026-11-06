<?php

namespace App\Support\DataEntries;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * One admin data-entry section: which model it edits, its form fields and how a row is saved.
 *
 * Field keys: name, label, type (text|textarea|email|url|integer|decimal|boolean|date|select|list|password|location),
 * required, list (shown as a table column), unique, min, max, step, options (list of {value,label} or a closure),
 * suggestions (list or closure; free text with hints), help, placeholder, span (2 = full width).
 * A `location` field edits municipality_id + barangay_id; when it is left empty and the resource has
 * latitude/longitude fields, the location is set from the coordinates.
 */
final class DataEntryResource
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $fields
     * @param  list<string>  $search  columns matched by the search box
     * @param  array<string, mixed>  $defaults  attribute => value|Closure(Model): mixed, filled on create when still null
     * @param  Closure(Model, array, bool): void|null  $saving  runs after the fields are applied, before save
     * @param  Closure(Model): ?string|null  $deleting  returns an error message to block a delete
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $singular,
        public readonly string $group,
        public readonly string $icon,
        public readonly string $description,
        public readonly string $model,
        public readonly array $fields,
        public readonly array $search = [],
        public readonly string $title = 'name',
        public readonly string $orderBy = 'id',
        public readonly string $direction = 'desc',
        public readonly ?Closure $scope = null,
        public readonly array $defaults = [],
        public readonly ?Closure $saving = null,
        public readonly ?Closure $deleting = null,
        public readonly ?string $note = null,
    ) {}

    public function table(): string
    {
        return (new $this->model)->getTable();
    }

    public function available(): bool
    {
        return Schema::hasTable($this->table());
    }

    public function query(): Builder
    {
        $query = $this->model::query();
        if ($this->scope) {
            ($this->scope)($query);
        }

        return $query;
    }

    public function hasLocation(): bool
    {
        return collect($this->fields)->contains('type', 'location');
    }

    public function hasCoordinates(): bool
    {
        $names = array_column($this->fields, 'name');

        return in_array('latitude', $names, true) && in_array('longitude', $names, true);
    }

    /** Columns the table may be sorted by: listed, stored, scalar fields. */
    public function sortable(): array
    {
        return array_values(array_map(fn ($field) => $field['name'], array_filter(
            $this->fields,
            fn ($field) => ($field['list'] ?? false) && ! in_array($field['type'], ['location', 'list', 'password'], true),
        )));
    }

    /** Field definitions for the client, with option/suggestion closures resolved. */
    public function schema(): array
    {
        return array_map(function (array $field) {
            foreach (['options', 'suggestions'] as $key) {
                if (isset($field[$key]) && $field[$key] instanceof Closure) {
                    $field[$key] = ($field[$key])();
                }
            }

            return $field;
        }, $this->fields);
    }

    /** Validation rules built from the field definitions. */
    public function rules(?Model $record): array
    {
        $rules = [];
        foreach ($this->schema() as $field) {
            $name = $field['name'];
            $presence = ($field['required'] ?? false) ? 'required' : 'nullable';
            $rules[$name] = match ($field['type']) {
                'text' => [$presence, 'string', 'max:'.($field['max'] ?? 255)],
                'textarea' => [$presence, 'string', 'max:'.($field['max'] ?? 20000)],
                'email' => [$presence, 'string', 'email', 'max:255'],
                'url' => [$presence, 'string', 'url', 'max:2048'],
                'integer' => array_filter([$presence, 'integer', isset($field['min']) ? 'min:'.$field['min'] : null, isset($field['max']) ? 'max:'.$field['max'] : null]),
                'decimal' => array_filter([$presence, 'numeric', isset($field['min']) ? 'min:'.$field['min'] : null, isset($field['max']) ? 'max:'.$field['max'] : null]),
                'boolean' => ['boolean'],
                'date' => [$presence, 'date'],
                'select' => [$presence, Rule::in(array_map(fn ($option) => (string) $option['value'], $field['options'] ?? []))],
                'list' => ['nullable', 'array', 'max:40'],
                'password' => [$record ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
                'location' => [],
                default => [$presence],
            };
            if ($field['type'] === 'list') {
                $rules["{$name}.*"] = ['string', 'max:60'];
            }
            if ($field['type'] === 'location') {
                unset($rules[$name]);
                $rules['municipality_id'] = ['nullable', 'integer', Rule::exists('municipalities', 'id')];
                $rules['barangay_id'] = ['nullable', 'integer', Rule::exists('barangays', 'id')];
            }
            if ($field['unique'] ?? false) {
                $rules[$name][] = Rule::unique($this->table(), $name)->ignore($record?->getKey());
            }
        }

        return $rules;
    }

    /** Human field names for validation messages. */
    public function attributes(): array
    {
        $names = [];
        foreach ($this->fields as $field) {
            $names[$field['name']] = strtolower($field['label']);
        }

        return $names + ['municipality_id' => 'city / municipality', 'barangay_id' => 'barangay'];
    }

    /** Write validated input onto the model: types normalized, empty text stored as NULL. */
    public function apply(Model $record, array $input, bool $creating): void
    {
        foreach ($this->fields as $field) {
            $name = $field['name'];
            if ($field['type'] === 'location') {
                $record->municipality_id = $input['municipality_id'] ?? null;
                $record->barangay_id = $input['barangay_id'] ?? null;

                continue;
            }
            if (! array_key_exists($name, $input) && $field['type'] !== 'boolean') {
                continue;
            }
            $value = $input[$name] ?? null;
            if ($field['type'] === 'password') {
                if (filled($value)) {
                    $record->{$name} = $value;
                }

                continue;
            }
            $record->{$name} = match ($field['type']) {
                'boolean' => (bool) $value,
                'integer' => $value === null || $value === '' ? null : (int) $value,
                'decimal' => $value === null || $value === '' ? null : (float) $value,
                'list' => ($items = array_values(array_unique(array_filter(array_map('trim', (array) $value), 'strlen')))) === [] ? null : $items,
                default => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value),
            };
        }

        if ($this->hasLocation() && $this->hasCoordinates() && $record->municipality_id === null
            && $record->latitude !== null && $record->longitude !== null) {
            $found = app(LocationFromCoordinates::class)->resolve((float) $record->latitude, (float) $record->longitude);
            $record->municipality_id = $found['municipality_id'];
            $record->barangay_id = $found['barangay_id'];
        }

        if ($creating) {
            foreach ($this->defaults as $attribute => $value) {
                if ($record->getAttribute($attribute) === null) {
                    $record->setAttribute($attribute, $value instanceof Closure ? $value($record) : $value);
                }
            }
        }

        if ($this->saving) {
            ($this->saving)($record, $input, $creating);
        }
    }

    /** A row as the client sees it: every form value (never a password) plus the display title. */
    public function present(Model $record): array
    {
        $row = ['id' => $record->getKey(), 'title' => (string) ($record->getAttribute($this->title) ?? '#'.$record->getKey())];
        foreach ($this->fields as $field) {
            $name = $field['name'];
            if ($field['type'] === 'location') {
                $row['municipality_id'] = $record->municipality_id;
                $row['barangay_id'] = $record->barangay_id;

                continue;
            }
            $value = $record->getAttribute($name);
            $row[$name] = match ($field['type']) {
                'password' => '',
                'boolean' => (bool) $value,
                'date' => $value instanceof Carbon ? $value->format('Y-m-d') : ($value ? substr((string) $value, 0, 10) : null),
                'list' => is_array($value) ? array_values($value) : (filled($value) ? array_map('trim', explode(',', (string) $value)) : []),
                default => $value,
            };
        }

        return $row;
    }

    /** Section summary for the overview page and page header. */
    public function meta(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'singular' => $this->singular,
            'group' => $this->group,
            'icon' => $this->icon,
            'description' => $this->description,
            'note' => $this->note,
            'hasLocation' => $this->hasLocation(),
            'hasCoordinates' => $this->hasCoordinates(),
        ];
    }
}
