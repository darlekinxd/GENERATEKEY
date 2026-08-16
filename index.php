<?php
date_default_timezone_set('America/Sao_Paulo');
ini_set('session.gc_maxlifetime', 2592000);
ini_set('session.cookie_lifetime', 2592000);
session_set_cookie_params(['lifetime' => 2592000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$db_keys = 'db_keys.json'; $db_config = 'db_config.json';
$db_users = 'db_users.json'; $db_invites = 'db_invites.json';

function get_data($file, $default = []) {
    if (!file_exists($file)) { file_put_contents($file, json_encode($default)); return $default; }
    $data = json_decode(file_get_contents($file), true); return is_array($data) ? $data : $default;
}
function save_data($file, $data) { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX); }

$config = get_data($db_config, ['script_active' => true, 'maintenance_mode' => false]);
$users = get_data($db_users);
if (empty($users)) {
    $users['admin'] = ['password' => password_hash('admin', PASSWORD_DEFAULT), 'role' => 'admin', 'monthly_limit' => 9999];
    save_data($db_users, $users);
}
$invites = get_data($db_invites);

$is_api_request = isset($_GET['key']) || (isset($_GET['action']) && $_GET['action'] === 'verify_key');
if ($is_api_request) {
    header('Content-Type: application/json');
    if (!$config['script_active']) { echo json_encode(["status" => "script_disabled", "message" => "Desativado"]); exit; }
    if ($config['maintenance_mode']) { echo json_encode(["status" => "maintenance", "message" => "Manutencao"]); exit; }
    
    $user_key = $_GET['key'] ?? ''; $hwid = $_GET['hwid'] ?? ''; $keys = get_data($db_keys);
    if (!isset($keys[$user_key])) { echo json_encode(["status" => "invalid"]); exit; }
    
    $key_data = $keys[$user_key];
    if (isset($key_data['enabled']) && $key_data['enabled'] === false) { echo json_encode(["status" => "key_disabled"]); exit; }
    
    if (empty($key_data['first_used_at']) || $key_data['status'] === 'pending') {
        $keys[$user_key]['first_used_at'] = time();
        $keys[$user_key]['expiration_timestamp'] = time() + $key_data['duration_seconds'];
        $keys[$user_key]['status'] = 'active';
        $key_data = $keys[$user_key];
        save_data($db_keys, $keys);
    }
    if (time() > $key_data['expiration_timestamp']) {
        if ($keys[$user_key]['status'] !== 'expired') { $keys[$user_key]['status'] = 'expired'; save_data($db_keys, $keys); }
        echo json_encode(["status" => "expired"]); exit;
    }
    
    $hwids = $key_data['hwids'] ?? []; $max_devices = $key_data['max_devices'] ?? 1;
    if (!in_array($hwid, $hwids)) {
        if (count($hwids) < $max_devices) { $hwids[] = $hwid; $keys[$user_key]['hwids'] = $hwids; save_data($db_keys, $keys); } 
        else { echo json_encode(["status" => "device_limit_reached"]); exit; }
    }
    echo json_encode(["status" => "success", "expires_in" => $key_data['expiration_timestamp'] - time()]); exit;
}
?>
<?php
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    if ($_POST['auth_action'] === 'login') {
        $username = trim($_POST['username'] ?? ''); $password = $_POST['password'] ?? '';
        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            $_SESSION['user'] = $username; $_SESSION['role'] = $users[$username]['role'];
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        } else { $message = "Usuário/senha incorretos!"; }
    } elseif ($_POST['auth_action'] === 'register') {
        $username = trim($_POST['username'] ?? ''); $password = $_POST['password'] ?? ''; $invite = trim($_POST['invite'] ?? '');
        if (isset($users[$username])) { $message = "Usuário já existe!"; } 
        elseif (!isset($invites[$invite])) { $message = "Convite inválido!"; } 
        else {
            $users[$username] = ['password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'user', 'monthly_limit' => 50];
            unset($invites[$invite]); save_data($db_users, $users); save_data($db_invites, $invites);
            $message = "Conta criada! Faça login.";
        }
    } elseif ($_POST['auth_action'] === 'logout') { session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit; }
}

