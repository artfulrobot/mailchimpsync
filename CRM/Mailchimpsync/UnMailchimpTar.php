<?php
/**
 * Mailchimp's implementation of tar is not compatible with PHP's phar extension (which opens tars)
 *
 * $t = new CRM_Mailchimpsync_UnMailchimpTar('/path/to/file.tar');
 * $t->open();
 *
 */
class CRM_Mailchimpsync_UnMailchimpTar
{
  public $source_filename;
  protected $source_handle;

  public function __construct($filename) {
    $this->source_filename = $filename;
  }
  public function getNextFile() {
    if (!$this->source_handle) {
      $this->source_handle = gzopen($this->source_filename, 'r');
    }

    while (!feof($this->source_handle)) {
      $data = fread($this->source_handle, 512);

      $chunk = new CRM_Mailchimpsync_UnMailchimpTarChunk($data);

      if ($chunk->isDirectory()) {
        continue;
      }
      if ($chunk->is_null) {
        break;
      }
      // Found a file.
      return ['filename' => $chunk->filename, 'data' => $this->readFile($chunk)];
    }
  }
  public function readFile(CRM_Mailchimpsync_UnMailchimpTarChunk $header) {
    $length = ceil((int) $header->length / 512) * 512;
    if ($length < 1) {
      throw new InvalidArgumentException("Refusing to read $length bytes.");
    }
    $data = substr(fread($this->source_handle, $length), 0, $header->length);
    $data = json_decode($data, TRUE);
    if ($data === FALSE) {
      throw new \InvalidArgumentException("Failed to decode file as json");
    }
    foreach ($data as &$row) {
      if (isset($row['response'])) {
        $row['response'] = json_decode($row['response'], TRUE);
        if (!$row['response']) {
          throw new \InvalidArgumentException("Failed to decode response as json");
        }
      }

    }

    return $data;
  }
}
