<?php
declare(strict_types=1);

session_start();

const MAX_UPLOAD_SIZE = 104857600;
const STORAGE_DIR = __DIR__ . '/files';
const HASH_INDEX_FILE = STORAGE_DIR . '/hash_index.json';
const STATS_FILE = STORAGE_DIR . '/stats.json';

function ensureStorageDir(): void
{
    if (!is_dir(STORAGE_DIR)) {
        mkdir(STORAGE_DIR, 0775, true);
    }
}

function createFileId(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $id = '';

    for ($i = 0; $i < $length; $i++) {
        $id .= $alphabet[random_int(0, $max)];
    }

    return $id;
}

function normalizeExtension(string $originalName): string
{
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $ext = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $ext));
    return substr($ext, 0, 20);
}

function loadHashIndex(): array
{
    if (!is_file(HASH_INDEX_FILE)) {
        return [];
    }

    $raw = file_get_contents(HASH_INDEX_FILE);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveHashIndex(array $index): void
{
    file_put_contents(HASH_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function loadStats(): array
{
    ensureStorageDir();
    if (!is_file(STATS_FILE)) {
        return [
            'created_at' => time(),
            'uploaded_bytes' => 0,
            'downloaded_bytes' => 0,
        ];
    }

    $raw = file_get_contents(STATS_FILE);
    if ($raw === false || $raw === '') {
        return [
            'created_at' => time(),
            'uploaded_bytes' => 0,
            'downloaded_bytes' => 0,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'created_at' => time(),
            'uploaded_bytes' => 0,
            'downloaded_bytes' => 0,
        ];
    }

    return [
        'created_at' => isset($decoded['created_at']) ? (int) $decoded['created_at'] : time(),
        'uploaded_bytes' => isset($decoded['uploaded_bytes']) ? (int) $decoded['uploaded_bytes'] : 0,
        'downloaded_bytes' => isset($decoded['downloaded_bytes']) ? (int) $decoded['downloaded_bytes'] : 0,
    ];
}

function saveStats(array $stats): void
{
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function addStatsBytes(string $type, int $bytes): void
{
    if ($bytes <= 0) {
        return;
    }

    $stats = loadStats();
    if ($type === 'upload') {
        $stats['uploaded_bytes'] += $bytes;
    } elseif ($type === 'download') {
        $stats['downloaded_bytes'] += $bytes;
    }

    saveStats($stats);
}

function getAverageBytesPerDay(): int
{
    $stats = loadStats();
    $totalBytes = (int) $stats['uploaded_bytes'] + (int) $stats['downloaded_bytes'];
    $createdAt = max(1, (int) $stats['created_at']);
    $days = max(1, (int) ceil((time() - $createdAt + 1) / 86400));

    return (int) floor($totalBytes / $days);
}

function outputCaptchaImage(string $text): void
{
    $width = 120;
    $height = 36;
    $image = imagecreatetruecolor($width, $height);

    $bg = imagecolorallocate($image, 248, 248, 248);
    imagefilledrectangle($image, 0, 0, $width, $height, $bg);

    for ($i = 0; $i < 9; $i++) {
        $lineColor = imagecolorallocate(
            $image,
            random_int(100, 220),
            random_int(100, 220),
            random_int(100, 220)
        );
        imageline(
            $image,
            random_int(0, $width),
            random_int(0, $height),
            random_int(0, $width),
            random_int(0, $height),
            $lineColor
        );
    }

    for ($i = 0; $i < 500; $i++) {
        $dotColor = imagecolorallocate(
            $image,
            random_int(120, 255),
            random_int(120, 255),
            random_int(120, 255)
        );
        imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dotColor);
    }

    $chars = str_split($text);
    $x = 10;
    foreach ($chars as $char) {
        $textColor = imagecolorallocate(
            $image,
            random_int(0, 120),
            random_int(0, 120),
            random_int(0, 120)
        );
        $y = random_int(9, 16);
        imagestring($image, 4, $x, $y, $char, $textColor);
        imagestring($image, 4, $x + 1, $y, $char, $textColor);
        $x += random_int(21, 24);
    }

    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    imagepng($image);
    imagedestroy($image);
}

function serveDownload(string $id, string $ext = ''): void
{
    ensureStorageDir();

    $id = preg_replace('/[^A-Za-z0-9]/', '', $id) ?? '';
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?? '');

    if ($id === '') {
        http_response_code(404);
        exit('File not found.');
    }

    $path = STORAGE_DIR . '/' . $id . ($ext !== '' ? '.' . $ext : '');

    if (!is_file($path)) {
        $matches = glob(STORAGE_DIR . '/' . $id . '.*');
        if (!empty($matches)) {
            $path = $matches[0];
            $ext = pathinfo($path, PATHINFO_EXTENSION);
        }
    }

    if (!is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }

    $fileSize = filesize($path);
    if ($fileSize === false) {
        http_response_code(500);
        exit('Cannot read file size.');
    }

    $downloadName = $id . ($ext !== '' ? '.' . $ext : '');
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $fileSize);
    addStatsBytes('download', (int) $fileSize);
    readfile($path);
    exit;
}

if (isset($_GET['captcha'])) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha = '';
    for ($i = 0; $i < 4; $i++) {
        $captcha .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $_SESSION['captcha_code'] = $captcha;
    outputCaptchaImage($captcha);
    exit;
}

if (isset($_GET['d'])) {
    serveDownload((string) $_GET['d'], (string) ($_GET['ext'] ?? ''));
}

$message = '';
$error = '';
$link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $captchaInput = strtoupper(trim((string) ($_POST['captcha'] ?? '')));
    $captchaSession = strtoupper((string) ($_SESSION['captcha_code'] ?? ''));
    unset($_SESSION['captcha_code']);

    if ($captchaInput === '' || $captchaInput !== $captchaSession) {
        $error = 'Captcha incorrect. Try again.';
    } elseif (!isset($_FILES['LoadFileName']) || $_FILES['LoadFileName']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. Select file and try again.';
    } elseif ((int) $_FILES['LoadFileName']['size'] > MAX_UPLOAD_SIZE) {
        $error = 'File too large. Max size is 100 MB.';
    } else {
        ensureStorageDir();
        $originalName = (string) $_FILES['LoadFileName']['name'];
        $uploadSize = (int) $_FILES['LoadFileName']['size'];
        $ext = normalizeExtension($originalName);
        $tmpFile = (string) $_FILES['LoadFileName']['tmp_name'];
        $fileHash = hash_file('sha256', $tmpFile);

        if ($fileHash === false) {
            $error = 'Cannot calculate file hash.';
        } else {
            $hashIndex = loadHashIndex();
            $known = $hashIndex[$fileHash] ?? null;

            if (is_array($known) && isset($known['id'], $known['ext'])) {
                $existingId = preg_replace('/[^A-Za-z0-9]/', '', (string) $known['id']) ?? '';
                $existingExt = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $known['ext']) ?? '');
                $existingPath = STORAGE_DIR . '/' . $existingId . ($existingExt !== '' ? '.' . $existingExt : '');

                if ($existingId !== '' && is_file($existingPath)) {
                    $publicLink = 'http://' . $_SERVER["HTTP_HOST"] . '/' . $existingId . ($existingExt !== '' ? '.' . $existingExt : '');
                    $message = 'Your file uploaded successfully.';
                    $link = $publicLink;
                    addStatsBytes('upload', $uploadSize);
                } else {
                    unset($hashIndex[$fileHash]);
                    saveHashIndex($hashIndex);
                }
            }
        }

        if ($link === '' && $error === '') {
            do {
                $id = createFileId(8);
                $target = STORAGE_DIR . '/' . $id . ($ext !== '' ? '.' . $ext : '');
            } while (file_exists($target));

            if (move_uploaded_file($tmpFile, $target)) {
                $publicLink = 'http://' . $_SERVER["HTTP_HOST"] . '/' . $id . ($ext !== '' ? '.' . $ext : '');
                $message = 'Your file uploaded successfully.';
                $link = $publicLink;
                addStatsBytes('upload', $uploadSize);

                if ($fileHash !== false) {
                    $hashIndex = loadHashIndex();
                    $hashIndex[$fileHash] = ['id' => $id, 'ext' => $ext];
                    saveHashIndex($hashIndex);
                }
            } else {
                $error = 'Cannot save uploaded file.';
            }
        }
    }
}