if (!isset($_SESSION['user'])) {
    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Login</title><style>body{background:#0f0f12;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif}.box{background:#18181b;padding:30px;border-radius:8px;text-align:center;width:100%;max-width:400px}input,button{width:100%;padding:10px;margin-top:10px;border-radius:6px;border:1px solid #27272a;background:#09090b;color:#fff}button{background:#6366f1;cursor:pointer;font-weight:bold}button:hover{background:#4f46e5}a{color:#6366f1;cursor:pointer;display:block;margin-top:15px}.msg{color:#ef4444;font-weight:bold}</style></head><body><div class="box"><h2>Login</h2>';
    if ($message) echo '<div class="msg">'.$message.'</div>';
    echo '<form id="l" method="POST"><input type="hidden" name="auth_action" value="login"><input type="text" name="username" placeholder="Usuário" required><input type="password" name="password" placeholder="Senha" required><button type="submit">Entrar</button><a onclick="document.getElementById(\'l\').style.display=\'none\';document.getElementById(\'r\').style.display=\'block\'">Usar Convite</a></form><form id="r" method="POST" style="display:none;"><input type="hidden" name="auth_action" value="register"><input type="text" name="username" placeholder="Novo Usuário" required><input type="password" name="password" placeholder="Nova Senha" required><input type="text" name="invite" placeholder="Convite" required><button type="submit">Criar Conta</button><a onclick="document.getElementById(\'r\').style.display=\'none\';document.getElementById(\'l\').style.display=\'block\'">Fazer Login</a></form></div></body></html>';
    exit;
}

$current_user = $_SESSION['user']; $is_admin = ($_SESSION['role'] === 'admin'); $keys = get_data($db_keys);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'generate_key') {
        $m = date('Y-m'); $c = 0; foreach ($keys as $k => $v) { if (($v['created_by'] ?? '') === $current_user && strpos($v['created_at'], $m) === 0) $c++; }
        $lim = $users[$current_user]['monthly_limit'] ?? 50;
        if (!$is_admin && $c >= $lim) { echo "<script>alert('Limite atingido!'); window.location.href='?';</script>"; exit; }
        
        $sec = ($_POST['duration_type'] === 'hours') ? intval($_POST['duration_value']) * 3600 : intval($_POST['duration_value']) * 86400;
        $new_key = !empty($_POST['custom_name']) ? trim($_POST['custom_name']) : "KEY-" . strtoupper(bin2hex(random_bytes(4)));
        
        $keys[$new_key] = ['created_at' => date('Y-m-d H:i:s'), 'created_by' => $current_user, 'duration_seconds' => $sec, 'first_used_at' => null, 'expiration_timestamp' => null, 'status' => 'pending', 'enabled' => true, 'max_devices' => intval($_POST['max_devices'] ?? 1), 'hwids' => []];
        save_data($db_keys, $keys);
    } 
    elseif ($action === 'toggle_key_status' && ($is_admin || $keys[$_POST['key']]['created_by'] === $current_user)) { $keys[$_POST['key']]['enabled'] = !($keys[$_POST['key']]['enabled'] ?? true); save_data($db_keys, $keys); }
    elseif ($action === 'reset_hwid' && ($is_admin || $keys[$_POST['key']]['created_by'] === $current_user)) { $keys[$_POST['key']]['hwids'] = []; save_data($db_keys, $keys); }
    elseif ($action === 'delete_key' && ($is_admin || $keys[$_POST['key']]['created_by'] === $current_user)) { unset($keys[$_POST['key']]); save_data($db_keys, $keys); }
    
    if ($is_admin) {
        if ($action === 'toggle_script') { $config['script_active'] = !$config['script_active']; save_data($db_config, $config); }
        elseif ($action === 'toggle_maintenance') { $config['maintenance_mode'] = !$config['maintenance_mode']; save_data($db_config, $config); }
        elseif ($action === 'generate_invite') { $invites["INVITE-" . strtoupper(bin2hex(random_bytes(3)))] = true; save_data($db_invites, $invites); }
        elseif ($action === 'delete_invite') { unset($invites[$_POST['invite_code']]); save_data($db_invites, $invites); }
        elseif ($action === 'update_limit' && isset($users[$_POST['target_user']])) { $users[$_POST['target_user']]['monthly_limit'] = intval($_POST['new_limit']); save_data($db_users, $users); }
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Painel</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; } body { font-family: sans-serif; background: #0f0f12; color: #e1e1e6; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; } .card { background: #18181b; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #27272a; overflow-x: auto; }
        .nav { display: flex; gap: 10px; margin-bottom: 20px; } .btn { padding: 8px 15px; border-radius: 6px; border: none; color: #fff; cursor: pointer; font-weight: bold; background: #27272a; }
        .btn.active, .btn-primary { background: #6366f1; } .btn-danger { background: #ef4444; } .btn-warn { background: #f59e0b; } .btn-succ { background: #22c55e; }
        .tab { display: none; } .tab.active { display: block; } table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border-bottom: 1px solid #27272a; text-align: left; } th { background: #27272a; } form { display: inline-flex; gap: 5px; }
        input, select { padding: 8px; border-radius: 6px; border: 1px solid #27272a; background: #09090b; color: #fff; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; background: #3f3f46; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card" style="display:flex; justify-content:space-between; align-items:center;">
            <h2>Painel DarkEkin</h2>
            <div><?= htmlspecialchars($current_user) ?> (<?= strtoupper($_SESSION['role']) ?>) 
            <form method="POST" style="margin:0;"><input type="hidden" name="auth_action" value="logout"><button class="btn btn-danger">Sair</button></form></div>
        </div>
        <div class="nav">
            <button class="btn active" onclick="s('k', this)">Keys</button>
            <?php if ($is_admin): ?><button class="btn" onclick="s('u', this)">Usuários</button><button class="btn" onclick="s('sec', this)">Segurança</button><?php endif; ?>
        </div>

        <div id="k" class="tab active">
            <div class="card">
                <h3>Gerar Key</h3><br>
                <form method="POST">
                    <input type="hidden" name="action" value="generate_key">
                    <input type="text" name="custom_name" placeholder="Nome (Opcional)">
                    <select name="duration_type" id="dt" onchange="ud()"><option value="hours">Horas</option><option value="days" selected>Dias</option></select>
                    <select name="duration_value" id="dv"></select>
                    <input type="number" name="max_devices" value="1" min="1" placeholder="Max Pessoas" style="width:100px;">
                    <button class="btn btn-primary">Gerar</button>
                </form>
            </div>
            <div class="card">
                <h3>Lista de Keys</h3>
                <table>
                    <tr><th>Key</th><th>Status</th><th>Criador</th><th>Dispositivos</th><th>Ações</th></tr>
                    <?php foreach (array_reverse($keys, true) as $key => $data): if (!$is_admin && ($data['created_by'] ?? '') !== $current_user) continue; ?>
                    <tr>
                        <td><code><?= htmlspecialchars($key) ?></code></td>
                        <td><span class="badge"><?= !($data['enabled']??true) ? 'DESATIVADA' : (empty($data['first_used_at']) ? 'NÃO INICIADA' : (time() > $data['expiration_timestamp'] ? 'EXPIRADA' : 'EM USO')) ?></span></td>
                        <td><?= htmlspecialchars($data['created_by'] ?? '-') ?></td>
                        <td><?= count($data['hwids']??[]) ?>/<?= $data['max_devices']??1 ?></td>
                        <td>
                            <form method="POST"><input type="hidden" name="action" value="toggle_key_status"><input type="hidden" name="key" value="<?= $key ?>"><button class="btn btn-warn">Status</button></form>
                            <form method="POST"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="key" value="<?= $key ?>"><button class="btn btn-primary">Reset</button></form>
                            <form method="POST"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= $key ?>"><button class="btn btn-danger">X</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <?php if ($is_admin): ?>
        <div id="u" class="tab">
            <div class="card">
                <h3>Convites</h3><br>
                <form method="POST"><input type="hidden" name="action" value="generate_invite"><button class="btn btn-succ">Gerar Convite</button></form>
                <table style="margin-top:15px;">
                    <?php foreach ($invites as $inv => $v): ?><tr><td><code><?= $inv ?></code></td><td><form method="POST"><input type="hidden" name="action" value="delete_invite"><input type="hidden" name="invite_code" value="<?= $inv ?>"><button class="btn btn-danger">Apagar</button></form></td></tr><?php endforeach; ?>
                </table>
            </div>
            <div class="card">
                <h3>Usuários</h3>
                <table>
                    <tr><th>Usuário</th><th>Cargo</th><th>Limite Mensal</th></tr>
                    <?php foreach ($users as $u => $ud): ?>
                    <tr>
                        <td><?= htmlspecialchars($u) ?></td><td><?= strtoupper($ud['role']) ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_limit"><input type="hidden" name="target_user" value="<?= $u ?>">
                                <input type="number" name="new_limit" value="<?= $ud['monthly_limit']??50 ?>" style="width:80px;" <?= $ud['role']==='admin'?'disabled':'' ?>>
                                <?php if($ud['role']!=='admin'): ?><button class="btn btn-primary">Salvar</button><?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <div id="sec" class="tab">
            <div class="card">
                <h3>Anti-Crack (Script Global)</h3><br>
                <form method="POST"><input type="hidden" name="action" value="toggle_script"><button class="btn <?= $config['script_active'] ? 'btn-danger' : 'btn-succ' ?>"><?= $config['script_active'] ? 'DESATIVAR SCRIPT PARA TODOS' : 'ATIVAR SCRIPT' ?></button></form>
            </div>
            <div class="card">
                <h3>Modo Manutenção</h3><br>
                <form method="POST"><input type="hidden" name="action" value="toggle_maintenance"><button class="btn btn-warn"><?= $config['maintenance_mode'] ? 'TIRAR MANUTENÇÃO' : 'ATIVAR MANUTENÇÃO' ?></button></form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <script>
        function s(id, e) { document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active')); document.querySelectorAll('.btn').forEach(b=>b.classList.remove('active')); document.getElementById(id).classList.add('active'); e.classList.add('active'); }
        function ud() { const t=document.getElementById('dt').value, s=document.getElementById('dv'); s.innerHTML=''; if(t==='hours'){ [1,2,5].forEach(h=>s.innerHTML+=`<option value="${h}">${h} Hora(s)</option>`); }else{ [1,3,7,15,30].forEach(d=>s.innerHTML+=`<option value="${d}">${d} Dia(s)</option>`); } } ud();
    </script>
</body>
</html>
