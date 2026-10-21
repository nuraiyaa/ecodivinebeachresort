<?php
// admin/includes/pricing_helper.php
// Dynamic Night-by-Night Pricing Calculator for Eco Divine Beach Resort

if (!function_exists('getSpecificWeekendDates')) {
    /**
     * Fetch list of custom specific weekend dates from database.
     * Caches statically for the current request.
     *
     * @return array Array of 'YYYY-MM-DD' strings
     */
    function getSpecificWeekendDates() {
        global $conn;
        static $cachedDates = null;
        if ($cachedDates !== null) {
            return $cachedDates;
        }

        $cachedDates = [];
        if (!$conn || !($conn instanceof mysqli)) {
            return $cachedDates;
        }

        // Auto-create table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS weekend_dates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            weekend_date DATE NOT NULL UNIQUE,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = $conn->query("SELECT weekend_date FROM weekend_dates ORDER BY weekend_date ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cachedDates[] = $row['weekend_date'];
            }
        }
        return $cachedDates;
    }
}

if (!function_exists('getAllWeekendRecords')) {
    /**
     * Fetch detailed list of all custom weekend dates with ID, note and date.
     *
     * @return array
     */
    function getAllWeekendRecords() {
        global $conn;
        if (!$conn || !($conn instanceof mysqli)) {
            return [];
        }

        $conn->query("CREATE TABLE IF NOT EXISTS weekend_dates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            weekend_date DATE NOT NULL UNIQUE,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $records = [];
        $res = $conn->query("SELECT id, weekend_date, note, created_at FROM weekend_dates ORDER BY weekend_date ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $records[] = $row;
            }
        }
        return $records;
    }
}

if (!function_exists('isDateWeekend')) {
    /**
     * Check if a specific date (night of stay) is a weekend night.
     * 1. Friday and Saturday nights are the standard weekend nights.
     * 2. Any specific date set in the admin panel (e.g. holidays, festivals).
     *
     * @param string $dateStr 'YYYY-MM-DD'
     * @return bool
     */
    function isDateWeekend($dateStr) {
        $timestamp = strtotime($dateStr);
        if (!$timestamp) return false;
        
        // Friday (5) & Saturday (6)
        $dayOfWeek = (int)date('N', $timestamp);
        if ($dayOfWeek === 5 || $dayOfWeek === 6) {
            return true;
        }

        // Custom specific weekend dates set in admin
        $specificDates = getSpecificWeekendDates();
        $formattedDate = date('Y-m-d', $timestamp);
        if (!empty($specificDates) && in_array($formattedDate, $specificDates, true)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('calculateStayPricing')) {
    /**
     * Calculate night-by-night breakdown and total price for a stay.
     * Automatically applies weekend rate for Friday & Saturday nights or custom weekend dates,
     * and regular weekday rate for other nights.
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
            $dateStr = $curr->format('Y-m-d');
            $isWk = isDateWeekend($dateStr);
            $rate = $isWk ? $weekendPrice : $weekdayPrice;

            if ($isWk) {
                $weekendNights++;
            } else {
                $weekdayNights++;
            }

            $nightDetails[] = [
                'date'       => $dateStr,
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
            'total_price'     => $totalPrice,
            'avg_price'       => $avgPrice,
            'breakdown_text'  => $breakdownText,
            'rate_type_badge' => $rateTypeBadge,
            'is_weekend'      => $hasWeekend,
            'has_weekend'     => $hasWeekend,
            'has_weekday'     => $hasWeekday,
            'is_all_weekend'  => $isAllWeekend,
            'is_all_weekday'  => $isAllWeekday,
            'night_details'   => $nightDetails
        ];
    }
}
