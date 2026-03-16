<?php

namespace Kura\Index;

class IndexDefinition
{
    /**
     * @param  list<string>  $columns  対象カラム（複数でcomposite）
     * @param  bool  $unique  unique index かどうか
     */
    public function __construct(
        /** @var list<string> */
        public readonly array $columns,
        public readonly bool $unique = false,
    ) {}

    public static function unique(string ...$columns): self
    {
        return new self(columns: array_values($columns), unique: true);
    }

    public static function nonUnique(string ...$columns): self
    {
        return new self(columns: array_values($columns), unique: false);
    }
}
