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
        ));
    }
}
