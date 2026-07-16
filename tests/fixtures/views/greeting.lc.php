<p>Hello, {{ $name }}!</p>
@if (count($items) > 0)
<ul>
@foreach ($items as $item)
<li>{{ $item }}</li>
@endforeach
</ul>
@else
<p>No items.</p>
@endif
<div>{!! $rawHtml !!}</div>
