<?php
/**
 * AJAX Handler for Panasonic TV LoxBerry Plugin
 * Compatible with PHP 7.0 - 8.x
 */

header('Content-Type: application/json; charset=UTF-8');

// Polyfills for PHP < 8.0 compatibility
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || $needle === substr($haystack, -strlen($needle));
    }
}

require_once "loxberry_system.php";
require_once "loxberry_io.php";

function get_plugin_config_file() {
    global $lbpconfigdir;
    if (!empty($lbpconfigdir) && file_exists("$lbpconfigdir/panasonictv.json")) {
        return "$lbpconfigdir/panasonictv.json";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/config/plugins/panasonictv/panasonictv.json";
    if (file_exists($std)) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/config/panasonictv.json";
}

function get_log_file_path() {
    global $lbplogdir;
    if (!empty($lbplogdir)) {
        return "$lbplogdir/daemon.log";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/log/plugins/panasonictv/daemon.log";
    if (file_exists(dirname($std))) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/daemon.log";
}

function get_python_binary() {
    global $lbpdatadir;
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $venv_python = !empty($lbpdatadir) ? "$lbpdatadir/venv/bin/python3" : "$lb_home/data/plugins/panasonictv/venv/bin/python3";
    if (file_exists($venv_python)) {
        return $venv_python;
    }
    return "python3";
}

function get_control_script() {
    global $lbpbindir;
    if (!empty($lbpbindir) && file_exists("$lbpbindir/panasonictv_control.py")) {
        return "$lbpbindir/panasonictv_control.py";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/bin/plugins/panasonictv/panasonictv_control.py";
    if (file_exists($std)) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/bin/panasonictv_control.py";
}

function get_daemon_script() {
    global $lbpbindir;
    if (!empty($lbpbindir) && file_exists("$lbpbindir/panasonictv_daemon.py")) {
        return "$lbpbindir/panasonictv_daemon.py";
    }
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $std = "$lb_home/bin/plugins/panasonictv/panasonictv_daemon.py";
    if (file_exists($std)) {
        return $std;
    }
    return dirname(__DIR__, 2) . "/bin/panasonictv_daemon.py";
}

function sync_plugin_loglevel_to_system($new_level) {
    global $lbsdatadir;
    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $db_path = !empty($lbsdatadir) ? "$lbsdatadir/plugindatabase.json" : "$lb_home/data/system/plugindatabase.json";
    if (file_exists($db_path) && is_writable($db_path)) {
        try {
            $raw = file_get_contents($db_path);
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['plugins'])) {
                $changed = false;
                foreach ($data['plugins'] as $key => &$plugin) {
                    $name = strtolower($plugin['name'] ?? '');
                    $folder = strtolower($plugin['folder'] ?? '');
                    if ($name === 'panasonictv' || $folder === 'panasonictv') {
                        $plugin['loglevel'] = intval($new_level);
                        $changed = true;
                    }
                }
                if ($changed) {
                    file_put_contents($db_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        } catch (\Throwable $t) {
            // Non-fatal
        }
    }
}

function parse_command_json($output_array) {
    if (!is_array($output_array) || empty($output_array)) {
        return null;
    }
    // Check backwards from the last line (CLI outputs JSON on stdout at end)
    for ($i = count($output_array) - 1; $i >= 0; $i--) {
        $line = trim($output_array[$i]);
        if ($line !== '' && $line[0] === '{' && substr($line, -1) === '}') {
            $data = json_decode($line, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    // Fallback: search regex block
    $raw = implode("\n", $output_array);
    if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
        $data = json_decode($matches[0], true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function is_daemon_running() {
    $out = [];
    $ret = 0;
    exec("pgrep -f panasonictv_daemon.py", $out, $ret);
    return ($ret === 0 && !empty($out));
}

function restart_background_daemon() {
    // 1. Terminate existing daemon processes
    exec("pkill -f panasonictv_daemon.py 2>&1");
    usleep(500000);

    $lb_home = getenv('LBHOMEDIR') ?: 'REPLACELBHOMEDIR';
    $py = get_python_binary();
    $daemon_py = get_daemon_script();
    $log_file = get_log_file_path();

    // Check system daemons paths
    $daemon_runners = [
        "$lb_home/system/daemons/plugins/panasonictv",
        "$lb_home/system/daemons/plugins/daemon.sh",
        "$lb_home/system/daemons/plugins/panasonictv/daemon.sh",
        "$lb_home/system/daemon/plugins/panasonictv",
        dirname(__DIR__, 2) . "/daemon/daemon.sh"
    ];

    $started = false;
    foreach ($daemon_runners as $runner) {
        if (file_exists($runner)) {
            exec("bash " . escapeshellarg($runner) . " restart > /dev/null 2>&1 &");
            $started = true;
            break;
        }
    }

    // Direct fallback if no daemon runner script was found
    if (!$started && file_exists($daemon_py)) {
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0775, true);
        }
        $cmd = "nohup " . escapeshellcmd($py) . " " . escapeshellarg($daemon_py) . " >> " . escapeshellarg($log_file) . " 2>&1 &";
        exec($cmd);
        $started = true;
    }

    sleep(1);
    return is_daemon_running();
}

$action = $_REQUEST['action'] ?? '';
$response = ['success' => false];

$cfg_file = get_plugin_config_file();
$config = file_exists($cfg_file) ? json_decode(file_get_contents($cfg_file), true) : [];

try {
    switch ($action) {
        case 'discover':
            $py = get_python_binary();
            $script = get_control_script();
            $cmd = escapeshellcmd("$py $script discover");
            $output = [];
            $rc = 0;
            exec($cmd . " 2>&1", $output, $rc);
            $parsed = parse_command_json($output);
            $response = $parsed ?: ['success' => false, 'raw' => implode("\n", $output), 'devices' => []];
            break;

        case 'get_status':
            $daemon_running = is_daemon_running();
            $py = get_python_binary();
            $script = get_control_script();
            
            $host = $config['tv']['host'] ?? '';
            
            $tv_status = [
                'online' => false,
                'power' => 'OFF',
                'volume' => 0,
                'mute' => false
            ];

            if (!empty($host)) {
                $cmd = escapeshellcmd("$py $script status");
                $output = [];
                $rc = 0;
                exec($cmd . " 2>&1", $output, $rc);
                $parsed = parse_command_json($output);
                if (is_array($parsed) && isset($parsed['online'])) {
                    $tv_status = $parsed;
                }
            }

            $response = [
                'success' => true,
                'daemon_running' => $daemon_running,
                'tv' => $tv_status
            ];
            break;

        case 'save_config':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }

            if (!empty($input['tv'])) {
                $config['tv']['host'] = trim($input['tv']['host'] ?? '');
                $config['tv']['port'] = intval($input['tv']['port'] ?? 55000);
                $config['tv']['mac'] = trim($input['tv']['mac'] ?? '');
                $config['tv']['model'] = trim($input['tv']['model'] ?? 'TX-55EX600W');
                $config['tv']['app_id'] = trim($input['tv']['app_id'] ?? '');
                $config['tv']['encryption_key'] = trim($input['tv']['encryption_key'] ?? '');
            }

            if (!empty($input['mqtt'])) {
                $config['mqtt']['use_loxberry_gateway'] = !empty($input['mqtt']['use_loxberry_gateway']);
                $config['mqtt']['topic_prefix'] = trim($input['mqtt']['topic_prefix'] ?? 'panasonictv');
                $config['mqtt']['broker_host'] = trim($input['mqtt']['broker_host'] ?? 'localhost');
                $config['mqtt']['broker_port'] = intval($input['mqtt']['broker_port'] ?? 1883);
                $config['mqtt']['broker_user'] = trim($input['mqtt']['broker_user'] ?? '');
                $config['mqtt']['broker_pass'] = trim($input['mqtt']['broker_pass'] ?? '');
            }

            if (!empty($input['polling'])) {
                $config['polling']['interval_active'] = max(2, intval($input['polling']['interval_active'] ?? 5));
                $config['polling']['interval_standby'] = max(10, intval($input['polling']['interval_standby'] ?? 30));
            }

            if (!empty($input['macros'])) {
                $config['macros'] = $input['macros'];
            }

            if (isset($input['system']['loglevel'])) {
                $lvl = $input['system']['loglevel'];
                $parsed_lvl = is_numeric($lvl) ? intval($lvl) : $lvl;
                $config['system']['loglevel'] = $parsed_lvl;
                if (is_numeric($parsed_lvl)) {
                    sync_plugin_loglevel_to_system($parsed_lvl);
                }
            }

            $dir = dirname($cfg_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $written = file_put_contents($cfg_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            
            // Also restart daemon to apply new configuration immediately
            $daemon_is_up = restart_background_daemon();

            $response = [
                'success' => ($written !== false),
                'daemon_running' => $daemon_is_up,
                'message' => ($written !== false) ? 'Konfiguration gespeichert' : 'Fehler beim Schreiben der Konfiguration'
            ];
            break;

        case 'send_key':
            $key = trim($_REQUEST['key'] ?? '');
            if (empty($key)) {
                $response = ['success' => false, 'error' => 'Keine Taste angegeben'];
                break;
            }
            $py = get_python_binary();
            $script = get_control_script();
            $cmd = escapeshellcmd("$py $script key ") . escapeshellarg($key);
            $output = [];
            $rc = 0;
            exec($cmd . " 2>&1", $output, $rc);
            $parsed = parse_command_json($output);
            $response = $parsed ?: ['success' => ($rc === 0), 'raw' => implode("\n", $output)];
            break;

        case 'send_wol':
            $py = get_python_binary();
            $script = get_control_script();
            $cmd = escapeshellcmd("$py $script wol");
            $output = [];
            $rc = 0;
            exec($cmd . " 2>&1", $output, $rc);
            $parsed = parse_command_json($output);
            $response = $parsed ?: ['success' => ($rc === 0), 'raw' => implode("\n", $output)];
            break;

        case 'send_macro':
            $macro = trim($_REQUEST['macro'] ?? '');
            if (empty($macro)) {
                $response = ['success' => false, 'error' => 'Kein Makro angegeben'];
                break;
            }
            $py = get_python_binary();
            $script = get_control_script();
            $cmd = escapeshellcmd("$py $script macro ") . escapeshellarg($macro);
            $output = [];
            $rc = 0;
            exec($cmd . " 2>&1", $output, $rc);
            $parsed = parse_command_json($output);
            $response = $parsed ?: ['success' => ($rc === 0), 'raw' => implode("\n", $output)];
            break;

        case 'pin_request':
            $py = get_python_binary();
            $script = get_control_script();
            $cmd = escapeshellcmd("$py $script pin_request");
            $output = [];
            $rc = 0;
            exec($cmd . " 2>&1", $output, $rc);
            $parsed = parse_command_json($output);
            $response = $parsed ?: ['success' => ($rc === 0), 'raw' => implode("\n", $output)];
            break;

        case 'pin_authorize':
            $pin = trim($_REQUEST['pin'] ?? '');
            if (empty($pin)) {
                $response = ['success' => false, 'error' => 'Kein PIN angegeben'];
                break;
            }
            $py = get_python_binary();
            $script = get_control_script();
            $cmd = escapeshellcmd("$py $script pin_authorize ") . escapeshellarg($pin);
            $output = [];
            $rc = 0;
            exec($cmd . " 2>&1", $output, $rc);
            $parsed = parse_command_json($output);
            if (is_array($parsed) && !empty($parsed['app_id'])) {
                $config['tv']['app_id'] = $parsed['app_id'];
                $config['tv']['encryption_key'] = $parsed['encryption_key'];
                file_put_contents($cfg_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            $response = $parsed ?: ['success' => ($rc === 0), 'raw' => implode("\n", $output)];
            break;

        case 'restart_daemon':
            $daemon_is_up = restart_background_daemon();
            $response = [
                'success' => true,
                'daemon_running' => $daemon_is_up,
                'message' => 'Dienst neu gestartet'
            ];
            break;

        case 'get_log':
            $log_file = get_log_file_path();
            $lines_to_read = max(20, min(500, intval($_REQUEST['lines'] ?? 100)));
            $log_content = "";
            $exists = file_exists($log_file);

            if ($exists) {
                $escaped = escapeshellarg($log_file);
                $tail = [];
                exec("tail -n $lines_to_read $escaped 2>&1", $tail);
                $log_content = implode("\n", $tail);
            } else {
                $log_content = "(Logdatei existiert noch nicht oder ist leer: $log_file)";
            }

            $response = [
                'success' => true,
                'log_file' => $log_file,
                'exists' => $exists,
                'content' => $log_content
            ];
            break;

        case 'clear_log':
            $log_file = get_log_file_path();
            if (file_exists($log_file)) {
                @file_put_contents($log_file, "");
            }
            $response = [
                'success' => true,
                'message' => 'Logdatei wurde geleert'
            ];
            break;

        default:
            $response = ['success' => false, 'error' => "Unbekannte Aktion '$action'"];
            break;
    }
} catch (\Exception $e) {
    $response = ['success' => false, 'error' => 'Serverfehler: ' . $e->getMessage()];
} catch (\Throwable $t) {
    $response = ['success' => false, 'error' => 'Kritischer Fehler: ' . $t->getMessage()];
}

echo json_encode($response);
exit;
