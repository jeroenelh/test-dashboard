<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js" integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO" crossorigin="anonymous"></script>
        <title>Laravel</title>

    </head>
    <body>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Orders %</th>
                    <th>Orders</th>
                    @foreach($products as $product)
                        <th style="text-align: right;">{{ $product }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @foreach($productionMetricsAll->groupBy('appointmentDate') as $productionMetricsOfDate)
            <tr style="@if((New \Carbon\Carbon())->subWeek()->format('Y-m-d') === $productionMetricsOfDate->first()->appointmentDate) border-top: 5px solid black; @endif">
                <td>
                    <a style="color: black;" href="?date={{ $productionMetricsOfDate->first()->appointmentDate }}">{{ $productionMetricsOfDate->first()->appointmentDate }}</a>
                    <a style="color: black;" href="?date={{ $productionMetricsOfDate->first()->appointmentDate }}&missing=1">Problems</a>
                </td>
                <td>
                    {{ round($orderStats[$productionMetricsOfDate->first()->appointmentDate]['completed'] / $orderStats[$productionMetricsOfDate->first()->appointmentDate]['total'] * 100,1) }}%
                </td>
                <td>
                    {{ $orderStats[$productionMetricsOfDate->first()->appointmentDate]['completed'] }} / {{ $orderStats[$productionMetricsOfDate->first()->appointmentDate]['total'] }}
                </td>
                <td style="display: none;">
                    {{ round(($productionMetricsOfDate->where('isCompleted', true)->count() / $productionMetricsOfDate->count()) * 100,1) }}%
                </td>
                @foreach($products as $product)
                    @php
                        $total = $productionMetricsOfDate->where('product', $product)->count();
                        $completed = $productionMetricsOfDate->where('product', $product)->where('isCompleted', true)->count();
                        $percentage = $total > 0 ? $completed / $total : 1;
                    @endphp
                    <td style="text-align: right;@if($percentage >= 1) background:green; @elseif($percentage > 0.95) background:lightgreen; @elseif($percentage > 0.7) background:lightsalmon; @else background:lightcoral @endif">
                        {{ $productionMetricsOfDate->where('product', $product)->where('isCompleted', true)->count() }} / {{ $productionMetricsOfDate->where('product', $product)->count() }}
                    </td>
                @endforeach
            </tr>
            @endforeach
            </tbody>
        </table>



        <table class="table table-striped ">
            <thead style="position: sticky; top: 0; ">
            <tr>
                <th>Order ID</th>
                <th>Search</th>
                @foreach($products as $product)
                    <th style="text-align: center;">{{ $product }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach($productionMetricsDate->groupBy('orderId') as $productionMetricsOfOrder)
                <tr>
                    <td>
                        <a href="https://zone.zibber.nl/orders/{{ $productionMetricsOfOrder->first()->orderId }}" target="_blank"  style="color: black;">
                            {{ $productionMetricsOfOrder->first()->orderId }}
                        </a>
                    </td>
                    <td>
                        <a href="https://brre.atlassian.net/issues/?jql=%22relatesToReferenceId%5BShort%20text%5D%22~{{ $productionMetricsOfOrder->first()->orderId }}" target="_blank">JOI</a>
                        <a href="https://stream.bright-river.com/all/find/{{ $productionMetricsOfOrder->first()->orderId }}" target="_blank">STREAM</a>
                    </td>
                    @foreach($products as $product)
                        @php $production = $productionMetricsOfOrder->where('product', $product)->first(); @endphp
                        @if ($production)
                            <td style="{{ ($production && $production->isCompleted) ? 'background:green' : 'background:orange' }}">
                                <a href="https://zone.zibber.nl/production/{{ $production->productionId }}" target="_blank" style="color: black;">
                                    {{ $production->productionId }} - {{ $production->appointmentDate }}
                                </a>
                            </td>
                        @else
                            <td></td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>

        <style>
            .table-fixed tbody {
                height: 300px;
                overflow-y: auto;
                width: 100%;
            }

            .table-fixed thead,
            .table-fixed tbody,
            .table-fixed tr,
            .table-fixed td,
            .table-fixed th {
                display: block;
            }

            .table-fixed tbody td,
            .table-fixed tbody th,
            .table-fixed thead > tr > th {
                float: left;
                position: relative;

                &::after {
                    content: '';
                    clear: both;
                    display: block;
                }
            }
        </style>
    </body>
</html>
