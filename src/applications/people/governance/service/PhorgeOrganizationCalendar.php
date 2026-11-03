<?php

/** Calendar preview only. It is never a credential or permission decision. */
final class PhorgeOrganizationCalendar extends Phobject {
  private static function epoch($value) {
    if (!is_int($value) || $value < 0 || $value > 253402300799) {
      throw new InvalidArgumentException('organization_handover_time_refused');
    }
    return $value;
  }

  public static function preview($startAt, $days = 14, $timezone = 'UTC', $offset = null) {
    self::epoch($startAt);
    if (!is_int($days) || $days < 0 || $days > 30 ||
        !is_string($timezone) ||
        !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ||
        ($offset !== null && (!is_int($offset) || abs($offset) > 86400))) {
      throw new InvalidArgumentException('organization_handover_calendar_refused');
    }
    $zone = new DateTimeZone($timezone);
    $start = (new DateTimeImmutable('@'.$startAt))->setTimezone($zone);
    $date = new DateTimeImmutable($start->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
    $date = $date->modify('+'.$days.' days');
    if ((int)$date->format('Y') > 9999) {
      throw new InvalidArgumentException('organization_handover_time_refused');
    }
    $local = $date->format('Y-m-d').' '.$start->format('H:i:s');
    $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new DateTimeZone('UTC'));
    $naive = $wall->getTimestamp();
    $candidates = array();
    $transitions=$zone->getTransitions($naive - 172800,$naive + 172800);
    // Named constant-offset TZDB aliases may be represented by PHP without a
    // transition table. Still validate the candidate through the real zone.
    if($transitions===false){$transitions=array(array('offset'=>$zone->getOffset($start)));}
    foreach ($transitions as $transition) {
      $candidate = $naive - $transition['offset'];
      if ($candidate < 0 || $candidate > 253402300799) { continue; }
      $observed = (new DateTimeImmutable('@'.$candidate))->setTimezone($zone);
      if ($observed->format('Y-m-d H:i:s') === $local) {
        $candidates[$transition['offset']] = $candidate;
      }
    }
    if (!$candidates) {
      // No offset can make this local wall time exist. A changed start time
      // needs another explicit preview, never silent PHP normalization.
      throw new InvalidArgumentException('organization_handover_nonexistent_local_time');
    }
    ksort($candidates, SORT_NUMERIC);
    if ($days === 0) {
      // The input instant already disambiguates its own local wall time.
      $candidates = array($start->getOffset() => $startAt);
    }
    if ($offset !== null && !array_key_exists($offset, $candidates)) {
      throw new InvalidArgumentException('organization_handover_offset_refused');
    }
    $resolved = $offset !== null ? $offset : (count($candidates) === 1 ? key($candidates) : null);
    return array(
      'start_at' => $startAt, 'deadline_at' => $resolved === null ? null : $candidates[$resolved],
      'timezone' => $timezone, 'days' => $days,
      'start_local' => $start->format('Y-m-d H:i:s'), 'deadline_local' => $local,
      'start_offset' => $start->getOffset(), 'deadline_offset' => $resolved,
      'resolution_required' => $resolved === null,
      'candidate_offsets' => array_keys($candidates));
  }

  public static function extension($previousDeadline, $days, $timezone, $cumulativeDays,
      $reason, $exceptionReason = null, $offset = null) {
    if (!is_int($days) || $days < 1 || $days > 30 || !is_int($cumulativeDays) ||
        $cumulativeDays < 0 || $cumulativeDays > 4294967295 - $days ||
        !self::reason($reason) || ($exceptionReason !== null && !self::reason($exceptionReason))) {
      throw new InvalidArgumentException('organization_handover_extension_refused');
    }
    $total = $cumulativeDays + $days;
    if ($total > 30 && $exceptionReason === null) {
      throw new InvalidArgumentException('organization_handover_cumulative_exception_required');
    }
    $result = self::preview($previousDeadline, $days, $timezone, $offset);
    $result['cumulative_days'] = $total;
    $result['requires_independent_approval'] = true;
    return $result;
  }

  private static function reason($value) {
    return is_string($value) && trim($value) !== '' && strlen($value) <= 1024 && phutil_is_utf8($value);
  }
}
