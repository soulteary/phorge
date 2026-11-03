<?php

/** Isolated bridge policy: never inherit the application CDN script policy. */
final class PhorgeGovernanceMediaHTTPResponse extends AphrontResponse {
  private $body='';
  private $mediaHeaders=array();
  public function setBody($body){$this->body=$body;return $this;}
  public function withHeader($name,$value){$this->mediaHeaders[]=array($name,$value);return $this;}
  public function buildResponseString(){return $this->body;}
  public function getHeaders(){return array_merge(array(
    array('Cache-Control','no-store, private'),array('Pragma','no-cache'),
    array('X-Content-Type-Options','nosniff'),array('Referrer-Policy','strict-origin'),
    array('Permissions-Policy','camera=(), microphone=(), geolocation=()'),
    array('Strict-Transport-Security','max-age=31536000')), $this->mediaHeaders);}
}
