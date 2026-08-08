@extends('errors/base')
@section('content')
<p class="code">{{ $status }}</p>
<h1 class="headline">Something went wrong</h1>
<p class="prose">The request couldn't be completed. If it keeps happening, the status code above is the useful part to report.</p>
<p class="actions"><a href="/">Go home</a></p>
@endsection
