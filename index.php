<?php
session_start();
error_reporting(0);

@date_default_timezone_set('America/Fortaleza');

$file_users  = 'db_users.json';
$file_keys   = 'db_keys.json';
$file_config = 'db_config.json';

function load_db($file) {
    if (file_exists($file)) {
        $json = @file_get_contents($file);
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
    }
    return array();
}

function save_db($file, $data) {
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

$users_db  = load_db($file_users);
$keys_db   = load_db($file_keys);
$config_db = load_db($file_config);

if (!isset($config_db['invite_code'])) { $config_db['invite_code'] = 'DARLEKIN123'; save_db($file_config, $config_db); }
if (!isset($config_db['system_status'])) { $config_db['system_status'] = 'active'; save_db($file_config, $config_db); }

if (!isset($_SESSION['captcha1'])) {
    $_SESSION['captcha1'] = rand(1, 9);
    $_SESSION['captcha2'] = rand(1, 9);
}

function tempoRestante($expires_at) {
    if (empty($expires_at)) {
        return "<span style='color:#fbbf24; font-weight:bold;'>Aguardando Uso</span>";
    }

    $agora = new DateTime();
    $expira = new DateTime($expires_at);

    if ($agora > $expira) {
        return "<span style='color:#ef4444; font-weight:bold;'>Expirada</span>";
    }

    $diff = $agora->diff($expira);
    $partes = [];
    
    if ($diff->d > 0) $partes[] = $diff->d . "d";
    if ($diff->h > 0) $partes[] = $diff->h . "h";
    if ($diff->i > 0) $partes[] = $diff->i . "m";

    if (empty($partes)) return "<span style='color:#10b981; font-weight:bold;'>Menos de 1 min</span>";
    
    return "<span style='color:#10b981; font-weight:bold;'>Falta " . implode(" ", $partes) . "</span>";
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'verify') {
    header('Content-Type: application/json');

    $key   = isset($_GET['key']) ? trim($_GET['key']) : '';
    $hwid  = isset($_GET['hwid']) ? trim($_GET['hwid']) : '';
    $ip    = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $nonce = isset($_GET['nonce']) ? trim($_GET['nonce']) : '';

    if ($config_db['system_status'] === 'paused') {
        echo json_encode(array("status" => "system_paused")); exit;
    }

    if (!isset($keys_db[$key])) {
        echo json_encode(array("status" => "invalid")); exit;
    }

    $kdata = $keys_db[$key];

    if (isset($kdata['status']) && $kdata['status'] === 'paused') {
        echo json_encode(array("status" => "paused")); exit;
    }

    $current_time = date('Y-m-d H:i:s');

    if (empty($kdata['expires_at'])) {
        $duration_str = isset($kdata['duration']) ? $kdata['duration'] : '+1 day';
        $keys_db[$key]['expires_at'] = date('Y-m-d H:i:s', strtotime($duration_str));
        $kdata['expires_at'] = $keys_db[$key]['expires_at'];
        save_db($file_keys, $keys_db);
    }

    if ($kdata['expires_at'] < $current_time) {
        $keys_db[$key]['status'] = 'expired';
        save_db($file_keys, $keys_db);
        echo json_encode(array("status" => "expired")); exit;
    }

    if (!isset($kdata['hwids']) || !is_array($kdata['hwids'])) $kdata['hwids'] = array();
    if (!isset($kdata['ips']) || !is_array($kdata['ips'])) $kdata['ips'] = array();

    if (!in_array($hwid, $kdata['hwids'])) {
        $max = isset($kdata['max_users']) ? (int)$kdata['max_users'] : 1;
        if (count($kdata['hwids']) >= $max) {
            echo json_encode(array("status" => "device_locked")); exit;
        } else {
            $keys_db[$key]['hwids'][] = $hwid;
            if (!in_array($ip, $keys_db[$key]['ips'])) {
                $keys_db[$key]['ips'][] = $ip;
            }
            save_db($file_keys, $keys_db);
        }
    }

    $secret_key = "DARLEKINTHEGOD137";
    $signature  = md5($nonce . $secret_key . "SERVIDORSIM");

    echo json_encode(array("status" => "success", "auth" => $signature)); exit;
}

$msg = '';
$err = '';

if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_btn'])) {
    $usr = trim($_POST['username']);
    $pwd = trim($_POST['password']);
    $cap = (int)$_POST['captcha'];
    $exp = $_SESSION['captcha1'] + $_SESSION['captcha2'];

    if ($cap !== $exp) { $err = "Verificação incorreta!"; } 
    else {
        if (isset($users_db[$usr]) && $pwd === $users_db[$usr]['password']) {
            $_SESSION['user'] = $usr;
            header("Location: index.php?aba=gerar"); exit;
        } else { $err = "Usuário ou Senha incorretos!"; }
    }
    $_SESSION['captcha1'] = rand(1, 9);
    $_SESSION['captcha2'] = rand(1, 9);
}

