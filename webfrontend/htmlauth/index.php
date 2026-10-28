<?php
/**
 * LoxBerry Panasonic TV Plugin - Main WebUI
 * Uses LoxBerry Design System (lb-* classes, no jQuery Mobile), loxberry_XL and PHP SDK.
 */

require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "loxberry_log.php";

// Localization
$L = LBSystem::readlanguage("language.ini");

function __t($key, $fallback = '') {
    global $L;
    if (!empty($L[$key])) {
        return htmlspecialchars($L[$key]);
    }
    $parts = explode('.', $key, 2);
    if (count($parts) === 2 && !empty($L[$parts[0]][$parts[1]])) {
        return htmlspecialchars($L[$parts[0]][$parts[1]]);
    }
    return htmlspecialchars($fallback ?: $key);
}

// Config file path
$cfg_file = "$lbpconfigdir/panasonictv.json";
if (!file_exists($cfg_file)) {
    $cfg_file = dirname(__DIR__, 2) . "/config/panasonictv.json";
}
$config = file_exists($cfg_file) ? json_decode(file_get_contents($cfg_file), true) : [];

// Read form parameter
$form = isset($_REQUEST['form']) ? $_REQUEST['form'] : 'main';

// Safe default values
$tv_host = $config['tv']['host'] ?? '';
$tv_port = $config['tv']['port'] ?? 55000;
$tv_mac = $config['tv']['mac'] ?? '';
$tv_model = $config['tv']['model'] ?? 'TX-55EX600W';

$mqtt_use_gateway = $config['mqtt']['use_loxberry_gateway'] ?? true;
$mqtt_topic_prefix = $config['mqtt']['topic_prefix'] ?? 'panasonictv';
$poll_active = $config['polling']['interval_active'] ?? 5;
$poll_standby = $config['polling']['interval_standby'] ?? 30;

// Read LoxBerry native plugin loglevel (set in LoxBerry Plugin Management widget)
$lb_sys_loglevel = null;
if (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
    $lb_sys_loglevel = LBSystem::pluginloglevel();
}
if ($lb_sys_loglevel !== null && $lb_sys_loglevel !== '' && is_numeric($lb_sys_loglevel)) {
    $log_level = intval($lb_sys_loglevel);
} else {
    $log_level = $config['system']['loglevel'] ?? 6;
}

// Read LoxBerry MQTT Gateway info if function exists
$lb_mqtt_info = __t('MQTT.LABEL_USE_LB_GATEWAY', 'Integriertes Gateway');
if (function_exists('mqtt_connectiondetails')) {
    $creds = mqtt_connectiondetails();
    if (!empty($creds['brokerhost'])) {
        $lb_mqtt_info = "LoxBerry Broker: " . htmlspecialchars($creds['brokerhost']) . ":" . intval($creds['brokerport']);
    }
}

// Navigation Bar (renders inline in LoxBerry)
$navbar[1]['Name']   = __t('TV.SECTION_TITLE', 'Einstellungen & Steuerung');
$navbar[1]['URL']    = 'index.php?form=main';
$navbar[1]['active'] = ($form === 'main');

$navbar[2]['Name']   = __t('LOGGING.SECTION_TITLE', 'Logdatei & Diagnose');
$navbar[2]['URL']    = 'index.php?form=logs';
$navbar[2]['active'] = ($form === 'logs');

// Render LoxBerry Header (4th parameter true = no jQuery Mobile, pure LoxBerry Design System)
$template_title = __t('COMMON.PLUGIN_TITLE', 'Panasonic TV');
$helplink = "https://wiki.loxberry.de/";
$helptemplate = "help.html";

LBWeb::lbheader($template_title, $helplink, $helptemplate, true);

