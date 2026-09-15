# eloquage/tokens

Fast tokenizers for PHP: BPE/WordPiece-style encode/decode, vocab loading, and batch tokenization.

- Composer: `eloquage/tokens`
- Entrypoint: `Eloquage\Tokens\Tokens`
- This package is framework-agnostic. The Laravel app at the monorepo root is a local test bench only.

## Layout

- `src/` — public PHP API (source of truth)
- `native/` — optional TypePHP AOT sources (empty today)
- `tests/` — Pest 5 and small checked-in tokenizer fixtures only
- `TYPEPHP.md` — extension build contract
- `project.yml.example` — TypePHP project config (copy to gitignored `project.yml`)

## Commands

```bash
composer test
composer format
vendor/bin/pest --coverage --min=90
```

## Conventions

- No Illuminate / Laravel service providers.
- Always ship a pure-PHP fallback. Never `require` `swoole/typephp`.
- Keep the public seam at `Tokens::load(array $config): Tokenizer`; the Laravel
  harness may adapt it for its welcome panel but must not own tokenizer logic.
- Consumers: PHP 8.3+. Package CI: PHP 8.4. TypePHP compile: PHP 8.5 syntax.

## TypePHP

Extension mode only (`mode: ext`). Build in Docker, not on the host:

```bash
# harness (default: ghcr.io/eloquage/typephp-builder)
docker/typephp/build-package.sh tokens

# this repo
docker run --rm -v "$PWD":/src -w /src \
  "${ELOQUAGE_TYPEPHP_IMAGE:-ghcr.io/eloquage/typephp-builder:latest}" \
  sh -c 'test -f project.yml || cp project.yml.example project.yml; tpc.php project.yml'
```

See `TYPEPHP.md`. Linux containers only for the shared builder. Optional native install channels: setup-php, docker-php-ext-install, PECL, Windows DLL.

## Harness demo

Public behavior must be exercisable from the laravel-x welcome page (`/` → `resources/views/welcome.blade.php`) with a Feature test.

The package tests must remain framework-agnostic and use the checked-in local
fixtures. They must not download model assets, call a tokenizer subprocess, or
require a native extension.

## Humans vs agents

- README — install/usage for humans
- This file — agent context
- TYPEPHP.md — AOT / Docker / release

Native compilation is skipped for the current tokenizer implementation. Keep
the `mode: ext` Docker contract and unchanged public API; do not add native
source or build artifacts unless a later change explicitly scopes that work.