if (!isset($_SESSION['user'])) {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DARLEKIN VIP</title>
    <style>
        body { background: #0a0512; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin:0;}
        .auth-card { background: #140b24; border: 2px solid #8a2be2; padding: 30px; border-radius: 12px; width: 90%; max-width: 380px; text-align: center; }
        input { width: 100%; padding: 12px; margin-bottom: 15px; background: #1f1235; border: 1px solid #6b21a8; color: #fff; border-radius: 6px; box-sizing: border-box;}
        button { width: 100%; padding: 12px; background: #8a2be2; border: none; color: white; font-weight: bold; border-radius: 6px; cursor: pointer;}
        .msg-err { color: #ef4444; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="auth-card">
        <h1 style="color:#a855f7;">DARLEKIN VIP</h1>
        <?php if ($err) echo "<div class='msg-err'>$err</div>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Usuário" required>
            <input type="password" name="password" placeholder="Senha" required>
            <div style="background:#1f1235; border:1px solid #6b21a8; padding:10px; margin-bottom:15px; text-align:left;">
                Soma: <?php echo $_SESSION['captcha1'] . " + " . $_SESSION['captcha2']; ?> ?
                <input type="number" name="captcha" style="margin-top:10px; margin-bottom:0;" required>
            </div>
            <button type="submit" name="login_btn">ENTRAR NO PAINEL</button>
        </form>
    </div>
</body>
</html>
<?php exit; }

$current_user = $_SESSION['user'];
$aba_atual = isset($_GET['aba']) ? $_GET['aba'] : 'gerar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['create_random_key']) || isset($_POST['create_custom_key'])) {
        $duration = $_POST['duration'];
        $max_u    = (int)$_POST['max_users'];
        $new_key  = isset($_POST['create_random_key']) ? strtoupper($current_user) . "-" . rand(100000, 999999) : trim($_POST['custom_name']);
        
        if (empty($new_key) || isset($keys_db[$new_key])) { $err = "Erro: Nome vazio ou Key já existe!"; } 
        else {
            $times = ['2h'=>'+2 hours','1d'=>'+1 day','3d'=>'+3 days','7d'=>'+7 days','30d'=>'+30 days'];
            $time_add = isset($times[$duration]) ? $times[$duration] : '+1 day';

            $keys_db[$new_key] = array(
                "created_by" => $current_user, "duration" => $time_add, "expires_at" => "",
                "max_users"  => max(1, $max_u), "hwids" => array(), "ips" => array(), "status" => "active"
            );
            save_db($file_keys, $keys_db);
            
            $msg = "Sucesso! Nova Key: <b style='color:#a855f7; font-size:16px;'>$new_key</b><br><br>
                    <button type='button' onclick=\"copiarKey('$new_key')\" style='background:#10b981; margin:0; border:none; padding:10px; border-radius:6px; color:white; font-weight:bold; cursor:pointer;'>📋 COPIAR KEY</button>";
        }
    }

    if (isset($_POST['system_action'])) {
        $act = $_POST['system_action'];
        
        if ($act === 'panic_on') {
            $config_db['system_status'] = 'paused';
            save_db($file_config, $config_db);
            $msg = "ALERTA: SCRIPT TOTALMENTE DESATIVADO!";
        }
        if ($act === 'panic_off') {
            $config_db['system_status'] = 'active';
            save_db($file_config, $config_db);
            $msg = "Script ativado novamente e operando normalmente.";
        }
        if ($act === 'pause_all') {
            foreach ($keys_db as $k => $v) $keys_db[$k]['status'] = 'paused';
            save_db($file_keys, $keys_db); $msg = "Todas as keys foram pausadas!";
        }
        if ($act === 'active_all') {
            foreach ($keys_db as $k => $v) $keys_db[$k]['status'] = 'active';
            save_db($file_keys, $keys_db); $msg = "Todas as keys foram ativadas!";
        }
    }

    if (isset($_POST['key_action'])) {
        $tk = $_POST['target_key']; $act = $_POST['key_action'];
        if (isset($keys_db[$tk])) {
            if ($act == 'reset') { $keys_db[$tk]['hwids'] = array(); $keys_db[$tk]['ips'] = array(); $msg="Key resetada!"; }
            if ($act == 'toggle') { $keys_db[$tk]['status'] = ($keys_db[$tk]['status']=='active') ? 'paused' : 'active'; $msg="Status alterado!"; }
            if ($act == 'del') { unset($keys_db[$tk]); $msg="Key apagada!"; }
            save_db($file_keys, $keys_db);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DARLEKIN VIP</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: sans-serif; }
        body { background: #0a0512; color: #e9d5ff; padding-bottom: 50px; }
        
        .top-menu { background: #140b24; border-bottom: 2px solid #3b0764; padding: 10px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; position: sticky; top: 0; z-index: 100;}
        .top-menu a { flex-grow: 1; text-align: center; background: #2e1065; color: #d8b4fe; padding: 12px; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: bold; border: 1px solid #4c1d95;}
        .top-menu a:hover { background: #4c1d95; }
        .top-menu a.ativo { background: #a855f7; color: #fff; border-color: #c084fc; }
        .top-menu a.sair { background: #7f1d1d; border-color: #ef4444; color: #fca5a5; }

        .container { padding: 15px; max-width: 800px; margin: auto; }
        .card { background: #140b24; padding: 20px; border-radius: 10px; border: 1px solid #3b0764; margin-bottom: 20px; }
        .card h2 { color: #a855f7; font-size: 18px; margin-bottom: 15px; border-bottom: 1px solid #3b0764; padding-bottom: 8px; }
        
        input, select, button { padding: 12px; border-radius: 6px; border: 1px solid #581c87; background: #1f1235; color: #fff; margin-bottom: 15px; width: 100%; outline:none; font-size:14px;}
        .btn-primary { background: #8a2be2; font-weight: bold; cursor: pointer; border: none; }
        
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 450px;}
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #2e1065; }
        th { background: #1e1135; color: #c084fc; }
        .table-responsive { overflow-x: auto; }

        .msg { background: #064e3b; border: 1px solid #10b981; color: #34d399; padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align:center; font-weight:bold;}
        .msg-err { background: #7f1d1d; border: 1px solid #ef4444; color: #fca5a5; padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align:center; font-weight:bold;}
        
        .grid-botoes { display: flex; gap: 10px; }
        .grid-botoes button { margin-bottom: 0; }
        .btn-panic { font-weight: bold; padding: 15px; font-size: 15px; border-radius: 6px; color: white; cursor: pointer; }
    </style>
</head>
<body>

    <div class="top-menu">
        <a href="?aba=gerar" class="<?php echo $aba_atual == 'gerar' ? 'ativo' : ''; ?>">🔑 Gerar</a>
        <a href="?aba=lista" class="<?php echo $aba_atual == 'lista' ? 'ativo' : ''; ?>">📋 Keys & Controle</a>
        <a href="?aba=disp"  class="<?php echo $aba_atual == 'disp' ? 'ativo' : ''; ?>">📱 Aparelhos</a>
        <a href="?logout=1"  class="sair">Sair</a>
    </div>

    <div class="container">
        <?php if ($msg) echo "<div class='msg'>$msg</div>"; ?>
        <?php if ($err) echo "<div class='msg-err'>$err</div>"; ?>

        <?php if ($aba_atual === 'gerar'): ?>
        <div class="card">
            <h2 style="color: #38bdf8; border-color: #0284c7;">🎲 GERAR KEY ALEATÓRIA</h2>
            <form method="POST">
                <label>Validade:</label>
                <select name="duration">
                    <option value="2h">2 Horas</option><option value="1d" selected>1 Dia</option>
                    <option value="3d">3 Dias</option><option value="7d">7 Dias</option>
                    <option value="30d">30 Dias</option>
                </select>
                <label>Limite de Aparelhos:</label>
                <input type="number" name="max_users" value="1" min="1">
                <button type="submit" name="create_random_key" class="btn-primary" style="background: #0284c7;">GERAR ALEATÓRIA</button>
            </form>
        </div>

        <div class="card">
            <h2>✍️ GERAR KEY PERSONALIZADA</h2>
            <form method="POST">
                <label>Nome da Key:</label>
                <input type="text" name="custom_name" placeholder="Ex: CLIENTE_VIP" required>
                <label>Validade:</label>
                <select name="duration">
                    <option value="2h">2 Horas</option><option value="1d" selected>1 Dia</option>
                    <option value="3d">3 Dias</option><option value="7d">7 Dias</option>
                    <option value="30d">30 Dias</option>
                </select>
                <label>Limite de Aparelhos:</label>
                <input type="number" name="max_users" value="1" min="1">
                <button type="submit" name="create_custom_key" class="btn-primary">GERAR PERSONALIZADA</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($aba_atual === 'lista'): ?>
        <div class="card" style="border-color: #ef4444;">
            <h2 style="color: #ef4444; border-color: #ef4444;">🚨 CONTROLE GERAL DO SCRIPT</h2>
            
            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:15px;">
                <form method="POST">
                    <button type="submit" name="system_action" value="panic_on" class="btn-panic" style="background:#dc2626; border:2px solid #991b1b; width:100%;">🛑 DESATIVAR SCRIPT (PÂNICO)</button>
                </form>
                <form method="POST">
                    <button type="submit" name="system_action" value="panic_off" class="btn-panic" style="background:#10b981; border:2px solid #065f46; width:100%;">✅ ATIVAR SCRIPT (NORMAL)</button>
                </form>
            </div>
            
            <div class="grid-botoes">
                <form method="POST" style="flex:1;">
                    <button type="submit" name="system_action" value="pause_all" style="background:#d97706; font-weight:bold; color:white; border:none; width:100%; border-radius:6px; padding:12px;">Pausar TODAS Keys</button>
                </form>
                <form method="POST" style="flex:1;">
                    <button type="submit" name="system_action" value="active_all" style="background:#059669; font-weight:bold; color:white; border:none; width:100%; border-radius:6px; padding:12px;">Ativar TODAS Keys</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>📋 Tabela de Keys</h2>
            <div class="table-responsive">
                <table>
                    <tr><th>Key</th><th>Status / Tempo</th><th>Ações</th></tr>
                    <?php foreach ($keys_db as $k => $v): ?>
                    <tr>
                        <td style="font-weight:bold; color:#fff;">
                            <?php echo htmlspecialchars($k); ?><br>
                            <span style="font-size:11px; color:#9ca3af; font-weight:normal;">Disp: <?php echo count($v['hwids']).'/'.$v['max_users']; ?></span>
                        </td>
                        <td>
                            <?php 
                            if ($v['status'] == 'paused') {
                                echo "<span style='color:#d97706; font-weight:bold;'>Pausada</span>";
                            } else {
                                echo tempoRestante($v['expires_at']);
                            }
                            ?>
                        </td>
                        <td>
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="target_key" value="<?php echo htmlspecialchars($k); ?>">
                                <button type="submit" name="key_action" value="reset" style="background:#0284c7; padding:8px; border:none; width:auto; font-size:12px; border-radius:4px; color:white;">Reset</button>
                                <button type="submit" name="key_action" value="toggle" style="background:#d97706; padding:8px; border:none; width:auto; font-size:12px; border-radius:4px; color:white;">Pausar</button>
                                <button type="submit" name="key_action" value="del" style="background:#dc2626; padding:8px; border:none; width:auto; font-size:12px; border-radius:4px; color:white;">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($aba_atual === 'disp'): ?>
        <div class="card">
            <h2>📱 Dispositivos Conectados</h2>
            <div class="table-responsive">
                <table>
                    <tr><th>Key</th><th>HWIDs Vinculados</th></tr>
                    <?php foreach ($keys_db as $k => $v): 
                        if (!empty($v['hwids'])):
                    ?>
                    <tr>
                        <td style="color:#d8b4fe; font-weight:bold;"><?php echo htmlspecialchars($k); ?></td>
                        <td style="font-family:monospace; font-size:11px; color:#9ca3af;"><?php echo implode('<br>', $v['hwids']); ?></td>
                    </tr>
                    <?php endif; endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function copiarKey(texto) {
        var inputTemp = document.createElement("input");
        inputTemp.value = texto;
        document.body.appendChild(inputTemp);
        inputTemp.select();
        document.execCommand("copy");
        document.body.removeChild(inputTemp);
        alert("✅ Key copiada com sucesso: " + texto);
    }
    </script>
</body>
</html>
