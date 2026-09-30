<?php
declare(strict_types=1);

/**
 * Contact Appart & Beauté : script PHP pour hébergement IONOS avec PHP.
 * À placer dans public/api/contact.php (Astro le copie ensuite dans dist/api).
 * Les secrets ET la bibliothèque PHPMailer restent en dehors de la racine publique.
 */

function redirectContact(string $result): never
{
    header('Cache-Control: no-store');
    header('Location: /contact/?contact=' . $result . '#form-success', true, 303);
    exit;
}

function failContact(int $status, string $reason): never
{
    error_log('[appartbeaute-contact] ' . $reason);
    http_response_code($status);
    redirectContact('erreur');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) {
    failContact(413, 'Corps trop volumineux');
}

/**
 * IMPORTANT : créer ce fichier PRIVÉ à côté du répertoire public d'IONOS,
 * pas dans public/, dist/, ni dans une zone téléchargeable sur le site.
 * Adapter la ligne ci-dessous si l'arborescence de votre hébergement diffère.
 */
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$configPath = $documentRoot !== ''
    ? dirname($documentRoot) . '/appartbeaute-private/contact-config.php'
    : '';

if ($configPath === '' || !is_readable($configPath)) {
    failContact(503, 'Configuration privée absente / chemin à adapter');
}

$config = require $configPath;
if (!is_array($config)) {
    failContact(503, 'Configuration privée invalide');
}

$requiredConfigKeys = [
    'turnstile_secret', 'turnstile_allowed_hosts', 'rate_salt',
    'phpmailer_autoload', 'smtp_host', 'smtp_port', 'smtp_user',
    'smtp_password', 'to_email'
];
foreach ($requiredConfigKeys as $key) {
    if (empty($config[$key])) {
        failContact(503, 'Paramètre manquant : ' . $key);
    }
}

// Vérification de tous les champs côté serveur (le HTML seul ne protège pas).
$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$topic = trim((string) ($_POST['topic'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$trap = trim((string) ($_POST['website'] ?? ''));
$privacy = (string) ($_POST['privacy'] ?? '');
$startedAt = (string) ($_POST['started_at'] ?? '');
$turnstileToken = (string) ($_POST['cf-turnstile-response'] ?? '');

// Honeypot : pas de retour distinct qui pourrait renseigner les robots.
if ($trap !== '') {
    redirectContact('ok');
}

// Contrôle complémentaire : soumission très rapide ou trop ancienne.
$elapsedMs = ctype_digit($startedAt) ? (int) round(microtime(true) * 1000) - (int) $startedAt : 0;
if ($elapsedMs < 2000 || $elapsedMs > 7200000) {
    failContact(400, 'Horodatage de formulaire invalide');
}

$allowedTopics = [
    'Renseignement sur une prestation',
    'Question sur l’institut',
    'Autre demande',
];
if (
    $privacy !== 'yes' ||
    $name === '' || strlen($name) > 180 || preg_match('/[\r\n\x00]/', $name) ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 ||
    !in_array($topic, $allowedTopics, true) ||
    strlen($message) < 10 || strlen($message) > 9000 ||
    preg_match('/\x00/', $message) ||
    $turnstileToken === '' || strlen($turnstileToken) > 2048
) {
    failContact(400, 'Champs manquants ou incorrects');
}

$visitorIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'ip-inconnue');

// Limitation par IP : max 8 tentatives/heure et min 15 secondes entre deux.
// Un hash salé est utilisé pour éviter le stockage de l'adresse IP en clair.
$rateFile = sys_get_temp_dir() . '/ab-contact-' . hash('sha256', $config['rate_salt'] . $visitorIp) . '.json';
$fp = @fopen($rateFile, 'c+');
if ($fp === false || !flock($fp, LOCK_EX)) {
    failContact(503, 'Stockage temporaire indisponible');
}

$previous = stream_get_contents($fp);
$attempts = is_string($previous) ? json_decode($previous, true) : null;
$attempts = is_array($attempts) ? $attempts : [];
$now = time();
$attempts = array_values(array_filter($attempts, static function ($value) use ($now): bool {
    return is_int($value) && $value > $now - 3600 && $value <= $now;
}));
$tooFrequent = count($attempts) >= 8 || (count($attempts) > 0 && $now - end($attempts) < 15);
if (!$tooFrequent) {
    $attempts[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($attempts, JSON_THROW_ON_ERROR));
    fflush($fp);
}
flock($fp, LOCK_UN);
fclose($fp);
if ($tooFrequent) {
    failContact(429, 'Limite de fréquence atteinte');
}

// Turnstile DOIT être validé côté serveur et lié au bon nom de domaine.
if (!function_exists('curl_init')) {
    failContact(503, 'Extension cURL nécessaire');
}
$curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
if ($curl === false) {
    failContact(503, 'cURL indisponible');
}
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $config['turnstile_secret'],
        'response' => $turnstileToken,
        'remoteip' => $visitorIp,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 8,
]);
$verifyResponse = curl_exec($curl);
$verifyCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if (!is_string($verifyResponse) || $verifyCode !== 200) {
    failContact(503, 'Vérification anti-spam inaccessible');
}
$verification = json_decode($verifyResponse, true);
if (
    !is_array($verification) ||
    ($verification['success'] ?? false) !== true ||
    !in_array((string) ($verification['hostname'] ?? ''), $config['turnstile_allowed_hosts'], true)
) {
    failContact(400, 'Vérification anti-spam refusée');
}

// Envoi via SMTP authentifié de la boîte IONOS, jamais depuis l'adresse du visiteur.
// Son adresse est utilisée exclusivement comme Reply-To.
$autoload = (string) $config['phpmailer_autoload'];
if (!is_readable($autoload)) {
    failContact(503, 'Bibliothèque PHPMailer introuvable');
}
require_once $autoload;

try {
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) $config['smtp_host'];
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) $config['smtp_user'];
    $mailer->Password = (string) $config['smtp_password'];
    $mailer->Port = (int) $config['smtp_port'];
    $mailer->SMTPSecure = $mailer->Port === 587
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mailer->CharSet = 'UTF-8';
    $mailer->Timeout = 12;
    $mailer->setFrom((string) $config['smtp_user'], 'Appart & Beauté Institut');
    $mailer->addAddress((string) $config['to_email']);
    $mailer->addReplyTo($email, $name);
    $mailer->Subject = 'Contact du site : ' . $topic;
    $mailer->isHTML(false);
    $mailer->Body = "Nouveau message depuis le formulaire Appart & Beauté\n\n"
        . "Nom : {$name}\nEmail : {$email}\nSujet : {$topic}\n\n"
        . "Message :\n{$message}\n";
    $mailer->send();
} catch (\Throwable $e) {
    // Ne jamais afficher les données du formulaire ni les détails SMTP au visiteur.
    error_log('[appartbeaute-contact] Erreur envoi SMTP');
    failContact(503, 'Envoi impossible');
}

redirectContact('ok');
