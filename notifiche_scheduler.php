<?php
/**
 * SCHEDULER NOTIFICHE PUSH - controlla appuntamenti e invia promemoria
 * Da eseguire via CRON (es. ogni ora).
 */

header('Content-Type: application/json; charset=utf-8');

$db_path = __DIR__ . '/appointments.db';
$log_file = __DIR__ . '/notifiche_log.txt';

// Log funzione (protetta)
if (!function_exists('logNotifica')) {
    function logNotifica($messaggio) {
        global $log_file;
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $messaggio\n", FILE_APPEND);
    }
}

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Tabella notifiche inviate
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifiche_inviate (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            data_invio DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(appointment_id, tipo)
        )
    ");
    
    logNotifica("Inizio controllo notifiche...");
    $now = new DateTime();

    // NOTIFICHE 24H PRIMA
    $domani_start = (clone $now)->modify('+23 hours');
    $domani_end = (clone $now)->modify('+25 hours');
    $sql24h = "
        SELECT a.* 
        FROM appointments a
        LEFT JOIN notifiche_inviate n ON a.id = n.appointment_id AND n.tipo = '24h'
        WHERE 
            datetime(a.appointment_date || ' ' || a.appointment_time || ':00') BETWEEN :start AND :end
            AND a.status IN ('pending', 'confirmed', 'confermato')
            AND n.id IS NULL
    ";
    $stmt = $pdo->prepare($sql24h);
    $stmt->execute([
        'start' => $domani_start->format('Y-m-d H:i:s'),
        'end' => $domani_end->format('Y-m-d H:i:s')
    ]);
    $appuntamenti_24h = $stmt->fetchAll(PDO::FETCH_ASSOC);
    logNotifica("Trovati " . count($appuntamenti_24h) . " appuntamenti per notifica 24h");
    foreach ($appuntamenti_24h as $appt) {
        inviaNotificaPush(
            $appt,
            '24h',
            'PROMEMORIA 24H',
            "Domani alle {$appt['appointment_time']} appuntamento per {$appt['pet_name']} - [24H PRIMA]"
        );
        $pdo->prepare("INSERT OR IGNORE INTO notifiche_inviate (appointment_id, tipo) VALUES (?, '24h')")
            ->execute([$appt['id']]);
    }

    // NOTIFICHE 1H PRIMA
    $unora_start = (clone $now)->modify('+50 minutes');
    $unora_end = (clone $now)->modify('+70 minutes');
    $sql1h = "
        SELECT a.* 
        FROM appointments a
        LEFT JOIN notifiche_inviate n ON a.id = n.appointment_id AND n.tipo = '1h'
        WHERE 
            datetime(a.appointment_date || ' ' || a.appointment_time || ':00') BETWEEN :start AND :end
            AND a.status IN ('pending', 'confirmed', 'confermato')
            AND n.id IS NULL
    ";
    $stmt = $pdo->prepare($sql1h);
    $stmt->execute([
        'start' => $unora_start->format('Y-m-d H:i:s'),
        'end' => $unora_end->format('Y-m-d H:i:s')
    ]);
    $appuntamenti_1h = $stmt->fetchAll(PDO::FETCH_ASSOC);
    logNotifica("Trovati " . count($appuntamenti_1h) . " appuntamenti per notifica 1h");
    foreach ($appuntamenti_1h as $appt) {
        inviaNotificaPush(
            $appt,
            '1h',
            'PROMEMORIA 1H',
            "TRA 1 ORA alle {$appt['appointment_time']} appuntamento per {$appt['pet_name']} - [1H PRIMA]"
        );
        $pdo->prepare("INSERT OR IGNORE INTO notifiche_inviate (appointment_id, tipo) VALUES (?, '1h')")
            ->execute([$appt['id']]);
    }

    logNotifica("Controllo completato!");
    echo json_encode([
        'success' => true,
        'notifiche_24h' => count($appuntamenti_24h),
        'notifiche_1h' => count($appuntamenti_1h),
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    logNotifica("ERRORE: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Invia notifica push al cliente usando Web Push Protocol
 */
if (!function_exists('inviaNotificaPush')) {
    function inviaNotificaPush($appuntamento, $tipo, $titolo, $messaggio) {
        logNotifica("Invio notifica $tipo per appuntamento ID {$appuntamento['id']}");
        
        require_once __DIR__ . '/push_sender.php';
        
        try {
            $pushSender = new PushSender(__DIR__ . '/push_devices-toilet-001.db');
            
            $numeri = [];
            if (!empty($appuntamento['telefono'])) {
                $numeri[] = $appuntamento['telefono'];
            }
            if (!empty($appuntamento['telefono2'])) {
                $numeri[] = $appuntamento['telefono2'];
            }
            if (!empty($appuntamento['telefono3'])) {
                $numeri[] = $appuntamento['telefono3'];
            }
            
            if (!empty($numeri)) {
                $results = [];
                foreach ($numeri as $numero) {
                    $results[] = $pushSender->sendToTelefono(
                        $numero,
                        $titolo,
                        $messaggio,
                        [
                            'appointment_id' => $appuntamento['id'],
                            'type' => $tipo,
                            'appointment_date' => $appuntamento['appointment_date'],
                            'appointment_time' => $appuntamento['appointment_time']
                        ]
                    );
                }
                logNotifica("   Telefono principale: {$appuntamento['telefono']}");
                logNotifica("   Messaggio: $messaggio");
                logNotifica("   Push inviati: " . count($results));
            } else {
                logNotifica("   Nessun telefono associato all'appuntamento");
            }
            
        } catch (Exception $e) {
            logNotifica("   Errore invio push: " . $e->getMessage());
        }
    }
}
?>
