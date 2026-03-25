<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['message' => 'Method not allowed.']);
  exit;
}

$config = require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/SmtpMailer.php';

function fail(int $code, string $msg) {
  http_response_code($code);
  echo json_encode(['message' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}

function normalize_newlines(string $text): string {
  return str_replace(["\r\n", "\r"], "\n", $text);
}

function is_upload_limit_error(int $err): bool {
  return in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
}

function create_image_resource(string $tmp, string $mime) {
  return match ($mime) {
    'image/jpeg' => @imagecreatefromjpeg($tmp),
    'image/png' => @imagecreatefrompng($tmp),
    'image/gif' => @imagecreatefromgif($tmp),
    'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
    default => false,
  };
}

function resize_image_blob(string $tmp, string $mime, int $maxWidth, int $maxHeight, int $jpegQuality, int $webpQuality): ?array {
  if (!function_exists('imagecreatetruecolor')) {
    return null;
  }

  $size = @getimagesize($tmp);
  if (!$size || empty($size[0]) || empty($size[1])) {
    return null;
  }

  [$srcWidth, $srcHeight] = $size;
  if ($srcWidth <= $maxWidth && $srcHeight <= $maxHeight) {
    return null;
  }

  $src = create_image_resource($tmp, $mime);
  if (!$src) {
    return null;
  }

  $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
  $newWidth = max(1, (int) floor($srcWidth * $ratio));
  $newHeight = max(1, (int) floor($srcHeight * $ratio));

  $dst = imagecreatetruecolor($newWidth, $newHeight);
  if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
  }

  imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

  ob_start();
  $written = false;
  switch ($mime) {
    case 'image/jpeg':
      $written = imagejpeg($dst, null, $jpegQuality);
      break;
    case 'image/png':
      $written = imagepng($dst, null, 6);
      break;
    case 'image/gif':
      $written = imagegif($dst);
      break;
    case 'image/webp':
      if (function_exists('imagewebp')) {
        $written = imagewebp($dst, null, $webpQuality);
      }
      break;
  }
  $blob = $written ? ob_get_clean() : null;
  if (!$written) {
    ob_end_clean();
  }

  imagedestroy($src);
  imagedestroy($dst);

  if (!$blob) {
    return null;
  }

  return [
    'content' => $blob,
    'width' => $newWidth,
    'height' => $newHeight,
  ];
}

function save_failed_submission(array $payload, array $attachments, string $dir): ?string {
  ensure_dir($dir);
  if (!is_dir($dir) || !is_writable($dir)) {
    return null;
  }

  $id = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
  $base = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $id;
  if (!@mkdir($base, 0775, true) && !is_dir($base)) {
    return null;
  }

  @file_put_contents($base . DIRECTORY_SEPARATOR . 'application.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  foreach ($attachments as $index => $att) {
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($att['filename'] ?? ('photo_' . ($index + 1))));
    if ($name === '') {
      $name = 'photo_' . ($index + 1);
    }
    @file_put_contents($base . DIRECTORY_SEPARATOR . $name, $att['content']);
  }
  return $id;
}

$lang = ($_POST['lang'] ?? 'tr') === 'en' ? 'en' : 'tr';

