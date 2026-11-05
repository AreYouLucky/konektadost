<?php

namespace App\Models;

use Database\Factories\TechnologyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A DOST science, technology or innovation resource.
 *
 * These are the only interventions the system may recommend. A finding with no
 * matching resource must say so rather than inventing a programme.
 */
class Technology extends Model
{
    /** @use HasFactory<TechnologyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'agency',
        'category',
        'duration_date',
        'target_audience',
        'delivery_mode',
        'description',
        'url',
        'tags',
        'active_status',
        'source_reference',
    ];

    /**
     * Restrict to one category, e.g. "Testing & Technical".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * Restrict to resources that are not retired.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('active_status', ['Active', 'active']);
    }

    /**
     * Categories present in the catalogue.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return static::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /**
     * Tags as a list, since the workbook stores them semicolon separated.
     *
     * @return list<string>
     */
    public function tagList(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(';', (string) $this->tags)
        )));
    }

    /**
     * Whether this resource can be offered for a stated audience.
     *
     * A light match: the audience string is compared case-insensitively against
     * the supplied terms.
     */
    public function servesAudience(array $terms): bool
    {
        $audience = mb_strtolower((string) $this->target_audience);

        foreach ($terms as $term) {
            if ($audience !== '' && str_contains($audience, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }
}
