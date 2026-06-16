<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController
{
    public function index(Request $request)
    {
        $dateFrom = $request->get('from', now()->startOfYear()->toDateTimeString());
        $dateTo   = $request->get('to',   now()->toDateTimeString());
        $country  = $request->get('country', 'ALL'); // NL / BE / DE / ALL

        $rows = $this->fetchProductions($dateFrom, $dateTo, $country);

        return response()->json([
            'months'   => $this->aggregateMonths($rows),
            'daily'    => $this->aggregateDaily($rows),
            'weeks'    => $this->aggregateWeeks($rows),
            'products' => $this->aggregateProducts($rows),
            'orders'   => $this->aggregateOrders($rows),
            'breach'   => $this->breachList($rows),
        ]);
    }

    public function mtd(Request $request)
    {
        $country  = $request->get('country', 'ALL');

        // MTD window: first day of current month to now
        $dateFrom = now()->subDays(31)->toDateTimeString();
        $dateTo   = now()->toDateTimeString();

        $rows = $this->fetchProductions($dateFrom, $dateTo, $country);

        return response()->json([
            'daily'    => $this->aggregateDaily($rows),
            'products' => $this->aggregateProducts($rows),
            'orders'   => $this->aggregateOrders($rows),
            'breach'   => $this->breachList($rows),
        ]);
    }


    // ── Fetch & merge both queries ────────────────────────────────────────────
    private function fetchProductions(string $from, string $to, string $country): \Illuminate\Support\Collection
    {
        $params = ['date_from' => $from, 'date_to' => $to];

        $withAppt    = DB::select($this->queryWith(), $params);
        $withoutAppt = DB::select($this->queryWithout(), $params);

        $rows = collect(array_merge($withAppt, $withoutAppt))->map(function ($r) {
            $ftr  = $r->revisions == 0;
            $ltOk = $r->upload_timestamp <= $r->expected_delivery_date;
            $sla  = $ftr && $ltOk;

            $breachType = null;
            if (!$ltOk && !$ftr) $breachType = 'both';
            elseif (!$ltOk)      $breachType = 'lt';
            elseif (!$ftr)       $breachType = 'ftr';

            $r->ftr         = $ftr;
            $r->lt_ok       = $ltOk;
            $r->sla_ok      = $sla;
            $r->breach_type = $breachType;
            $r->month       = Carbon::parse($r->order_created)->format('M');
            $r->week        = 'W'.Carbon::parse($r->order_created)->isoWeek();
            $r->day         = Carbon::parse($r->order_created)->toDateString();
            return $r;
        });

        // Country filter: assumes orders table has a country column
        // Adjust field name to match your schema
        if ($country !== 'ALL') {
            $rows = $rows->where('country', $country);
        }

        return $rows;
    }

    // ── Aggregate: orders (order is on target only if ALL prods on target) ───
    private function aggregateOrders(\Illuminate\Support\Collection $rows): array
    {
        return $rows->groupBy('order_id')->map(function ($prods, $orderId) {
            $allOk     = $prods->every(fn($p) => $p->sla_ok);
            $anyLt     = $prods->contains(fn($p) => !$p->lt_ok);
            $anyFtr    = $prods->contains(fn($p) => !$p->ftr);
            $first     = $prods->first();
            return [
                'id'           => $orderId,
                'created'      => $first->order_created,
                'day'          => $first->day,
                'week'         => $first->week,
                'month'        => $first->month,
                'on_target'    => $allOk,
                'breach_lt'    => $anyLt && !$anyFtr,
                'breach_ftr'   => $anyFtr && !$anyLt,
                'breach_both'  => $anyLt && $anyFtr,
                'prods'        => $prods->map(fn($p) => [
                    'production_id'  => $p->production_id,
                    'product_group'  => $p->product_group,
                    'sla_ok'         => $p->sla_ok,
                    'lt_ok'          => $p->lt_ok,
                    'ftr'            => $p->ftr,
                    'breach_type'    => $p->breach_type,
                    'delivery'       => $p->delivery,
                    'revisions'      => $p->revisions,
                ])->values(),
            ];
        })->values()->toArray();
    }

    // ── Aggregate: months ─────────────────────────────────────────────────────
    private function aggregateMonths(\Illuminate\Support\Collection $rows): array
    {
        $orders = collect($this->aggregateOrders($rows));
        return $orders->groupBy('month')->map(function ($ords, $month) use ($rows) {
            $prods    = $rows->where('month', $month);
            $ordDel   = $ords->count();
            $ordOn    = $ords->where('on_target', true)->count();
            $prodDel  = $prods->count();
            $prodOn   = $prods->where('sla_ok', true)->count();
            return [
                'm'        => $month,
                'ord'      => $ordDel,
                'ordDel'   => $ordDel,
                'ordOn'    => $ordOn,
                'prod'     => $prodDel,
                'prodDel'  => $prodDel,
                'prodOn'   => $prodOn,
                'lt'       => $prods->where('breach_type', 'lt')->count(),
                'ftr'      => $prods->where('breach_type', 'ftr')->count(),
                'both'     => $prods->where('breach_type', 'both')->count(),
            ];
        })->values()->toArray();
    }

    // ── Aggregate: daily ─────────────────────────────────────────────────────
    private function aggregateDaily(\Illuminate\Support\Collection $rows): array
    {
        $orders = collect($this->aggregateOrders($rows));
        return $orders->groupBy('day')->map(function ($ords, $day) use ($rows) {
            $prods   = $rows->where('day', $day);
            $prodCounts = [];
            foreach (['Photography','Floorplanner','360 tour','Video','Measurement','CAD Report','Matterport','Social media video'] as $pg) {
                $pg_rows = $prods->where('product_group', $pg);
                $prodCounts[strtolower(str_replace([' ','360 '],['_','tour_'], $pg))] = [
                    $pg_rows->where('sla_ok', true)->count(),
                    $pg_rows->count(),
                ];
            }
            return array_merge([
                'd'    => $day,
                'tot'  => $ords->count(),
                'del'  => $ords->count(),
                'on'   => $ords->where('on_target', true)->count(),
            ], $prodCounts);
        })->values()->sortByDesc('d')->values()->toArray();
    }

    // ── Aggregate: weekly ────────────────────────────────────────────────────
    private function aggregateWeeks(\Illuminate\Support\Collection $rows): array
    {
        $orders = collect($this->aggregateOrders($rows));
        return $orders->groupBy('week')->map(function ($ords, $week) use ($rows) {
            $prods   = $rows->where('week', $week);
            return [
                'm'       => $week,
                'ord'     => $ords->count(),
                'ordDel'  => $ords->count(),
                'ordOn'   => $ords->where('on_target', true)->count(),
                'prod'    => $prods->count(),
                'prodDel' => $prods->count(),
                'prodOn'  => $prods->where('sla_ok', true)->count(),
                'lt'      => $prods->where('breach_type', 'lt')->count(),
                'ftr'     => $prods->where('breach_type', 'ftr')->count(),
                'both'    => $prods->where('breach_type', 'both')->count(),
            ];
        })->values()->toArray();
    }

    // ── Aggregate: per product group ─────────────────────────────────────────
    private function aggregateProducts(\Illuminate\Support\Collection $rows): array
    {
        $orders = collect($this->aggregateOrders($rows));
        return $rows->groupBy('product_group')->map(function ($prods, $group) use ($orders) {
            // Orders that have at least one production of this group
            $touchedOrderIds = $prods->pluck('order_id')->unique();
            $touchedOrders   = $orders->whereIn('id', $touchedOrderIds);
            return [
                'name'       => $group,
                'ordTot'     => $touchedOrders->count(),
                'ordDel'     => $touchedOrders->count(),
                'ordOn'      => $touchedOrders->where('on_target', true)->count(),
                'inProd'     => $prods->count(),
                'prodDel'    => $prods->count(),
                'prodOn'     => $prods->where('sla_ok', true)->count(),
                'lt'         => $prods->where('breach_type', 'lt')->count(),
                'ftr'        => $prods->where('breach_type', 'ftr')->count(),
                'both'       => $prods->where('breach_type', 'both')->count(),
                'reason'     => '', // populated from revisions / return notes when available
            ];
        })->values()->toArray();
    }

    // ── Breach list ───────────────────────────────────────────────────────────
    private function breachList(\Illuminate\Support\Collection $rows): array
    {
        return $rows->where('sla_ok', false)->map(fn($r) => [
            'id'         => $r->order_id,
            'country'    => $r->country ?? '—',
            'prod'       => $r->product_group,
            'key'        => strtolower(str_replace(' ', '_', $r->product_group)),
            'created'    => $r->order_created,
            'lt'         => $r->upload_timestamp
                ? Carbon::parse($r->upload_timestamp)
                    ->diffInHours(Carbon::parse($r->order_created))
                : null,
            'ftr'        => !$r->ftr,
            'reason'     => $r->breach_type,
            'retour'     => '', // add return reason field when available
        ])->values()->toArray();
    }

    // ── Raw SQL ───────────────────────────────────────────────────────────────
    private function queryWith(): string { return "
        SELECT p.order_id, o.created_at AS order_created, p.id AS production_id,
               p.created_at AS production_created, p.description, p.product_group,
               p.expected_delivery_date, p.appointment_id,
               fh.created_at AS upload_timestamp,
               d.id AS delivery_id, d.created_at AS delivery, COUNT(r.id) AS revisions
        FROM api.productions p
        JOIN api.orders o ON o.id = p.order_id
        JOIN api.appointments a ON a.id = p.appointment_id
        JOIN api.flow_histories fh ON fh.processable_id = a.id
        JOIN api.deliveries d ON d.production_id = p.id
        LEFT JOIN api.revisions r ON r.delivery_id = d.id
        WHERE p.appointment_id != 0
          AND fh.processable_type LIKE '%appointment'
          AND fh.action_id = 'complete' AND fh.status = 'upload'
          AND p.created_at >= :date_from AND p.created_at < :date_to
          AND (p.status = 'delivered' OR p.status = 'manualUpload')
        GROUP BY p.order_id, o.created_at, p.id, p.created_at, p.description,
                 p.product_group, p.expected_delivery_date, p.appointment_id,
                 fh.created_at, d.id, d.created_at
        ORDER BY p.order_id, p.id DESC
    "; }

    private function queryWithout(): string { return "
        SELECT p.order_id, o.created_at AS order_created, p.id AS production_id,
               p.created_at AS production_created, p.description, p.product_group,
               p.expected_delivery_date, p.appointment_id,
               fh.created_at AS upload_timestamp,
               d.id AS delivery_id, d.created_at AS delivery, COUNT(r.id) AS revisions
        FROM api.productions p
        JOIN api.orders o ON o.id = p.order_id
        LEFT JOIN api.appointments a ON a.id = p.appointment_id
        LEFT JOIN api.flow_histories fh ON fh.processable_id = p.id
        JOIN api.deliveries d ON d.production_id = p.id
        LEFT JOIN api.revisions r ON r.delivery_id = d.id
        WHERE (p.appointment_id = 0 OR p.appointment_id IS NULL)
          AND fh.processable_type LIKE '%production'
          AND fh.action_id = 'complete' AND fh.status = 'waitingOnClient'
          AND p.created_at >= :date_from AND p.created_at < :date_to
          AND (p.status = 'delivered' OR p.status = 'manualUpload')
        GROUP BY p.order_id, o.created_at, p.id, p.created_at, p.description,
                 p.product_group, p.expected_delivery_date, p.appointment_id,
                 fh.created_at, d.id, d.created_at
        ORDER BY p.id DESC
    ";
    }
}
