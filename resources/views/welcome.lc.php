@extends('layout')
@section('content')
<div class="card">
  <div class="card-header">
    <h1 class="card-title">It works.</h1>
  </div>
  <div class="card-body">
    <p class="card-text">
      This page is rendered by LombokClarion's ViewEngine using LombokCSS's
      own component vocabulary, themed by <code>data-style="{{ $theme->style }}"</code>
      — a value that comes from the typed config (<code>THEME_STYLE</code> env var),
      not from anything hardcoded in a layout.
    </p>
    <a class="btn btn-primary" href="/widgets">View widgets</a>
  </div>
</div>
@endsection
