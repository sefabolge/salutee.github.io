<?php
namespace PortoContactForm;

ini_set('allow_url_fopen', true);
session_cache_limiter('nocache');
header('Expires: ' . gmdate('r', 0));
header('Content-type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'php-mailer/src/PHPMailer.php';
require 'php-mailer/src/SMTP.php';
require 'php-mailer/src/Exception.php';

/* ====== CONFIG ====== */
$recipientEmail = 'sefa@loadingsoft.com';       // Başvurular buraya gelsin
$smtpHost       = 'mail.loadingsoft.com';
$smtpUser       = 'sefa@loadingsoft.com';
$smtpPass       = 'Sail@away!1122';
$smtpPort       = 465;
$smtpSecure     = PHPMailer::ENCRYPTION_SMTPS;   // 465/SSL
$debug          = 0;

$secret         = '6LcymLsrAAAAAJgToeMrUyvt25XtuKH9YGE16eWz';    // reCAPTCHA v3 secret
/* ==================== */

// (İsteğe bağlı) Honeypot
if (!empty($_POST['website'] ?? '')) {
  echo json_encode(['response'=>'error','errorMessage'=>'Bot algılandı.']); exit;
}

// reCAPTCHA kontrolü
if (!isset($_POST['g-recaptcha-response']) || empty($_POST['g-recaptcha-response'])) {
  echo json_encode(['response'=>'error','errorMessage'=>'reCaptcha Error: Geçersiz token.']); exit;
}

// Verify reCAPTCHA
$verifyFields = [
  'secret'   => $secret,
  'response' => $_POST['g-recaptcha-response'],
  'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
];

if (ini_get('allow_url_fopen')) {
  $verifyResponse = file_get_contents('https://www.google.com/recaptcha/api/siteverify?'.http_build_query($verifyFields));
  $responseData = json_decode($verifyResponse);
} else if (function_exists('curl_version')) {
  $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query($verifyFields),
  ]);
  $responseData = json_decode(curl_exec($ch));
  curl_close($ch);
} else {
  echo json_encode(['response'=>'error','errorMessage'=>'Sunucuda CURL veya file_get_contents yok.']); exit;
}

if (empty($responseData->success)) {
  echo json_encode(['response'=>'error','errorMessage'=>'reCaptcha Error: Doğrulama başarısız.']); exit;
}

/* ---- Form Verileri ---- */
$name       = trim($_POST['name']    ?? '');
$emailIn    = trim($_POST['email']   ?? '');
$telefonRaw = $_POST['telefon']      ?? '';
$telefon    = preg_replace('/\D+/', '', $telefonRaw); // rakam dışını temizle
$subjectInp = trim($_POST['subject'] ?? 'Genel Başvuru');
$messageInp = trim($_POST['message'] ?? '');

// Basit validasyon
if ($name === '' || $emailIn === '' || $messageInp === '') {
  echo json_encode(['response'=>'error','errorMessage'=>'Lütfen zorunlu alanları doldurunuz.']); exit;
}
// Telefon: 0 ile başlayan 11 hane (TR cep)
if (!preg_match('/^0\d{10}$/', $telefon)) {
  echo json_encode(['response'=>'error','errorMessage'=>'Telefon numarası geçersiz. 0 ile başlayan 11 haneli bir numara giriniz.']); exit;
}
$telefonFormatted = preg_replace('/^0(\d{3})(\d{3})(\d{2})(\d{2})$/', '0$1 $2 $3 $4', $telefon);

// Tarih (TR saati, sayısal)
$dt   = new \DateTime('now', new \DateTimeZone('Europe/Istanbul'));
$when = $dt->format('d.m.Y H:i');

/* ---- Mail İçerik ---- */
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';
$agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$rows = [
  ['Ad Soyad',  htmlspecialchars($name, ENT_QUOTES, 'UTF-8')],
  ['E-posta',   '<a href="mailto:'.htmlspecialchars($emailIn, ENT_QUOTES, 'UTF-8').'" style="color:#0d6efd;text-decoration:none;">'.htmlspecialchars($emailIn, ENT_QUOTES, 'UTF-8').'</a>'],
  ['Telefon',   htmlspecialchars($telefonFormatted, ENT_QUOTES, 'UTF-8')],
  ['Pozisyon',  htmlspecialchars($subjectInp, ENT_QUOTES, 'UTF-8')],
  ['Mesaj',     nl2br(htmlspecialchars($messageInp, ENT_QUOTES, 'UTF-8'))],
];

$rowsHtml = '';
foreach ($rows as [$k,$v]) {
  $rowsHtml .= '<tr>
    <td style="padding:10px 0;width:160px;font-weight:600;color:#555;">'.htmlspecialchars($k, ENT_QUOTES, 'UTF-8').'</td>
    <td style="padding:10px 0;">'.$v.'</td>
  </tr>';
}

$bodyHtml = '
<!doctype html>
<html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kariyer Başvurusu</title></head>
<body style="background:#f6f8fb;margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#222;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:680px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e8ecf3;">
    <tr><td style="padding:20px 24px;background:#0d6efd;color:#fff;">
      <h2 style="margin:0;font-size:18px;font-weight:700;">Yeni Kariyer Başvurusu</h2>
      <p style="margin:6px 0 0;font-size:13px;opacity:.9;">'.$when.' • '.$ip.'</p>
    </td></tr>
    <tr><td style="padding:20px 24px;">
      <table role="presentation" width="100%" style="border-collapse:collapse;">'.$rowsHtml.'</table>
    </td></tr>
  </table>
</body></html>';

$bodyText = "Yeni Kariyer Başvurusu\n"
          . "Tarih/IP: {$when} / {$ip}\n\n"
          . "Ad Soyad: {$name}\n"
          . "E-posta: {$emailIn}\n"
          . "Telefon: {$telefonFormatted}\n"
          . "Pozisyon: {$subjectInp}\n\n"
          . "Mesaj:\n{$messageInp}\n";

/* ---- Gönder ---- */
$mail = new PHPMailer(true);
try {
  $mail->SMTPDebug  = $debug;
  $mail->isSMTP();
  $mail->Host       = $smtpHost;
  $mail->SMTPAuth   = true;
  $mail->Username   = $smtpUser;
  $mail->Password   = $smtpPass;
  $mail->SMTPSecure = $smtpSecure;
  $mail->Port       = $smtpPort;
  $mail->SMTPAutoTLS = false; // 465/SSL

  // From = username kullanmak güvenli
  $mail->setFrom($smtpUser, 'Salute Website - Kariyer');
  $mail->addAddress($recipientEmail);

  if (filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
    $mail->addReplyTo($emailIn, $name ?: 'Aday');
  }

  $mail->isHTML(true);
  $mail->CharSet = 'UTF-8';
  $mail->Subject = 'Kariyer Başvurusu - ' . $subjectInp . ' - ' . $name;
  $mail->Body    = $bodyHtml;
  $mail->AltBody = $bodyText;

  $mail->send();
  echo json_encode(['response'=>'success']);

} catch (Exception $e) {
  echo json_encode(['response'=>'error','errorMessage'=>$e->errorMessage()]);
} catch (\Exception $e) {
  echo json_encode(['response'=>'error','errorMessage'=>$e->getMessage()]);
}
