<?php

final class PhabricatorMailOutboundStatus
  extends Phobject {

  const STATUS_QUEUE = 'queued';
  const STATUS_SENT  = 'sent';
  const STATUS_FAIL  = 'fail';
  const STATUS_VOID  = 'void';
  const STATUS_UNKNOWN = 'unknown';
  const STATUS_EXPIRED = 'expired';


  public static function getStatusName($status_code) {
    $names = array(
      self::STATUS_QUEUE => pht('Queued'),
      self::STATUS_FAIL  => pht('Delivery Failed'),
      self::STATUS_SENT  => pht('Sent'),
      self::STATUS_UNKNOWN => pht('Submission Outcome Unknown'),
      self::STATUS_EXPIRED => pht('Expired'),
      self::STATUS_VOID  => pht('Voided'),
    );
    $status_code = coalesce($status_code, '?');
    return idx($names, $status_code, $status_code);
  }

  public static function getStatusIcon($status_code) {
    $icons = array(
      self::STATUS_QUEUE => 'fa-clock-o',
      self::STATUS_FAIL  => 'fa-warning',
      self::STATUS_SENT  => 'fa-envelope',
      self::STATUS_UNKNOWN => 'fa-question-circle',
      self::STATUS_EXPIRED => 'fa-clock-o',
      self::STATUS_VOID  => 'fa-trash',
    );
    return idx($icons, $status_code, 'fa-question-circle');
  }

  public static function getStatusColor($status_code) {
    $colors = array(
      self::STATUS_QUEUE => 'blue',
      self::STATUS_FAIL  => 'red',
      self::STATUS_SENT  => 'green',
      self::STATUS_UNKNOWN => 'yellow',
      self::STATUS_EXPIRED => 'grey',
      self::STATUS_VOID  => 'black',
    );

    return idx($colors, $status_code, 'yellow');
  }

}
