<?php

namespace Eloquage\Tokens;

use InvalidArgumentException;

/**
 * Pure-PHP tokenizer loaded from a small, local vocabulary and merge file.
 */
final class LocalTokenizer implements Tokenizer
{
    /**
     * @param  array<string, int>  $tokenToId
     * @param  array<int, string>  $idToToken
     * @param  array<string, array{token: string, id: int}>  $specialTokens
     * @param  list<array{surface: string, id: int}>  $specialSurfaces
     * @param  array<string, array<string, int>>  $mergeRanks
     */
    private function __construct(
        private readonly string $selectedAlgorithm,
        private readonly ?string $selectedEncoding,
        private readonly array $tokenToId,
        private readonly array $idToToken,
        private readonly array $specialTokens,
        private readonly array $specialSurfaces,
        private readonly array $mergeRanks,
        private readonly int $unknownId,
    ) {}

    public static function fromConfig(array $config): self
    {
        $algorithm = self::requiredString($config, 'algorithm');

        if (! in_array($algorithm, ['bpe', 'wordpiece'], true)) {
            throw new InvalidArgumentException("Unsupported tokenizer algorithm '{$algorithm}'. Expected bpe or wordpiece.");
        }

        $vocabPath = self::requiredPath($config, 'vocab');
        [$tokenToId, $idToToken] = self::loadVocabulary($vocabPath);
        $specialTokens = self::loadSpecialTokens($config, $tokenToId, $idToToken);
        $specialSurfaces = array_map(
            static fn (array $special): array => ['surface' => $special['token'], 'id' => $special['id']],
            array_values($specialTokens),
        );

        usort($specialSurfaces, static function (array $left, array $right): int {
            $lengthComparison = strlen($right['surface']) <=> strlen($left['surface']);

            return $lengthComparison !== 0
                ? $lengthComparison
                : strcmp($left['surface'], $right['surface']);
        });

        if ($algorithm === 'bpe') {
            if (($config['bpe_encoding'] ?? null) !== 'unicode') {
                throw new InvalidArgumentException('BPE requires bpe_encoding=unicode. Byte-level BPE is not supported.');
            }

            $mergesPath = self::requiredPath($config, 'merges');

            return new self(
                selectedAlgorithm: $algorithm,
                selectedEncoding: 'unicode',
                tokenToId: $tokenToId,
                idToToken: $idToToken,
                specialTokens: $specialTokens,
                specialSurfaces: $specialSurfaces,
                mergeRanks: self::loadMerges($mergesPath, $tokenToId),
                unknownId: $specialTokens['unk']['id'],
            );
        }

        if (array_key_exists('merges', $config)) {
            throw new InvalidArgumentException('WordPiece does not accept a merges file. Omit the merges option.');
        }

        return new self(
            selectedAlgorithm: $algorithm,
            selectedEncoding: null,
            tokenToId: $tokenToId,
            idToToken: $idToToken,
            specialTokens: $specialTokens,
            specialSurfaces: $specialSurfaces,
            mergeRanks: [],
            unknownId: $specialTokens['unk']['id'],
        );
    }

    public function algorithm(): string
    {
        return $this->selectedAlgorithm;
    }

    public function encoding(): ?string
    {
        return $this->selectedEncoding;
    }

    public function specialTokenIds(): array
    {
        return array_map(
            static fn (array $special): int => $special['id'],
            $this->specialTokens,
        );
    }

    public function encode(string $text): array
    {
        self::assertUtf8($text, 'input text');

        return $this->selectedAlgorithm === 'bpe'
            ? $this->encodeBpe($text)
            : $this->encodeWordPiece($text);
    }

    public function encodeBatch(array $texts): array
    {
        if (! array_is_list($texts)) {
            throw new InvalidArgumentException('encodeBatch() expects a list of strings.');
        }

        $encoded = [];

        foreach ($texts as $text) {
            if (! is_string($text)) {
                throw new InvalidArgumentException('encodeBatch() expects a list of strings.');
            }

            $encoded[] = $this->encode($text);
        }

        return $encoded;
    }

    public function decode(array $ids): string
    {
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException('decode() expects a list of integer IDs.');
        }