$messages = [
  'tr' => [
    'missing' => 'Zorunlu alanlar eksik.',
    'consent' => 'KVKK onayı gerekli.',
    'about' => 'Hakkınızda alanı en az 100 karakter olmalıdır.',
    'jobs' => 'En az 1 iş seçiniz.',
    'photos_required' => 'Fotoğraflar zorunludur.',
    'photos_min' => 'En az %d fotoğraf yüklemelisiniz.',
    'photos_max' => 'En fazla %d fotoğraf yükleyebilirsiniz.',
    'upload_limit' => 'Fotoğraf boyutu çok büyük. Lütfen 5MB\'dan küçük bir fotoğraf yükleyin.',
    'upload_error' => 'Dosya yükleme hatası oluştu. Lütfen fotoğrafları küçültüp tekrar deneyin.',
    'only_images' => 'Sadece resim dosyaları kabul edilir.',
    'invalid_image' => 'Geçersiz resim dosyası.',
    'read_error' => 'Yüklenen dosya okunamadı.',
    'submit_ok' => 'Başarıyla gönderildi.',
    'server_error' => 'Sunucu hatası: ',
    'backup_saved' => ' Başvurunuz yedek olarak kaydedildi.',
  ],
  'en' => [
    'missing' => 'Missing required fields.',
    'consent' => 'Consent required.',
    'about' => 'About you must be at least 100 characters.',
    'jobs' => 'Select at least 1 job.',
    'photos_required' => 'Photos are required.',
    'photos_min' => 'At least %d photos are required.',
    'photos_max' => 'Maximum %d photos allowed.',
    'upload_limit' => 'A photo is too large. Please upload a photo smaller than 5MB.',
    'upload_error' => 'File upload error. Please resize the photos and try again.',
    'only_images' => 'Only image files are allowed.',
    'invalid_image' => 'Invalid image file.',
    'read_error' => 'Could not read uploaded file.',
    'submit_ok' => 'Submitted successfully.',
    'server_error' => 'Server error: ',
    'backup_saved' => ' Your application has been saved as a backup.',
  ],
];
$t = $messages[$lang];

$required = ['fullName','age','phone','city','height','weight','bust','hips','aboutYou','jobs','kvkkAccept'];
foreach ($required as $k) {
  if (!isset($_POST[$k]) || trim((string)$_POST[$k]) === '') {
    fail(400, $t['missing']);
  }
}
if (($_POST['kvkkAccept'] ?? '') !== 'true') {
  fail(400, $t['consent']);
}

$aboutYou = trim((string)$_POST['aboutYou']);
if (mb_strlen($aboutYou, 'UTF-8') < 100) {
  fail(400, $t['about']);
}

$jobs = json_decode((string)$_POST['jobs'], true);
if (!is_array($jobs) || count($jobs) < 1) {
  fail(400, $t['jobs']);
}

$files = $_FILES['photos'] ?? null;
if (!$files || !isset($files['name']) || !is_array($files['name'])) {
  fail(400, $t['photos_required']);
}

$minPhotos = (int)$config['min_photos'];
$maxPhotos = (int)$config['max_photos'];
$count = count($files['name']);
if ($count < $minPhotos) {
  fail(400, sprintf($t['photos_min'], $minPhotos));
}
if ($count > $maxPhotos) {
  fail(400, sprintf($t['photos_max'], $maxPhotos));
}

$serverMaxBytes = (int)$config['max_file_mb'] * 1024 * 1024;
$recommendedMaxBytes = (int)($config['client_recommended_mb'] ?? 5) * 1024 * 1024;
$maxWidth = (int)($config['image_max_width'] ?? 1600);
$maxHeight = (int)($config['image_max_height'] ?? 1600);
$jpegQuality = (int)($config['image_jpeg_quality'] ?? 82);
$webpQuality = (int)($config['image_webp_quality'] ?? 80);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
  'image/gif'  => 'gif',
  'image/heic' => 'heic',
  'image/heif' => 'heif'
];

$attachments = [];
$photoNotes = [];
for ($i = 0; $i < $count; $i++) {
  $err = (int)$files['error'][$i];
  if ($err !== UPLOAD_ERR_OK) {
    if (is_upload_limit_error($err)) {
      fail(400, $t['upload_limit']);
    }
    fail(400, $t['upload_error']);
  }

  $tmp = $files['tmp_name'][$i] ?? '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    fail(400, $t['upload_error']);
  }

  $mime = $finfo->file($tmp);
  if (!$mime || !isset($allowed[$mime])) {
    fail(400, $t['only_images']);
  }

  if (in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true) && @getimagesize($tmp) === false) {
    fail(400, $t['invalid_image']);
  }

  $original = (string)$files['name'][$i];
  $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $original);
  if ($safeName === '') {
    $safeName = 'photo_' . ($i + 1) . '.' . $allowed[$mime];
  }

  $sizeBytes = (int)$files['size'][$i];
  if ($sizeBytes > $serverMaxBytes) {
    fail(400, $t['upload_limit']);
  }

  $content = file_get_contents($tmp);
  if ($content === false) {
    fail(400, $t['read_error']);
  }

  $resized = null;
  if ($sizeBytes > $recommendedMaxBytes || in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
    $resized = resize_image_blob($tmp, $mime, $maxWidth, $maxHeight, $jpegQuality, $webpQuality);
    if ($resized && strlen($resized['content']) > 0 && strlen($resized['content']) < strlen($content)) {
      $content = $resized['content'];
      $photoNotes[] = $safeName . ' resized to ' . $resized['width'] . 'x' . $resized['height'];
    }
  }

  if (strlen($content) > $serverMaxBytes) {
    fail(400, $t['upload_limit']);
  }

  $attachments[] = [
    'filename' => $safeName,
    'contentType' => $mime,
    'content' => $content,
  ];
}

