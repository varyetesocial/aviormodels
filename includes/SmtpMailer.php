<?php
// includes/SmtpMailer.php
// Improved minimal SMTP mailer with attachments (AUTH LOGIN/PLAIN + optional STARTTLS/SSL + debug log)

class SmtpMailer {
  private string $host;
  private int $port;
  private string $user;
  private string $pass;
  private string $secure; // 'tls'|'ssl'|''
  private bool $debug;
  private string $debugLog;
  private array $trace = [];

  public function __construct(string $host, int $port, string $user, string $pass, string $secure='', bool $debug=false, string $debugLog='') {
    $this->host=$host; $this->port=$port; $this->user=$user; $this->pass=$pass; $this->secure=$secure;
    $this->debug=$debug;
    $this->debugLog=$debugLog;
  }

  private function log(string $line): void {
    $this->trace[] = $line;
    if ($this->debug && $this->debugLog) {
      @file_put_contents($this->debugLog, "[".date('c')."] ".$line."\n", FILE_APPEND);
    }
  }

  private function read($fp): string {
    $data='';
    while (($line = fgets($fp, 515)) !== false) {
      $data .= $line;
      if (preg_match('/^\d{3}\s/', $line)) break;
    }
    $this->log("S: ".trim($data));
    return $data;
  }

  private function write($fp, string $cmd, bool $hide=false): void {
    $this->log("C: ".($hide ? "[hidden]" : $cmd));
    fwrite($fp, $cmd . "\r\n");
  }

  private function expect($fp, array $codes): void {
    $resp = $this->read($fp);
    $ok = false;
    foreach ($codes as $c) {
      if (preg_match('/^' . preg_quote((string)$c, '/') . '/', $resp)) { $ok = true; break; }
    }
    if (!$ok) {
      throw new Exception("SMTP error. Expected ".implode('/', $codes).". Got: ".trim($resp));
    }
  }

  private function b64(string $s): string { return base64_encode($s); }

  private function supportsAuthPlain(string $ehlo): bool {
    return (bool)preg_match('/\bAUTH\b.*\bPLAIN\b/i', $ehlo);
  }
  private function supportsAuthLogin(string $ehlo): bool {
    return (bool)preg_match('/\bAUTH\b.*\bLOGIN\b/i', $ehlo);
  }

  public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $textBody, array $attachments=[]): void {
    // Normalize newlines to CRLF for SMTP compliance
    $textBody = str_replace(["
", ""], ["
", "
"], $textBody);
    $textBody = str_replace("
", "
", $textBody);
    $remote = ($this->secure === 'ssl') ? "ssl://{$this->host}" : $this->host;

    $fp = fsockopen($remote, $this->port, $errno, $errstr, 25);
    if (!$fp) throw new Exception("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 25);

    $this->expect($fp, [220]);

    $this->write($fp, "EHLO localhost");
    $ehlo = $this->read($fp);
    if (!preg_match('/^250/', $ehlo)) {
      $this->write($fp, "HELO localhost");
      $this->expect($fp, [250]);
      $ehlo = "";
    }

    if ($this->secure === 'tls') {
      $this->write($fp, "STARTTLS");
      $this->expect($fp, [220]);
      if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new Exception("STARTTLS failed.");
      }
      $this->write($fp, "EHLO localhost");
      $ehlo = $this->read($fp);
      if (!preg_match('/^250/', $ehlo)) throw new Exception("EHLO after STARTTLS failed.");
    }

    // AUTH
    $didAuth = false;
    if ($ehlo && $this->supportsAuthLogin($ehlo)) {
      // AUTH LOGIN
      $this->write($fp, "AUTH LOGIN");
      $this->expect($fp, [334]);
      $this->write($fp, $this->b64($this->user), true);
      $this->expect($fp, [334]);
      $this->write($fp, $this->b64($this->pass), true);
      $this->expect($fp, [235]);
      $didAuth = true;
    } elseif ($ehlo && $this->supportsAuthPlain($ehlo)) {
      // AUTH PLAIN base64(\0user\0pass)
      $plain = base64_encode("\0".$this->user."\0".$this->pass);
      $this->write($fp, "AUTH PLAIN ".$plain, true);
      $this->expect($fp, [235]);
      $didAuth = true;
    } else {
      // fallback try LOGIN anyway
      $this->write($fp, "AUTH LOGIN");
      $this->expect($fp, [334]);
      $this->write($fp, $this->b64($this->user), true);
      $this->expect($fp, [334]);
      $this->write($fp, $this->b64($this->pass), true);
      $this->expect($fp, [235]);
      $didAuth = true;
    }

    if (!$didAuth) throw new Exception("SMTP auth failed.");

    $this->write($fp, "MAIL FROM:<{$fromEmail}>");
    $this->expect($fp, [250]);

    $this->write($fp, "RCPT TO:<{$toEmail}>");
    $this->expect($fp, [250, 251]);

    $this->write($fp, "DATA");
    $this->expect($fp, [354]);

    $boundary = "=_BOUNDARY_" . bin2hex(random_bytes(10));
    $headers = [];
    $headers[] = "From: " . $this->encodeHeader($fromName) . " <{$fromEmail}>";
    $headers[] = "To: <{$toEmail}>";
    $headers[] = "Subject: " . $this->encodeHeader($subject);
    $headers[] = "Date: " . date('r');
    $headers[] = "Message-ID: <" . bin2hex(random_bytes(12)) . "@localhost>";
    $headers[] = "MIME-Version: 1.0";

    if (count($attachments) > 0) {
      $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
      $message = "";
      $message .= "--{$boundary}\r\n";
      $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
      $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
      $message .= $textBody . "\r\n\r\n";

      foreach ($attachments as $att) {
        $filename = $att['filename'];
        $contentType = $att['contentType'] ?? "application/octet-stream";
        $content = $att['content'];

        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: {$contentType}; name=\"" . $this->escapeQuotes($filename) . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . $this->escapeQuotes($filename) . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode($content)) . "\r\n";
      }
      $message .= "--{$boundary}--\r\n";
    } else {
      $headers[] = "Content-Type: text/plain; charset=UTF-8";
      $headers[] = "Content-Transfer-Encoding: 8bit";
      $message = $textBody . "\r\n";
    }

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $message;

    // Dot-stuffing: lines beginning with "." must be prefixed with another "."
    $data = preg_replace("/\r\n\./", "\r\n..", $data);

    // Send data; terminate with <CRLF>.<CRLF>
    $this->log("C: [DATA ".strlen($data)." bytes]");
    fwrite($fp, rtrim($data, "\r\n") . "\r\n.\r\n");
    $this->expect($fp, [250]);

    $this->write($fp, "QUIT");
    fclose($fp);
  }

  private function escapeQuotes(string $s): string {
    return str_replace(['\\','"'], ['\\\\','\"'], $s);
  }

  private function encodeHeader(string $s): string {
    if (preg_match('/[^\x20-\x7E]/', $s)) {
      return "=?UTF-8?B?" . base64_encode($s) . "?=";
    }
    return $s;
  }
}
