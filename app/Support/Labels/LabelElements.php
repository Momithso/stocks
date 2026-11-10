<?php

namespace App\Support\Labels;

use Illuminate\Validation\Rule;

/**
 * Elements of a label template, stored as json in label_templates.elements. Positions and sizes are
 * in mm from the top left corner of the label; the frontend draws them (resources/js/labels/render.ts).
 *
 * Every element: id, type, x, y, w, h, rotation (0, 90, 180, 270).
 * - text: text with variables in braces, font_size (pt), bold, align, valign, overflow
 *   ("shrink" makes too long text smaller, "clip" cuts it)
 * - code: symbology "qrcode" or "datamatrix"; always holds the short link
 * - logo: logo of the organization
 * - image: free image uploaded to S3, path
 * - photo: first photo of the item
 * - line: horizontal line through the middle of the box, stroke (mm); rotate for a vertical one
 * - rect: frame along the box, stroke (mm)
 */
class LabelElements
{
    /**
     * Types of elements.
     *
     * @var list<string>
     */
    public const TYPES = ['text', 'code', 'logo', 'image', 'photo', 'line', 'rect'];

    /**
     * Largest label in mm, in both directions (an A4 page).
     */
    public const MAX_SIZE_MM = 297;

    /**
     * Validation rules of the elements under the given key.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $key = 'elements'): array
    {
        $max = self::MAX_SIZE_MM;

        return [
            $key => ['present', 'array', 'max:100'],
            "{$key}.*.id" => ['required', 'string', 'max:40'],
            "{$key}.*.type" => ['required', Rule::in(self::TYPES)],
            "{$key}.*.x" => ['required', 'numeric', "between:-{$max},{$max}"],
            "{$key}.*.y" => ['required', 'numeric', "between:-{$max},{$max}"],
            "{$key}.*.w" => ['required', 'numeric', "between:0.1,{$max}"],
            "{$key}.*.h" => ['required', 'numeric', "between:0.1,{$max}"],
            "{$key}.*.rotation" => ['required', Rule::in([0, 90, 180, 270])],
            "{$key}.*.text" => ['nullable', 'string', 'max:500'],
            "{$key}.*.font_size" => ['nullable', 'numeric', 'between:2,200'],
            "{$key}.*.bold" => ['nullable', 'boolean'],
            "{$key}.*.align" => ['nullable', Rule::in(['left', 'center', 'right'])],
            "{$key}.*.valign" => ['nullable', Rule::in(['top', 'middle', 'bottom'])],
            "{$key}.*.overflow" => ['nullable', Rule::in(['shrink', 'clip'])],
            "{$key}.*.symbology" => ['nullable', Rule::in(['qrcode', 'datamatrix'])],
            "{$key}.*.path" => ['nullable', 'string', 'max:255', 'regex:~^labels/[A-Za-z0-9._-]+$~'],
            "{$key}.*.stroke" => ['nullable', 'numeric', 'between:0.1,20'],
        ];
    }

    /**
     * Keep only the known keys of each element, so the json holds nothing else.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public static function clean(array $elements): array
    {
        $keys = array_flip(['id', 'type', 'x', 'y', 'w', 'h', 'rotation', 'text', 'font_size', 'bold', 'align', 'valign', 'overflow', 'symbology', 'path', 'stroke']);

        return array_values(array_map(
            fn (array $element) => array_filter(array_intersect_key($element, $keys), fn ($value) => $value !== null),
            $elements,
        ));
    }
}
