# lombokclarion/view

**Blade-like template engine with auto-escaping, themes, content-hashed assets.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/view
```

## Namespace

```php
LombokClarion\View
```

## What's Inside

| Class | Role |
|-------|------|
| `ViewEngine` | Template rendering: `render($template, $data)` |
| `ViewCompiler` | Compiles Blade-like syntax → PHP (cached to disk) |
| `Theme` | Resolves `data-style` attribute from `THEME_STYLE` env |
| `AssetManifest` | Maps logical names → content-hashed filenames |
| `AssetPublisher` | Copies + content-hashes assets to `public/assets/` |
| `StaticAssetsMiddleware` | Serves static assets with `Cache-Control: immutable` |
| `Safe` | Marks strings as pre-escaped (skips auto-escape) |
| `ViewErrorPageRenderer` | Error pages rendered through the view engine |

## Template Syntax (`.lc.php` files)

```blade
@extends('layout')

@section('content')
    <h1>{{ $title }}</h1>          {{-- auto-escaped --}}
    {!! $trustedHtml !!}           {{-- raw output --}}

    @if($widgets)
        @foreach($widgets as $w)
            <p>{{ $w->name }}</p>
        @endforeach
    @else
        <p>No widgets.</p>
    @endif

    @include('partials.sidebar')
@endsection
```

### Themes

4 presets: `resonant-stark` (default), `neo-brutalism`, `glassmorphism`, `quiet-editorial`.

```php
// Set via env: THEME_STYLE=neo-brutalism
$theme = new Theme(getenv('THEME_STYLE') ?: 'resonant-stark');
// In layout: <html data-style="{{ $theme->style() }}">
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
