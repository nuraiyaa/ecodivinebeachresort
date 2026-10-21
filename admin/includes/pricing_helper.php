<?php
// admin/includes/pricing_helper.php
// Dynamic Night-by-Night Pricing Calculator for Eco Divine Beach Resort

if (!function_exists('isDateWeekend')) {
    /**
     * Check if a specific date (night of stay) is a weekend night.
     * In Bangladesh resorts, Friday and Saturday nights are the weekend rates.
     *
     * @param string $dateStr 'YYYY-MM-DD'
     * @return bool
     */
    function isDateWeekend($dateStr) {
        $timestamp = strtotime($dateStr);
        if (!$timestamp) return false;
        $dayOfWeek = (int)date('N', $timestamp); // 1 (Mon) to 7 (Sun)
        return ($dayOfWeek === 5 || $dayOfWeek === 6); // 5 = Friday, 6 = Saturday
    }
}

if (!function_exists('calculateStayPricing')) {
    /**
     * Calculate night-by-night breakdown and total price for a stay.
     * Automatically applies weekend rate for Friday & Saturday nights,
     * and regular weekday rate for Sunday through Thursday nights.
     *
     * @param string $checkIn      'YYYY-MM-DD'
     * @param string $checkOut     'YYYY-MM-DD'
     * @param float  $weekdayPrice Regular weekday price
     * @param float  $weekendPrice Weekend price
     * @return array
     */
    function calculateStayPricing($checkIn, $checkOut, $weekdayPrice, $weekendPrice = 0) {
        $weekdayPrice = floatval($weekdayPrice);
        $weekendPrice = floatval($weekendPrice > 0 ? $weekendPrice : $weekdayPrice);

        try {
            $cin = new DateTime($checkIn ?: date('Y-m-d'));
            $cout = new DateTime($checkOut ?: date('Y-m-d', strtotime('+1 day')));
            if ($cout <= $cin) {
                $cout = (clone $cin)->modify('+1 day');
            }
        } catch (Exception $e) {
            $cin = new DateTime();
            $cout = (new DateTime())->modify('+1 day');
        }

        $curr = clone $cin;
        $weekdayNights = 0;
        $weekendNights = 0;
        $nightDetails = [];

        while ($curr < $cout) {
            $dayOfWeek = (int)$curr->format('N'); // 1 (Mon) - 7 (Sun)
            $isWk = ($dayOfWeek === 5 || $dayOfWeek === 6); // 5 = Fri, 6 = Sat
            $rate = $isWk ? $weekendPrice : $weekdayPrice;

            if ($isWk) {
                $weekendNights++;
            } else {
                $weekdayNights++;
            }

            $nightDetails[] = [
                'date'       => $curr->format('Y-m-d'),
                'day_name'   => $curr->format('l'),
                'day_short'  => $curr->format('D'),
                'is_weekend' => $isWk,
                'rate'       => $rate
            ];

            $curr->modify('+1 day');
        }

        $totalNights = $weekdayNights + $weekendNights;
        if ($totalNights < 1) $totalNights = 1;

        $totalPrice = ($weekdayNights * $weekdayPrice) + ($weekendNights * $weekendPrice);
        $avgPrice   = round($totalPrice / $totalNights);

        $hasWeekend   = ($weekendNights > 0);
        $hasWeekday   = ($weekdayNights > 0);
        $isAllWeekend = ($weekendNights > 0 && $weekdayNights === 0);
        $isAllWeekday = ($weekdayNights > 0 && $weekendNights === 0);

        if ($hasWeekend && $hasWeekday) {
            $breakdownText = "{$weekdayNights} Weekday (" . number_format($weekdayNights * $weekdayPrice) . ") + {$weekendNights} Weekend (" . number_format($weekendNights * $weekendPrice) . ")";
            $rateTypeBadge = "Mixed Rate ({$weekdayNights} Weekday + {$weekendNights} Weekend)";
        } elseif ($isAllWeekend) {
            $breakdownText = ($weekendNights > 1 ? "{$weekendNights} Weekend Nights × ৳" . number_format($weekendPrice) : "1 Weekend Night × ৳" . number_format($weekendPrice));
            $rateTypeBadge = "Weekend Rate Active";
        } else {
            $breakdownText = ($weekdayNights > 1 ? "{$weekdayNights} Weekday Nights × ৳" . number_format($weekdayPrice) : "1 Weekday Night × ৳" . number_format($weekdayPrice));
            $rateTypeBadge = "Weekday Rate";
        }

        return [
            'check_in'        => $cin->format('Y-m-d'),
            'check_out'       => $cout->format('Y-m-d'),
            'total_nights'    => $totalNights,
            'weekday_nights'  => $weekdayNights,
            'weekend_nights'  => $weekendNights,
            'weekday_price'   => $weekdayPrice,
            'weekend_price'   => $weekendPrice,
            'total_price'     => $totalPrice,
            'avg_price'       => $avgPrice,
            'has_weekend'     => $hasWeekend,
            'has_weekday'     => $hasWeekday,
            'is_all_weekend'  => $isAllWeekend,
            'is_all_weekday'  => $isAllWeekday,
            'breakdown_text'  => $breakdownText,
            'rate_type_badge' => $rateTypeBadge,
            'night_details'   => $nightDetails
        ];
    }
}