if ($form === 'logs') {
    ?>
    <div class="lb-content">
        <style>
        .card-box {
            background: #ffffff;
            border: 1px solid #dcdde1;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .card-header-bar {
            border-bottom: 2px solid #f1f2f6;
            padding-bottom: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-header-bar h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #2f3640;
        }
        .remote-btn {
            padding: 8px 14px;
            border: 1px solid #ccc;
            background: #fdfdfd;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
            text-align: center;
        }
        .remote-btn:hover { background: #eaeaea; }
        .console-box {
            background: #1e1e1e;
            color: #00ff66;
            padding: 14px;
            border-radius: 6px;
            max-height: 520px;
            overflow-y: auto;
            font-family: Consolas, "Courier New", monospace;
            font-size: 0.84rem;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .alert-box {
            display: none;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .alert-box.success { display: block; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-box.error { display: block; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        </style>

        <div id="alertFeedback" class="alert-box"></div>

        <div class="card-box">
            <div class="card-header-bar">
                <h2><?php echo __t('LOGGING.SECTION_TITLE', 'Logdatei & Diagnose'); ?> (panasonictv)</h2>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="remote-btn" onclick="fetchLog()">↻ <?php echo __t('LOGGING.BTN_REFRESH_LOG', 'Aktualisieren'); ?></button>
                    <button type="button" class="remote-btn" onclick="copyLog()">📋 <?php echo __t('LOGGING.BTN_COPY_LOG', 'Kopieren'); ?></button>
                    <button type="button" class="remote-btn" onclick="downloadLog()">⬇ <?php echo __t('LOGGING.BTN_DOWNLOAD_LOG', 'Herunterladen'); ?></button>
                    <button type="button" class="remote-btn" onclick="clearLog()">🗑 <?php echo __t('LOGGING.BTN_CLEAR_LOG', 'Log leeren'); ?></button>
                    <a href="/admin/system/logmanager.cgi?package=panasonictv" class="remote-btn" style="text-decoration:none;">
                        📂 <?php echo __t('LOGGING.BTN_OPEN_LOG_MANAGER', 'LoxBerry Log-Manager'); ?>
                    </a>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; font-size:0.85rem; color:#666;">
                <span id="logFilePath">Lade Log...</span>
                <label><input type="checkbox" id="chkAutoLog" onchange="toggleAutoLog()"> Auto-Refresh (5s)</label>
            </div>
            <div id="logOutput" class="console-box">Lade Logdatei...</div>
        </div>
    </div>

    <script>
    let autoLogTimer = null;

    function showAlert(msg, isSuccess) {
        const el = document.getElementById('alertFeedback');
        el.textContent = msg;
        el.className = 'alert-box ' + (isSuccess ? 'success' : 'error');
        setTimeout(() => { el.className = 'alert-box'; }, 4000);
    }

    function fetchLog() {
        fetch('ajax.php?action=get_log&lines=250')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('logFilePath').textContent = 'Pfad: ' + data.log_file;
                    const el = document.getElementById('logOutput');
                    el.textContent = data.content || '(Logdatei ist leer)';
                    el.scrollTop = el.scrollHeight;
                }
            })
            .catch(err => {
                document.getElementById('logOutput').textContent = 'Fehler beim Laden des Logs: ' + err;
            });
    }

    function clearLog() {
        if (!confirm('Logdatei wirklich leeren?')) return;
        fetch('ajax.php?action=clear_log')
            .then(res => res.json())
            .then(data => {
                showAlert('Logdatei wurde geleert', true);
                fetchLog();
            });
    }

    function copyLog() {
        const text = document.getElementById('logOutput').textContent;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                showAlert('Loginhalt in Zwischenablage kopiert!', true);
            }).catch(() => {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.top = '0';
            ta.style.left = '0';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            const successful = document.execCommand('copy');
            document.body.removeChild(ta);
            if (successful) {
                showAlert('Loginhalt in Zwischenablage kopiert!', true);
            } else {
                showAlert('Kopieren nicht unterstützt - bitte Text manuell markieren', false);
            }
        } catch (err) {
            showAlert('Kopieren fehlgeschlagen: ' + err, false);
        }
    }

    function downloadLog() {
        const text = document.getElementById('logOutput').textContent;
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'panasonictv_daemon.log';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function toggleAutoLog() {
        const chk = document.getElementById('chkAutoLog');
        if (chk.checked) {
            if (!autoLogTimer) autoLogTimer = setInterval(fetchLog, 5000);
        } else {
            if (autoLogTimer) {
                clearInterval(autoLogTimer);
                autoLogTimer = null;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => { fetchLog(); });
    </script>
    <?php
    LBWeb::lbfooter();
    exit;
}
?>

<div class="lb-content">

<style>
.panasonic-dashboard {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}
.card-box {
    background: #ffffff;
    border: 1px solid #dcdde1;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 22px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.card-header-bar {
    border-bottom: 2px solid #f1f2f6;
    padding-bottom: 12px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.card-header-bar h2 {
    margin: 0;
    font-size: 1.25rem;
    color: #2f3640;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: bold;
}
.status-online { background-color: #d4edda; color: #155724; }
.status-offline { background-color: #f8d7da; color: #721c24; }
.status-standby { background-color: #fff3cd; color: #856404; }

.remote-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    max-width: 320px;
    margin: 0 auto;
}
.remote-grid .span-3 { grid-column: span 3; }
.remote-btn {
    padding: 10px;
    border: 1px solid #ccc;
    background: #fdfdfd;
    border-radius: 6px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, transform 0.05s;
    text-align: center;
}
.remote-btn:hover { background: #eaeaea; }
.remote-btn:active { transform: scale(0.96); background: #ddd; }
.btn-power { background-color: #ff4d4d; color: #fff; border-color: #e03b3b; }
.btn-power:hover { background-color: #e63939; }
.btn-audio { background-color: #2b78e4; color: #fff; border-color: #1a62c7; }
.btn-audio:hover { background-color: #1a62c7; }

.form-row {
    margin-bottom: 15px;
}
.form-row label {
    display: block;
    margin-bottom: 6px;
    font-weight: bold;
    font-size: 0.9rem;
}
.form-row input, .form-row select {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-sizing: border-box;
}
.form-hint {
    font-size: 0.8rem;
    color: #666;
    margin-top: 4px;
}

.table-topics {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    margin-top: 10px;
}
.table-topics th, .table-topics td {
    padding: 9px 12px;
    border: 1px solid #e0e0e0;
    text-align: left;
}
.table-topics th {
    background-color: #f7f7f7;
}
.code-badge {
    background: #f1f3f5;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.88rem;
}
.alert-box {
    display: none;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.alert-box.success { display: block; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-box.error { display: block; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

.discovery-panel {
    background: #f0f7ff;
    border: 1px solid #b8daff;
    border-radius: 6px;
    padding: 14px 16px;
    margin-bottom: 20px;
}
.console-box {
    background: #1e1e1e;
    color: #00ff66;
    padding: 14px;
    border-radius: 6px;
    max-height: 320px;
    overflow-y: auto;
    font-family: Consolas, "Courier New", monospace;
    font-size: 0.84rem;
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-all;
}
</style>

<div id="alertFeedback" class="alert-box"></div>

<!-- Auto-Discovery Box -->
<div class="discovery-panel">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <strong>🔍 Automatische Erkennung (Auto-Discovery):</strong>
            <span style="font-size: 0.9rem; color: #495057; display: block;">
                Sucht Panasonic Viera Fernseher automatisch im lokalen Netzwerk über UPnP / SSDP.
            </span>
        </div>
        <button type="button" class="remote-btn" id="btnScan" onclick="startDiscovery()" style="background:#007bff; color:#fff; border-color:#0069d9;">
            📡 Netzwerk nach Fernsehern scannen
        </button>
    </div>

    <div id="discoveryResults" style="display: none; margin-top: 15px; padding-top: 12px; border-top: 1px dashed #b8daff;">
        <label for="selectTv" style="font-weight: bold; font-size: 0.9rem; display: block; margin-bottom: 6px;">
            Gefundene Geräte im Netzwerk:
        </label>
        <div style="display: flex; gap: 10px;">
            <select id="selectTv" style="flex: 1; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 6px;"></select>
            <button type="button" class="remote-btn" onclick="applyDiscoveredTv()" style="background:#28a745; color:#fff; border-color:#28a745;">
                ✔ Daten übernehmen
            </button>
        </div>
    </div>
</div>

<div class="panasonic-dashboard">
    <!-- Live Status Card -->
    <div class="card-box">
        <div class="card-header-bar">
            <h2><?php echo __t('COMMON.LABEL_STATUS', 'Gerätestatus'); ?></h2>
            <button type="button" class="remote-btn" onclick="checkStatus(true)">↻ <?php echo __t('COMMON.LABEL_STATUS', 'Aktualisieren'); ?></button>
        </div>
        <div style="line-height: 2;">
            <div><strong>Fernseher:</strong> <span id="tvStatusBadge" class="status-pill status-standby">Prüfe...</span></div>
            <div><strong><?php echo __t('COMMON.LABEL_DAEMON', 'Hintergrunddienst (Daemon)'); ?>:</strong> <span id="daemonStatusBadge" class="status-pill status-standby">Prüfe...</span></div>
            <div><strong>Lautstärke:</strong> <span id="tvVolume" style="font-weight:bold;">-</span>%</div>
            <div><strong>Stumm (Mute):</strong> <span id="tvMute" style="font-weight:bold;">-</span></div>
            <div><strong>MQTT Topic:</strong> <span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/*</span></div>
        </div>
        <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="remote-btn" onclick="restartDaemon()"><?php echo __t('COMMON.LABEL_RESTART_DAEMON', 'Dienst neu starten'); ?></button>
            <button type="button" class="remote-btn btn-power" onclick="sendWol()">⚡ Wake-on-LAN (Einschalten)</button>
        </div>
    </div>

    <!-- Audio Output Quick Switch (Harman Kardon / TV Speakers) -->
    <div class="card-box">
        <div class="card-header-bar">
            <h2><?php echo __t('AUDIO.SECTION_TITLE', 'Audioausgabe (VIERA Link)'); ?></h2>
        </div>
        <p style="font-size:0.9rem; color:#555;">
            <?php echo __t('AUDIO.DESC_AUDIO', 'Umschaltung zwischen externem Heimkino-Receiver (z.B. Harman Kardon) und internen TV-Lautsprechern über VIERA Link.'); ?>
        </p>
        <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
            <button type="button" class="remote-btn btn-audio" style="font-size: 1rem; padding: 14px;" onclick="sendMacro('home_theater')">
                🔊 <?php echo __t('AUDIO.BTN_SWITCH_HOME_THEATER', 'Auf Heimkino (Harman Kardon) umschalten'); ?>
            </button>
            <button type="button" class="remote-btn" style="font-size: 1rem; padding: 14px;" onclick="sendMacro('tv_speakers')">
                📺 <?php echo __t('AUDIO.BTN_SWITCH_TV', 'Auf TV-Lautsprecher umschalten'); ?>
            </button>
        </div>
        <div class="form-hint" style="margin-top: 15px;">
            MQTT: <span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/audio_output</span> &rarr; <span class="code-badge">home_theater</span> / <span class="code-badge">tv</span>
        </div>
    </div>

    <!-- Live Remote Control Pad -->
    <div class="card-box">
        <div class="card-header-bar">
            <h2><?php echo __t('REMOTE.SECTION_TITLE', 'Fernbedienung Live-Test'); ?></h2>
        </div>
        <div class="remote-grid">
            <button type="button" class="remote-btn btn-power span-3" onclick="sendKey('NRC_POWER-ONOFF')">POWER (Toggle)</button>
            
            <button type="button" class="remote-btn" onclick="sendKey('NRC_VOLUP-ONOFF')">Lauter +</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_MUTE-ONOFF')">MUTE</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_VOLDOWN-ONOFF')">Leiser -</button>
            
            <div></div>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_UP-ONOFF')">▲</button>
            <div></div>
            
            <button type="button" class="remote-btn" onclick="sendKey('NRC_LEFT-ONOFF')">◀</button>
            <button type="button" class="remote-btn" style="background:#e8f0fe;" onclick="sendKey('NRC_ENTER-ONOFF')">OK</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_RIGHT-ONOFF')">▶</button>
            
            <div></div>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_DOWN-ONOFF')">▼</button>
            <div></div>

            <button type="button" class="remote-btn" onclick="sendKey('NRC_RETURN-ONOFF')">Zurück</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_HOME-ONOFF')">HOME</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_CANCEL-ONOFF')">EXIT</button>

            <button type="button" class="remote-btn" onclick="sendKey('NRC_HDMI1-ONOFF')">HDMI 1</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_HDMI2-ONOFF')">HDMI 2</button>
            <button type="button" class="remote-btn" onclick="sendKey('NRC_HDMI3-ONOFF')">HDMI 3</button>
        </div>
    </div>
</div>

<!-- Configuration Form -->
<div class="card-box">
    <div class="card-header-bar">
        <h2><?php echo __t('TV.SECTION_TITLE', 'Einstellungen'); ?></h2>
    </div>
    <form id="cfgForm" onsubmit="saveConfig(event)">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
            <div>
                <h3 style="font-size: 1.05rem; margin-top:0;">Fernseher</h3>
                <div class="form-row">
                    <label for="tvHost"><?php echo __t('TV.LABEL_HOST', 'IP-Adresse oder Hostname'); ?>:</label>
                    <input type="text" id="tvHost" name="tv_host" value="<?php echo htmlspecialchars($tv_host); ?>" required placeholder="z.B. 192.168.1.55">
                    <div class="form-hint"><?php echo __t('TV.HINT_HOST', 'Die feste IP deines Panasonic Fernsehers im Heimnetz.'); ?></div>
                </div>
                <div class="form-row">
                    <label for="tvPort"><?php echo __t('TV.LABEL_PORT', 'Netzwerk-Port'); ?>:</label>
                    <input type="number" id="tvPort" name="tv_port" value="<?php echo htmlspecialchars($tv_port); ?>" required>
                    <div class="form-hint">Standard: 55000</div>
                </div>
                <div class="form-row">
                    <label for="tvMac"><?php echo __t('TV.LABEL_MAC', 'MAC-Adresse (für Wake-on-LAN)'); ?>:</label>
                    <input type="text" id="tvMac" name="tv_mac" value="<?php echo htmlspecialchars($tv_mac); ?>" placeholder="z.B. 00:80:45:AB:CD:EF">
                    <div class="form-hint"><?php echo __t('TV.HINT_MAC', 'Ermöglicht das Einschalten aus dem Standby per LAN.'); ?></div>
                </div>
                <div class="form-row">
                    <label for="tvModel">Modellbezeichnung:</label>
                    <input type="text" id="tvModel" name="tv_model" value="<?php echo htmlspecialchars($tv_model); ?>" placeholder="z.B. TX-55EX600W">
                </div>
            </div>

            <div>
                <h3 style="font-size: 1.05rem; margin-top:0;"><?php echo __t('MQTT.SECTION_TITLE', 'MQTT & Polling'); ?></h3>
                <div class="form-row">
                    <label>
                        <input type="checkbox" id="mqttUseGateway" <?php echo $mqtt_use_gateway ? 'checked' : ''; ?>>
                        <?php echo __t('MQTT.LABEL_USE_LB_GATEWAY', 'LoxBerry MQTT Gateway nutzen (empfohlen)'); ?>
                    </label>
                    <div class="form-hint"><?php echo htmlspecialchars($lb_mqtt_info); ?></div>
                </div>
                <div class="form-row">
                    <label for="mqttPrefix"><?php echo __t('MQTT.LABEL_TOPIC_PREFIX', 'MQTT Topic-Präfix'); ?>:</label>
                    <input type="text" id="mqttPrefix" value="<?php echo htmlspecialchars($mqtt_topic_prefix); ?>" placeholder="panasonictv">
                    <div class="form-hint"><?php echo __t('MQTT.HINT_TOPIC_PREFIX', 'Basis-Pfad für alle MQTT-Nachrichten.'); ?></div>
                </div>
                <div class="form-row">
                    <label for="pollActive"><?php echo __t('MQTT.LABEL_POLL_ACTIVE', 'Abfrageintervall wenn TV an (Sekunden)'); ?>:</label>
                    <input type="number" id="pollActive" min="2" max="60" value="<?php echo htmlspecialchars($poll_active); ?>">
                </div>
                <div class="form-row">
                    <label for="pollStandby"><?php echo __t('MQTT.LABEL_POLL_STANDBY', 'Abfrageintervall im Standby (Sekunden)'); ?>:</label>
                    <input type="number" id="pollStandby" min="10" max="300" value="<?php echo htmlspecialchars($poll_standby); ?>">
                </div>
                <div class="form-row">
                    <label for="sysLogLevel"><?php echo __t('LOGGING.LABEL_LOGLEVEL', 'Log-Level'); ?>:</label>
                    <select id="sysLogLevel">
                        <option value="7" <?php echo ($log_level == 7 || $log_level === 'DEBUG') ? 'selected' : ''; ?>>DEBUG (Detaillierte Diagnose)</option>
                        <option value="6" <?php echo ($log_level == 6 || $log_level === 'INFO') ? 'selected' : ''; ?>>INFO (Standard)</option>
                        <option value="4" <?php echo ($log_level == 4 || $log_level === 'WARNING') ? 'selected' : ''; ?>>WARNING (Nur Warnungen und Fehler)</option>
                        <option value="3" <?php echo ($log_level == 3 || $log_level === 'ERROR') ? 'selected' : ''; ?>>ERROR (Nur Fehler)</option>
                        <option value="0" <?php echo ($log_level === 0 || $log_level === '0' || $log_level === 'OFF') ? 'selected' : ''; ?>>AUS (Kein Logging)</option>
                    </select>
                    <div class="form-hint"><?php echo __t('LOGGING.HINT_LOGLEVEL', 'Wird auch mit der LoxBerry Plugin-Übersicht synchronisiert.'); ?></div>
                </div>
            </div>
        </div>

        <div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
            <button type="submit" class="lb-btn lb-btn-primary" style="background:#28a745; color:#fff; padding:10px 24px; font-size:1rem; border:none; border-radius:6px; cursor:pointer;">
                💾 <?php echo __t('COMMON.LABEL_SAVE', 'Einstellungen speichern'); ?>
            </button>
        </div>
    </form>
</div>

<!-- Live Log Viewer Card (Inline) -->
<div class="card-box">
    <div class="card-header-bar">
        <h2><?php echo __t('LOGGING.SECTION_TITLE', 'Logdatei & Diagnose'); ?></h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="remote-btn" onclick="fetchLog()">↻ <?php echo __t('LOGGING.BTN_REFRESH_LOG', 'Aktualisieren'); ?></button>
            <button type="button" class="remote-btn" onclick="copyLog()">📋 <?php echo __t('LOGGING.BTN_COPY_LOG', 'Kopieren'); ?></button>
            <button type="button" class="remote-btn" onclick="downloadLog()">⬇ <?php echo __t('LOGGING.BTN_DOWNLOAD_LOG', 'Herunterladen'); ?></button>
            <button type="button" class="remote-btn" onclick="clearLog()">🗑 <?php echo __t('LOGGING.BTN_CLEAR_LOG', 'Log leeren'); ?></button>
            <a href="/admin/system/logmanager.cgi?package=panasonictv" class="remote-btn" style="text-decoration:none;">
                📂 <?php echo __t('LOGGING.BTN_OPEN_LOG_MANAGER', 'LoxBerry Log-Manager'); ?>
            </a>
        </div>
    </div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; font-size:0.85rem; color:#666;">
        <span id="logFilePath">Lade Log...</span>
        <label><input type="checkbox" id="chkAutoLog" onchange="toggleAutoLog()"> Auto-Refresh (5s)</label>
    </div>
    <div id="logOutput" class="console-box">Lade Logdatei...</div>
</div>

<!-- Loxone Integration Reference -->
<div class="card-box">
    <div class="card-header-bar">
        <h2><?php echo __t('LOXONE.SECTION_TITLE', 'Loxone Miniserver Einbindung (MQTT)'); ?></h2>
    </div>
    <p style="font-size:0.92rem; color:#555;">
        <?php echo __t('LOXONE.DESC_LOXONE', 'Alle Werte werden bei Statusänderung automatisch an das LoxBerry MQTT Gateway übergeben.'); ?>
    </p>

    <table class="table-topics">
        <thead>
            <tr>
                <th><?php echo __t('LOXONE.COL_TYPE', 'Loxone Element'); ?></th>
                <th><?php echo __t('LOXONE.COL_TOPIC', 'MQTT Topic'); ?></th>
                <th><?php echo __t('LOXONE.COL_VALUES', 'Werte / Funktion'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Virtueller Eingang (Status)</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/power</span></td>
                <td><span class="code-badge">ON</span> / <span class="code-badge">OFF</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Eingang (Status)</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/volume</span></td>
                <td>Lautstärke von <span class="code-badge">0</span> bis <span class="code-badge">100</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Eingang (Status)</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/status/mute</span></td>
                <td><span class="code-badge">true</span> / <span class="code-badge">false</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Ausgangsbefehl</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/power</span></td>
                <td><span class="code-badge">ON</span> (WOL), <span class="code-badge">OFF</span>, <span class="code-badge">TOGGLE</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Ausgangsbefehl</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/volume</span></td>
                <td><span class="code-badge">0-100</span>, <span class="code-badge">+5</span>, <span class="code-badge">-5</span>, <span class="code-badge">UP</span>, <span class="code-badge">DOWN</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Ausgangsbefehl</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/mute</span></td>
                <td><span class="code-badge">true</span>, <span class="code-badge">false</span>, <span class="code-badge">toggle</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Ausgangsbefehl</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/audio_output</span></td>
                <td><span class="code-badge">home_theater</span> / <span class="code-badge">tv</span></td>
            </tr>
            <tr>
                <td><strong>Virtueller Ausgangsbefehl</strong></td>
                <td><span class="code-badge"><?php echo htmlspecialchars($mqtt_topic_prefix); ?>/set/key</span></td>
                <td><span class="code-badge">MUTE</span>, <span class="code-badge">HDMI1</span>, <span class="code-badge">HOME</span>, <span class="code-badge">ENTER</span>...</td>
            </tr>
        </tbody>
    </table>
</div>

</div><!-- /lb-content -->

<script>
let discoveredDevices = [];
let autoLogTimer = null;

function showAlert(message, isSuccess) {
    const el = document.getElementById('alertFeedback');
    el.textContent = message;
    el.className = 'alert-box ' + (isSuccess ? 'success' : 'error');
    setTimeout(() => { el.className = 'alert-box'; }, 4500);
}

function startDiscovery() {
    const btn = document.getElementById('btnScan');
    btn.disabled = true;
    btn.textContent = '⏳ Scanne Netzwerk...';
    showAlert('Suche Panasonic Fernseher im Netzwerk...', true);

    fetch('ajax.php?action=discover')
        .then(res => {
            if (!res.ok) throw new Error('HTTP Status ' + res.status);
            return res.json();
        })
        .then(data => {
            btn.disabled = false;
            btn.textContent = '📡 Netzwerk nach Fernsehern scannen';
            if (data.success && data.devices && data.devices.length > 0) {
                discoveredDevices = data.devices;
                const select = document.getElementById('selectTv');
                select.innerHTML = '';
                data.devices.forEach((dev, idx) => {
                    const opt = document.createElement('option');
                    opt.value = idx;
                    const macTxt = dev.mac ? ' [MAC: ' + dev.mac + ']' : '';
                    const displayName = dev.model_full || dev.model_number || dev.name || dev.model || 'Panasonic TV';
                    opt.textContent = displayName + ' (' + dev.ip + ')' + macTxt;
                    select.appendChild(opt);
                });
                document.getElementById('discoveryResults').style.display = 'block';
                showAlert(data.devices.length + ' Panasonic Fernseher gefunden!', true);
            } else {
                showAlert('Kein Panasonic Fernseher gefunden. Stelle sicher, dass der TV eingeschaltet ist.', false);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = '📡 Netzwerk nach Fernsehern scannen';
            showAlert('Fehler beim Netzwerkscan: ' + err, false);
        });
}

function applyDiscoveredTv() {
    const select = document.getElementById('selectTv');
    const idx = parseInt(select.value);
    if (!isNaN(idx) && discoveredDevices[idx]) {
        const dev = discoveredDevices[idx];
        if (dev.ip) document.getElementById('tvHost').value = dev.ip;
        if (dev.port) document.getElementById('tvPort').value = dev.port;
        if (dev.mac) document.getElementById('tvMac').value = dev.mac;
        
        // Take specific model type (e.g. TX-55EX600W or TX-55EX600 Series)
        const chosenModel = dev.model_full || dev.model_number || dev.name || dev.model || 'TX-55EX600W';
        document.getElementById('tvModel').value = chosenModel;
        
        showAlert('Daten von ' + chosenModel + ' (' + dev.ip + ') erfolgreich übernommen!', true);
    }
}

function checkStatus(manual = false) {
    fetch('ajax.php?action=get_status')
        .then(res => {
            if (!res.ok) throw new Error('HTTP Status ' + res.status);
            return res.json();
        })
        .then(data => {
            if (!data.success) {
                if (manual) showAlert('Fehler: ' + (data.error || 'Statusabruf fehlgeschlagen'), false);
                return;
            }
            
            const dBadge = document.getElementById('daemonStatusBadge');
            if (data.daemon_running) {
                dBadge.textContent = '<?php echo __t('COMMON.LABEL_RUNNING', 'Läuft'); ?>';
                dBadge.className = 'status-pill status-online';
            } else {
                dBadge.textContent = '<?php echo __t('COMMON.LABEL_STOPPED', 'Gestoppt'); ?>';
                dBadge.className = 'status-pill status-offline';
            }

            const tBadge = document.getElementById('tvStatusBadge');
            if (data.tv && data.tv.online) {
                tBadge.textContent = '<?php echo __t('COMMON.LABEL_ONLINE', 'Eingeschaltet / Online'); ?>';
                tBadge.className = 'status-pill status-online';
                document.getElementById('tvVolume').textContent = (data.tv.volume !== null && data.tv.volume !== undefined) ? data.tv.volume : '-';
                document.getElementById('tvMute').textContent = data.tv.mute ? 'Ja' : 'Nein';
            } else {
                tBadge.textContent = '<?php echo __t('COMMON.LABEL_OFFLINE', 'Standby / Offline'); ?>';
                tBadge.className = 'status-pill status-offline';
                document.getElementById('tvVolume').textContent = '-';
                document.getElementById('tvMute').textContent = '-';
            }

            if (manual) showAlert('Status erfolgreich aktualisiert', true);
        })
        .catch(err => {
            console.error('Status check error:', err);
            const dBadge = document.getElementById('daemonStatusBadge');
            if (dBadge) {
                dBadge.textContent = 'Gestoppt / Offline';
                dBadge.className = 'status-pill status-offline';
            }
            if (manual) showAlert('Verbindungsfehler beim Statusabruf: ' + err, false);
        });
}

function sendKey(keyName) {
    fetch('ajax.php?action=send_key&key=' + encodeURIComponent(keyName))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert('Taste gesendet: ' + keyName, true);
                setTimeout(checkStatus, 500);
            } else {
                showAlert('Fehler beim Senden: ' + (data.error || 'TV nicht erreichbar'), false);
            }
        });
}

function sendMacro(macroName) {
    showAlert('Führe Audio-Makro aus...', true);
    fetch('ajax.php?action=send_macro&macro=' + encodeURIComponent(macroName))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert('Audioausgabe erfolgreich umgeschaltet (' + macroName + ')', true);
            } else {
                showAlert('Fehler bei Makroausführung: ' + (data.error || 'TV nicht erreichbar'), false);
            }
        });
}

function sendWol() {
    showAlert('Sende Wake-on-LAN Signal...', true);
    fetch('ajax.php?action=send_wol')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert('Wake-On-LAN Paket gesendet!', true);
                setTimeout(checkStatus, 2500);
            } else {
                showAlert('WOL fehlgeschlagen: ' + (data.error || 'MAC-Adresse prüfen'), false);
            }
        });
}

function restartDaemon() {
    showAlert('Dienst wird neu gestartet...', true);
    fetch('ajax.php?action=restart_daemon')
        .then(res => res.json())
        .then(data => {
            showAlert('Dienst neu gestartet', true);
            setTimeout(checkStatus, 1500);
            setTimeout(fetchLog, 2000);
        })
        .catch(err => {
            showAlert('Fehler beim Neustart des Dienstes: ' + err, false);
        });
}

function fetchLog() {
    fetch('ajax.php?action=get_log&lines=120')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('logFilePath').textContent = 'Datei: ' + data.log_file;
                const el = document.getElementById('logOutput');
                el.textContent = data.content || '(Logdatei ist leer)';
                el.scrollTop = el.scrollHeight;
            }
        })
        .catch(err => {
            document.getElementById('logOutput').textContent = 'Fehler beim Laden des Logs: ' + err;
        });
}

function clearLog() {
    if (!confirm('Logdatei wirklich leeren?')) return;
    fetch('ajax.php?action=clear_log')
        .then(res => res.json())
        .then(data => {
            showAlert('Logdatei wurde geleert', true);
            fetchLog();
        });
}

function copyLog() {
    const text = document.getElementById('logOutput').textContent;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showAlert('Loginhalt in Zwischenablage kopiert!', true);
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.left = '0';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const successful = document.execCommand('copy');
        document.body.removeChild(ta);
        if (successful) {
            showAlert('Loginhalt in Zwischenablage kopiert!', true);
        } else {
            showAlert('Kopieren nicht unterstützt - bitte Text manuell markieren', false);
        }
    } catch (err) {
        showAlert('Kopieren fehlgeschlagen: ' + err, false);
    }
}

function downloadLog() {
    const text = document.getElementById('logOutput').textContent;
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'panasonictv_daemon.log';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function toggleAutoLog() {
    const chk = document.getElementById('chkAutoLog');
    if (chk.checked) {
        if (!autoLogTimer) autoLogTimer = setInterval(fetchLog, 5000);
    } else {
        if (autoLogTimer) {
            clearInterval(autoLogTimer);
            autoLogTimer = null;
        }
    }
}

function saveConfig(e) {
    e.preventDefault();
    const payload = {
        tv: {
            host: document.getElementById('tvHost').value,
            port: document.getElementById('tvPort').value,
            mac: document.getElementById('tvMac').value,
            model: document.getElementById('tvModel').value
        },
        mqtt: {
            use_loxberry_gateway: document.getElementById('mqttUseGateway').checked,
            topic_prefix: document.getElementById('mqttPrefix').value
        },
        polling: {
            interval_active: document.getElementById('pollActive').value,
            interval_standby: document.getElementById('pollStandby').value
        },
        system: {
            loglevel: parseInt(document.getElementById('sysLogLevel').value) || 6
        }
    };

    showAlert('Speichere Konfiguration...', true);

    fetch('ajax.php?action=save_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => {
        if (!res.ok) throw new Error('HTTP Status ' + res.status);
        return res.json();
    })
    .then(data => {
        if (data.success) {
            showAlert('Einstellungen erfolgreich gespeichert und Dienst aktualisiert!', true);
            setTimeout(checkStatus, 1500);
            setTimeout(fetchLog, 2000);
        } else {
            showAlert('Fehler beim Speichern: ' + (data.error || data.message || 'Unbekannt'), false);
        }
    })
    .catch(err => {
        showAlert('Speicherfehler: ' + err, false);
    });
}

// Initial calls
document.addEventListener('DOMContentLoaded', () => {
    checkStatus();
    fetchLog();
    setInterval(checkStatus, 15000);
});
</script>

<?php
LBWeb::lbfooter();
?>
