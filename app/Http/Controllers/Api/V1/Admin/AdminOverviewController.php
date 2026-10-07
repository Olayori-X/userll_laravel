<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminOverviewController extends Controller
{
    private const MAX_DAYS = 366;

    /**
     * The numbers an operator watches. Add ?from=2026-10-01&to=2026-10-31 to choose the period for sales and
     * growth (default: the last 30 days). The "right now" sections ignore the dates.
     */
    public function show(Request $request, PlatformOverviewService $overview): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            return response()->json([
                'message' => 'Choose a period of at most '.self::MAX_DAYS.' days.',
                'errors' => ['from' => ['Choose a period of at most '.self::MAX_DAYS.' days.']],
            ], 422);
        }

        return response()->json(['data' => $overview->build($from, $to)]);
    }
}