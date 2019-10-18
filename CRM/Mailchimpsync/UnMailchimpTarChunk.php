<?php
class CRM_Mailchimpsync_UnMailchimpTarChunk implements JsonSerializable {
  public $filename;
  public $length;
  public $type;
  public $is_null;

  public function __construct($data) {
    if (strlen($data) !== 512) {
      throw new InvalidArgumentException("require 512 bytes, got " . strlen($data));
    }
    $length = strlen($data);
    if ($length < 157) {
      throw new InvalidArgumentException("Received chunk of tar file that is only $length bytes. This is not long enough for even the headers.");
    }
    else {
      $this->is_null = ($data === str_repeat("\x00", 512));
    }
    if (!$this->is_null) {
      $this->filename = rtrim(substr($data, 0, 100), "\x00");
      $this->type = $data[156];
      $this->length = octdec(ltrim(rtrim(substr($data, 124, 12), "\x00"),'0'));
    }
  }
  public function jsonSerialize() {
    return [
      'filename' => $this->filename,
      'length' => $this->length,
      'type' => $this->type,
    ];
  }
  /**
   * Is it a directory?
   *
   * @return bool
   */
  public function isDirectory() {
    return ($this->type === '5');
  }
}

