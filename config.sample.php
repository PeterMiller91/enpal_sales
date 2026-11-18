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

    // Ordner für die Kundenordner (per FTP sichtbar, über die Webseite gesperrt).
    // Standard: "daten/kunden" im Web-Verzeichnis, gesperrt per .htaccess.
    // Darf PHP auch außerhalb schreiben, geht z. B. dirname(__DIR__) . '/baubarkeits-daten/kunden'.
    // Liegt der Pfad außerhalb des erlaubten Bereichs (open_basedir), nimmt die App automatisch daten/kunden.
    'data_dir' => __DIR__ . '/daten/kunden',

    // Erscheint im Kopf des Berichts.
    'company_name' => '[FIRMENNAME]',

    // Optional: Logo (JPG oder PNG) oben rechts im Bericht, z. B. __DIR__ . '/logo.png'
    'logo_path' => '',

    // Mindestbreite in cm für Engstellen (Gerät + Transport). 0 = keine Prüfung.
    'min_width_cm' => 0,

    // Max. Größe einer einzelnen Datei in MB (Videos!).
    'max_upload_mb' => 1024,
];
