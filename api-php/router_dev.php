<?php
// Router untuk server pengembangan/uji: php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php
// Meneruskan semua permintaan ke front controller (tidak ada file statis di api-php).
require __DIR__ . '/index.php';
