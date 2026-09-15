<?php

namespace Eloquage\Tokens;

interface Tokenizer
{
    public function algorithm(): string;

    public function encoding(): ?string;

    /**
     * @return array<string, int>
     */
    public function specialTokenIds(): array;

    /**
     * @return list<int>
     */
    public function encode(string $text): array;

    /**
     * @param  list<string>  $texts
     * @return list<list<int>>
     */
    public function encodeBatch(array $texts): array;

    /**
     * @param  list<int>  $ids
     */
    public function decode(array $ids): string;
}
