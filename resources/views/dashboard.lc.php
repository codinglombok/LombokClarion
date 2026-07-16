@extends('layout')
@section('content')
<div class="card">
  <div class="card-header"><h1 class="card-title">Dashboard</h1></div>
  <div class="card-body">
    @if (count($chartData) > 0)
    <div class="grid grid-2">
      <div class="card">
        <div class="card-header"><h2 class="card-title">Widget prices</h2></div>
        <div class="card-body"><div id="chart-prices" style="height:280px"></div></div>
      </div>
      <div class="card">
        <div class="card-header"><h2 class="card-title">Price share</h2></div>
        <div class="card-body"><div id="chart-share" style="height:280px"></div></div>
      </div>
    </div>
    @else
    <div class="alert alert-info">No widgets yet — create some on the <a href="/widgets">Widgets</a> page to see charts.</div>
    @endif
  </div>
</div>

<script src="{{ $assets->url('lombok-charts.umd.min.js') }}"></script>
<script>
  const data = {!! \LombokClarion\View\Safe::mark($chartJson) !!};
  if (data.length > 0) {
    LombokCharts.chart('#chart-prices', { data: data, mark: 'bar',  title: 'Price (cents) per widget' });
    LombokCharts.chart('#chart-share',  { data: data, mark: 'arc',  title: 'Share of total price' });
  }
</script>
@endsection
