<!doctype html>
<html lang="en" data-style="{{ $theme->style }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — LombokClarion</title>
<link rel="stylesheet" href="{{ $assets->url('lombok.min.css') }}">
<link rel="stylesheet" href="{{ $assets->url('quiet-editorial.css') }}">
</head>
<body>
<nav class="navbar">
  <a class="navbar-brand" href="/">LombokClarion</a>
  <ul class="navbar-nav">
    <li><a href="/">Home</a></li>
    <li><a href="/widgets">Widgets</a></li>
    <li><a href="/dashboard">Dashboard</a></li>
  </ul>
</nav>
<main class="container">
@yield('content')
</main>
</body>
</html>
