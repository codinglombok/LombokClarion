<?php /*
  Deliberately NOT extending the app layout: that layout needs $theme and
  $assets, so it needs the config and the asset manifest to be working. An
  error page whose prerequisites include "everything else is fine" goes blank
  exactly when it is needed. Styles are inlined for the same reason — a 500
  caused by a broken asset build should still render.

  $details is EMPTY in production: ErrorHandler never passes it when debug is
  false, so the debug block below cannot leak by being mis-templated.
*/ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $status }} — LombokClarion</title>
<style>
  :root { color-scheme: light dark; }
  body {
    font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    margin: 0; min-height: 100vh; display: grid; place-items: center;
    color: #1a1a1a; background: #fafafa;
  }
  main { max-width: 46rem; padding: 2rem; }
  .code { font-size: clamp(3rem, 12vw, 6rem); font-weight: 700; letter-spacing: -.03em; margin: 0 0 .25rem; line-height: 1; }
  .headline { font-size: 1.25rem; font-weight: 600; margin: 0 0 .5rem; }
  .prose { color: #555; margin: 0 0 1.5rem; }
  .actions a { display: inline-block; padding: .5rem 1rem; border-radius: .375rem; background: #1a1a1a; color: #fff; text-decoration: none; font-size: .9375rem; }
  .debug { margin-top: 2.5rem; border-top: 1px solid #ddd; padding-top: 1.5rem; }
  .debug h2 { font-size: .8125rem; text-transform: uppercase; letter-spacing: .08em; color: #777; margin: 0 0 .75rem; }
  .debug dt { font-weight: 600; font-size: .8125rem; margin-top: .875rem; }
  .debug dd { margin: .25rem 0 0; }
  .debug pre { overflow-x: auto; background: #f0f0f0; padding: .75rem; border-radius: .25rem; font-size: .8125rem; margin: 0; white-space: pre-wrap; word-break: break-word; }
  @media (prefers-color-scheme: dark) {
    body { color: #e8e8e8; background: #121212; }
    .prose { color: #a0a0a0; }
    .actions a { background: #e8e8e8; color: #121212; }
    .debug { border-top-color: #333; }
    .debug pre { background: #1e1e1e; }
  }
</style>
</head>
<body>
<main>
@yield('content')

@if (!empty($details))
<div class="debug">
  <h2>Debug</h2>
  <dl>
@foreach ($details as $key => $value)
    <dt>{{ $key }}</dt>
    <dd><pre>{{ $value }}</pre></dd>
@endforeach
  </dl>
</div>
@endif
</main>
</body>
</html>
