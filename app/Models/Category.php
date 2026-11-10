<?php

namespace App\Models;

use App\Support\Audit\AuditSubject;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Category of items. Two levels: a category and its sub categories.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 */
class Category extends Model implements Auditable, AuditSubject
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Name with the name of the parent, e.g. "Technik › Kabel".
     */
    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }

    /**
     * All categories as options for select fields, sub categories after their parent.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::whereNull('parent_id')->with('children')->orderBy('name')->get()
            ->flatMap(fn (Category $category) => [
                ['value' => $category->id, 'label' => $category->name],
                ...$category->children->map(fn (Category $child) => ['value' => $child->id, 'label' => $category->name.' › '.$child->name]),
            ])
            ->values()
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Kategorie';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return (string) ($attributes['name'] ?? '');
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'parent_id' => 'Oberkategorie',
            'name' => 'Name',
        ];
    }
}
