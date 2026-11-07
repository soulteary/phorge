<?php
/** Calendar-only thin adapter over the reviewed keeper-owned queue shutdown. */
require_once __DIR__.'/notice-history-browser.php';
function governance_calendar_wire_queue_quiesce($fixture,$directory,$owner,&$queue,array &$cleanup,callable $write) {
  $calendar_write=static function($name,array $value)use($write){
    if($name!=='history-queue-quiesced.json'||($value['scope']??null)!=='owned-notice-history-queue-quiescence'){
      throw new RuntimeException('calendar_wire_quiescence_projection_refused');}
    $value['scope']='owned-calendar-wire-queue-quiescence';$write($name,$value);
  };
  return governance_notice_history_queue_quiesce($fixture,$directory,$owner,$queue,$cleanup,$calendar_write);
}
