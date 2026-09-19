<?php

namespace SafferIt\LibrenmsNetconf\Definitions;

/**
 * One column of a `tables:` mapping: an XPath, the column type from TableSchema and an
 * optional transform applied to the text before the type coercion.
 */
final class TableColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $xpath,
        public readonly string $type,
        public readonly ?string $transform = null,
    ) {
    }
}
