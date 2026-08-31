<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'security.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'captcha_challenge.php';
easysched_start_session();
easysched_send_security_headers();

if (empty($_SESSION['login_captcha_required'])) { http_response_code(404); exit; }
if (isset($_GET['refresh']) || !easysched_captcha_is_available()) easysched_captcha_issue();
$expression = (string) $_SESSION['login_challenge_expression'];

if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/svg+xml; charset=utf-8');
    $safe = htmlspecialchars($expression, ENT_QUOTES | ENT_XML1, 'UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="96" viewBox="0 0 300 96"><rect width="300" height="96" fill="#f4f8f5"/><path d="M5 25 C60 75 110 5 170 55 S245 20 295 70 M8 82 C70 30 125 90 205 35 S265 75 298 18" fill="none" stroke="#9aaba1" stroke-width="3"/><text x="150" y="63" text-anchor="middle" font-family="sans-serif" font-size="42" font-weight="700" fill="#18352d">' . $safe . '</text></svg>';
    exit;
}

$width = 300; $height = 96;
$image = imagecreatetruecolor($width, $height);
$background = imagecolorallocate($image, 244, 248, 245);
$ink = imagecolorallocate($image, 24, 53, 45);
$noise = imagecolorallocate($image, 145, 164, 153);
$lightNoise = imagecolorallocate($image, 205, 220, 211);
imagefilledrectangle($image, 0, 0, $width, $height, $background);
for ($i = 0; $i < 8; $i++) imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $i < 3 ? $noise : $lightNoise);
for ($i = 0; $i < 160; $i++) imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $noise);

$font = 5; $scale = 3; $characterWidth = imagefontwidth($font) * $scale; $spacing = 8;
$totalWidth = strlen($expression) * ($characterWidth + $spacing) - $spacing;
$x = max(12, (int) (($width - $totalWidth) / 2));
foreach (str_split($expression) as $character) {
    $characterImage = imagecreatetruecolor(imagefontwidth($font), imagefontheight($font));
    $transparent = imagecolorallocate($characterImage, 244, 248, 245);
    imagefilledrectangle($characterImage, 0, 0, imagesx($characterImage), imagesy($characterImage), $transparent);
    imagecolortransparent($characterImage, $transparent);
    imagestring($characterImage, $font, 0, 0, $character, $ink);
    $scaled = imagescale($characterImage, $characterWidth, imagefontheight($font) * $scale, IMG_NEAREST_NEIGHBOUR);
    $rotated = imagerotate($scaled, random_int(-12, 12), $transparent);
    imagecolortransparent($rotated, $transparent);
    imagecopy($image, $rotated, $x, random_int(24, 38), 0, 0, imagesx($rotated), imagesy($rotated));
    $x += $characterWidth + $spacing;
    imagedestroy($characterImage); imagedestroy($scaled); imagedestroy($rotated);
}
header('Content-Type: image/png');
imagepng($image);
imagedestroy($image);
