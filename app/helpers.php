<?php

function getHourlySlots($start_str, $end_str) {
    $start = new DateTime($start_str);
    $end = new DateTime($end_str);
    
    if ($start >= $end) {
        return [];
    }
    
    $slots = [];
    $interval = new DateInterval('PT1H');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach ($period as $dt) {
        $slot_end = clone $dt;
        $slot_end->add($interval);
        if ($slot_end > $end) {
            $slot_end = $end;
        }
        $slots[] = [
            'start_time' => $dt->format('H:i'),
            'end_time' => $slot_end->format('H:i')
        ];
    }
    
    return $slots;
}
?>