$fullName = trim((string)$_POST['fullName']);
$subjectPrefix = $lang === 'en' ? $config['subject_prefix_en'] : $config['subject_prefix_tr'];
$subject = $subjectPrefix . ' - ' . $fullName;
$applicationDate = date('d.m.Y H:i:s');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

$lines = [];
$lines[] = 'Ad Soyad: ' . $fullName;
$lines[] = 'Yaş: ' . trim((string)$_POST['age']);
$lines[] = 'Telefon: ' . trim((string)$_POST['phone']);
$lines[] = 'Şehir: ' . trim((string)$_POST['city']);
$lines[] = 'Boy (cm): ' . trim((string)$_POST['height']);
$lines[] = 'Kilo (kg): ' . trim((string)$_POST['weight']);
$lines[] = 'Göğüs (cm): ' . trim((string)$_POST['bust']);
$lines[] = 'Kalça (cm): ' . trim((string)$_POST['hips']);
$lines[] = 'Hakkınızda: ' . normalize_newlines($aboutYou);
$lines[] = 'İşler: ' . implode(', ', array_map('strval', $jobs));
$lines[] = 'Fotoğraf Sayısı: ' . (string)$count;
if ($photoNotes) {
  $lines[] = 'Fotoğraf İşleme: ' . implode(' | ', $photoNotes);
}
$lines[] = 'KVKK: Onaylandı';
$lines[] = 'Başvuru Tarihi: ' . $applicationDate;
$lines[] = 'IP Adresi: ' . $ipAddress;
$lines[] = 'Tarayıcı Bilgisi: ' . $userAgent;
$text = implode("\n", $lines) . "\n";

try {
  $debug = (bool)($config['debug'] ?? false);
  $debugLog = (string)($config['debug_log'] ?? '');

  $mailer = new SmtpMailer(
    (string)$config['smtp_host'],
    (int)$config['smtp_port'],
    (string)$config['smtp_user'],
    (string)$config['smtp_pass'],
    (string)$config['smtp_secure'],
    $debug,
    $debugLog
  );

  $mailer->send(
    (string)$config['from_email'],
    (string)$config['from_name'],
    (string)$config['to_email'],
    $subject,
    $text,
    $attachments
  );

  echo json_encode(['message' => $t['submit_ok']], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
  $backupId = save_failed_submission([
    'subject' => $subject,
    'application_date' => $applicationDate,
    'payload' => [
      'lang' => $lang,
      'fullName' => $fullName,
      'age' => trim((string)$_POST['age']),
      'phone' => trim((string)$_POST['phone']),
      'city' => trim((string)$_POST['city']),
      'height' => trim((string)$_POST['height']),
      'weight' => trim((string)$_POST['weight']),
      'bust' => trim((string)$_POST['bust']),
      'hips' => trim((string)$_POST['hips']),
      'aboutYou' => $aboutYou,
      'jobs' => $jobs,
      'kvkkAccept' => true,
    ],
    'server' => [
      'ip' => $ipAddress,
      'user_agent' => $userAgent,
    ],
    'error' => $e->getMessage(),
  ], $attachments, (string)($config['failed_submissions_dir'] ?? (__DIR__ . '/../storage/failed_submissions')));

  $message = $t['server_error'] . $e->getMessage();
  if ($backupId) {
    $message .= $t['backup_saved'];
  }
  fail(500, $message);
}
