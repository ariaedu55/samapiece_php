#!/usr/bin/env php
<?php
/**
 * Teste la connexion SMTP définie dans .env / config (sans envoyer d’e-mail réel si vous annulez).
 *
 *   /Applications/XAMPP/xamppfiles/bin/php scripts/diagnose_smtp.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI uniquement.\n");
    exit(1);
}

$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $root . '/config.php';
require_once $root . '/includes/smtp_mail.php';

echo "SMTP configuré : " . (smtp_mail_is_configured() ? 'oui' : 'non') . "\n";
if (!smtp_mail_is_configured()) {
    echo "Renseignez SAMAPIECE_SMTP_* dans .env\n";
    exit(1);
}

echo 'Serveur : ' . SMTP_HOST . ':' . SMTP_PORT . ' (' . SMTP_ENCRYPTION . ")\n";
echo 'Utilisateur : ' . SMTP_USERNAME . "\n";
echo 'Expéditeur : ' . EMAIL_FROM . "\n";
echo 'Vérif. SSL : ' . (SMTP_SSL_VERIFY ? 'activée' : 'désactivée') . "\n\n";

$to = SMTP_USERNAME;
echo "Test d’envoi vers : {$to}\n";
$ok = smtp_send_html($to, 'Test SMTP Samapiece', '<p>Si vous recevez ce message, SMTP fonctionne.</p>');
if ($ok) {
    echo "Résultat : OK (message accepté par le serveur SMTP).\n";
    exit(0);
}

echo "Résultat : ÉCHEC\n";
echo "Détail : " . samapiece_smtp_get_last_error() . "\n\n";
echo "535 / authentification : vérifiez le mot de passe dans cPanel → Comptes e-mail, puis mettez à jour .env.\n";
echo "SSL : en local uniquement, essayez SAMAPIECE_SMTP_SSL_VERIFY=0 dans .env.\n";
exit(2);
