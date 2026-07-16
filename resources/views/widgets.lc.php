@extends('layout')
@section('content')
<div class="card">
  <div class="card-header"><h1 class="card-title">Widgets</h1></div>
  <div class="card-body">
    @if (count($widgets) > 0)
    <div class="table-wrap">
      <table class="table table-striped">
        <thead><tr><th>ID</th><th>Name</th><th>Price</th></tr></thead>
        <tbody>
        @foreach ($widgets as $widget)
        <tr>
          <td><code>{{ $widget->id }}</code></td>
          <td>{{ $widget->name }}</td>
          <td>{{ number_format($widget->priceCents / 100, 2) }}</td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @else
    <div class="alert alert-info">No widgets yet — create the first one below.</div>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-header"><h2 class="card-title">Create widget</h2></div>
  <div class="card-body">
    <form method="post" action="/widgets">
      <input type="hidden" name="_csrf" value="{{ $csrfToken }}">
      <div class="input-group">
        <label for="name">Name</label>
        <input class="input" id="name" name="name" required>
      </div>
      <div class="input-group">
        <label for="price_cents">Price (cents)</label>
        <input class="input" id="price_cents" name="price_cents" type="number" min="0" required>
      </div>
      <button class="btn btn-primary" type="submit">Create</button>
    </form>
  </div>
</div>
@endsection
