<?php

namespace imarc\padstonemason\services;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\fields\Dropdown;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\data\LinkData;
use Craft;

class FieldSupport
{
    public const CONTENT_DESIGNER = 'contentDesigner';

    public static function isMatrix(FieldInterface $field): bool
    {
        return $field instanceof Matrix;
    }

    public static function isReadOnly(FieldInterface $field): bool
    {
        $class = strtolower($field::class);

        return in_array($class, [
            'nystudio107\\seomatic\\fields\\seosettings',
            'nystudio107\\codefield\\fields\\code',
            'imarc\\pickture\\fields\\pickturefield',
        ], true);
    }

    public static function isWritable(FieldInterface $field): bool
    {
        return $field instanceof PlainText
            || $field instanceof CkeditorField
            || $field instanceof Lightswitch
            || $field instanceof Dropdown
            || $field instanceof Link
            || self::isRegex($field)
            || self::isRelation($field);
    }

    public static function isRegex(FieldInterface $field): bool
    {
        return strtolower($field::class) === 'imarc\\regexfield\\fields\\regexfield';
    }

    public static function inputHint(FieldInterface $field): string
    {
        if ($field instanceof Matrix) {
            return 'Use summarize_page for orientation, apply_blocks for multi-block recipes, or add_block / update_block / reorder_blocks / remove_block for single edits. min=' . ($field->minEntries ?? 'none') . ' max=' . ($field->maxEntries ?? 'none');
        }
        if (self::isReadOnly($field)) {
            return 'Read-only. Writes are skipped.';
        }
        if ($field instanceof Lightswitch) {
            return 'Boolean.';
        }
        if ($field instanceof Dropdown) {
            $values = array_values(array_filter(array_map(
                fn(array $option) => $option['value'] ?? null,
                $field->options,
            )));

            return 'One of: ' . implode(', ', $values);
        }
        if ($field instanceof Link) {
            return 'Object with type, value, and optional label. A URL string is accepted as a url link.';
        }
        if (self::isRelation($field)) {
            return 'Existing element id, or a list of ids. Raw uploads in field values are refused; use conversation asset ids.';
        }
        if ($field instanceof CkeditorField) {
            return 'HTML string.';
        }

        return 'String.';
    }

    /**
     * @return array{status: string, value?: mixed, message?: string}
     */
    public static function prepare(FieldInterface $field, mixed $value, ?\craft\elements\User $user): array
    {
        if (self::isMatrix($field)) {
            return [
                'status' => 'fail',
                'message' => $field->handle . ' is a nested Matrix field. Use the block tools instead of setting it as a value.',
            ];
        }

        if (self::isReadOnly($field)) {
            return [
                'status' => 'skip',
                'message' => $field->handle . ' is read-only and was skipped.',
            ];
        }

        if (self::looksLikeUpload($value)) {
            return [
                'status' => 'fail',
                'message' => 'Uploads are not supported.',
            ];
        }

        if (self::isRelation($field)) {
            return self::prepareRelation($field, $value, $user);
        }

        if ($field instanceof Lightswitch) {
            return ['status' => 'ok', 'value' => filter_var($value, FILTER_VALIDATE_BOOLEAN)];
        }

        if ($field instanceof Dropdown) {
            $allowed = array_map(fn(array $option) => (string)($option['value'] ?? ''), $field->options);
            if (!in_array((string)$value, $allowed, true)) {
                return ['status' => 'fail', 'message' => $field->handle . ' does not allow that option.'];
            }

            return ['status' => 'ok', 'value' => (string)$value];
        }

        if ($field instanceof Link) {
            if (is_string($value)) {
                return ['status' => 'ok', 'value' => ['type' => 'url', 'value' => $value]];
            }
            if (is_array($value)) {
                return ['status' => 'ok', 'value' => $value];
            }

            return ['status' => 'fail', 'message' => $field->handle . ' expects a URL string or link object.'];
        }

        if ($field instanceof PlainText || $field instanceof CkeditorField || self::isRegex($field)) {
            if (is_array($value) || is_object($value)) {
                return ['status' => 'fail', 'message' => $field->handle . ' expects text.'];
            }

            return ['status' => 'ok', 'value' => (string)$value];
        }

        return [
            'status' => 'skip',
            'message' => $field->handle . ' is not writable and was skipped.',
        ];
    }

    public static function readValue(FieldInterface $field, mixed $value): mixed
    {
        if ($field instanceof Matrix) {
            return null;
        }

        if ($field instanceof Link || $value instanceof LinkData) {
            if ($value instanceof LinkData) {
                return [
                    'type' => $value->getType(),
                    'value' => $value->getValue(),
                    'label' => $value->getLabel(),
                ];
            }

            return $value;
        }

        if (self::isRelation($field)) {
            if ($value instanceof \craft\elements\db\ElementQuery) {
                $elements = $value->status(null)->all();
            } elseif (is_array($value)) {
                $elements = $value;
            } else {
                return [];
            }

            return array_map(fn(ElementInterface $element) => [
                'id' => $element->id,
                'title' => $element->title ?? (string)$element,
            ], $elements);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }

    public static function isRelation(FieldInterface $field): bool
    {
        return $field instanceof Assets || $field instanceof EntriesField;
    }

    public static function looksLikeUpload(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $keys = ['tmp_name', 'base64', 'file', 'upload', 'contents', 'data'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && !in_array($key, ['value', 'label', 'type'], true)) {
                if ($key === 'value' || $key === 'label' || $key === 'type') {
                    continue;
                }
                if (in_array($key, ['tmp_name', 'base64', 'file', 'upload', 'contents'], true)) {
                    return true;
                }
            }
        }

        foreach ($value as $item) {
            if (is_array($item) && (isset($item['tmp_name']) || isset($item['base64']) || (isset($item['filename']) && isset($item['contents'])))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status: string, value?: int[], message?: string}
     */
    private static function prepareRelation(FieldInterface $field, mixed $value, ?\craft\elements\User $user): array
    {
        $ids = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($ids as $id) {
            if (is_array($id) && isset($id['id'])) {
                $id = $id['id'];
            }
            if (!is_numeric($id)) {
                return ['status' => 'fail', 'message' => $field->handle . ' only accepts existing element ids.'];
            }

            $element = $field instanceof Assets
                ? Asset::find()->id((int)$id)->status(null)->one()
                : Craft::$app->getElements()->getElementById((int)$id);

            if (!$element) {
                return ['status' => 'fail', 'message' => $field->handle . " element $id was not found."];
            }

            if ($user && !Craft::$app->getElements()->canView($element, $user)) {
                return ['status' => 'fail', 'message' => 'You cannot view that related element.'];
            }

            $normalized[] = (int)$element->id;
        }

        return ['status' => 'ok', 'value' => $normalized];
    }
}
