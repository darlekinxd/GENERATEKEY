<?php
// Configura a sessão para durar 30 dias
ini_set('session.gc_maxlifetime', 2592000);
ini_set('session.cookie_lifetime', 2592000);
session_set_cookie_params([
    'lifetime' => 2592000,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

$db_file = 'db_keys.json';

function get_keys_db($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_keys_db($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// 1. API DO GAMEGUARDIAN / CHECAGEM
if (isset($_GET['action']) && $_GET['action'] === 'verify_key') {
    header('Content-Type: application/json');
    $user_key = $_GET['key'] ?? '';
    $hwid = $_GET['hwid'] ?? '';
    
    $keys = get_keys_db($db_file);

    if (!isset($keys[$user_key])) {
        echo json_encode(["status" => "invalid"]);
        exit;
    }

    $key_data = $keys[$user_key];

    if (time() > $key_data['expiration_timestamp']) {
        $keys[$user_key]['status'] = 'expired';
        save_keys_db($db_file, $keys);
        
        echo json_encode(["status" => "expired"]);
        exit;
    }

    if (empty($key_data['hwid'])) {
        $keys[$user_key]['hwid'] = $hwid;
        save_keys_db($db_file, $keys);
    } elseif ($key_data['hwid'] !== $hwid) {
        echo json_encode(["status" => "device_locked"]);
        exit;
    }

    echo json_encode(["status" => "success"]);
    exit;
}

// 2. PAINEL VISUAL (Exibido quando acessado pelo navegador)
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Keys</title>
    <style>
        body { font-family: Arial, sans-serif; background: #121212; color: #fff; text-align: center; padding: 50px; }
        .card { background: #1e1e1e; padding: 20px; border-radius: 8px; display: inline-block; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Gerenciador de Keys</h1>
        <p>Sistema online e operacional.</p>
        <!-- Adicione aqui os seus formulários de login/geração de keys -->
    </div>
</body>
</html>
