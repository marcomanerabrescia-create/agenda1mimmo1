<?php
/**
 * SCRIPT DEBUG PUSH NOTIFICATIONS
 * Testa tutti i punti critici del sistema push
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 DEBUG PUSH NOTIFICATIONS</h1>";
echo "<style>body{font-family:monospace;background:#1a1a1a;color:#0f0;padding:20px}h2{color:#ff0}.ok{color:#0f0}.error{color:#f00}.warning{color:#ff0}</style>";

// ==========================================
// 1. VERIFICA CONFIGURAZIONE
// ==========================================
echo "<h2>1️⃣ VERIFICA CONFIGURAZIONE</h2>";

// Chiavi VAPID (SOSTITUISCI CON LE TUE)
$vapid_public_key = 'TUA_CHIAVE_PUBBLICA_VAPID';
$vapid_private_key = 'TUA_CHIAVE_PRIVATA_VAPID';
$vapid_subject = 'mailto:tua@email.com';

if ($vapid_public_key === 'TUA_CHIAVE_PUBBLICA_VAPID') {
    echo "<p class='error'>❌ ERRORE: Devi inserire le chiavi VAPID reali!</p>";
    echo "<p class='warning'>Genera le chiavi qui: https://vapidkeys.com/</p>";
} else {
    echo "<p class='ok'>✅ Chiavi VAPID configurate</p>";
}

// ==========================================
// 2. VERIFICA LIBRERIA WEB PUSH
// ==========================================
echo "<h2>2️⃣ VERIFICA LIBRERIA WEB-PUSH</h2>";

if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
    echo "<p class='ok'>✅ Autoloader trovato</p>";
    
    if (class_exists('Minishlink\WebPush\WebPush')) {
        echo "<p class='ok'>✅ Libreria WebPush installata</p>";
    } else {
        echo "<p class='error'>❌ Classe WebPush non trovata</p>";
        echo "<p class='warning'>Installa: composer require minishlink/web-push</p>";
    }
} else {
    echo "<p class='error'>❌ Composer autoloader non trovato</p>";
    echo "<p class='warning'>Esegui: composer install</p>";
    die();
}

// ==========================================
// 3. VERIFICA DATABASE
// ==========================================
echo "<h2>3️⃣ VERIFICA DATABASE</h2>";

// CONFIGURA LA TUA CONNESSIONE
$db_host = 'localhost';
$db_name = 'tuo_database';
$db_user = 'tuo_user';
$db_pass = 'tua_password';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ Connessione database OK</p>";
    
    // Verifica tabella subscriptions
    $stmt = $pdo->query("SHOW TABLES LIKE 'push_subscriptions'");
    if ($stmt->rowCount() > 0) {
        echo "<p class='ok'>✅ Tabella push_subscriptions esiste</p>";
        
        // Conta subscriptions
        $stmt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions");
        $count = $stmt->fetchColumn();
        echo "<p class='ok'>✅ Subscriptions registrate: $count</p>";
        
        if ($count == 0) {
            echo "<p class='warning'>⚠️ Nessuna subscription trovata! L'utente deve autorizzare le notifiche nel browser.</p>";
        } else {
            // Mostra le subscriptions
            $stmt = $pdo->query("SELECT * FROM push_subscriptions LIMIT 5");
            echo "<h3>📋 Subscriptions:</h3>";
            echo "<pre>";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                print_r($row);
                echo "\n---\n";
            }
            echo "</pre>";
        }
    } else {
        echo "<p class='error'>❌ Tabella push_subscriptions NON esiste</p>";
        echo "<p class='warning'>Crea la tabella con questo SQL:</p>";
        echo "<pre>
CREATE TABLE push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    endpoint TEXT NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
        </pre>";
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ ERRORE DATABASE: " . $e->getMessage() . "</p>";
}

// ==========================================
// 4. TEST INVIO PUSH
// ==========================================
echo "<h2>4️⃣ TEST INVIO PUSH</h2>";

if (isset($_GET['test_send']) && class_exists('Minishlink\WebPush\WebPush')) {
    
    echo "<p>🚀 Tentativo di invio push...</p>";
    
    try {
        // Prendi una subscription dal DB
        $stmt = $pdo->query("SELECT * FROM push_subscriptions ORDER BY created_at DESC LIMIT 1");
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subscription) {
            echo "<p class='error'>❌ Nessuna subscription trovata nel database!</p>";
        } else {
            echo "<p class='ok'>✅ Subscription trovata per user_id: " . ($subscription['user_id'] ?? 'N/A') . "</p>";
            
            $auth = [
                'VAPID' => [
                    'subject' => $vapid_subject,
                    'publicKey' => $vapid_public_key,
                    'privateKey' => $vapid_private_key,
                ]
            ];
            
            $webPush = new Minishlink\WebPush\WebPush($auth);
            
            $subscriptionData = \Minishlink\WebPush\Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'keys' => [
                    'p256dh' => $subscription['p256dh_key'],
                    'auth' => $subscription['auth_key']
                ]
            ]);
            
            $payload = json_encode([
                'title' => '🧪 Test Push',
                'body' => 'Se vedi questo messaggio, il push funziona!',
                'icon' => '/icon.png',
                'badge' => '/badge.png',
                'timestamp' => time()
            ]);
            
            $result = $webPush->sendOneNotification($subscriptionData, $payload);
            
            if ($result->isSuccess()) {
                echo "<p class='ok'>✅✅✅ PUSH INVIATO CON SUCCESSO!</p>";
                echo "<p>Controlla il browser, dovresti aver ricevuto la notifica!</p>";
            } else {
                echo "<p class='error'>❌ Errore nell'invio:</p>";
                echo "<pre>" . $result->getReason() . "</pre>";
                
                // Se la subscription è scaduta, eliminala
                if ($result->isSubscriptionExpired()) {
                    echo "<p class='warning'>⚠️ Subscription scaduta, la elimino...</p>";
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$subscription['id']]);
                }
            }
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ ERRORE: " . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
}

// ==========================================
// 5. CONTROLLI FINALI
// ==========================================
echo "<h2>5️⃣ CONTROLLI FINALI</h2>";

// Verifica estensioni PHP
$extensions = ['curl', 'openssl', 'mbstring'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p class='ok'>✅ Estensione $ext installata</p>";
    } else {
        echo "<p class='error'>❌ Estensione $ext NON installata</p>";
    }
}

// Verifica HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    echo "<p class='ok'>✅ Connessione HTTPS attiva</p>";
} else {
    echo "<p class='warning'>⚠️ Connessione NON HTTPS (le push richiedono HTTPS in produzione)</p>";
}

echo "<hr>";
echo "<h2>🎯 AZIONI</h2>";

if (!isset($_GET['test_send'])) {
    echo "<p><a href='?test_send=1' style='background:#0f0;color:#000;padding:10px 20px;text-decoration:none;font-weight:bold'>📤 INVIA PUSH DI TEST</a></p>";
}

echo "<hr>";
echo "<h2>📝 CHECKLIST</h2>";
echo "<ol>
<li>✓ Installa libreria: <code>composer require minishlink/web-push</code></li>
<li>✓ Crea tabella database push_subscriptions</li>
<li>✓ Genera chiavi VAPID su https://vapidkeys.com/</li>
<li>✓ Configura chiavi VAPID in questo script</li>
<li>✓ L'utente deve autorizzare le notifiche nel browser</li>
<li>✓ Salva la subscription nel database quando l'utente autorizza</li>
<li>✓ Usa HTTPS (obbligatorio in produzione)</li>
<li>✓ Verifica che il service worker sia registrato</li>
</ol>";

?>