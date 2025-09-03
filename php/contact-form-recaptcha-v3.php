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

if (isset($_POST['g-recaptcha-response']) && !empty($_POST['g-recaptcha-response'])) {

  // reCAPTCHA secret (v3)
  $secret = '6LcymLsrAAAAAJgToeMrUyvt25XtuKH9YGE16eWz';

  // --- reCAPTCHA verify ---
  if (ini_get('allow_url_fopen')) {
    $verifyResponse = file_get_contents(
      'https://www.google.com/recaptcha/api/siteverify?secret='
      . urlencode($secret) . '&response=' . urlencode($_POST['g-recaptcha-response'])
      . '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? '')
    );
    $responseData = json_decode($verifyResponse);
  } else if (function_exists('curl_version')) {
    $fields = [
      'secret'   => $secret,
      'response' => $_POST['g-recaptcha-response'],
      'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    $ch = curl_init("https://www.google.com/recaptcha/api/siteverify");
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_POSTFIELDS     => http_build_query($fields)
    ]);
    $responseData = json_decode(curl_exec($ch));
    curl_close($ch);
  } else {
    echo json_encode(['response'=>'error','errorMessage'=>'Sunucuda CURL veya file_get_contents() yok.']); exit;
  }

  if (!empty($responseData->success)) {

    /* --------- SMTP CONFIG --------- */
    $recipientEmail = 'email';  // kime gelsin
    $smtpHost = 'host';
    $smtpUser = 'mail';
    $smtpPass = 'şifre';             // <-- değiştir
    $smtpPort = 465;
    $smtpSecure = PHPMailer::ENCRYPTION_SMTPS; // 465/SSL
    $debug = 0;                                // sorun olursa 2 yap
    /* -------------------------------- */

    // --- FORM alanları ---
    $name       = trim($_POST['name']    ?? '');
    $emailIn    = trim($_POST['email']   ?? '');
    $telefon    = trim($_POST['telefon'] ?? '');
    $subjectInp = trim($_POST['subject'] ?? 'İletişim Formu');
    $messageInp = trim($_POST['message'] ?? '');

	// 1) Dizi olarak almaya çalış
	$prefArr = filter_input(INPUT_POST, 'iletisim_tercihi', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if ($prefArr === null) {
	$prefArr = filter_input(INPUT_POST, 'İletişim Tercihi', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	}

	// 2) Eğer dizi yoksa / tek değerse, joined fallback’i dene
	if (!is_array($prefArr) || count($prefArr) < 2) {
	$joined = $_POST['iletisim_tercihi_joined'] ?? '';
	if ($joined) {
		$prefArr = array_filter(array_map('trim', explode(',', $joined)));
	} elseif (!is_array($prefArr) && $prefArr !== null && $prefArr !== '') {
		$prefArr = [$prefArr];
	} else {
		$prefArr = $prefArr ?: [];
	}
	}

	$prefPretty = implode(', ', array_map('strval', $prefArr));


    // Basit validasyon
    if ($name === '' || $emailIn === '' || $messageInp === '') {
      echo json_encode(['response'=>'error','errorMessage'=>'Lütfen zorunlu alanları doldurunuz.']); exit;
    }
	// Tarih formatını TR ve UTF-8 güvenli verir
	function trDateString(\DateTime $dt): string {
		if (class_exists('IntlDateFormatter')) {
			$fmt = new \IntlDateFormatter(
				'tr_TR',
				\IntlDateFormatter::NONE,
				\IntlDateFormatter::NONE,
				'Europe/Istanbul',
				\IntlDateFormatter::GREGORIAN,
				'd MMMM yyyy HH:mm'
			);
			return $fmt->format($dt);
		}
		// Fallback: manuel TR ay isimleri (her koşulda UTF-8)
		$months = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
		$m = (int)$dt->format('n');
		return $dt->format('j ').$months[$m].$dt->format(' Y H:i');
	}
    // --- Mail içerik (HTML + Text) ---
	$dt   = new \DateTime('now', new \DateTimeZone('Europe/Istanbul'));
	$when = $dt->format('d.m.Y H:i'); 
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Satırlar
    $rows = [
      ['Ad Soyad',         htmlspecialchars($name, ENT_QUOTES, 'UTF-8')],
      ['E-posta',          '<a href="mailto:'.htmlspecialchars($emailIn, ENT_QUOTES, 'UTF-8').'" style="color:#0d6efd;text-decoration:none;">'.htmlspecialchars($emailIn, ENT_QUOTES, 'UTF-8').'</a>'],
       ['Telefon',          htmlspecialchars($telefon, ENT_QUOTES, 'UTF-8')],
      ['Konu',             htmlspecialchars($subjectInp, ENT_QUOTES, 'UTF-8')],
      ['İletişim Tercihi', htmlspecialchars($prefPretty, ENT_QUOTES, 'UTF-8')],
      ['Mesaj',            nl2br(htmlspecialchars($messageInp, ENT_QUOTES, 'UTF-8'))],
    ];

    $rowsHtml = '';
    foreach ($rows as [$k,$v]) {
      $rowsHtml .= '<tr>
        <td style="padding:10px 0;width:160px;font-weight:600;color:#555;">'.htmlspecialchars($k, ENT_QUOTES, "UTF-8").'</td>
        <td style="padding:10px 0;">'.$v.'</td>
      </tr>';
    }

	

    $bodyHtml = '
    <!doctype html>
    <html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>İletişim Mesajı</title></head>
    <body style="background:#f6f8fb;margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#222;">
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e8ecf3;">
        <tr><td style="padding:20px 24px;background:#136182;color:#fff;">
          <h2 style="margin:0;font-size:18px;font-weight:700;">Yeni İletişim Mesajı</h2>
          <p style="margin:6px 0 0;font-size:13px;opacity:.9;">'.$when.' • '.$ip.'</p>
        </td></tr>
        <tr><td style="padding:20px 24px;">
          <table role="presentation" width="100%" style="border-collapse:collapse;">'.$rowsHtml.'</table>
          
        </td></tr>
      </table>
    </body></html>';

    $bodyText = "Yeni İletişim Mesajı\n"
              . "Tarih/IP: {$when} / {$ip}\n\n"
              . "Ad Soyad: {$name}\n"
              . "E-posta: {$emailIn}\n"
              . "Telefon: {$telefon}\n"
              . "Konu: {$subjectInp}\n"
              . "İletişim Tercihi: {$prefPretty}\n\n"
              . "Mesaj:\n{$messageInp}\n";

    // --- Gönder ---
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
      $mail->SMTPAutoTLS = false; // 465/SSL için

      // From (çoğu sunucu From=Username ister; güvenli seçim)
      $mail->setFrom($smtpUser, 'Salute Website');

      // To (alıcı)
      $mail->addAddress($recipientEmail);

      // Reply-To (kullanıcı)
      if (filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($emailIn, $name ?: 'Website User');
      }

      $mail->isHTML(true);
      $mail->CharSet = 'UTF-8';
      $mail->Subject = 'İletişim Formu - ' . $subjectInp;
      $mail->Body    = $bodyHtml;
      $mail->AltBody = $bodyText;

      $mail->send();
      echo json_encode(['response'=>'success']);

    } catch (Exception $e) {
      echo json_encode(['response'=>'error','errorMessage'=>$e->errorMessage()]);
    } catch (\Exception $e) {
      echo json_encode(['response'=>'error','errorMessage'=>$e->getMessage()]);
    }

  } else {
    echo json_encode(['response'=>'error','errorMessage'=>'reCaptcha Error: Doğrulama başarısız oldu.']);
  }

} else {
  echo json_encode(['response'=>'error','errorMessage'=>'reCaptcha Error: Geçersiz token.']);
}
