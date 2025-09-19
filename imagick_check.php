<?php
try {
  $in = __DIR__ . '/test.jpg';
  $out = __DIR__ . '/test_out.pdf';
  $im = new Imagick($in);
  $im->setImageFormat('pdf');
  $im->writeImages($out, true);
  echo "OK: created {$out}";
} catch (Exception $e) {
  echo "ERR: " . $e->getMessage();
}
