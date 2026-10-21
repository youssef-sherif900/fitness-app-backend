<?php

namespace App\Services\Nutrition;

/**
 * BODY-1: smoothed weight trend (exponentially weighted moving average).
 *
 *   trend_0 = first weight
 *   trend_t = trend_{t-1} + a_eff * (weight_t - trend_{t-1}),  a_eff = 1 - (1 - alpha)^gapDays
 *
 * a_eff makes the trend behave the same whether the user weighs in daily or every few days.
 * If several weights fall on one day, the latest one is used.
 */
class TrendService
{
    public function __construct(private readonly float $alpha = 0.1) {}

    /**
     * @param  array<int,array{date:string,weight:float}>  $entries  any order
     * @return array<int,array{date:string,weight:float,trend:float}>
     */
    public function compute(array $entries): array
    {
        $byDay = [];
        foreach ($entries as $e) {
            $byDay[$e['date']] = (float) $e['weight']; // later entries override earlier ones, so pass sorted by time
        }
        ksort($byDay);

        $out = [];
        $trend = null;
        $prev = null;
        foreach ($byDay as $date => $weight) {
            if ($trend === null) {
                $trend = $weight;
            } else {
                $gap = max(1, (int) ((strtotime($date) - strtotime($prev)) / 86400));
                $a = 1 - (1 - $this->alpha) ** $gap;
                $trend += $a * ($weight - $trend);
            }
            $prev = $date;
            $out[] = ['date' => $date, 'weight' => $weight, 'trend' => round($trend, 2)];
        }

        return $out;
    }

    /** Trend slope in kg per week over the series (null when it cannot be estimated). */
    public function weeklySlope(array $series): ?float
    {
        if (count($series) < 2) {
            return null;
        }
        $first = $series[0];
        $last = $series[array_key_last($series)];
        $days = (strtotime($last['date']) - strtotime($first['date'])) / 86400;

        return $days < 1 ? null : ($last['trend'] - $first['trend']) / $days * 7;
    }
}
