# Baubarkeits-Check

Web-App fürs Handy zur Prüfung der Baubarkeit beim Kunden vor Ort. Sie führt durch 6 Schritte:

1. Jeder Heizkörper – 2 Fotos (Gesamtansicht & Hahnblock) + Maße
2. Zählerschrank geöffnet (+ 20 cm Umfeld)
3. Heizraum 360°-Video
4. Video vom Heizraum bis zur Haustür, Engstellen mit Zollstock-Foto + Breite
5. Jede Hausseite mit Abstand zu Nachbarfenster und Grenze
6. Bauplan, Gas-/Öl- und Stromrechnung

Fotos und Videos kommen direkt aus der Kamera oder aus der Galerie, inklusive Uhrzeit und GPS-Position.
Am Ende erstellt der Server einen **PDF-Bericht** mit allen Fotos und Maßen und legt ihn im Kundenordner ab.

Läuft auf normalem PHP-Webspace (getestet für Netcup Webhosting / Plesk), ohne Datenbank und ohne Composer.

---

## Einrichtung bei Netcup (Plesk) – einmalig

### 1. Subdomain anlegen
Plesk → *Websites & Domains* → *Subdomain hinzufügen*, z. B. `check.meine-domain.de`.
Dokumentstamm z. B. `/check.meine-domain.de` (so schlägt Plesk ihn vor).

### 2. SSL aktivieren
Bei der Subdomain → *SSL/TLS-Zertifikate* → **Let's Encrypt** → Zertifikat holen.
HTTPS ist Pflicht: ohne HTTPS gibt es am Handy kein GPS und keinen Offline-Modus.

### 3. Git-Deployment einrichten
Bei der Subdomain → *Git* → Repository hinzufügen:
- Remote-Repository: `https://github.com/PeterMiller91/enpal_sales.git`
  (privates Repo: SSH-URL `git@github.com:PeterMiller91/enpal_sales.git` nehmen und den von Plesk angezeigten
  SSH-Schlüssel bei GitHub unter *Settings → Deploy keys* eintragen)
- Branch: `main`
- Bereitstellungsmodus: **automatisch** oder **manuell** („Pull Updates“ + „Deploy now“)
- Pfad für die Bereitstellung: der **Dokumentstamm der Subdomain** (`/check.meine-domain.de`)

### 4. `config.php` anlegen
Im Dateimanager (oder per FTP) im Dokumentstamm `config.sample.php` kopieren nach **`config.php`** und anpassen:

```php
'users' => ['peter' => 'ein-gutes-passwort', 'kollege' => '…'],
'secret' => '…langer Zufallswert…',
'company_name' => 'Meine Firma GmbH',
'min_width_cm' => 80,
```

`config.php` steht in `.gitignore`. Ein späteres `git pull` überschreibt sie also nie.

### 5. PHP-Version
Bei der Subdomain → *PHP* → **PHP 8.1 oder neuer** (FPM). Die Datei `.user.ini` setzt die nötigen Limits selbst.

Fertig: `https://check.meine-domain.de` aufrufen, anmelden und am Handy „Zum Startbildschirm hinzufügen“ wählen.

---

## Wo liegen die Daten? (FTP)

Im Web-Verzeichnis der Subdomain im Ordner **`daten/kunden`**. Per FTP sieht man ihn, über die Webseite ist er gesperrt
(`.htaccess`: „Require all denied“). Bei Netcup darf PHP nur innerhalb von `httpdocs` schreiben (open_basedir),
deshalb liegt der Ordner dort.

```
/bau.meine-domain.de/httpdocs/            ← App (aus Git)
└── daten/kunden/                         ← Kundenordner (wird automatisch angelegt)
    └── K-2026-0412_Familie_Beispiel/     ← Kunden-Nr. + Name
        └── 2026-11-17/                   ← Termin-Datum
            ├── check.json                ← alle Daten & Maße
            ├── 1_heizkoerper/Familie_Beispiel_2026-11-17_14-32_HK1_Wohnzimmer_Gesamt_ab12cd34.jpg
            ├── 3_heizraum/Familie_Beispiel_2026-11-17_14-40_Heizraum_360_….mp4  (+ _vorschau.jpg)
            ├── 4_weg/Familie_Beispiel_2026-11-17_14-45_Engstelle1_Kellertuer_….jpg
            ├── 5_hausseiten/Familie_Beispiel_2026-11-17_15-02_Hausseite_Ost_….jpg
            ├── 6_unterlagen/Familie_Beispiel_2026-11-17_15-10_Bauplan_….jpg
            └── Bericht_K-2026-0412_Familie_Beispiel_2026-11-17.pdf
```

Dateinamen: `<Kunde>_<Datum>_<Uhrzeit der Aufnahme>_<Bezeichnung>_<ID>`. So bleiben Fotos auch nach dem Herauskopieren eindeutig zuzuordnen.

`daten/` steht in `.gitignore`. Ein Git-Pull rührt die Kundendaten also nicht an.

**Wichtig:** In Plesk unter *Apache & nginx* die Option „Statische Dateien direkt durch nginx verarbeiten“ **ausgeschaltet** lassen.
Sonst greift die Sperre per `.htaccess` für Fotos nicht.

Einen anderen Ort stellt man in `config.php` mit `data_dir` ein. Liegt er außerhalb des für PHP erlaubten Bereichs,
nimmt die App automatisch `daten/kunden`. Ist der Ordner nicht nutzbar, zeigt die App nach dem Login den genauen Grund an.

## Updates

Neue Version auf GitHub `main` → in Plesk unter *Git* „Pull Updates“ (bei automatischer Bereitstellung passiert das von selbst).
`config.php` und die Kundendaten bleiben dabei unangetastet.

## Bedienung

- **Neuer Check** → Kundendaten → „Check starten“.
- Pro Schritt *Kamera* (öffnet direkt die Kamera) oder *Galerie/Datei wählen*. Mehrere Dateien gleichzeitig möglich.
- Vor der Aufnahme Raum bzw. Bezeichnung eintragen, dann steht er im Dateinamen.
- **Ohne Netz** wird alles auf dem Handy gespeichert und hochgeladen, sobald wieder Verbindung besteht.
  Große Videos gehen in 4-MB-Stücken hoch, abgebrochene Uploads werden fortgesetzt.
- **Abschluss** zeigt fehlende Punkte und den Upload-Stand und erstellt den Bericht.
  „Teilen / per E-Mail“ nutzt am Handy das Teilen-Menü (Mail, WhatsApp, …).

## Aufbau

| Datei | Zweck |
|---|---|
| `index.html`, `app.js`, `db.js`, `styles.css`, `sw.js` | App im Browser (ohne Build-Schritt), Offline-Speicher, Service Worker |
| `api.php` | Schnittstelle: Login, Speichern, Upload, Bericht |
| `lib/util.php` | Anmeldung, Ablage in Kundenordnern |
| `lib/report.php` | PDF-Bericht (mit [FPDF](http://www.fpdf.org), liegt in `lib/fpdf/`) |
| `config.sample.php` | Vorlage für `config.php` |
| `.htaccess`, `.user.ini` | Schutz, HTTPS-Weiterleitung, PHP-Limits |

Hinweis: Entfernt man eine Aufnahme in der App, verschwindet sie aus dem Bericht. Die Datei bleibt aber im Kundenordner liegen.
