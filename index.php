<?php
date_default_timezone_set('America/Sao_Paulo');

// Sessão Persistente (30 Dias)
ini_set('session.gc_maxlifetime', 2592000);
ini_set('session.cookie_lifetime', 2592000);
session_set_cookie_params(['lifetime' => 2592000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Arquivos de Banco de Dados
$db_keys = 'db_keys.json';
$db_config = 'db_config.json';
$db_users = 'db_users.json';
$db_invites = 'db_invites.json';

function get_data($file, $default = []) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default));
        return $default;
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function save_data($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// Configurações e Conta Admin Padrão
$config = get_data($db_config, ['script_active' => true, 'maintenance_mode' => false]);
$users = get_data($db_users);
if (empty($users)) {
    $users['admin'] = ['password' => password_hash('admin', PASSWORD_DEFAULT), 'role' => 'admin', 'monthly_limit' => 9999];
    save_data($db_users, $users);
}
$invites = get_data($db_invites);

// ==========================================
// 1. API GAMEGUARDIAN (Fix Erro da Tela HTML)
// ==========================================
$is_api_request = isset($_GET['key']) || (isset($_GET['action']) && $_GET['action'] === 'verify_key');

if ($is_api_request) {
    header('Content-Type: application/json');
    
    if (!$config['script_active']) {
        echo json_encode(["status" => "script_disabled", "message" => "O script foi desativado pelo desenvolvedor."]); exit;
    }
    if ($config['maintenance_mode']) {
        echo json_encode(["status" => "maintenance", "message" => "O sistema está em manutenção no momento."]); exit;
    }

    $user_key = $_GET['key'] ?? '';
    $hwid = $_GET['hwid'] ?? '';
    $keys = get_data($db_keys);

    if (!isset($keys[$user_key])) {
        echo json_encode(["status" => "invalid"]); exit;
    }

    $key_data = $keys[$user_key];

    if (isset($key_data['enabled']) && $key_data['enabled'] === false) {
        echo json_encode(["status" => "key_disabled"]); exit;
    }

    // SISTEMA DE CONTAGEM: Só inicia no PRIMEIRO USO
    if (empty($key_data['first_used_at']) || $key_data['status'] === 'pending') {
        $keys[$user_key]['first_used_at'] = time();
        $keys[$user_key]['expiration_timestamp'] = time() + $key_data['duration_seconds'];
        $keys[$user_key]['status'] = 'active';
        $key_data = $keys[$user_key];
        save_data($db_keys, $keys);
    }

    // Checa expiração
    if (time() > $key_data['expiration_timestamp']) {
        if ($keys[$user_key]['status'] !== 'expired') {
            $keys[$user_key]['status'] = 'expired';
            save_data($db_keys, $keys);
        }
        echo json_encode(["status" => "expired"]); exit;
    }

    // Trava de HWID (Dispositivos Permitidos)
    $hwids = $key_data['hwids'] ?? [];
    $max_devices = $key_data['max_devices'] ?? 1;

    if (!in_array($hwid, $hwids)) {
        if (count($hwids) < $max_devices) {
            $hwids[] = $hwid;
            $keys[$user_key]['hwids'] = $hwids;
            save_data($db_keys, $keys);
        } else {
            echo json_encode(["status" => "device_limit_reached"]); exit;
        }
    }

    echo json_encode(["status" => "success", "expires_in" => $key_data['expiration_timestamp'] - time()]); exit;
}


// ==========================================
// 2. SISTEMA DE LOGIN E REGISTRO
// ==========================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    if ($_POST['auth_action'] === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            $_SESSION['user'] = $username;
            $_SESSION['role'] = $users[$username]['role'];
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        } else {
            $message = "Usuário ou senha incorretos!";
        }
    } 
    elseif ($_POST['auth_action'] === 'register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $invite = trim($_POST['invite'] ?? '');
        
        if (isset($users[$username])) {
            $message = "Este usuário já existe!";
        } elseif (!isset($invites[$invite])) {
            $message = "Convite inválido ou já utilizado!";
        } else {
            $users[$username] = [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'user', // Contas registradas por convite são sempre usuários normais
                'monthly_limit' => 50 // Limite padrão de 50 keys por mês
            ];
            unset($invites[$invite]); // Queima o convite
            save_data($db_users, $users);
            save_data($db_invites, $invites);
            $message = "Conta criada com sucesso! Faça login.";
        }
    }
    elseif ($_POST['auth_action'] === 'logout') {
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
}

