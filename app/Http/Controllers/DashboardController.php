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

    public function mtd(Request $request)
    {
        $this->setDatabase();
        $country  = $request->get('country', 'ALL');
        $dateFrom = now()->startOfMonth()->toDateTimeString();
        $dateTo   = now()->toDateTimeString();

        $cacheKey = 'dashboard_v6_mtd_' . md5($country . date('Y-m-d-H'));

        return Cache::remember($cacheKey, 300, function () use ($dateFrom, $dateTo, $country) {
            $rows = $this->fetchProductions($dateFrom, $dateTo, $country);

            return response()->json([
                'daily'     => $this->aggregateDaily($rows),
                'products'  => $this->aggregateProducts($rows),
                'orders'    => $this->aggregateOrders($rows),
                'breach'    => $this->breachList($rows),
                'returns'   => $this->returnsList($rows),   // NEW in v6
                'revisions' => $this->revisionsList($rows), // NEW in v6
            ]);
        });
    }

    private function fetchProductions(string $from, string $to, string $country)
    {
        $params = ['date_from' => $from, 'date_to' => $to];

        $rows = collect(array_merge(
            DB::connection('live')->select($this->queryWithAppt(),    $params),
            DB::connection('live')->select($this->queryWithoutAppt(), $params)
        ))->map(function ($r) {
            $isReturn = (bool)($r->is_return ?? false);
            $hasRevisions = $r->revisions > 0;

            // Lead time check
            $ltOk = $r->upload_timestamp && $r->expected_delivery_date
                ? $r->upload_timestamp <= $r->expected_delivery_date
                : false;

            // FTR: first time right = no revisions AND no return
            $ftr = !$hasRevisions && !$isReturn;

            // SLA: on target = lead time ok AND FTR
            $slaOk = $ltOk && $ftr;

            // Breach type — returns and revisions are separate categories
            $breachType = null;
            if ($slaOk) {
                $breachType = null;
            } elseif ($isReturn && !$ltOk) {
                $breachType = 'lt+return';
            } elseif ($isReturn) {
                $breachType = 'return';
            } elseif ($hasRevisions && !$ltOk) {
                $breachType = 'lt+revision';
            } elseif ($hasRevisions) {
                $breachType = 'revision';
            } elseif (!$ltOk) {
                $breachType = 'lt';
            }

            $ltHours = $r->upload_timestamp && $r->order_created
                ? Carbon::parse($r->upload_timestamp)
                    ->diffInHours(Carbon::parse($r->order_created))
                : null;

            $r->ftr           = $ftr;
            $r->lt_ok         = $ltOk;
            $r->sla_ok        = $slaOk;
            $r->is_return     = $isReturn;
            $r->has_revisions = $hasRevisions;
            $r->breach_type   = $breachType;
            $r->lt_hours      = $ltHours;
            $r->day           = Carbon::parse($r->order_created)->toDateString();
            $r->country       = $r->country ?? '?'; // ⚠️ adjust to your schema

            return $r;
        });

        if ($country !== 'ALL') {
            $rows = $rows->where('country', $country);
        }

        return $rows;
    }

    // ── Daily aggregation ─────────────────────────────────────────────────────
    private function aggregateDaily($rows): array
    {
        $ordersByDay = $this->getOrdersCollection($rows)->groupBy('day');

        return $rows->groupBy('day')->map(function ($prods, $day) use ($ordersByDay) {
            $ords = $ordersByDay->get($day, collect());
            return [
                'd'         => $day,
                'ordTot'    => $ords->count(),
                'ordDel'    => $ords->count(),
                'ordOn'     => $ords->where('on_target', true)->count(),
                'returns'   => $prods->where('is_return', true)->count(),
                'revisions' => $prods->where('has_revisions', true)->where('is_return', false)->count(),
                'prodTot'   => $prods->count(),
                'prodDel'   => $prods->count(),
                'prodOn'    => $prods->where('sla_ok', true)->count(),
                'lt'        => $prods->whereIn('breach_type', ['lt', 'lt+return', 'lt+revision'])->count(),
                'ftr'       => $prods->whereIn('breach_type', ['return', 'revision'])->count(),
                'both'      => $prods->whereIn('breach_type', ['lt+return', 'lt+revision'])->count(),
            ];
        })->values()->sortByDesc('d')->values()->toArray();
    }

    // ── Product group aggregation ─────────────────────────────────────────────
    private function aggregateProducts($rows): array
    {
        $orders = $this->getOrdersCollection($rows);

        return $rows->groupBy('product_group')->map(function ($prods, $group) use ($orders) {
            $touchedOrders = $orders->whereIn('order_id', $prods->pluck('order_id')->unique());
            return [
                'key'       => $group,
                'ordTot'    => $touchedOrders->count(),
                'ordDel'    => $touchedOrders->count(),
                'ordOn'     => $touchedOrders->where('on_target', true)->count(),
                'returns'   => $prods->where('is_return', true)->count(),
                'revisions' => $prods->where('has_revisions', true)->where('is_return', false)->count(),
                'prodTot'   => $prods->count(),
                'prodDel'   => $prods->count(),
                'prodOn'    => $prods->where('sla_ok', true)->count(),
                'lt'        => $prods->whereIn('breach_type', ['lt'])->count(),
                'ftr'       => $prods->whereIn('breach_type', ['return', 'revision', 'lt+return', 'lt+revision'])->count(),
                'both'      => $prods->whereIn('breach_type', ['lt+return', 'lt+revision'])->count(),
            ];
        })->values()->toArray();
    }

    // ── Order detail (flat, with product short keys) ──────────────────────────
    private function aggregateOrders($rows): array
    {
        $keyMap = [
            'Photography'        => 'photo',  'Floorplanner'       => 'floor',
            'Measurement'        => 'meas',   'CAD Report'         => 'cad',
            'Video'              => 'vid',    'Social media video'  => 'soc',
            'Social Media Reel'  => 'reel',   '360 tour'           => 'tour',
            'Drone photography'  => 'drone',  'Matterport'         => 'mat',
            'KuulaTour'          => 'kuula',  'Drone video'        => 'dvid',
            'Artist impression'  => 'art',    'Evening impression' => 'eve',
            'WOFL'               => 'wofl',
        ];

        return $rows->groupBy('order_id')->map(function ($prods, $orderId) use ($keyMap) {
            $allOk  = $prods->every(fn($p) => $p->sla_ok);
            $first  = $prods->first();
            $result = [
                'id'        => $orderId,
                'country'   => $first->country,
                'on_target' => $allOk,
            ];
            foreach ($prods as $p) {
                $key = $keyMap[$p->product_group] ?? null;
                if ($key) {
                    // Status for cell color: ok / lt / return / revision
                    $status = $p->sla_ok ? 'ok'
                        : ($p->is_return ? 'return'
                            : ($p->has_revisions ? 'revision'
                                : 'lt'));
                    $result[$key] = [
                        'id'     => $p->production_id,
                        'ok'     => $p->sla_ok,
                        'status' => $status,
                    ];
                }
            }
            return $result;
        })->values()->toArray();
    }

    // ── Breach list (lead time + return; excludes revision-only) ─────────────
    private function breachList($rows): array
    {
        return $rows
            ->where('sla_ok', false)
            ->whereNotIn('breach_type', ['revision']) // revisions go to revisionsList
            ->map(function ($r) {
                return [
                    'id'           => $r->order_id,
                    'country'      => $r->country,
                    'prod'         => $r->product_group,
                    'created'      => $r->order_created
                        ? Carbon::parse($r->order_created)->toDateString()
                        : null,
                    'upload'       => $r->upload_timestamp
                        ? Carbon::parse($r->upload_timestamp)->format('Y-m-d H:i')
                        : '—',
                    'deadline'     => $r->expected_delivery_date
                        ? Carbon::parse($r->expected_delivery_date)->format('Y-m-d H:i')
                        : '—',
                    'lt'           => $r->lt_hours !== null ? $r->lt_hours.'h' : '—',
                    'sla'          => self::SLA_NORMS[$r->product_group] ?? 24,
                    'rev'          => $r->revisions,
                    'reason'       => $r->breach_type,
                    'return_reason'=> $r->return_reason ?? '',
                    'rev_notes'    => $r->revision_notes ?? '',
                ];
            })->values()->toArray();
    }

    // ── Returns list (NEW in v6) ──────────────────────────────────────────────
    // From api.appointments where return_appointment = true
    private function returnsList($rows): array
    {
        return $rows
            ->where('is_return', true)
            ->map(function ($r) {
                return [
                    'id'           => $r->order_id,
                    'country'      => $r->country,
                    'prod'         => $r->product_group,
                    'appt_date'    => $r->appt_scheduled_at
                        ? Carbon::parse($r->appt_scheduled_at)->toDateString()
                        : null,
                    'return_reason'=> $r->return_reason ?? '',  // picklist value
                    'appt_notes'   => $r->appt_notes ?? '',     // free text
                    'lt'           => $r->lt_hours !== null ? $r->lt_hours.'h' : '—',
                    'sla_ok'       => $r->sla_ok,
                    'breach_type'  => $r->breach_type,
                ];
            })->values()->toArray();
    }

    // ── Revisions list (NEW in v6) ────────────────────────────────────────────
    // From api.revisions where notes != null, client-requested changes
    private function revisionsList($rows): array
    {
        return $rows
            ->where('has_revisions', true)
            ->where('is_return', false) // returns are separate
            ->filter(fn($r) => !empty($r->revision_notes))
            ->map(function ($r) {
                return [
                    'id'          => $r->order_id,
                    'country'     => $r->country,
                    'prod'        => $r->product_group,
                    'rev_date'    => $r->delivery_at
                        ? Carbon::parse($r->delivery_at)->toDateString()
                        : null,
                    'rev_notes'   => $r->revision_notes ?? '', // free text from client
                    'lt'          => $r->lt_hours !== null ? $r->lt_hours.'h' : '—',
                    'sla_ok'      => $r->sla_ok,
                    'breach_type' => $r->breach_type,
                ];
            })->values()->toArray();
    }

    // ── Helper: order-level collection ────────────────────────────────────────
    private function getOrdersCollection($rows)
    {
        return $rows->groupBy('order_id')->map(function ($prods, $orderId) {
            return (object)[
                'order_id'   => $orderId,
                'country'    => $prods->first()->country,
                'day'        => $prods->first()->day,
                'on_target'  => $prods->every(fn($p) => $p->sla_ok),
            ];
        })->values();
    }


    // ── Raw SQL ───────────────────────────────────────────────────────────────
    private function queryWithAppt(): string { return "
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

    private function queryWithoutAppt(): string { return "
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