        return $this->selectedAlgorithm === 'bpe'
            ? $this->decodeBpe($ids)
            : $this->decodeWordPiece($ids);
    }

    /**
     * @return list<int>
     */
    private function encodeBpe(string $text): array
    {
        $encoded = [];

        foreach ($this->splitSpecialTokens($text) as $segment) {
            if ($segment['id'] !== null) {
                $encoded[] = $segment['id'];

                continue;
            }

            foreach ($this->encodeBpeChunk($segment['text']) as $id) {
                $encoded[] = $id;
            }
        }

        return $encoded;
    }

    /**
     * @return list<int>
     */
    private function encodeBpeChunk(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $pieces = [];

        foreach (self::codePoints($text) as $codePoint) {
            $pieces[] = array_key_exists($codePoint, $this->tokenToId) ? $codePoint : null;
        }

        while (true) {
            $bestIndex = null;
            $bestRank = null;

            for ($index = 0, $last = count($pieces) - 1; $index < $last; $index++) {
                $left = $pieces[$index];
                $right = $pieces[$index + 1];

                if ($left === null || $right === null || ! array_key_exists($left, $this->mergeRanks)) {
                    continue;
                }

                if (! array_key_exists($right, $this->mergeRanks[$left])) {
                    continue;
                }

                $rank = $this->mergeRanks[$left][$right];

                if ($bestRank === null || $rank < $bestRank) {
                    $bestIndex = $index;
                    $bestRank = $rank;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $pieces[$bestIndex] .= $pieces[$bestIndex + 1];
            array_splice($pieces, $bestIndex + 1, 1);
        }

        return array_map(
            fn (?string $surface): int => $surface === null ? $this->unknownId : $this->tokenToId[$surface],
            $pieces,
        );
    }

    /**
     * @return list<int>
     */
    private function encodeWordPiece(string $text): array
    {
        $encoded = [];

        foreach ($this->splitSpecialTokens($text) as $segment) {
            if ($segment['id'] !== null) {
                $encoded[] = $segment['id'];

                continue;
            }

            $words = preg_split('/\p{White_Space}+/u', $segment['text'], -1, PREG_SPLIT_NO_EMPTY);

            if ($words === false) {
                throw new InvalidArgumentException('Input text must be valid UTF-8.');
            }

            foreach ($words as $word) {
                foreach ($this->encodeWordPieceWord($word) as $id) {
                    $encoded[] = $id;
                }
            }
        }

        return $encoded;
    }

    /**
     * @return list<int>
     */
    private function encodeWordPieceWord(string $word): array
    {
        $codePoints = self::codePoints($word);
        $encoded = [];
        $offset = 0;

        while ($offset < count($codePoints)) {
            $matchId = null;
            $matchLength = 0;

            for ($length = count($codePoints) - $offset; $length > 0; $length--) {
                $surface = implode('', array_slice($codePoints, $offset, $length));
                $candidate = $offset === 0 ? $surface : '##'.$surface;

                if (array_key_exists($candidate, $this->tokenToId)) {
                    $matchId = $this->tokenToId[$candidate];
                    $matchLength = $length;
                    break;
                }
            }

            if ($matchId === null) {
                return [$this->unknownId];
            }

            $encoded[] = $matchId;
            $offset += $matchLength;
        }

        return $encoded;
    }

    /**
     * @param  list<int>  $ids
     */
    private function decodeBpe(array $ids): string
    {
        $decoded = '';

        foreach ($ids as $id) {
            $decoded .= $this->tokenSurface($id);
        }

        return $decoded;
    }

    /**
     * @param  list<int>  $ids
     */
    private function decodeWordPiece(array $ids): string
    {
        $decoded = '';
        $previousKind = null;
        $specialIds = array_fill_keys(array_column($this->specialTokens, 'id'), true);

        foreach ($ids as $id) {
            $surface = $this->tokenSurface($id);

            if (array_key_exists($id, $specialIds)) {
                if ($decoded !== '' && $previousKind !== 'special') {
                    $decoded .= ' ';
                }

                $decoded .= $surface;
                $previousKind = 'special';

                continue;
            }

            if (str_starts_with($surface, '##')) {
                $decoded .= substr($surface, 2);
                $previousKind = 'word';

                continue;
            }

            if ($decoded !== '') {
                $decoded .= ' ';
            }

            $decoded .= $surface;
            $previousKind = 'word';
        }

        return $decoded;
    }

    /**
     * @return list<array{id: int|null, text: string}>
     */
    private function splitSpecialTokens(string $text): array
    {
        $segments = [];
        $ordinary = '';
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $match = null;

            foreach ($this->specialSurfaces as $special) {
                if (substr_compare($text, $special['surface'], $offset, strlen($special['surface'])) === 0) {
                    $match = $special;
                    break;
                }
            }

            if ($match === null) {
                $ordinary .= $text[$offset];
                $offset++;

                continue;
            }

            if ($ordinary !== '') {
                $segments[] = ['id' => null, 'text' => $ordinary];
                $ordinary = '';
            }

            $segments[] = ['id' => $match['id'], 'text' => ''];
            $offset += strlen($match['surface']);
        }

        if ($ordinary !== '') {
            $segments[] = ['id' => null, 'text' => $ordinary];
        }

        return $segments;
    }

    private function tokenSurface(mixed $id): string
    {
        if (! is_int($id) || ! array_key_exists($id, $this->idToToken)) {
            throw new InvalidArgumentException('decode() received an ID absent from the loaded vocabulary.');
        }

        return $this->idToToken[$id];
    }

    /**
     * @return array{0: array<string, int>, 1: array<int, string>}
     */
    private static function loadVocabulary(string $path): array
    {
        $contents = self::readLocalFile($path, 'vocabulary');

        try {
            $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('The vocabulary must contain valid JSON.', 0, $exception);
        }

        if (! is_object($decoded)) {
            throw new InvalidArgumentException('The vocabulary must be a JSON object mapping token strings to integer IDs.');
        }

        $tokenToId = [];
        $idToToken = [];

        foreach (get_object_vars($decoded) as $token => $id) {
            if ($token === '' || ! is_string($id) && ! is_int($id)) {
                throw new InvalidArgumentException('Vocabulary tokens must map to unique non-negative integer IDs.');
            }

            if (! is_int($id) || $id < 0) {
                throw new InvalidArgumentException("Vocabulary token '{$token}' must map to a non-negative integer ID.");
            }

            self::assertUtf8($token, 'vocabulary token');

            if (array_key_exists($id, $idToToken)) {
                throw new InvalidArgumentException("Vocabulary ID {$id} is assigned to more than one token.");
            }

            $tokenToId[$token] = $id;
            $idToToken[$id] = $token;
        }

        return [$tokenToId, $idToToken];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, int>  $tokenToId
     * @param  array<int, string>  $idToToken
     * @return array<string, array{token: string, id: int}>
     */
    private static function loadSpecialTokens(array $config, array $tokenToId, array $idToToken): array
    {
        $specialTokens = $config['special_tokens'] ?? null;

        if (! is_array($specialTokens) || ! array_key_exists('unk', $specialTokens)) {
            throw new InvalidArgumentException('special_tokens must include a configured unk token.');
        }

        $loaded = [];

        foreach ($specialTokens as $role => $special) {
            if (! in_array($role, ['unk', 'pad', 'cls', 'sep'], true)) {
                throw new InvalidArgumentException("Unsupported special-token role '{$role}'.");
            }

            if (! is_array($special) || ! is_string($special['token'] ?? null) || ! is_int($special['id'] ?? null)) {
                throw new InvalidArgumentException("Special token '{$role}' must declare a token string and integer ID.");
            }

            $token = $special['token'];
            $id = $special['id'];

            if (! array_key_exists($token, $tokenToId) || $tokenToId[$token] !== $id || ! array_key_exists($id, $idToToken) || $idToToken[$id] !== $token) {
                throw new InvalidArgumentException("Special token '{$role}' does not match its vocabulary token and ID.");
            }

            $loaded[$role] = ['token' => $token, 'id' => $id];
        }

        return $loaded;
    }

    /**
     * @param  array<string, int>  $tokenToId
     * @return array<string, array<string, int>>
     */
    private static function loadMerges(string $path, array $tokenToId): array
    {
        $contents = self::readLocalFile($path, 'merges');
        $ranks = [];
        $rank = 0;

        foreach (explode("\n", $contents) as $line) {
            $line = rtrim($line, "\r");
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException('Each merge must contain one non-empty left and right token separated by a tab.');
            }

            [$left, $right] = $parts;
            self::assertUtf8($left, 'merge token');
            self::assertUtf8($right, 'merge token');

            if (! array_key_exists($left, $tokenToId) || ! array_key_exists($right, $tokenToId)) {
                throw new InvalidArgumentException('Every merge input token must exist in the vocabulary.');
            }

            $merged = $left.$right;

            if (! array_key_exists($merged, $tokenToId)) {
                throw new InvalidArgumentException("Merge '{$left}' + '{$right}' produces a surface absent from the vocabulary.");
            }

            if (array_key_exists($left, $ranks) && array_key_exists($right, $ranks[$left])) {
                throw new InvalidArgumentException("Merge '{$left}' + '{$right}' is declared more than once.");
            }

            $ranks[$left] ??= [];
            $ranks[$left][$right] = $rank;
            $rank++;
        }

        return $ranks;
    }

    private static function requiredString(array $config, string $key): string
    {
        if (! is_string($config[$key] ?? null) || $config[$key] === '') {
            throw new InvalidArgumentException("Tokenizer configuration requires a non-empty '{$key}' string.");
        }

        return $config[$key];
    }

    private static function requiredPath(array $config, string $key): string
    {
        $path = self::requiredString($config, $key);

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw new InvalidArgumentException("Tokenizer {$key} must be a local filesystem path, not a URL.");
        }

        return $path;
    }

    private static function readLocalFile(string $path, string $description): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("The local {$description} file '{$path}' is missing or unreadable.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException("The local {$description} file '{$path}' could not be read.");
        }

        return $contents;
    }

    private static function assertUtf8(string $value, string $description): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("The {$description} must be valid UTF-8.");
        }
    }

    /**
     * @return list<string>
     */
    private static function codePoints(string $value): array
    {
        $codePoints = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($codePoints === false) {
            throw new InvalidArgumentException('Input text must be valid UTF-8.');
        }

        return $codePoints;
    }
}
