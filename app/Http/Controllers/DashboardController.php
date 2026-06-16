<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController
{
    private const SLA_NORMS = [
        'Photography'        => 24,
        'Floorplanner'       => 24,
        'Measurement'        => 24,
        'CAD Report'         => 54,
        'Video'              => 24,
        'Social media video' => 24,
        'Social Media Reel'  => 24,
        '360 tour'           => 24,
        'Drone photography'  => 54,
        'Matterport'         => 24,
        'KuulaTour'          => 24,
        'Drone video'        => 24,
        'Artist impression'  => 72,
        'Evening impression' => 24,
        'WOFL'               => 24,
    ];

    // Product group → short key used in order detail tab
    private const KEY_MAP = [
        'Photography'        => 'photo',
        'Floorplanner'       => 'floor',
        'Measurement'        => 'meas',
        'CAD Report'         => 'cad',
        'Video'              => 'vid',
        'Social media video' => 'soc',
        'Social Media Reel'  => 'reel',
        '360 tour'           => 'tour',
        'Drone photography'  => 'drone',
        'Matterport'         => 'mat',
        'KuulaTour'          => 'kuula',
        'Drone video'        => 'dvid',
        'Artist impression'  => 'art',
        'Evening impression' => 'eve',
        'WOFL'               => 'wofl',
        'Spotlight'          => 'spot',
    ];

    // ── MTD endpoint ──────────────────────────────────────────────────────────
    public function mtd(Request $request)
    {
        $this->setDatabase();
        $country  = $request->get('country', 'ALL');
        $dateFrom = now()->startOfMonth()->toDateTimeString();
        $dateTo   = now()->toDateTimeString();

        $cacheKey = 'dashboard_v7_mtd_' . md5($country . date('Y-m-d-H'));

        return Cache::remember($cacheKey, 300, function () use ($dateFrom, $dateTo, $country) {

            $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];

            // 1. Fetch all rows — multiple rows per production (one per delivery)
            $allRows = collect(array_merge(
                DB::connection('live')->select($this->queryWithAppt(),    $params),
                DB::connection('live')->select($this->queryWithoutAppt(), $params)
            ));

            // 2. Count deliveries per production — this drives FTR
            $deliveryCounts = $allRows
                ->groupBy('production_id')
                ->map(fn($rows) => $rows->count());

            // 3. Deduplicate: keep FIRST delivery per production
            //    First delivery upload_timestamp = when photographer first uploaded
            $productions = $allRows
                ->sortBy('delivery_id')
                ->groupBy('production_id')
                ->map(function ($rows) use ($deliveryCounts) {
                    $row = $rows->first();

                    $deliveryCount = $deliveryCounts[$row->production_id] ?? 1;
                    $ftr  = $deliveryCount === 1;
                    $ltOk = $row->upload_timestamp && $row->expected_delivery_date
                        ? $row->upload_timestamp <= $row->expected_delivery_date
                        : false;
                    $slaOk = $ftr && $ltOk;

                    $breachType = null;
                    if (!$slaOk) {
                        if (!$ltOk && !$ftr) $breachType = 'both';
                        elseif (!$ltOk)      $breachType = 'lt';
                        else                 $breachType = 'ftr';
                    }

                    $row->delivery_count = $deliveryCount;
                    $row->ftr            = $ftr;
                    $row->lt_ok          = $ltOk;
                    $row->sla_ok         = $slaOk;
                    $row->breach_type    = $breachType;
                    $row->day            = Carbon::parse($row->order_created)->toDateString();
                    $row->week           = 'W' . Carbon::parse($row->order_created)->isoWeek();
                    // ⚠️ country: adjust field name to match your schema
                    $row->country        = $row->country ?? '?';

                    return $row;
                })
                ->values();

            // 4. Apply country filter
            if ($country !== 'ALL') {
                $productions = $productions->where('country', $country);
            }

            // 5. Build order-level collection
            //    Order is on target only if ALL its productions are on target
            $orderMap = $productions
                ->groupBy('order_id')
                ->map(fn($prods) => (object)[
                    'order_id'  => $prods->first()->order_id,
                    'on_target' => $prods->every(fn($p) => $p->sla_ok),
                    'day'       => $prods->first()->day,
                    'week'      => $prods->first()->week,
                    'country'   => $prods->first()->country,
                ])
                ->values();

            return response()->json([
                'daily'     => $this->aggregateDaily($productions, $orderMap),
                'weekly'    => $this->aggregateWeekly($productions, $orderMap),
                'products'  => $this->aggregateProducts($productions, $orderMap),
                'orders'    => $this->aggregateOrders($productions, $orderMap),
                'breach'    => $this->breachList($productions),
                'returns'   => [], // add when return_appointment included in queries
                'revisions' => $this->revisionsList($productions),
            ]);
        });
    }

    // ── Aggregate: daily ──────────────────────────────────────────────────────
    private function aggregateDaily($prods, $orders): array
    {
        $ordersByDay = $orders->groupBy('day');

        return $prods->groupBy('day')->map(function ($p, $day) use ($ordersByDay) {
            $ords = $ordersByDay->get($day, collect());
            return [
                'd'         => $day,
                'ordTot'    => $ords->count(),
                'ordDel'    => $ords->count(),
                'ordOn'     => $ords->where('on_target', true)->count(),
                'returns'   => 0,
                'revisions' => $p->where('delivery_count', '>', 1)->count(),
                'prodTot'   => $p->count(),
                'prodDel'   => $p->count(),
                'prodOn'    => $p->where('sla_ok', true)->count(),
                'lt'        => $p->where('breach_type', 'lt')->count(),
                'ftr'       => $p->where('breach_type', 'ftr')->count(),
                'both'      => $p->where('breach_type', 'both')->count(),
            ];
        })->values()->sortByDesc('d')->values()->toArray();
    }

    // ── Aggregate: weekly ────────────────────────────────────────────────────
    private function aggregateWeekly($prods, $orders): array
    {
        $ordersByWeek = $orders->groupBy('week');

        return $prods->groupBy('week')->map(function ($p, $week) use ($ordersByWeek, $prods) {
            $ords  = $ordersByWeek->get($week, collect());
            $first = Carbon::parse($p->first()->order_created ?? now());
            $mon   = $first->copy()->startOfWeek()->format('d M');
            $sun   = $first->copy()->endOfWeek()->format('d M');

            return [
                'week'      => $week,
                'label'     => "{$week} · {$mon} – {$sun}",
                'ordTot'    => $ords->count(),
                'ordDel'    => $ords->count(),
                'ordOn'     => $ords->where('on_target', true)->count(),
                'returns'   => 0,
                'revisions' => $p->where('delivery_count', '>', 1)->count(),
                'prodTot'   => $p->count(),
                'prodDel'   => $p->count(),
                'prodOn'    => $p->where('sla_ok', true)->count(),
                'lt'        => $p->where('breach_type', 'lt')->count(),
                'ftr'       => $p->where('breach_type', 'ftr')->count(),
                'both'      => $p->where('breach_type', 'both')->count(),
            ];
        })->values()->sortBy('week')->values()->toArray();
    }

    // ── Aggregate: per product group ─────────────────────────────────────────
    private function aggregateProducts($prods, $orders): array
    {
        return $prods->groupBy('product_group')->map(function ($p, $group) use ($orders) {
            $orderIds    = $p->pluck('order_id')->unique();
            $touchedOrds = $orders->whereIn('order_id', $orderIds);

            return [
                'key'       => $group,
                'ordTot'    => $touchedOrds->count(),
                'ordDel'    => $touchedOrds->count(),
                'ordOn'     => $touchedOrds->where('on_target', true)->count(),
                'returns'   => 0,
                'revisions' => $p->where('delivery_count', '>', 1)->count(),
                'prodTot'   => $p->count(),
                'prodDel'   => $p->count(),
                'prodOn'    => $p->where('sla_ok', true)->count(),
                'lt'        => $p->where('breach_type', 'lt')->count(),
                'ftr'       => $p->where('breach_type', 'ftr')->count(),
                'both'      => $p->where('breach_type', 'both')->count(),
            ];
        })->values()->toArray();
    }

    // ── Aggregate: orders (for Order detail tab) ─────────────────────────────
    private function aggregateOrders($prods, $orders): array
    {
        return $prods->groupBy('order_id')->map(function ($p, $orderId) use ($orders) {
            $ord    = $orders->firstWhere('order_id', $orderId);
            $result = [
                'id'        => (string) $orderId,
                'country'   => $p->first()->country,
                'on_target' => $ord ? $ord->on_target : false,
            ];
            foreach ($p as $prod) {
                $key = self::KEY_MAP[$prod->product_group] ?? null;
                if ($key) {
                    $status = $prod->sla_ok ? 'ok'
                        : ($prod->breach_type === 'lt'  ? 'lt'
                            : ($prod->breach_type === 'ftr' ? 'ftr' : 'both'));
                    $result[$key] = [
                        'id'     => (string) $prod->production_id,
                        'ok'     => $prod->sla_ok,
                        'status' => $status,
                        'del'    => $prod->delivery_count,
                    ];
                }
            }
            return $result;
        })->values()->toArray();
    }

    // ── Breach list (for SLA breach tab) ────────────────────────────────────
    private function breachList($prods): array
    {
        return $prods->where('sla_ok', false)->map(function ($r) {
            $ltHours = $r->upload_timestamp && $r->order_created
                ? Carbon::parse($r->upload_timestamp)
                    ->diffInHours(Carbon::parse($r->order_created))
                : null;

            return [
                'id'         => (string) $r->order_id,
                'country'    => $r->country,
                'prod'       => $r->product_group,
                'created'    => Carbon::parse($r->order_created)->toDateString(),
                'upload'     => $r->upload_timestamp
                    ? Carbon::parse($r->upload_timestamp)->format('Y-m-d H:i') : '—',
                'deadline'   => $r->expected_delivery_date
                    ? Carbon::parse($r->expected_delivery_date)->format('Y-m-d H:i') : '—',
                'lt_display' => $ltHours !== null ? round($ltHours) . 'h' : '—',
                'lt_vs_exp'  => $r->upload_timestamp && $r->expected_delivery_date
                    ? round(Carbon::parse($r->upload_timestamp)
                        ->diffInHours(Carbon::parse($r->expected_delivery_date), false), 1)
                    : null,
                'sla'        => self::SLA_NORMS[$r->product_group] ?? 24,
                'deliveries' => $r->delivery_count,
                'reason'     => $r->breach_type,
                'return_reason' => '',  // populate when return_appointment added
                'rev_notes'  => '',     // populate when revisions.notes added
            ];
        })->values()->toArray();
    }

    // ── Revisions list (for Returns & Revisions tab) ─────────────────────────
    // FTR breach = multiple deliveries = production was re-delivered
    private function revisionsList($prods): array
    {
        return $prods->where('delivery_count', '>', 1)->map(function ($r) {
            $ltHours = $r->upload_timestamp && $r->order_created
                ? Carbon::parse($r->upload_timestamp)
                    ->diffInHours(Carbon::parse($r->order_created))
                : null;

            return [
                'id'          => (string) $r->order_id,
                'prod'        => $r->product_group,
                'deliveries'  => $r->delivery_count,
                'lt_display'  => $ltHours !== null ? round($ltHours) . 'h' : '—',
                'sla_ok'      => $r->sla_ok,
                'breach_type' => $r->breach_type,
            ];
        })->values()->toArray();
    }

    // ── Raw SQL ───────────────────────────────────────────────────────────────
    private function queryWithAppt(): string
    {
        return "
            SELECT
                p.order_id,
                o.created_at                AS order_created,
                p.id                        AS production_id,
                p.product_group,
                p.expected_delivery_date,
                p.appointment_id,
                fh.created_at               AS upload_timestamp,
                d.id                        AS delivery_id,
                d.created_at                AS delivery_at,
                COUNT(r.id)                 AS revisions
            FROM api.productions p
            JOIN api.orders           o   ON o.id  = p.order_id
            JOIN api.appointments     a   ON a.id  = p.appointment_id
            JOIN api.flow_histories   fh  ON fh.processable_id = a.id
            JOIN api.deliveries       d   ON d.production_id   = p.id
            LEFT JOIN api.revisions   r   ON r.delivery_id     = d.id
            WHERE
                p.appointment_id != 0
                AND fh.processable_type LIKE '%appointment'
                AND fh.action_id = 'complete'
                AND fh.status    = 'upload'
                AND p.created_at >= :date_from
                AND p.created_at <  :date_to
                AND (p.status = 'delivered' OR p.status = 'manualUpload')
            GROUP BY
                p.order_id, o.created_at, p.id, p.product_group,
                p.expected_delivery_date, p.appointment_id,
                fh.created_at, d.id, d.created_at
            ORDER BY p.order_id, p.id ASC
        ";
    }

    private function queryWithoutAppt(): string
    {
        return "
            SELECT
                p.order_id,
                o.created_at                AS order_created,
                p.id                        AS production_id,
                p.product_group,
                p.expected_delivery_date,
                p.appointment_id,
                fh.created_at               AS upload_timestamp,
                d.id                        AS delivery_id,
                d.created_at                AS delivery_at,
                COUNT(r.id)                 AS revisions
            FROM api.productions p
            JOIN api.orders           o   ON o.id  = p.order_id
            LEFT JOIN api.appointments    a   ON a.id  = p.appointment_id
            LEFT JOIN api.flow_histories  fh  ON fh.processable_id = p.id
            JOIN api.deliveries       d   ON d.production_id   = p.id
            LEFT JOIN api.revisions   r   ON r.delivery_id     = d.id
            WHERE
                (p.appointment_id = 0 OR p.appointment_id IS NULL)
                AND fh.processable_type LIKE '%production'
                AND fh.action_id = 'complete'
                AND fh.status    = 'waitingOnClient'
                AND p.created_at >= :date_from
                AND p.created_at <  :date_to
                AND (p.status = 'delivered' OR p.status = 'manualUpload')
            GROUP BY
                p.order_id, o.created_at, p.id, p.product_group,
                p.expected_delivery_date, p.appointment_id,
                fh.created_at, d.id, d.created_at
            ORDER BY p.id ASC
        ";
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
