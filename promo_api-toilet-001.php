<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Piccolo logger su file locale per capire errori in produzione
$__API_LOG = __DIR__ . '/api_debug.log';
function logApi($msg, $data = null) {
    global $__API_LOG;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($data !== null) {
        $line .= ' | ' . json_encode($data);
    }
    file_put_contents($__API_LOG, $line . "\n", FILE_APPEND);
}

// Percorso DB assoluto (no copie, no path relativi)
$dbFile = '/web/htdocs/www.consulenticaniegatti.com/home/app/ristorantedamimmo/promozioni-toilet-001.db';
logApi('DB path', ['dbFile' => $dbFile, 'method' => $_SERVER['REQUEST_METHOD']]);

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $db->exec("CREATE TABLE IF NOT EXISTS promozioni (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titolo TEXT NOT NULL,
        messaggio TEXT NOT NULL,
        img_url TEXT,
        data_inizio TEXT NOT NULL,
        data_fine TEXT NOT NULL,
        attivo INTEGER DEFAULT 1,
        popup_attivo INTEGER DEFAULT 1,
        popup_max INTEGER DEFAULT 1,
        popup_orari TEXT,
        popup_giorni INTEGER DEFAULT 0,
        push_attivo INTEGER DEFAULT 0,
        push_max INTEGER DEFAULT 1,
        push_orari TEXT,
        push_giorni INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Aggiungi colonne se non esistono (migrazione)
    try {
        $db->exec("ALTER TABLE promozioni ADD COLUMN popup_orari TEXT");
    } catch (Exception $e) {
        // Colonna già esiste
    }

    try {
        $db->exec("ALTER TABLE promozioni ADD COLUMN img_url TEXT");
    } catch (Exception $e) {
        // Colonna già esiste
    }

    try {
        $db->exec("ALTER TABLE promozioni ADD COLUMN push_orari TEXT");
    } catch (Exception $e) {
        // Colonna già esiste
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore database: ' . $e->getMessage(), 'promozioni' => []]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Recupera promozioni attive CON CALCOLI DINAMICI
if ($method === 'GET') {
    try {
        $oggi = date('Y-m-d');
        
        // Controlla se è richiesta la modalità ADMIN (tutte le promozioni)
        $admin_mode = isset($_GET['admin']) && $_GET['admin'] === '1';
        
        // Recupera tutte le promozioni attive
        $stmt = $db->prepare("
            SELECT * FROM promozioni
            WHERE attivo = 1
            ORDER BY id DESC
        ");
        $stmt->execute();
        $promozioni = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Aggiungi calcoli dinamici per ogni promozione
        $promozioniConCalcoli = [];
        
        foreach ($promozioni as $promo) {
            $dataInizio = new DateTime($promo['data_inizio']);
            $dataFine = new DateTime($promo['data_fine']);
            $oggiDate = new DateTime($oggi);
            
            // In modalità ADMIN mostra TUTTE le promozioni (anche future/passate)
            // In modalità CLIENTE filtra solo quelle valide oggi
            if (!$admin_mode && ($oggiDate < $dataInizio || $oggiDate > $dataFine)) {
                continue; // Salta promozioni non valide oggi (solo per clienti)
            }
            
            // Calcola durata in giorni
            $durataGiorni = $dataFine->diff($dataInizio)->days + 1;
            $promo['durata_giorni'] = $durataGiorni;
            
            // USA I VALORI DAL DATABASE (non calcolare!)
            // Se non esistono (vecchie promozioni), usa i default
            if (!isset($promo['popup_max']) || $promo['popup_max'] === null) {
                $promo['popup_max'] = 1;
            }
            if (!isset($promo['popup_giorni']) || $promo['popup_giorni'] === null) {
                $promo['popup_giorni'] = 0;
            }
            
            // Per compatibilità con agenda-cliente.html che usa questi nomi
            $promo['max_visualizzazioni'] = $promo['popup_max'];
            $promo['pausa_giorni'] = $promo['popup_giorni'];
            
            $promozioniConCalcoli[] = $promo;
        }
        
        echo json_encode([
            'oggi' => $oggi,
            'totale_promozioni_attive' => count($promozioniConCalcoli),
            'promozioni' => $promozioniConCalcoli
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Errore lettura: ' . $e->getMessage()]);
    }
}

// POST - Crea nuova promozione
elseif ($method === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['titolo']) || !isset($input['messaggio']) ||
            !isset($input['data_inizio']) || !isset($input['data_fine'])) {
            echo json_encode(['error' => 'Campi mancanti']);
            exit;
        }
        
        // ✅ CORRETTO: Converti POPUP orari in JSON
        $popup_orari = isset($input['popup_orari']) && is_array($input['popup_orari']) 
            ? json_encode($input['popup_orari']) 
            : json_encode([]);
        
        // ✅ CORRETTO: Converti PUSH orari in JSON (SEPARATI!)
        $push_orari = isset($input['push_orari']) && is_array($input['push_orari']) 
            ? json_encode($input['push_orari']) 
            : json_encode([]);
        
        $stmt = $db->prepare("
            INSERT INTO promozioni (
                titolo, messaggio, img_url, data_inizio, data_fine, attivo,
                popup_attivo, popup_max, popup_orari, popup_giorni,
                push_attivo, push_max, push_orari, push_giorni
            )
            VALUES (
                :titolo, :messaggio, :img_url, :data_inizio, :data_fine, 1,
                :popup_attivo, :popup_max, :popup_orari, :popup_giorni,
                :push_attivo, :push_max, :push_orari, :push_giorni
            )
        ");
        $stmt->execute([
            'titolo' => $input['titolo'],
            'messaggio' => $input['messaggio'],
            'img_url' => $input['img_url'] ?? null,
            'data_inizio' => $input['data_inizio'],
            'data_fine' => $input['data_fine'],
            'popup_attivo' => $input['popup_attivo'] ?? 1,
            'popup_max' => $input['popup_max'] ?? 1,
            'popup_orari' => $popup_orari,
            'popup_giorni' => $input['popup_giorni'] ?? 0,
            'push_attivo' => $input['push_attivo'] ?? 0,
            'push_max' => $input['push_max'] ?? 1,
            'push_orari' => $push_orari,  // ✅ CORRETTO!
            'push_giorni' => $input['push_giorni'] ?? 0
        ]);

        logApi('POST insert ok', ['id' => $db->lastInsertId(), 'input' => $input]);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Promozione creata',
            'id' => $db->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        logApi('POST insert error', ['err' => $e->getMessage(), 'input' => $input]);
        echo json_encode(['error' => 'Errore creazione: ' . $e->getMessage()]);
    }
}

// DELETE - Elimina promozione
elseif ($method === 'DELETE') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['id'])) {
            echo json_encode(['error' => 'ID mancante']);
            exit;
        }
        
        $stmt = $db->prepare("DELETE FROM promozioni WHERE id = :id");
        $stmt->execute(['id' => $input['id']]);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Promozione eliminata'
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Errore eliminazione: ' . $e->getMessage()]);
    }
}
else {
    echo json_encode(['error' => 'Metodo non supportato']);
}
?>
