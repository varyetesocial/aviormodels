<?php
// includes/config.php
// SMTP ve alıcı ayarlarını burada doldurun.

return [
  // SMTP
  'smtp_host' => 'host113.ni.net.tr',
  'smtp_port' => 587,              // 465 (SSL) / 587 (STARTTLS)
  'smtp_user' => 'info@bodrumcastajans.com',
  'smtp_pass' => 'info2026!',
  'smtp_secure' => 'tls',          // 'tls' | 'ssl' | ''  (587 için tls, 465 için ssl)

  // Mail
  'from_email' => 'info@bodrumcastajans.com',
  'from_name'  => 'Bodrum Cast Ajans',
  'to_email'   => 'baris@barisarslan.com',
  'subject_prefix_tr' => 'Bodrum Cast Ajans',
  'subject_prefix_en' => 'Bodrum Cast Ajans',

  // Kısıtlar
  'min_photos' => 5,
  'max_photos' => 20,
  'max_file_mb' => 10,             // sunucu tarafı dosya başına üst limit (MB)
  'client_recommended_mb' => 5,    // kullanıcıya gösterilen önerilen üst limit (MB)
  'image_max_width' => 1600,
  'image_max_height' => 1600,
  'image_jpeg_quality' => 82,
  'image_webp_quality' => 80,
  'failed_submissions_dir' => __DIR__ . '/../storage/failed_submissions',

  // Debug (sorun tespiti için true yapabilirsiniz)
  'debug' => false,
  'debug_log' => __DIR__ . '/../smtp-debug.log',
];
