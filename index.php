<?php
// Configura a sessão para durar 30 dias e não deslogar no Render
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

// Lê o banco de dados
function get_keys_db($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// Salva sem apagar nenhuma chave
function save_keys_db($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Verificação de Acesso / API do GameGuardian
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

    // Checa expiração
    if (time() > $key_data['expiration_timestamp']) {
        // NÃO USA UNSET/DELETE: Apenas atualiza o status visual
        $keys[$user_key]['status'] = 'expired';
        save_keys_db($db_file, $keys);
        
        echo json_encode(["status" => "expired"]);
        exit;
    }

    // Trava de HWID / Dispositivo
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
?>
