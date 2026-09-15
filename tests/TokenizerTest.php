<?php

use Eloquage\Tokens\Tokenizer;
use Eloquage\Tokens\Tokens;

function bpeConfig(): array
{
    return [
        'algorithm' => 'bpe',
        'vocab' => __DIR__.'/fixtures/bpe-vocab.json',
        'merges' => __DIR__.'/fixtures/bpe-merges.txt',
        'bpe_encoding' => 'unicode',
        'special_tokens' => [
            'unk' => ['token' => '[UNK]', 'id' => 0],
            'pad' => ['token' => '[PAD]', 'id' => 1],
            'cls' => ['token' => '[CLS]', 'id' => 2],
            'sep' => ['token' => '[SEP]', 'id' => 3],
        ],
    ];
}

function wordPieceConfig(): array
{
    return [
        'algorithm' => 'wordpiece',
        'vocab' => __DIR__.'/fixtures/wordpiece-vocab.json',
        'special_tokens' => [
            'unk' => ['token' => '[UNK]', 'id' => 0],
            'pad' => ['token' => '[PAD]', 'id' => 1],
            'cls' => ['token' => '[CLS]', 'id' => 2],
            'sep' => ['token' => '[SEP]', 'id' => 3],
        ],
    ];
}

it('loads both algorithms through the public factory', function () {
    $bpe = Tokens::load(bpeConfig());
    $wordPiece = Tokens::load(wordPieceConfig());

    expect($bpe)
        ->toBeInstanceOf(Tokenizer::class)
        ->and($bpe->algorithm())->toBe('bpe')
        ->and($bpe->encoding())->toBe('unicode')
        ->and($wordPiece->algorithm())->toBe('wordpiece')
        ->and($wordPiece->encoding())->toBeNull();
});

it('keeps the existing package identity API', function () {
    expect((new Tokens)->name())->toBe('tokens');
});

it('applies ordered Unicode BPE merges and preserves whitespace', function () {
    $tokenizer = Tokens::load(bpeConfig());

    expect($tokenizer->encode('abc abc'))->toBe([9, 7, 9])
        ->and($tokenizer->decode([9, 7, 9]))->toBe('abc abc')
        ->and($tokenizer->encode('é'))->toBe([10])
        ->and($tokenizer->decode($tokenizer->encode('é')))->toBe('é');
});

it('maps unknown BPE code points to the configured unknown token', function () {
    $tokenizer = Tokens::load(bpeConfig());

    expect($tokenizer->encode('a🙂c'))->toBe([4, 0, 6])
        ->and($tokenizer->decode([4, 0, 6]))->toBe('a[UNK]c');
});

it('greedily selects the longest WordPiece matches', function () {
    $tokenizer = Tokens::load(wordPieceConfig());

    expect($tokenizer->encode('tokenizers playing'))->toBe([10, 7, 4, 5])
        ->and($tokenizer->decode([10, 7, 4, 5]))->toBe('tokenizers playing')
        ->and($tokenizer->encode('hello world'))->toBe([11, 12]);
});

it('uses one unknown token for an unsegmentable WordPiece word', function () {
    $tokenizer = Tokens::load(wordPieceConfig());

    expect($tokenizer->encode('tokenizers nope'))->toBe([10, 7, 0]);
});

it('recognizes configured special tokens atomically without inserting them', function () {
    $bpe = Tokens::load(bpeConfig());
    $wordPiece = Tokens::load(wordPieceConfig());

    expect($bpe->specialTokenIds())->toBe(['unk' => 0, 'pad' => 1, 'cls' => 2, 'sep' => 3])
        ->and($bpe->encode(''))->toBe([])
        ->and($bpe->encode('[CLS]abc[SEP]'))->toBe([2, 9, 3])
        ->and($wordPiece->encode('[CLS] hello [SEP]'))->toBe([2, 11, 3])
        ->and($wordPiece->decode([2, 11, 3]))->toBe('[CLS] hello [SEP]')
        ->and($bpe->decode([]))->toBe('');
});

it('delegates batch encoding in order without padding', function () {
    $tokenizer = Tokens::load(wordPieceConfig());
    $batch = ['', 'hello world', 'nope'];

    expect($tokenizer->encodeBatch($batch))->toBe([
        [],
        $tokenizer->encode('hello world'),
        $tokenizer->encode('nope'),
    ])
        ->and($tokenizer->encodeBatch([]))->toBe([]);
});

it('rejects invalid local configuration', function (array $config, string $message) {
    expect(fn () => Tokens::load($config))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing vocabulary' => [array_merge(bpeConfig(), ['vocab' => '/tmp/eloquage-tokens-missing.json']), 'missing or unreadable'],
    'remote vocabulary' => [array_merge(bpeConfig(), ['vocab' => 'https://example.test/vocab.json']), 'local filesystem path'],
    'byte BPE' => [array_merge(bpeConfig(), ['bpe_encoding' => 'bytes']), 'Byte-level BPE'],
    'missing BPE encoding' => [array_diff_key(bpeConfig(), ['bpe_encoding' => true]), 'bpe_encoding=unicode'],
    'WordPiece merges' => [array_merge(wordPieceConfig(), ['merges' => __DIR__.'/fixtures/bpe-merges.txt']), 'does not accept a merges'],
    'missing unknown' => [array_merge(bpeConfig(), ['special_tokens' => ['pad' => ['token' => '[PAD]', 'id' => 1]]]), 'configured unk'],
    'wrong special ID' => [array_merge(bpeConfig(), ['special_tokens' => ['unk' => ['token' => '[UNK]', 'id' => 99]]]), 'does not match'],
]);

it('rejects malformed vocabulary and merge files', function (string $kind) {
    $directory = sys_get_temp_dir().'/eloquage-tokens-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $vocab = $directory.'/vocab.json';
        $merges = $directory.'/merges.txt';
        file_put_contents($vocab, $kind === 'json' ? '{' : '{"[UNK]": 0, "x": 0}');
        file_put_contents($merges, $kind === 'merge' ? "a\tb\n" : "a\tc\n");

        $config = bpeConfig();
        $config['vocab'] = $vocab;
        $config['merges'] = $merges;

        expect(fn () => Tokens::load($config))->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($vocab);
        @unlink($merges);
        @rmdir($directory);
    }
})->with(['json', 'merge']);

it('rejects malformed merge lines and unknown decoded IDs', function () {
    $directory = sys_get_temp_dir().'/eloquage-tokens-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $merges = $directory.'/merges.txt';
    file_put_contents($merges, "a b\n");

    try {
        $config = bpeConfig();
        $config['merges'] = $merges;

        expect(fn () => Tokens::load($config))->toThrow(InvalidArgumentException::class, 'separated by a tab');
    } finally {
        @unlink($merges);
        @rmdir($directory);
    }

    expect(fn () => Tokens::load(bpeConfig())->decode([999]))
        ->toThrow(InvalidArgumentException::class, 'absent from the loaded vocabulary');
});

it('rejects invalid batch and decode shapes', function () {
    $tokenizer = Tokens::load(bpeConfig());

    expect(fn () => $tokenizer->encodeBatch(['abc', 1]))
        ->toThrow(InvalidArgumentException::class, 'list of strings')
        ->and(fn () => $tokenizer->decode([9 => 9]))
        ->toThrow(InvalidArgumentException::class, 'list of integer IDs')
        ->and(fn () => $tokenizer->decode([1.0]))
        ->toThrow(InvalidArgumentException::class, 'absent from the loaded vocabulary');
});