// Se não estiver logado, exibe a tela de Login/Registro e PARA o script aqui.
if (!isset($_SESSION['user'])) {
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DarkEkin Cheats</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0f0f12; color: #e1e1e6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .auth-box { background: #18181b; padding: 30px; border-radius: 8px; border: 1px solid #27272a; width: 100%; max-width: 400px; text-align: center; }
        input, button { width: 100%; padding: 10px; margin-top: 10px; border-radius: 6px; border: 1px solid #27272a; background: #09090b; color: #fff; box-sizing: border-box; }
        button { background: #6366f1; cursor: pointer; font-weight: bold; border: none; margin-top: 20px; }
        button:hover { background: #4f46e5; }
        .toggle-link { color: #6366f1; cursor: pointer; text-decoration: underline; margin-top: 15px; display: inline-block; font-size: 14px; }
        .msg { color: #ef4444; margin-bottom: 15px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="auth-box">
        <h2>Área Restrita</h2>
        <?php if ($message): ?><div class="msg"><?= $message ?></div><?php endif; ?>
        
        <form id="login-form" method="POST">
            <input type="hidden" name="auth_action" value="login">
            <input type="text" name="username" placeholder="Usuário" required>
            <input type="password" name="password" placeholder="Senha" required>
            <button type="submit">Entrar</button>
            <a class="toggle-link" onclick="toggleAuth()">Precisa de uma conta? Use um Convite</a>
        </form>

        <form id="register-form" method="POST" style="display: none;">
            <input type="hidden" name="auth_action" value="register">
            <input type="text" name="username" placeholder="Novo Usuário" required>
            <input type="password" name="password" placeholder="Nova Senha" required>
            <input type="text" name="invite" placeholder="Código do Convite" required>
            <button type="submit">Criar Conta</button>
            <a class="toggle-link" onclick="toggleAuth()">Já tem conta? Faça Login</a>
        </form>
    </div>
    <script>
        function toggleAuth() {
            var login = document.getElementById('login-form');
            var reg = document.getElementById('register-form');
            if (login.style.display === 'none') { login.style.display = 'block'; reg.style.display = 'none'; } 
            else { login.style.display = 'none'; reg.style.display = 'block'; }
        }
    </script>
</body>
</html>
<?php 
    exit; 
}


// ==========================================
// 3. AÇÕES DO PAINEL (Usuários Logados)
// ==========================================
$current_user = $_SESSION['user'];
$is_admin = ($_SESSION['role'] === 'admin');
$keys = get_data($db_keys);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'generate_key') {
        // Conta as keys geradas por este usuário neste mês
        $current_month = date('Y-m');
        $user_keys_this_month = 0;
        foreach ($keys as $k => $v) {
            if (($v['created_by'] ?? '') === $current_user && strpos($v['created_at'], $current_month) === 0) {
                $user_keys_this_month++;
            }
        }

        $user_limit = $users[$current_user]['monthly_limit'] ?? 50;

        if (!$is_admin && $user_keys_this_month >= $user_limit) {
            echo "<script>alert('Você atingiu seu limite mensal de $user_limit keys!'); window.location.href='?';</script>"; exit;
        }

        $duration_type = $_POST['duration_type'] ?? 'days';
        $duration_value = intval($_POST['duration_value'] ?? 1);
        $max_devices = intval($_POST['max_devices'] ?? 1);
        $custom_name = trim($_POST['custom_name'] ?? '');
        
        $seconds = ($duration_type === 'hours') ? ($duration_value * 3600) : ($duration_value * 86400);
        $new_key = !empty($custom_name) ? $custom_name : "KEY-" . strtoupper(bin2hex(random_bytes(4)));
        
        $keys[$new_key] = [
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $current_user,
            'duration_seconds' => $seconds,
            'first_used_at' => null, // Marcador de início de uso
            'expiration_timestamp' => null,
            'status' => 'pending', // Fica pendente até ser injetada
            'enabled' => true,
            'max_devices' => $max_devices,
            'hwids' => []
        ];
        save_data($db_keys, $keys);
    } 
    elseif ($action === 'toggle_key_status' && ($is_admin || $keys[$_POST['key']]['created_by'] === $current_user)) {
        $keys[$_POST['key']]['enabled'] = !($keys[$_POST['key']]['enabled'] ?? true);
        save_data($db_keys, $keys);
    }
    elseif ($action === 'reset_hwid' && ($is_admin || $keys[$_POST['key']]['created_by'] === $current_user)) {
        $keys[$_POST['key']]['hwids'] = [];
        save_data($db_keys, $keys);
    } 
    elseif ($action === 'delete_key' && ($is_admin || $keys[$_POST['key']]['created_by'] === $current_user)) {
        unset($keys[$_POST['key']]);
        save_data($db_keys, $keys);
    }
    
    // Ações exclusivas de Admin
    if ($is_admin) {
        if ($action === 'toggle_script') {
            $config['script_active'] = !$config['script_active'];
            save_data($db_config, $config);
        }
        elseif ($action === 'toggle_maintenance') {
            $config['maintenance_mode'] = !$config['maintenance_mode'];
            save_data($db_config, $config);
        }
        elseif ($action === 'generate_invite') {
            $new_inv = "INVITE-" . strtoupper(bin2hex(random_bytes(3)));
            $invites[$new_inv] = true;
            save_data($db_invites, $invites);
        }
        elseif ($action === 'delete_invite') {
            unset($invites[$_POST['invite_code']]);
            save_data($db_invites, $invites);
        }
        elseif ($action === 'update_limit') {
            $target_user = $_POST['target_user'];
            if (isset($users[$target_user])) {
                $users[$target_user]['monthly_limit'] = intval($_POST['new_limit']);
                save_data($db_users, $users);
            }
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel - DarkEkin Cheats</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0f0f12; color: #e1e1e6; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: #18181b; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #27272a; }
        .card { background: #18181b; border: 1px solid #27272a; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        h1, h2 { margin-bottom: 15px; color: #fff; }
        
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #27272a; padding-bottom: 10px; flex-wrap: wrap; }
        .tab-btn { background: #27272a; padding: 10px 20px; border: none; color: #fff; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .tab-btn.active { background: #6366f1; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        form { display: inline-flex; gap: 10px; flex-wrap: wrap; align-items: center;}
        input, select, button { padding: 8px 12px; border-radius: 6px; border: 1px solid #27272a; background: #09090b; color: #fff; }
        button { background: #6366f1; cursor: pointer; border: none; font-weight: bold; }
        button:hover { background: #4f46e5; }
        button.danger { background: #ef4444; }
        button.warning { background: #f59e0b; }
        button.success { background: #22c55e; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; margin-top: 10px; font-size: 14px; }
        th, td { padding: 10px; border-bottom: 1px solid #27272a; vertical-align: middle; }
        th { background: #27272a; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .active { background: #22c55e22; color: #22c55e; border: 1px solid #22c55e; }
        .expired { background: #ef444422; color: #ef4444; border: 1px solid #ef4444; }
        .disabled { background: #6b728022; color: #9ca3af; border: 1px solid #6b7280; }
        .pending { background: #3b82f622; color: #3b82f6; border: 1px solid #3b82f6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <h2>Painel DarkEkin</h2>
            <div style="display: flex; gap: 15px; align-items: center;">
                <span>Logado como: <strong><?= htmlspecialchars($current_user) ?></strong> (<?= strtoupper($_SESSION['role']) ?>)</span>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="auth_action" value="logout">
                    <button type="submit" class="danger">Sair</button>
                </form>
            </div>
        </div>

        <div class="nav-tabs">
            <button class="tab-btn active" onclick="openTab('keys-tab', this)">Gerenciador de Keys</button>
            <?php if ($is_admin): ?>
            <button class="tab-btn" onclick="openTab('users-tab', this)">Usuários & Convites</button>
            <button class="tab-btn" onclick="openTab('security-tab', this)">Segurança Global</button>
            <?php endif; ?>
        </div>

        <!-- ABA 1: GERENCIADOR DE KEYS -->
        <div id="keys-tab" class="tab-content active">
            <div class="card">
                <h2>Gerar Nova Key</h2>
                <?php 
                    $current_month = date('Y-m');
                    $user_keys_this_month = 0;
                    foreach ($keys as $k => $v) {
                        if (($v['created_by'] ?? '') === $current_user && strpos($v['created_at'], $current_month) === 0) {
                            $user_keys_this_month++;
                        }
                    }
                    $limit = $users[$current_user]['monthly_limit'] ?? 50;
                ?>
                <p style="margin-bottom: 10px; font-size: 13px; color: #a1a1aa;">
                    Você gerou <?= $user_keys_this_month ?> de <?= $is_admin ? 'ILIMITADAS' : $limit ?> keys este mês.
                    A Key só começa a consumir tempo de uso a partir do momento em que for inserida no script pela primeira vez.
                </p>

                <form method="POST" onsubmit="return confirm('Você tem certeza que quer GERAR esta Key?');">
                    <input type="hidden" name="action" value="generate_key">
                    <input type="text" name="custom_name" placeholder="Nome da Key (Opcional)">
                    <select name="duration_type" id="dur_type" onchange="updateDurationOptions()">
                        <option value="hours">Horas</option>
                        <option value="days" selected>Dias</option>
                    </select>
                    <select name="duration_value" id="dur_val"></select>
                    <input type="number" name="max_devices" value="1" min="1" max="100" title="Limite de Pessoas" placeholder="Limite Pessoas" style="width:120px;">
                    <button type="submit">Gerar Key</button>
                </form>
            </div>

            <div class="card" style="overflow-x: auto;">
                <h2>Keys (<?= $is_admin ? 'Todas' : 'Suas' ?>)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Status/Tempo</th>
                            <th>Criador</th>
                            <th>Uso Dispositivos</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($keys) as $key => $data): 
                            // Mostrar apenas as keys do usuário se não for admin
                            if (!$is_admin && ($data['created_by'] ?? '') !== $current_user) continue;

                            $is_enabled = $data['enabled'] ?? true;
                            $hwid_count = count($data['hwids'] ?? []);
                            $max_devices = $data['max_devices'] ?? 1;
                            
                            $status_class = ''; $status_label = '';
                            
                            if (!$is_enabled) {
                                $status_class = 'disabled'; $status_label = 'DESATIVADA';
                            } elseif (empty($data['first_used_at']) || $data['status'] === 'pending') {
                                $status_class = 'pending'; $status_label = 'NÃO INICIADA';
                            } elseif (time() > $data['expiration_timestamp']) {
                                $status_class = 'expired'; $status_label = 'EXPIRADA';
                            } else {
                                $status_class = 'active'; $status_label = 'EM USO';
                            }
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($key) ?></code></td>
                                <td>
                                    <span class="badge <?= $status_class ?>"><?= $status_label ?></span>
                                    <div style="font-size:11px; margin-top:4px; color:#a1a1aa;">
                                        <?php if ($status_label === 'EM USO'): ?>
                                            Expira: <?= date('d/m H:i', $data['expiration_timestamp']) ?>
                                        <?php elseif ($status_label === 'NÃO INICIADA'): ?>
                                            Duração: <?= $data['duration_seconds'] / ($data['duration_seconds'] >= 86400 ? 86400 : 3600) ?> <?= $data['duration_seconds'] >= 86400 ? 'Dias' : 'Horas' ?>
                                        <?php endif; ?>
                           
