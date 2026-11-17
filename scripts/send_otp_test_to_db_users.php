#!/usr/bin/env php
<?php
/**
 * Envoie un e-mail de test au format OTP (même gabarit que la connexion) à chaque adresse
 * distincte enregistrée dans users.email — pour vérifier SMTP / délivrabilité.
 *
 * Usage :
 *   php scripts/send_otp_test_to_db_users.php --dry-run
 *   php scripts/send_otp_test_to_db_users.php --yes
 *   php scripts/send_otp_test_to_db_users.php --yes --limit=5
 *
 * Options :
 *   --dry-run   Liste les adresses sans envoyer.
 *   --yes       Pas de confirmation interactive (obligatoire pour envoyer).
 *   --limit=N   Nombre maximum d’e-mails (défaut : 500).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI uniquement.\n");
    exit(1);
}

$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once $root . '/functions.php';

$dry = false;
$yes = false;
$limit = 500;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--dry-run') {
        $dry = true;
    } elseif ($arg === '--yes') {
        $yes = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    } else {
        fwrite(STDERR, "Argument inconnu : {$arg}\n");
        exit(1);
    }
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->query(
        "SELECT id, email, prenom, nom FROM users WHERE email IS NOT NULL AND TRIM(email) <> '' ORDER BY email ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, 'Base de données : ' . $e->getMessage() . "\n");
    exit(1);
}

if ($rows === []) {
    echo "Aucun utilisateur avec une adresse e-mail en base.\n";
    exit(0);
}

$byEmail = [];
foreach ($rows as $row) {
    $em = strtolower(trim((string) ($row['email'] ?? '')));
    if ($em === '' || isset($byEmail[$em])) {
        continue;
    }
    $byEmail[$em] = $row;
}

$recipients = array_values($byEmail);
$total = count($recipients);
if ($total > $limit) {
    $recipients = array_slice($recipients, 0, $limit);
    echo "Attention : {$total} adresse(s) distincte(s), envoi limité aux {$limit} premières.\n\n";
}

echo "Adresses distinctes à traiter : " . count($recipients) . "\n";
if ($dry) {
    foreach ($recipients as $r) {
        echo '  - ' . $r['email'] . ' (' . trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) . ")\n";
    }
    echo "\nMode --dry-run : aucun e-mail envoyé. Relancez sans --dry-run et avec --yes pour envoyer.\n";
    exit(0);
}

if (!$yes) {
    echo "\nPour envoyer réellement un e-mail de test (code factice) à chaque adresse, relancez avec --yes\n";
    echo "Exemple : php scripts/send_otp_test_to_db_users.php --yes\n";
    exit(0);
}

$okCount = 0;
$failCount = 0;
foreach ($recipients as $r) {
    $to = trim((string) $r['email']);
    $code = auth_generate_otp();
    $prenom = (string) ($r['prenom'] ?? '');
    $nom = (string) ($r['nom'] ?? '');
    echo "Envoi vers {$to} ... ";
    $sent = auth_send_otp_email_html($to, $code, $prenom, $nom, 'login');
    if ($sent) {
        echo "OK\n";
        $okCount++;
    } else {
        $err = function_exists('samapiece_smtp_get_last_error') ? samapiece_smtp_get_last_error() : '';
        echo "ÉCHEC" . ($err !== '' ? " — {$err}" : '') . "\n";
        $failCount++;
    }
}

echo "\nRésumé : {$okCount} envoyé(s), {$failCount} échec(s).\n";
echo "Les codes reçus sont des tests : ils ne permettent pas de se connecter (non enregistrés en base).\n";
exit($failCount > 0 ? 2 : 0);
