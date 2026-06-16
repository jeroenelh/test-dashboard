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

    public function v8(Request $request)
    {
        $this->setDatabase();
        $country  = $request->get('country', 'ALL');
        $dateFrom = now()->subDays(30)->startOfDay()->toDateTimeString();
        $dateTo   = now()->toDateTimeString();

        $cacheKey = 'dashboard_v8_' . md5($country . date('Y-m-d-H'));

        return Cache::remember($cacheKey, 300, function () use ($dateFrom, $dateTo, $country) {
            $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];

            // 1. Fetch all rows
            $allRows = collect(array_merge(
                DB::connection('live')->select($this->queryWithAppt(),    $params),
                DB::connection('live')->select($this->queryWithoutAppt(), $params)
            ));

            // 2. Enrich each row with SLA logic
            $rows = $allRows->map(function ($r) {
                // FTR: first_deliveries count == 1 (no re-deliveries excluding revisions)
                $ftr  = (int)($r->first_deliveries ?? 1) === 1;

                // Lead time: first upload <= expected delivery date
                $ltOk = $r->upload_timestamp && $r->expected_delivery_date
                    ? $r->upload_timestamp <= $r->expected_delivery_date
                    : false;

                // Return = Zibber fault, always a breach
                $isReturn = (bool)($r->is_return ?? false);

                $slaOk = $ftr && $ltOk && !$isReturn;

                $breachType = null;
                if (!$slaOk) {
                    if ($isReturn && !$ltOk) $breachType = 'lt+ret';
                    elseif ($isReturn)       $breachType = 'ret';
                    elseif (!$ltOk && !$ftr) $breachType = 'both';
                    elseif (!$ltOk)          $breachType = 'lt';
                    else                     $breachType = 'ftr';
                }

                $turnaroundH = $r->upload_timestamp && $r->appt_date
                    ? Carbon::parse($r->upload_timestamp)
                        ->diffInHours(Carbon::parse($r->appt_date))
                    : null;

                $r->ftr          = $ftr;
                $r->lt_ok        = $ltOk;
                $r->is_return    = $isReturn;
                $r->sla_ok       = $slaOk;
                $r->breach_type  = $breachType;
                $r->turnaround_h = $turnaroundH;
                $r->appt_day     = Carbon::parse($r->appt_date)->toDateString();
                // ⚠️ country: add via orders JOIN to addresses or clients table
                $r->country      = $r->country ?? '?';

                return $r;
            });

            // 3. Country filter
            if ($country !== 'ALL') {
                $rows = $rows->where('country', $country);
            }

            // 4. Build order-level collection
            $orderMap = $rows->groupBy('order_id')->map(fn($prods) => (object)[
                'order_id'   => $prods->first()->order_id,
                'on_target'  => $prods->every(fn($p) => $p->sla_ok),
                'day'        => $prods->first()->appt_day,
                'country'    => $prods->first()->country,
                'order_date' => $prods->first()->order_created
                    ? Carbon::parse($prods->first()->order_created)->toDateString() : null,
                'appt_date'  => $prods->first()->appt_day,
            ])->values();

            return response()->json([
                'periods'   => $this->aggregatePeriods($rows, $orderMap),
                'products'  => $this->aggregateProducts($rows, $orderMap),
                'orders'    => $this->aggregateOrders($rows, $orderMap),
                'breach'    => $this->breachList($rows),
                'returns'   => $this->returnsList($rows),
                'revisions' => $this->revisionsList($rows),
            ]);
        });
    }

    // ── Aggregate: daily periods ──────────────────────────────────────────────
    private function aggregatePeriods($rows, $orderMap): array
    {
        $ordersByDay = $orderMap->groupBy('day');

        return $rows->groupBy('appt_day')
            ->map(function ($prods, $day) use ($ordersByDay) {
                $ords    = $ordersByDay->get($day, collect());
                $ordTot  = $ords->count();
                $ordDel  = $ords->where('on_target', '!=', null)->count(); // all in query are delivered
                $ordOn   = $ords->where('on_target', true)->count();
                $ordProg = 0; // requires separate open orders query — see section 8

                return [
                    'period'      => $day,
                    'label'       => Carbon::parse($day)->format('d-m'),
                    'type'        => 'day',
                    'country'     => null, // aggregated, use country param on API
                    'appts'       => $ordTot,
                    'delivered'   => $ordDel,
                    'in_progress' => $ordProg,
                    'on_target'   => $ordOn,
                    'breach'      => $ordDel - $ordOn,
                    'lt_breach'   => $prods->whereIn('breach_type', ['lt','both','lt+ret'])->count(),
                    'ftr_breach'  => $prods->whereIn('breach_type', ['ftr','both'])->count(),
                    'returns'     => $prods->where('is_return', true)->count(),
                    'revisions'   => $prods->whereNotNull('revision_id')->pluck('order_id')->unique()->count(),
                ];
            })
            ->values()
            ->sortBy('period')
            ->values()
            ->toArray();
    }

    // ── Aggregate: per product group ──────────────────────────────────────────
    private function aggregateProducts($rows, $orderMap): array
    {
        return $rows->groupBy('product_group')
            ->map(function ($prods, $group) use ($orderMap) {
                $orderIds    = $prods->pluck('order_id')->unique();
                $touchedOrds = $orderMap->whereIn('order_id', $orderIds);
                $del         = $touchedOrds->count();
                $on          = $touchedOrds->where('on_target', true)->count();

                return [
                    'key'         => $group,
                    'sla_norm'    => self::SLA_NORMS[$group] ?? 24,
                    'appts'       => $del,
                    'delivered'   => $del,
                    'in_progress' => 0,
                    'on_target'   => $on,
                    'breach'      => $del - $on,
                    'lt_breach'   => $prods->whereIn('breach_type', ['lt','both','lt+ret'])->count(),
                    'ftr_breach'  => $prods->whereIn('breach_type', ['ftr','both'])->count(),
                    'returns'     => $prods->where('is_return', true)->count(),
                    'revisions'   => $prods->whereNotNull('revision_id')->count(),
                ];
            })
            ->values()
            ->toArray();
    }

    // ── Aggregate: order detail tab ───────────────────────────────────────────
    private function aggregateOrders($rows, $orderMap): array
    {
        return $rows->groupBy('order_id')->map(function ($prods, $orderId) use ($orderMap) {
            $ord    = $orderMap->firstWhere('order_id', $orderId);
            $result = [
                'id'         => (string) $orderId,
                'country'    => $prods->first()->country,
                'order_date' => $ord?->order_date,
                'appt_date'  => $ord?->appt_date,
                'on_target'  => $ord?->on_target ?? false,
            ];
            foreach ($prods as $p) {
                $key = self::KEY_MAP[$p->product_group] ?? null;
                if (!$key) continue;
                $status = $p->sla_ok ? 'ok'
                    : (str_contains($p->breach_type ?? '', 'ret') ? 'return'
                        : ($p->breach_type === 'ftr' || $p->breach_type === 'both' ? 'ftr'
                            : 'lt'));
                $result[$key] = [
                    'id'         => (string) $p->production_id,
                    'ok'         => $p->sla_ok,
                    'status'     => $status,
                    'deliveries' => (int)($p->total_deliveries ?? 1),
                ];
            }
            return $result;
        })->values()->toArray();
    }

    // ── Breach list ───────────────────────────────────────────────────────────
    private function breachList($rows): array
    {
        return $rows->where('sla_ok', false)->map(function ($r) {
            return [
                'id'           => (string) $r->order_id,
                'country'      => $r->country,
                'prod'         => $r->product_group,
                'appt_date'    => $r->appt_day,
                'first_upload' => $r->upload_timestamp
                    ? Carbon::parse($r->upload_timestamp)->format('Y-m-d H:i') : '—',
                'deadline'     => $r->expected_delivery_date
                    ? Carbon::parse($r->expected_delivery_date)->format('Y-m-d H:i') : '—',
                'turnaround_h' => $r->turnaround_h,
                'sla_norm'     => self::SLA_NORMS[$r->product_group] ?? 24,
                'deliveries'   => (int)($r->total_deliveries ?? 1),
                'reason'       => $r->breach_type,
                'return_reason'=> $r->return_reason ?? '',
            ];
        })->values()->toArray();
    }

    // ── Returns list (return_appointment = 1) ─────────────────────────────────
    private function returnsList($rows): array
    {
        return $rows->where('is_return', true)->map(function ($r) {
            return [
                'id'           => (string) $r->order_id,
                'country'      => $r->country,
                'prod'         => $r->product_group,
                'return_date'  => $r->appt_day,
                'return_reason'=> $r->return_reason ?? '',
                'appt_notes'   => $r->appt_notes ?? '',
                'upload'       => $r->upload_timestamp
                    ? Carbon::parse($r->upload_timestamp)->format('Y-m-d H:i') : '—',
                'sla_ok'       => $r->sla_ok,
            ];
        })->values()->toArray();
    }

    // ── Revisions list (klant verzoek) ────────────────────────────────────────
    private function revisionsList($rows): array
    {
        return $rows->whereNotNull('revision_id')->map(function ($r) {
            return [
                'id'          => (string) $r->order_id,
                'country'     => $r->country,
                'prod'        => $r->product_group,
                'rev_date'    => $r->revision_created_at
                    ? Carbon::parse($r->revision_created_at)->toDateString() : '—',
                'notes'       => $r->revision_notes ?? '',
                'expected_at' => $r->revision_expected_at
                    ? Carbon::parse($r->revision_expected_at)->toDateString() : '—',
                'status'      => $r->revision_status ?? 'pending',
            ];
        })->values()->toArray();
    }

    // ── Raw SQL ───────────────────────────────────────────────────────────────
    private function queryWithAppt(): string
    {
        return "
            SELECT
                o.id                            AS order_id,
                o.created_at                    AS order_created,
                o.status                        AS order_status,
                p.id                            AS production_id,
                p.product_group,
                p.expected_delivery_date,
                p.appointment_id,
                p.status                        AS production_status,
                a.scheduled_at                  AS appt_date,
                a.return_appointment            AS is_return,
                a.return_appointment_notes      AS return_reason,
                a.notes                         AS appt_notes,
                fh.created_at                   AS upload_timestamp,
                d.id                            AS delivery_id,
                d.created_at                    AS delivery_at,
                d.is_revision                   AS delivery_is_revision,
                COUNT(d2.id)                    AS total_deliveries,
                COUNT(CASE WHEN d2.is_revision = 0 THEN 1 END) AS first_deliveries,
                r.id                            AS revision_id,
                r.notes                         AS revision_notes,
                r.expected_delivery_at          AS revision_expected_at,
                r.created_at                    AS revision_created_at,
                r.status                        AS revision_status
            FROM api.orders o
            JOIN api.productions      p   ON p.order_id        = o.id
            JOIN api.appointments     a   ON a.id              = p.appointment_id
            JOIN api.flow_histories   fh  ON fh.processable_id = a.id
            JOIN api.deliveries       d   ON d.production_id   = p.id
                                         AND d.is_revision     = 0
            JOIN api.deliveries       d2  ON d2.production_id  = p.id
            LEFT JOIN api.revisions   r   ON r.production_id   = p.id
            WHERE
                p.appointment_id IS NOT NULL
                AND p.appointment_id != 0
                AND fh.processable_type LIKE '%appointment'
                AND fh.action_id = 'complete'
                AND fh.status    = 'upload'
                AND a.scheduled_at >= :date_from
                AND a.scheduled_at <  :date_to
            GROUP BY
                o.id, o.created_at, o.status,
                p.id, p.product_group, p.expected_delivery_date,
                p.appointment_id, p.status,
                a.scheduled_at, a.return_appointment,
                a.return_appointment_notes, a.notes,
                fh.created_at, d.id, d.created_at, d.is_revision,
                r.id, r.notes, r.expected_delivery_at,
                r.created_at, r.status
            ORDER BY a.scheduled_at DESC, o.id, p.id
        ";
    }

    private function queryWithoutAppt(): string
    {
        return "
            SELECT
                o.id                            AS order_id,
                o.created_at                    AS order_created,
                o.status                        AS order_status,
                p.id                            AS production_id,
                p.product_group,
                p.expected_delivery_date,
                p.appointment_id,
                p.status                        AS production_status,
                p.created_at                    AS appt_date,
                0                               AS is_return,
                NULL                            AS return_reason,
                NULL                            AS appt_notes,
                fh.created_at                   AS upload_timestamp,
                d.id                            AS delivery_id,
                d.created_at                    AS delivery_at,
                d.is_revision                   AS delivery_is_revision,
                COUNT(d2.id)                    AS total_deliveries,
                COUNT(CASE WHEN d2.is_revision = 0 THEN 1 END) AS first_deliveries,
                r.id                            AS revision_id,
                r.notes                         AS revision_notes,
                r.expected_delivery_at          AS revision_expected_at,
                r.created_at                    AS revision_created_at,
                r.status                        AS revision_status
            FROM api.orders o
            JOIN api.productions      p   ON p.order_id        = o.id
            LEFT JOIN api.flow_histories fh ON fh.processable_id = p.id
            JOIN api.deliveries       d   ON d.production_id   = p.id
                                         AND d.is_revision     = 0
            JOIN api.deliveries       d2  ON d2.production_id  = p.id
            LEFT JOIN api.revisions   r   ON r.production_id   = p.id
            WHERE
                (p.appointment_id IS NULL OR p.appointment_id = 0)
                AND fh.processable_type LIKE '%production'
                AND fh.action_id = 'complete'
                AND fh.status    = 'waitingOnClient'
                AND p.created_at >= :date_from
                AND p.created_at <  :date_to
            GROUP BY
                o.id, o.created_at, o.status,
                p.id, p.product_group, p.expected_delivery_date,
                p.appointment_id, p.status, p.created_at,
                fh.created_at, d.id, d.created_at, d.is_revision,
                r.id, r.notes, r.expected_delivery_at,
                r.created_at, r.status
            ORDER BY p.created_at DESC, o.id, p.id
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
