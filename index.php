<?php
date_default_timezone_set('America/Sao_Paulo');

// Sessão Persistente
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
$config_file = 'db_config.json';

function get_data($file, $default = []) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default));
        return $default;
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

function save_data($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

$config = get_data($config_file, [
    'script_active' => true,
    'maintenance_mode' => false
]);

// 1. API GAMEGUARDIAN (Retorna respostas para o script Lua)
if (isset($_GET['action']) && $_GET['action'] === 'verify_key') {
    header('Content-Type: application/json');
    
    // Trava Global: Anti-Crack / Script Desativado
    if (!$config['script_active']) {
        echo json_encode(["status" => "script_disabled", "message" => "O script foi desativado pelo desenvolvedor."]);
        exit;
    }

    // Trava Global: Modo Manutenção
    if ($config['maintenance_mode']) {
        echo json_encode(["status" => "maintenance", "message" => "O sistema está em manutenção no momento."]);
        exit;
    }

    $user_key = $_GET['key'] ?? '';
    $hwid = $_GET['hwid'] ?? '';
    
    $keys = get_data($db_file);

    if (!isset($keys[$user_key])) {
        echo json_encode(["status" => "invalid"]);
        exit;
    }

    $key_data = $keys[$user_key];

    // Checa se a key individual foi desativada
    if (isset($key_data['enabled']) && $key_data['enabled'] === false) {
        echo json_encode(["status" => "key_disabled"]);
        exit;
    }

    // Checa expiração sem apagar a key
    if (time() > $key_data['expiration_timestamp']) {
        if ($keys[$user_key]['status'] !== 'expired') {
            $keys[$user_key]['status'] = 'expired';
            save_data($db_file, $keys);
        }
        echo json_encode(["status" => "expired"]);
        exit;
    }

    // Gerenciamento de limite de HWIDs por Key
    $hwids = $key_data['hwids'] ?? [];
    $max_devices = $key_data['max_devices'] ?? 1;

    if (!in_array($hwid, $hwids)) {
        if (count($hwids) < $max_devices) {
            $hwids[] = $hwid;
            $keys[$user_key]['hwids'] = $hwids;
            save_data($db_file, $keys);
        } else {
            echo json_encode(["status" => "device_limit_reached"]);
            exit;
        }
    }

    echo json_encode(["status" => "success"]);
    exit;
}

// 2. LÓGICA DO PAINEL DE CONTROLE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $keys = get_data($db_file);

    if ($action === 'generate_key') {
        $duration_type = $_POST['duration_type'] ?? 'days';
        $duration_value = intval($_POST['duration_value'] ?? 1);
        $max_devices = intval($_POST['max_devices'] ?? 1);
        $custom_name = trim($_POST['custom_name'] ?? '');
        
        $seconds = ($duration_type === 'hours') ? ($duration_value * 3600) : ($duration_value * 86400);
        $new_key = !empty($custom_name) ? $custom_name : "KEY-" . strtoupper(bin2hex(random_bytes(4)));
        
        $keys[$new_key] = [
            'created_at' => date('Y-m-d H:i:s'),
            'expiration_timestamp' => time() + $seconds,
            'status' => 'active',
            'enabled' => true,
            'max_devices' => $max_devices,
            'hwids' => []
        ];
        save_data($db_file, $keys);
    } 
    elseif ($action === 'toggle_key_status') {
        $key = $_POST['key'] ?? '';
        if (isset($keys[$key])) {
            $keys[$key]['enabled'] = !($keys[$key]['enabled'] ?? true);
            save_data($db_file, $keys);
        }
    }
    elseif ($action === 'reset_hwid') {
        $key = $_POST['key'] ?? '';
        if (isset($keys[$key])) {
            $keys[$key]['hwids'] = [];
            save_data($db_file, $keys);
        }
    } 
    elseif ($action === 'delete_key') {
        $key = $_POST['key'] ?? '';
        if (isset($keys[$key])) {
            unset($keys[$key]);
            save_data($db_file, $keys);
        }
    }
    elseif ($action === 'toggle_script') {
        $config['script_active'] = !$config['script_active'];
        save_data($config_file, $config);
    }
    elseif ($action === 'toggle_maintenance') {
        $config['maintenance_mode'] = !$config['maintenance_mode'];
        save_data($config_file, $config);
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$all_keys = get_data($db_file);
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
        .container { max-width: 1000px; margin: 0 auto; }
        .card { background: #18181b; border: 1px solid #27272a; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        h1, h2 { margin-bottom: 15px; color: #fff; }
        
        /* Abas */
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #27272a; padding-bottom: 10px; }
        .tab-btn { background: #27272a; padding: 10px 20px; border: none; color: #fff; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .tab-btn.active { background: #6366f1; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        form { display: inline-flex; gap: 10px; flex-wrap: wrap; }
        input, select, button { padding: 8px 12px; border-radius: 6px; border: 1px solid #27272a; background: #09090b; color: #fff; }
        button { background: #6366f1; cursor: pointer; border: none; font-weight: bold; }
        button:hover { background: #4f46e5; }
        button.danger { background: #ef4444; }
        button.warning { background: #f59e0b; }
        button.success { background: #22c55e; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; margin-top: 10px; }
        th, td { padding: 12px; border-bottom: 1px solid #27272a; vertical-align: middle; }
        th { background: #27272a; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .active { background: #22c55e22; color: #22c55e; border: 1px solid #22c55e; }
        .expired { background: #ef444422; color: #ef4444; border: 1px solid #ef4444; }
        .disabled { background: #6b728022; color: #9ca3af; border: 1px solid #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Painel de Controle GG</h1>

        <div class="nav-tabs">
            <button class="tab-btn active" onclick="openTab('keys-tab', this)">Gerenciador de Keys</button>
            <button class="tab-btn" onclick="openTab('security-tab', this)">Segurança / Anti-Crack</button>
        </div>

        <!-- ABA 1: GERENCIADOR DE KEYS -->
        <div id="keys-tab" class="tab-content active">
            <div class="card">
                <h2>Gerar Nova Key</h2>
                <form method="POST" onsubmit="return confirm('Você tem certeza que quer GERAR esta Key?');">
                    <input type="hidden" name="action" value="generate_key">
                    <input type="text" name="custom_name" placeholder="Nome da Key (Opcional)">
                    
                    <select name="duration_type" id="dur_type" onchange="updateDurationOptions()">
                        <option value="hours">Horas</option>
                        <option value="days" selected>Dias</option>
                    </select>

                    <select name="duration_value" id="dur_val">
                        <!-- Preenchido dinamicamente via JS -->
                    </select>

                    <input type="number" name="max_devices" value="1" min="1" max="100" title="Limite de Pessoas/Dispositivos" placeholder="Limite Pessoas">
                    
                    <button type="submit">Gerar Key</button>
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
                            <th>Uso Dispositivos</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_keys)): ?>
                            <tr><td colspan="5" style="text-align:center;">Nenhuma key encontrada.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_keys as $key => $data): 
                                $is_expired = time() > $data['expiration_timestamp'];
                                $is_enabled = $data['enabled'] ?? true;
                                $hwid_count = count($data['hwids'] ?? []);
                                $max_devices = $data['max_devices'] ?? 1;

                                if (!$is_enabled) {
                                    $status_class = 'disabled';
                                    $status_label = 'DESATIVADA';
                                } elseif ($is_expired) {
                                    $status_class = 'expired';
                                    $status_label = 'EXPIRADA';
                                } else {
                                    $status_class = 'active';
                                    $status_label = 'ATIVA';
                                }
                            ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($key) ?></code></td>
                                    <td><?= date('d/m/Y H:i', $data['expiration_timestamp']) ?></td>
                                    <td><span class="badge <?= $status_class ?>"><?= $status_label ?></span></td>
                                    <td><?= $hwid_count ?> / <?= $max_devices ?> Pessoas</td>
                                    <td style="display:flex; gap: 5px;">
                                        <!-- Ativar/Desativar Key -->
                                        <form method="POST" onsubmit="return confirm('Você tem certeza que quer ALTERAR o status desta key?');">
                                            <input type="hidden" name="action" value="toggle_key_status">
                                            <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                            <button type="submit" class="<?= $is_enabled ? 'warning' : 'success' ?>">
                                                <?= $is_enabled ? 'Desativar' : 'Ativar' ?>
                                            </button>
                                        </form>

                                        <!-- Reset HWID -->
                                        <form method="POST" onsubmit="return confirm('Você tem certeza que quer RESETAR os HWIDs desta key?');">
                                            <input type="hidden" name="action" value="reset_hwid">
                                            <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                            <button type="submit">Reset HWID</button>
                                        </form>

                                        <!-- Apagar Key -->
                                        <form method="POST" onsubmit="return confirm('ATENÇÃO: Você tem certeza que quer DELETAR esta key?');">
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

        <!-- ABA 2: SEGURANÇA E MANUTENÇÃO GLOBAL -->
        <div id="security-tab" class="tab-content">
            <div class="card">
                <h2>Controle Global do Script (Anti-Crack)</h2>
                <p style="margin-bottom: 15px; color: #a1a1aa;">Use esta opção caso o script seja vazado ou crackeado. Isso bloqueia o acesso imediatamente para todos os usuários.</p>
                
                <form method="POST" onsubmit="return confirm('ATENÇÃO: Você tem certeza que quer ALTERAR o status global do Script?');">
                    <input type="hidden" name="action" value="toggle_script">
                    <button type="submit" class="<?= $config['script_active'] ? 'danger' : 'success' ?>">
                        <?= $config['script_active'] ? 'DESATIVAR SCRIPT COMPLETAMENTE' : 'ATIVAR SCRIPT' ?>
                    </button>
                </form>
                <p style="margin-top: 10px;">Status Atual: <strong><?= $config['script_active'] ? '<span style="color:#22c55e;">ONLINE</span>' : '<span style="color:#ef4444;">DESATIVADO (BLOQUEADO)</span>' ?></strong></p>
            </div>

            <div class="card">
                <h2>Modo Manutenção</h2>
                <p style="margin-bottom: 15px; color: #a1a1aa;">Coloca todas as keys em estado de manutenção temporária.</p>
                
                <form method="POST" onsubmit="return confirm('Você tem certeza que quer ALTERAR o modo de manutenção?');">
                    <input type="hidden" name="action" value="toggle_maintenance">
                    <button type="submit" class="warning">
                        <?= $config['maintenance_mode'] ? 'TIRAR DE MANUTENÇÃO' : 'COLOCAR TUDO EM MANUTENÇÃO' ?>
                    </button>
                </form>
                <p style="margin-top: 10px;">Status de Manutenção: <strong><?= $config['maintenance_mode'] ? '<span style="color:#f59e0b;">EM MANUTENÇÃO</span>' : '<span style="color:#22c55e;">NORMAL</span>' ?></strong></p>
            </div>
        </div>
    </div>

    <script>
        function openTab(tabId, element) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            element.classList.add('active');
        }

        function updateDurationOptions() {
            const type = document.getElementById('dur_type').value;
            const select = document.getElementById('dur_val');
            select.innerHTML = '';

            if (type === 'hours') {
                [1, 2, 5].forEach(h => {
                    select.innerHTML += `<option value="${h}">${h} Hora(s)</option>`;
                });
            } else {
                [1, 3, 7, 15, 30].forEach(d => {
                    select.innerHTML += `<option value="${d}">${d} Dia(s)</option>`;
                });
            }
        }

        // Inicializa o select com os valores padrão
        updateDurationOptions();
    </script>
</body>
</html>
