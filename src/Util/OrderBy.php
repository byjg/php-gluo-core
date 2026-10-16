<?php

namespace ByJG\Gluo\Util;

use ByJG\MicroOrm\Mapper;
use ByJG\RestServer\Exception\Error400Exception;

/**
 * Turns the `orderBy` query string of a list endpoint into ORDER BY terms.
 *
 * The value comes from the client, so nothing of it reaches the SQL: each name must be a
 * field of the model, and what is returned is the column name the model declares.
 */
class OrderBy
{
    /**
     * Accepts one or more fields separated by commas, each optionally followed by
     * `asc` or `desc`: "name", "name desc", "price desc,name". A field is named by its
     * property (`createdAt`) or by its column (`created_at`).
     *
     * @param class-string $modelClass
     * @return string[] e.g. ['price DESC', 'name']
     * @throws Error400Exception when a term is malformed or names a field the model does not have
     */
    public static function fromQueryString(?string $orderBy, string $modelClass): array
    {
        if ($orderBy === null || trim($orderBy) === '') {
            return [];
        }

        $columns = [];
        foreach ((new Mapper($modelClass))->getFieldMap() as $field) {
            $columns[$field->getPropertyName()] = $field->getFieldName();
            $columns[$field->getFieldName()] = $field->getFieldName();
        }

        $terms = [];
        foreach (explode(',', $orderBy) as $term) {
            if (preg_match('/^\s*(\w+)(?:\s+(asc|desc))?\s*$/i', $term, $matches) !== 1) {
                throw new Error400Exception("Invalid orderBy: '" . trim($term) . "'. Use a field name, optionally followed by asc or desc.");
            }
            if (!isset($columns[$matches[1]])) {
                throw new Error400Exception("Invalid orderBy: unknown field '{$matches[1]}'.");
            }

            $descending = strtolower($matches[2] ?? '') === 'desc';
            $terms[] = $columns[$matches[1]] . ($descending ? ' DESC' : '');
        }

        return $terms;
    }
}
