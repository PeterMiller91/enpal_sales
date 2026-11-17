<?php
/*
 * Baubarkeits-Check – Konfiguration
 *
 * Diese Datei nach "config.php" kopieren und anpassen.
 * config.php ist in .gitignore eingetragen und wird von "git pull" nie überschrieben.
 */
return [
    // Wer darf sich anmelden? Benutzername => Passwort.
    // Statt Klartext geht auch ein Hash: php -r "echo password_hash('meinPasswort', PASSWORD_DEFAULT);"
    'users' => [
        'team' => 'BITTE-AENDERN',
    ],

    // Langer Zufallswert für die Anmelde-Cookies (einmal setzen, nicht mehr ändern).
    // Erzeugen z. B. mit: php -r "echo bin2hex(random_bytes(32));"
    'secret' => 'BITTE-AENDERN-langer-zufallswert',

    // Ordner für die Kundenordner. Standard: neben dem Web-Verzeichnis der Subdomain,
    // also per FTP sichtbar, aber nicht über die Webseite aufrufbar.
    // Beispiel Netcup: /check.meine-domain.de  (Web)  →  /baubarkeits-daten/kunden  (Daten)
    'data_dir' => dirname(__DIR__) . '/baubarkeits-daten/kunden',

    // Erscheint im Kopf des Berichts.
    'company_name' => '[FIRMENNAME]',

    // Optional: Logo (JPG oder PNG) oben rechts im Bericht, z. B. __DIR__ . '/logo.png'
    'logo_path' => '',

    // Mindestbreite in cm für Engstellen (Gerät + Transport). 0 = keine Prüfung.
    'min_width_cm' => 0,

    // Max. Größe einer einzelnen Datei in MB (Videos!).
    'max_upload_mb' => 1024,
];
