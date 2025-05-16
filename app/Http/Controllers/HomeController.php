<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $missing = false;
        $productionMetricsAll = Cache::get('productionMetrics');
        $productionMetricsDate = Cache::get('productionMetrics');

        if ($request->get('date')) {
            $productionMetricsDate = $productionMetricsDate->where('appointmentDate', $request->get('date'));
        }
        if ($request->get('missing')) {
            $missing = true;
            $productionMetricsDate = $productionMetricsDate->where('isCompleted', false);
        }

        $orderStats = [];

        $productionMetricsAll->groupBy('appointmentDate')->each(function ($jobsOfDate) use (&$orderStats) {
            $orderStats[$jobsOfDate->first()->appointmentDate] = ['total' => 0, 'completed' => 0];
            $jobsOfDate->groupBy('orderId')->each(function ($jobsOfOrder) use (&$orderStats) {
                $orderStats[$jobsOfOrder->first()->appointmentDate]['total']++;

                if ($jobsOfOrder->where('isCompleted', false)->count() === 0) {
                    $orderStats[$jobsOfOrder->first()->appointmentDate]['completed']++;
                }
            });
        });

        $products = [
            'Photography',
            'Floorplanner',
            'Measurement report',
            '360 tour',
            'Video',
            'Measurement',
            'CAD Report',
            'Matterport',
        ];
        return view('home', compact(
            'productionMetricsAll',
            'productionMetricsDate',
            'products',
            'missing',
            'orderStats',
        ));
    }
}