$averageBytesPerDay = getAverageBytesPerDay();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
<title>YouSendIt | Email large files quickly, securely, and easily!</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<link href="style.css" rel="stylesheet" type="text/css">
<link rel="icon" href="favicon.ico" type="image/x-icon">
<script>
function refreshCaptchaImage() {
    var img = document.getElementById('captchaImage');
    if (!img) {
        return;
    }
    img.src = '/?captcha=1&v=' + new Date().getTime();
}
</script>
</head>
<body id="body1">
<div id="formLayer" style="left: 0px; overflow: visible; position: relative; top: 0px; width: 100%; height: 100%;">
  <table width="740" height="100%" border="0" align="center" cellpadding="0" cellspacing="0" class="Page">
    <tr>
      <td height="25" colspan="2"><table width="100%" border="0" cellpadding="0" cellspacing="0" class="HeaderUtilities">
          <tr>
            <td width="100%"><img src="images/utilities_left.gif" width="100%" height="25" alt=""></td>
            <td><img src="images/utilities_separator.gif" width="25" height="25" alt=""></td>
            <td nowrap="nowrap" class="HeaderUtilities"><a href="http://www.downgrade-net.ru/">Join Downgrade Network!</a></td>
          </tr>
        </table></td>
    </tr>
    <tr>
      <td height="50" colspan="2"><table width="100%" border="0" cellpadding="0" cellspacing="0" class="HeaderNav">
          <tr>
            <td width="220"><a href="/"><img src="images/logo.gif" height="50" border="0" alt="YouSendIt"></a></td>
            <td width="100%" class="HeaderNav"><a href="/" class="HeaderNav">Home</a> | <a href="solutions.php" class="HeaderNav">Solutions</a> | <a href="mailto:dsc@w10.site" class="HeaderNav">Contact Us</a></td>
          </tr>
        </table></td>
    </tr>
    <tr>
      <td colspan="2" valign="top">
        <table width="100%" border="0" cellpadding="0" cellspacing="0" background="images/feature_bg.gif">
          <tr>
            <td align="center"><img src="images/feature.gif" width="490" height="40" alt=""></td>
          </tr>
        </table>
        <div align="right"><span class="Subscript"><a href="howdoesitwork.php">How does it work</a><br>
	        <a href="whyyousendit.php">Why YouSendIt</a></div>
      </td>
    </tr>
    <tr>
      <td colspan="2" valign="top" class="Page">
        <form name="yousendit" method="post" action="/" id="yousendit" enctype="multipart/form-data">
          <table width="495" border="0" align="center" cellpadding="4" cellspacing="1">
            <tr align="left" valign="middle">
              <td nowrap="nowrap">&nbsp;</td>
              <td class="Instructions">
              Enter the text from the captcha image (case-insensitive), then select the file to upload and click <i>Send It</i>.
              </td>
            </tr>
            <tr align="left" valign="top">
              <td nowrap="nowrap"><img src="images/step_1.gif" width="50" height="50" alt=""></td>
              <td width="100%" class="Label">
                
                <img id="captchaImage" src="/?captcha=1&v=<?php echo time(); ?>" alt="Captcha image" title="Click on image to refresh captcha" onclick="refreshCaptchaImage()" style="border:1px solid #ccc; margin:6px 0; cursor:hand; width:120px; height:36px;">
                <br><span class="Instructions">Click on image to refresh captcha.</span>
                <br>
                <br><span class="Instructions">Enter text from image below (<i>not case-sensitive</i>):</span>
                <br><input name="captcha" type="text" style="WIDTH: 70%; letter-spacing: 2px; font-weight: bold;" maxlength="4" autocomplete="off">
              </td>
            </tr>
            <tr align="left" valign="middle">
              <td nowrap="nowrap"><img src="images/step_2.gif" width="50" height="50" alt=""></td>
              <td width="100%" class="Label">
                Select File to Send (Up to 100 MB):
                <br><input name="LoadFileName" id="LoadFileName" type="file" style="WIDTH: 70%">
              </td>
            </tr>
            <tr align="left" valign="middle">
              <td nowrap="nowrap"><img src="images/step_3.gif" width="50" height="50" alt=""></td>
              <td width="100%">
                <input src="images/send_it.gif" name="UploadFile" id="UploadFile" type="image" alt="Send It">
              </td>
            </tr>
            <?php if ($error !== ''): ?>
            <tr align="left" valign="middle">
              <td colspan="2" nowrap="nowrap"><div align="center"><b style="color:#b30000;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></b></div></td>
            </tr>
            <?php endif; ?>
            <?php if ($message !== '' && $link !== ''): ?>
            <tr align="left" valign="middle">
              <td colspan="2" nowrap="nowrap"><div align="center"><b><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?><br>Link: <a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?></a></b></div></td>
            </tr>
            <?php endif; ?>
            <tr align="left" valign="middle"> 
              <td colspan="2" nowrap="nowrap"> <div align="center"> 
                  </a><a href="https://github.com/tankwars92/YouSendIt">Source code of the site</a>
                </div></td>
            </tr>
          </table>
        </form>
      </td>
    </tr>
    <tr>
      <td width="444" class="Footer"><a href="/" class="Footer">YouSendIt</a> © 2026 | <a class="Footer" href="privacy.php">Privacy Policy</a> | <a class="Footer" href="terms.php">Terms of Service</a></td>
      <td width="296" class="Footer"><div align="right">Transferring over <?php echo number_format($averageBytesPerDay, 0, '.', ','); ?> bytes per day</div></td>
    </tr>
  </table>
</div>
</body>
</html>
