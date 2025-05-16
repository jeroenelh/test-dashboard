<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\ProductionMetric;
use App\Models\Production;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class TestAppointments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-appointments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->setDatabase();

//        $translations = [
//            'Floorplanner met 3D Laserscannen' => 'Floorplanner',
//            'Floorplanner with 3D Laserscanning' => 'Floorplanner',
//
//            'Photography' => 'Vastgoedfotografie',
//            'Vastgoedfotografie met Mast' => 'Vastgoedfotografie',
//            'Vastgoedfotografie met Drone' => 'Vastgoedfotografie',
//
//            'Video met Drone' => 'Video',
//            'Social Media Video' => 'Video',
//
//            'Measurement report' => 'Meetrapport',
//
//            'Artist Impressions (2x)' => 'Artist Impressions',
//            'Artist Impressions (3x)' => 'Artist Impressions',
//
//            '360-virtual tour' => '360 tour',
//        ];

        $appointments = Appointment::query()
            ->where('scheduled_at', '>=', Carbon::parse('2025-05-01 00:00:00'))
            ->isCompleted()
            ->orderBy('scheduled_at', 'desc')
            ->with(['productions', 'productions.deliveries'])
            ->get()
        ;

        /** @var Collection<array-key, ProductionMetric> $productionMetrics */
        $productionMetrics = new Collection();

        $this->output->progressStart($appointments->count());

        $appointments->each(function (Appointment $appointment) use ($productionMetrics) {
            $appointment->productions->each(function (Production $production) use ($productionMetrics) {

//                $productName = $production->product_group;
//                if (isset($translations[$productName])) {
//                    $productName = $translations[$productName];
//                }
                $deliveryDate = null;
                if ($production->deliveries->max('created_at')) {
                    $deliveryDate = $production->deliveries->max('created_at')->format('Y-m-d');
                }

                if ($production->status === 'cancelled' || $production->status === 'needsReturnAppointment') {
                    return;
                }

                $productionMetrics->push(new ProductionMetric(
                    orderId: $production->order_id,
                    productionId: $production->id,
                    product: $production->product_group,
                    appointmentDate: $production->appointment->scheduled_at->format('Y-m-d'),
                    deliveryDate: $deliveryDate,
                    isCompleted: $production->deliveries->count() > 0,
                ));

            });
            $this->output->progressAdvance();

        });
        $this->output->progressFinish();

        Cache::forget('productionMetrics');
        Cache::rememberForever('productionMetrics', fn () => $productionMetrics);

        $this->output->text('Amount of metrics: '.$productionMetrics->count());
    }

    private function setDatabase(): void
    {
        Config::set("database.connections.live", [
            "driver" => "mysql",
            "host" => env('DB_LIVE_HOST'),
            "port" => env('DB_LIVE_PORT'),
            "database"=> env('DB_LIVE_DATABASE'),
            "username" => env('DB_LIVE_USERNAME'),
            "password" => env('DB_LIVE_PASSWORD'),
        ]);
    }
}
