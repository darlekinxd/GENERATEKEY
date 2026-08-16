<?php
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
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function save_keys_db($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// 1. API GAMEGUARDIAN
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

    // Se expirou, só atualiza status (não apaga)
    if (time() > $key_data['expiration_timestamp']) {
        if ($keys[$user_key]['status'] !== 'expired') {
            $keys[$user_key]['status'] = 'expired';
            save_keys_db($db_file, $keys);
        }
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

// 2. AÇÕES DO PAINEL COM CONFIRMAÇÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $keys = get_keys_db($db_file);

    if ($action === 'generate_key') {
        $days = intval($_POST['days'] ?? 1);
        $new_key = "KEY-" . strtoupper(bin2hex(random_bytes(4)));
        
        $keys[$new_key] = [
            'created_at' => date('Y-m-d H:i:s'),
            'expiration_timestamp' => time() + ($days * 86400),
            'status' => 'active',
            'hwid' => ''
        ];
        save_keys_db($db_file, $keys);
    } 
    elseif ($action === 'reset_hwid') {
        $key_to_reset = $_POST['key'] ?? '';
        if (isset($keys[$key_to_reset])) {
            $keys[$key_to_reset]['hwid'] = '';
            save_keys_db($db_file, $keys);
        }
    } 
    elseif ($action === 'delete_key') {
        $key_to_delete = $_POST['key'] ?? '';
        if (isset($keys[$key_to_delete])) {
            unset($keys[$key_to_delete]);
            save_keys_db($db_file, $keys);
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$all_keys = get_keys_db($db_file);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel DarkEkin Cheats</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0f0f12; color: #e1e1e6; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .card { background: #18181b; border: 1px solid #27272a; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        h1, h2 { margin-bottom: 15px; color: #fff; }
        form { display: inline-flex; gap: 10px; }
        input, select, button { padding: 8px 12px; border-radius: 6px; border: 1px solid #27272a; background: #09090b; color: #fff; }
        button { background: #6366f1; cursor: pointer; border: none; font-weight: bold; }
        button:hover { background: #4f46e5; }
        button.danger { background: #ef4444; }
        button.danger:hover { background: #dc2626; }
        button.warning { background: #f59e0b; }
        button.warning:hover { background: #d97706; }
        table { width: 100%; border-collapse: collapse; text-align: left; margin-top: 10px; }
        th, td { padding: 12px; border-bottom: 1px solid #27272a; vertical-align: middle; }
        th { background: #27272a; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .active { background: #22c55e22; color: #22c55e; border: 1px solid #22c55e; }
        .expired { background: #ef444422; color: #ef4444; border: 1px solid #ef4444; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>Gerenciador de Keys</h1>
            <form method="POST" onsubmit="return confirm('Tem certeza que deseja GERAR uma nova Key?');">
                <input type="hidden" name="action" value="generate_key">
                <select name="days">
                    <option value="1">1 Dia</option>
                    <option value="7">7 Dias</option>
                    <option value="30">30 Dias</option>
                </select>
                <button type="submit">Gerar Nova Key</button>
            </form>
        </div>

        <div class="card">
            <h2>Keys Cadastradas</h2>
            <table>
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Expiração</th>
                        <th>Status</th>
                        <th>HWID</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_keys)): ?>
                        <tr><td colspan="5" style="text-align:center;">Nenhuma key encontrada.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_keys as $key => $data): 
                            $is_expired = time() > $data['expiration_timestamp'];
                            $status_class = $is_expired ? 'expired' : 'active';
                            $status_label = $is_expired ? 'EXPIRED' : strtoupper($data['status']);
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($key) ?></code></td>
                                <td><?= date('d/m/Y H:i', $data['expiration_timestamp']) ?></td>
                                <td><span class="badge <?= $status_class ?>"><?= $status_label ?></span></td>
                                <td><?= htmlspecialchars($data['hwid'] ?: 'Livre') ?></td>
                                <td>
                                    <!-- Reset HWID com confirmação -->
                                    <form method="POST" onsubmit="return confirm('Tem certeza que deseja RESETAR o HWID desta key?');">
                                        <input type="hidden" name="action" value="reset_hwid">
                                        <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                        <button type="submit" class="warning">Reset HWID</button>
                                    </form>

                                    <!-- Apagar Key com confirmação -->
                                    <form method="POST" onsubmit="return confirm('ATENÇÃO: Tem certeza que deseja DELETAR PERMANENTEMENTE esta key?');">
                                        <input type="hidden" name="action" value="delete_key">
                                        <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                        <button type="submit" class="danger">Apagar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
