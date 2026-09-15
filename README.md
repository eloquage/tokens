# Fast tokenizers for PHP: Unicode BPE and WordPiece-style encode/decode, local vocabulary loading, and batch tokenization.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/eloquage/tokens.svg?style=flat-square)](https://packagist.org/packages/eloquage/tokens)
[![Tests](https://github.com/eloquage/tokens/actions/workflows/run-tests.yml/badge.svg)](https://github.com/eloquage/tokens/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/eloquage/tokens.svg?style=flat-square)](https://packagist.org/packages/eloquage/tokens)

## Installation

Install via Composer (pure PHP; always works without a native extension):

```bash
composer require eloquage/tokens
```

### Optional native acceleration

When a release includes a TypePHP-built extension (`eloquage_tokens`), you can load it for faster paths. The public PHP API is unchanged.

#### shivammathur/setup-php (GitHub Actions)

Once the extension is on PECL:

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.4'
    extensions: eloquage_tokens
```

Until then, install from a GitHub Release phpize/PECL tarball or from source (see [setup-php wiki: Add extension from source](https://github.com/shivammathur/setup-php/wiki/Add-extension-from-source)).

#### docker-php-ext-install

Extract the Release phpize tree to an absolute path, then:

```dockerfile
RUN docker-php-ext-configure /tmp/eloquage_tokens \
 && docker-php-ext-install /tmp/eloquage_tokens \
 && docker-php-ext-enable eloquage_tokens
```

#### PECL

```bash
# from a GitHub Release asset URL (canonical until pecl.php.net listing exists)
pecl install https://github.com/eloquage/tokens/releases/download/vX.Y.Z/eloquage_tokens-X.Y.Z.tgz
# after channel registration:
# pecl install eloquage_tokens
```

#### Windows

Download the Release `eloquage_tokens.dll`, place it in your PHP extension directory, and enable:

```ini
extension=eloquage_tokens
```

On `windows-latest` with setup-php, the same PECL/DLL path applies once a Windows binary is published.

See [TYPEPHP.md](TYPEPHP.md) for building the extension yourself with the shared builder image.

## Usage

Load a local vocabulary and tokenizer configuration with the framework-agnostic
factory:

```php
use Eloquage\Tokens\Tokens;

$tokenizer = Tokens::load([
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
]);

$ids = $tokenizer->encode('abc abc');
$text = $tokenizer->decode($ids);

// $ids is an array of integer IDs; $text is "abc abc".
```

`vocab` is a local JSON object mapping token surfaces to unique, non-negative
integer IDs. BPE also requires a local tab-delimited merge file and
`bpe_encoding: unicode`. Remote paths and byte-level BPE are rejected.

WordPiece uses Unicode whitespace-delimited words and does not use a merge file:

```php
$tokenizer = Tokens::load([
    'algorithm' => 'wordpiece',
    'vocab' => __DIR__.'/fixtures/wordpiece-vocab.json',
    'special_tokens' => [
        'unk' => ['token' => '[UNK]', 'id' => 0],
    ],
]);

$ids = $tokenizer->encode('tokenizers playing');
$batch = $tokenizer->encodeBatch(['hello world', 'tokenizers']);
```

BPE applies ordered Unicode code-point merges. WordPiece chooses the longest
valid first piece and `##` continuation pieces; if a word cannot be fully
segmented, it becomes one UNK token. The required `unk` token and optional
`pad`, `cls`, and `sep` tokens are configured explicitly and are never
automatically inserted, padded, or masked.

The package provides pure-PHP behavior and does not require Laravel, a shell
tokenizer, network access, or a native extension. TypePHP acceleration is an
optional future path. This package does not promise tiktoken compatibility or
Hugging Face parity without a proving fixture.

## Testing

```bash
composer test
vendor/bin/pest --coverage --min=90
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Pull requests and issues are welcome on [GitHub](https://github.com/eloquage/tokens).

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Miguel Enes](https://github.com/eloquage)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Development

See [AGENTS.md](AGENTS.md) for agent context, tests, and TypePHP Docker builds.
