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
        set_time_limit(60);
        $this->setDatabase();
        $country  = $request->get('country', 'ALL');
        $dateFrom = now()->subDays(30)->startOfDay()->toDateTimeString();
        $dateTo   = now()->toDateTimeString();
        $cacheKey = 'dashboard_v8_' . md5($country . date('Y-m-d-H'));

        return Cache::remember($cacheKey, 900, function () use ($dateFrom, $dateTo, $country) {

            // ── QUERY 1: Appointments in window ──────────────────────────────
            $appointments = collect(DB::connection('live')->select("
                SELECT
                    a.id                        AS appointment_id,
                    a.order_id,
                    a.scheduled_at              AS appt_date,
                    a.return_appointment        AS is_return,
                    a.return_appointment_notes  AS return_reason,
                    a.notes                     AS appt_notes
                FROM api.appointments a
                WHERE a.scheduled_at >= ? AND a.scheduled_at < ?
                ORDER BY a.scheduled_at DESC
            ", [$dateFrom, $dateTo]));

            if ($appointments->isEmpty()) {
                return response()->json($this->emptyResponse());
            }

            $apptIds  = $appointments->pluck('appointment_id')->unique()->values()->all();
            $orderIds = $appointments->pluck('order_id')->unique()->values()->all();

            $apptPh  = implode(',', array_fill(0, count($apptIds), '?'));
            $orderPh = implode(',', array_fill(0, count($orderIds), '?'));

            // ── QUERY 2: Productions for those appointment IDs ────────────────
            $productions = collect(DB::connection('live')->select(
                "SELECT
                    p.id                     AS production_id,
                    p.order_id,
                    p.appointment_id,
                    p.product_group,
                    p.expected_delivery_date,
                    p.status                 AS production_status
                FROM api.productions p
                WHERE p.appointment_id IN ($apptPh)
                  AND p.status IN ('delivered','manualUpload')",
                $apptIds
            ));

            $prodIds = $productions->pluck('production_id')->unique()->values()->all();

            if (empty($prodIds)) {
                return response()->json($this->emptyResponse());
            }

            $prodPh = implode(',', array_fill(0, count($prodIds), '?'));

            // ── QUERY 3: Upload timestamps for appointment IDs ────────────────
            $uploads = collect(DB::connection('live')->select(
                "SELECT
                    fh.processable_id  AS appointment_id,
                    MIN(fh.created_at) AS upload_timestamp
                FROM api.flow_histories fh
                WHERE fh.processable_id IN ($apptPh)
                  AND fh.processable_type LIKE '%appointment'
                  AND fh.action_id = 'complete'
                  AND fh.status    = 'upload'
                GROUP BY fh.processable_id",
                $apptIds
            ))->keyBy('appointment_id');

            // ── QUERY 4: Delivery counts for production IDs ───────────────────
            $deliveries = collect(DB::connection('live')->select(
                "SELECT
                    production_id,
                    COUNT(*)                                            AS total_deliveries,
                    COUNT(CASE WHEN is_revision = 0 THEN 1 END)        AS non_revision_deliveries
                FROM api.deliveries
                WHERE production_id IN ($prodPh)
                GROUP BY production_id",
                $prodIds
            ))->keyBy('production_id');

            // ── QUERY 5: Revisions for production IDs ────────────────────────
            $revisions = collect(DB::connection('live')->select(
                "SELECT
                    production_id,
                    id                        AS revision_id,
                    COUNT(*) OVER (PARTITION BY production_id) AS revision_count,
                    notes                     AS revision_notes,
                    created_at                AS revision_created_at,
                    expected_delivery_at      AS revision_expected_at,
                    status                    AS revision_status
                FROM api.revisions
                WHERE production_id IN ($prodPh)
                ORDER BY production_id, created_at DESC",
                $prodIds
            ))->groupBy('production_id')->map(fn($rows) => $rows->first()); // latest revision per production

            // ── QUERY 5b: Revision feedback descriptions ──────────────────────
            $revisionIds = $revisions->pluck('revision_id')->filter()->unique()->values()->all();
            $feedbackByProd = collect();

            if (!empty($revisionIds)) {
                $revPh = implode(',', array_fill(0, count($revisionIds), '?'));
                $feedbackByProd = collect(DB::connection('live')->select(
                    "SELECT
                        rf.revision_id,
                        r.production_id,
                        rf.description
                    FROM api.revision_feedback rf
                    JOIN api.revisions r ON r.id = rf.revision_id
                    WHERE rf.revision_id IN ($revPh)
                      AND rf.description IS NOT NULL
                      AND rf.description != ''
                    ORDER BY rf.revision_id, rf.created_at ASC",
                    $revisionIds
                ))->groupBy('production_id')->map(fn($rows) => $rows->pluck('description')->all());
            }

            // ── QUERY 6: All productions per order (for in_progress) ──────────
            $orderProductions = collect(DB::connection('live')->select(
                "SELECT
                    p.order_id,
                    COUNT(*) AS total_productions,
                    SUM(CASE WHEN p.status IN ('delivered','manualUpload') THEN 1 ELSE 0 END)
                        AS delivered_productions
                FROM api.productions p
                WHERE p.order_id IN ($orderPh)
                GROUP BY p.order_id",
                $orderIds
            ))->keyBy('order_id');

            // ── PHP: Enrich production rows ───────────────────────────────────
            $apptMap = $appointments->keyBy('appointment_id');

            $rows = $productions->map(function ($p) use (
                $apptMap, $uploads, $deliveries, $revisions, $feedbackByProd
            ) {
                $appt  = $apptMap->get($p->appointment_id);
                $upl   = $uploads->get($p->appointment_id);
                $del   = $deliveries->get($p->production_id);
                $rev   = $revisions->get($p->production_id);

                $uploadTs   = $upl?->upload_timestamp ?? null;
                $expectedDl = $p->expected_delivery_date ?? null;
                $nonRevDel  = (int)($del?->non_revision_deliveries ?? 1);

                $ftr      = $nonRevDel === 1;
                $ltOk     = $uploadTs && $expectedDl ? $uploadTs <= $expectedDl : false;
                $isReturn = (bool)($appt?->is_return ?? false);
                $slaOk    = $ftr && $ltOk && !$isReturn;

                $breachType = null;
                if (!$slaOk) {
                    if ($isReturn)           $breachType = 'ret';
                    elseif (!$ltOk && !$ftr) $breachType = 'both';
                    elseif (!$ltOk)          $breachType = 'lt';
                    else                     $breachType = 'ftr';
                }

                $apptDate    = $appt?->appt_date ?? null;
                $turnaroundH = $uploadTs && $apptDate
                    ? Carbon::parse($uploadTs)->diffInHours(Carbon::parse($apptDate))
                    : null;

                return (object)[
                    'order_id'                => $p->order_id,
                    'production_id'           => $p->production_id,
                    'appointment_id'          => $p->appointment_id,
                    'product_group'           => $p->product_group,
                    'expected_delivery_date'  => $expectedDl,
                    'appt_date'               => $apptDate
                        ? Carbon::parse($apptDate)->toDateString() : null,
                    'upload_timestamp'        => $uploadTs,
                    'is_return'               => $isReturn,
                    'return_reason'           => $appt?->return_reason ?? null,
                    'appt_notes'              => $appt?->appt_notes ?? null,
                    'total_deliveries'        => (int)($del?->total_deliveries ?? 0),
                    'non_revision_deliveries' => $nonRevDel,
                    'revision_count'          => (int)($rev?->revision_count ?? 0),
                    'revision_id'             => $rev?->revision_id ?? null,
                    'revision_notes'          => $rev?->revision_notes ?? null,
                    'revision_created_at'     => $rev?->revision_created_at ?? null,
                    'revision_expected_at'    => $rev?->revision_expected_at ?? null,
                    'revision_status'         => $rev?->revision_status ?? null,
                    'revision_feedback'       => $feedbackByProd->get($p->production_id, []),
                    'ftr'                     => $ftr,
                    'lt_ok'                   => $ltOk,
                    'sla_ok'                  => $slaOk,
                    'breach_type'             => $breachType,
                    'turnaround_h'            => $turnaroundH,
                    // ⚠️ country: add via orders.address_id → addresses.country — see section 8
                    'country'                 => '?',
                ];
            });

            // Country filter
            if ($country !== 'ALL') {
                $rows = $rows->where('country', $country);
            }

            // Order-level aggregation
            $orderMap = $rows->groupBy('order_id')->map(function ($prods) use ($orderProductions) {
                $orderId    = $prods->first()->order_id;
                $ordProd    = $orderProductions->get($orderId);
                $totalP     = (int)($ordProd?->total_productions ?? $prods->count());
                $deliveredP = (int)($ordProd?->delivered_productions ?? $prods->count());
                $inProgress = ($totalP - $deliveredP) > 0;

                return (object)[
                    'order_id'    => $orderId,
                    'on_target'   => !$inProgress && $prods->every(fn($p) => $p->sla_ok),
                    'in_progress' => ($totalP - $deliveredP) > 0,
                    'appt_date'   => $prods->first()->appt_date,
                    'country'     => $prods->first()->country,
                ];
            })->values();

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function emptyResponse(): array
    {
        return [
            'periods' => [], 'products' => [], 'orders' => [],
            'breach'  => [], 'returns'  => [], 'revisions' => [],
        ];
    }

    private function aggregatePeriods($rows, $orderMap): array
    {
        $ordersByDay = $orderMap->groupBy('appt_date');

        return $rows->groupBy('appt_date')
            ->map(function ($prods, $day) use ($ordersByDay) {
                $ords    = $ordersByDay->get($day, collect());
                $ordTot  = $ords->count();
                $ordProg = $ords->where('in_progress', true)->count();
                $ordOn   = $ords->where('on_target', true)->count();
                $ordDel  = $ordTot - $ordProg;

                return [
                    'period'      => $day,
                    'label'       => $day ? Carbon::parse($day)->format('d M') : '—',
                    'type'        => 'day',
                    'appts'       => $ordTot,
                    'delivered'   => $ordDel,
                    'in_progress' => $ordProg,
                    'on_target'   => $ordOn,
                    'breach'      => $ordDel - $ordOn,
                    'lt_breach'   => $prods->whereIn('breach_type', ['lt', 'both'])->count(),
                    'ftr_breach'  => $prods->whereIn('breach_type', ['ftr', 'both'])->count(),
                    'returns'     => $prods->where('is_return', true)->count(),
                    'revisions'   => $prods->where('revision_count', '>', 0)
                        ->pluck('order_id')->unique()->count(),
                ];
            })
            ->values()->sortBy('period')->values()->toArray();
    }

    private function aggregateProducts($rows, $orderMap): array
    {
        return $rows->groupBy('product_group')
            ->map(function ($prods, $group) use ($orderMap) {
                $orderIds    = $prods->pluck('order_id')->unique();
                $touchedOrds = $orderMap->whereIn('order_id', $orderIds);
                $tot  = $touchedOrds->count();
                $prog = $touchedOrds->where('in_progress', true)->count();
                $on   = $touchedOrds->where('on_target', true)->count();

                return [
                    'key'         => $group,
                    'sla_norm'    => self::SLA_NORMS[$group] ?? 24,
                    'appts'       => $tot,
                    'delivered'   => $tot - $prog,
                    'in_progress' => $prog,
                    'on_target'   => $on,
                    'breach'      => ($tot - $prog) - $on,
                    'lt_breach'   => $prods->whereIn('breach_type', ['lt', 'both'])->count(),
                    'ftr_breach'  => $prods->whereIn('breach_type', ['ftr', 'both'])->count(),
                    'returns'     => $prods->where('is_return', true)->count(),
                    'revisions'   => $prods->where('revision_count', '>', 0)->count(),
                ];
            })
            ->values()->toArray();
    }

    private function aggregateOrders($rows, $orderMap): array
    {
        return $rows->groupBy('order_id')
            ->map(function ($prods, $orderId) use ($orderMap) {
                $ord    = $orderMap->firstWhere('order_id', $orderId);
                $result = [
                    'id'          => (string) $orderId,
                    'country'     => $prods->first()->country,
                    'appt_date'   => $prods->first()->appt_date,
                    'on_target'   => $ord?->on_target ?? false,
                    'in_progress' => $ord?->in_progress ?? false,
                ];
                foreach ($prods as $p) {
                    $key = self::KEY_MAP[$p->product_group] ?? null;
                    if (!$key) continue;
                    $st = $p->sla_ok ? 'ok'
                        : (str_contains($p->breach_type ?? '', 'ret') ? 'return'
                            : (in_array($p->breach_type, ['ftr', 'both']) ? 'ftr' : 'lt'));
                    $result[$key] = [
                        'id'         => (string) $p->production_id,
                        'ok'         => $p->sla_ok,
                        'status'     => $st,
                        'deliveries' => $p->total_deliveries,
                    ];
                }
                return $result;
            })
            ->values()->toArray();
    }

    private function breachList($rows): array
    {
        return $rows->where('sla_ok', false)
            ->map(function ($r) {
                return [
                    'id'           => (string) $r->order_id,
                    'country'      => $r->country,
                    'prod'         => $r->product_group,
                    'appt_date'    => $r->appt_date,
                    'first_upload' => $r->upload_timestamp
                        ? Carbon::parse($r->upload_timestamp)->format('Y-m-d H:i') : '—',
                    'deadline'     => $r->expected_delivery_date
                        ? Carbon::parse($r->expected_delivery_date)->format('Y-m-d H:i') : '—',
                    'turnaround_h' => $r->turnaround_h,
                    'sla_norm'     => self::SLA_NORMS[$r->product_group] ?? 24,
                    'deliveries'   => $r->total_deliveries,
                    'reason'       => $r->breach_type,
                    'return_reason'=> $r->return_reason ?? '',
                ];
            })
            ->values()->toArray();
    }

    private function returnsList($rows): array
    {
        return $rows->where('is_return', true)
            ->map(function ($r) {
                return [
                    'id'           => (string) $r->order_id,
                    'country'      => $r->country,
                    'prod'         => $r->product_group,
                    'return_date'  => $r->appt_date,
                    'return_reason'=> $r->return_reason ?? '',
                    'appt_notes'   => $r->appt_notes ?? '',
                    'upload'       => $r->upload_timestamp
                        ? Carbon::parse($r->upload_timestamp)->format('Y-m-d H:i') : '—',
                    'sla_ok'       => $r->sla_ok,
                ];
            })
            ->values()->toArray();
    }

    private function revisionsList($rows): array
    {
        return $rows->where('revision_count', '>', 0)
            ->map(function ($r) {
                return [
                    'id'          => (string) $r->order_id,
                    'country'     => $r->country,
                    'prod'        => $r->product_group,
                    'rev_date'    => $r->revision_created_at
                        ? Carbon::parse($r->revision_created_at)->toDateString() : '—',
                    // notes = client's free text note on the revision request
                    'notes'       => $r->revision_notes ?? '',
                    // feedback = array of description strings from revision_feedback table
                    'feedback'    => $r->revision_feedback ?? [],
                    'expected_at' => $r->revision_expected_at
                        ? Carbon::parse($r->revision_expected_at)->toDateString() : '—',
                    'status'      => $r->revision_status ?? 'pending',
                ];
            })
            ->values()->toArray();
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
