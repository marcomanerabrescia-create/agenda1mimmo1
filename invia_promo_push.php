<?php
header('Content-Type: text/plain; charset=utf-8');

// 🔧 CONFIGURA QUI I DATI DI ONESIGNAL
$ONESIGNAL_APP_ID  = '72b4c198-5ce4-4b12-997b-d14c017cd19f';
$ONESIGNAL_API_KEY = 'MGMxYWFhYWEtYWE1ZS00NTY2LTlkOGUtODc4ODZjMTIxYThk';
$TIMEZONE          = 'Europe/Rome';

// 🔧 PERCORSO DEL DATABASE (uguale a promo_api-toilet-001.php)
$dbFile = '/web/htdocs/www.consulenticaniegatti.com/home/app/ristorantedamimmo/promozioni-toilet-001.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "Errore DB: " . $e->getMessage();
    exit;
}

// Data di oggi
$now      = new DateTime('now', new DateTimeZone($TIMEZONE));
$oggi     = $now->format('Y-m-d');

// 🔍 Prendiamo le promozioni ATTIVE con push_attivo = 1 e valide oggi
$sql = "
    SELECT *
    FROM promozioni
    WHERE attivo = 1
      AND push_attivo = 1
      AND date(:oggi) BETWEEN date(data_inizio) AND date(data_fine)
";
$stmt = $db->prepare($sql);
$stmt->execute(['oggi' => $oggi]);
$promos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$promos) {
    echo "Nessuna promo con push attivo per oggi ($oggi)\n";
    exit;
}

echo "Trovate " . count($promos) . " promo con push_attivo=1 per oggi $oggi\n\n";

foreach ($promos as $promo) {
    echo "PROMO ID {$promo['id']} - {$promo['titolo']}\n";

    // Decodifica orari push: es. ["18:48","18:51","18:53"]
    $orari = json_decode($promo['push_orari'] ?? '[]', true);
    if (!is_array($orari) || empty($orari)) {
        echo "  Nessun orario push impostato\n\n";
        continue;
    }

    foreach ($orari as $oraStr) {
        // Crea la data/ora completa di oggi + orario
        $dt = DateTime::createFromFormat(
            'Y-m-d H:i',
            $oggi . ' ' . $oraStr,
            new DateTimeZone($TIMEZONE)
        );

        if (!$dt) {
            echo "  Orario NON valido: $oraStr\n";
            continue;
        }

        // Se l'orario è già passato, puoi decidere se saltarlo
        if ($dt < $now) {
            echo "  Orario $oraStr già passato, salto.\n";
            continue;
        }

        // OneSignal vuole la data con timezone, es. 2025-11-20 18:48:00 +0100
        $sendAfter = $dt->format('Y-m-d H:i:s O');

        echo "  Invio notifica programmata per le $oraStr (send_after: $sendAfter)\n";

        // Payload per OneSignal
        $payload = [
            'app_id' => $ONESIGNAL_APP_ID,
            // ⚠️ qui decidi a chi mandarla: tutti, tag, specifici...
            'included_segments' => ['All'],
            'headings' => [
                'en' => $promo['titolo'],
                'it' => $promo['titolo']
            ],
            'contents' => [
                'en' => $promo['messaggio'],
                'it' => $promo['messaggio']
            ],
            'send_after' => $sendAfter
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json; charset=utf-8",
            "Authorization: Basic " . $ONESIGNAL_API_KEY
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "    → HTTP $httpCode, risposta: $response\n\n";
    }

    echo "-----------------------------\n\n";
}

echo "Fine invio.\n";
