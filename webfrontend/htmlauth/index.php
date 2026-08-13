<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

$L = LBSystem::readlanguage("language.ini");

$pluginconfigdir = LBPCONFIGDIR;
$pluginbindir = LBPBINDIR;
$plugindatadir = LBPDATADIR;
$pluginlogdir = LBPLOGDIR;
$configfile = "$pluginconfigdir/somfy.json";
$pidfile = "/run/shm/somfy.pid";
$python = "$plugindatadir/venv/bin/python3";

$log = LBLog::newLog(["name" => "WebUI", "stderr" => 1, "addtime" => 1, "append" => 1]);

// --- Loxone XML export (before any HTML output) ---
if (isset($_GET['export']) && $_GET['export'] === 'loxone') {
    $config = json_decode(file_get_contents($configfile), true);
    if (!$config) { http_response_code(500); exit; }

    $prefix = htmlspecialchars($config['mqtt_prefix'] ?? 'somfy');
    $props = [
        ['closure', 'Closure',  'true',  0, 100],
        ['state',   'State',    'false', 0, 1],
        ['moving',  'Moving',   'false', 0, 1],
        ['online',  'Online',   'false', 0, 1],
    ];

    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $xml .= '<VirtualInUdp Title="Somfy Shades (MQTT UDP)" Comment="" Address="" Port="11883">' . "\n";
    foreach ($config['devices'] ?? [] as $dev) {
        if (!($dev['enabled'] ?? true)) continue;
        $dname = htmlspecialchars($dev['name'] ?? $dev['id']);
        $did = htmlspecialchars($dev['id']);
        foreach ($props as [$key, $label, $analog, $min, $max]) {
            $title = "$label $dname";
            $check = "MQTT:\\i$prefix/$did/status/$key=\\i\\v";
            $hi = $analog === 'true' ? $max : 1;
            $xml .= "  <VirtualInUdpCmd\n"
                . "    Title=\"$title\"\n"
                . "    Comment=\"\"\n"
                . "    Address=\"\"\n"
                . "    Check=\"$check\"\n"
                . "    Signed=\"true\"\n"
                . "    Analog=\"$analog\"\n"
                . "    SourceValLow=\"0\"\n"
                . "    DestValLow=\"0\"\n"
                . "    SourceValHigh=\"$hi\"\n"
                . "    DestValHigh=\"$hi\"\n"
                . "    DefVal=\"0\"\n"
                . "    MinVal=\"$min\"\n"
                . "    MaxVal=\"$max\"/>\n";
        }
    }
    $xml .= '</VirtualInUdp>' . "\n";

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="VI_MQTT_UDP_Somfy.xml"');
    echo $xml;
    exit;
}

function daemon_is_running($pidfile) {
    if (!file_exists($pidfile)) return false;
    $pid = trim(file_get_contents($pidfile));
    return $pid && file_exists("/proc/$pid");
}

function tahoma_api_call($ip, $token, $method, $endpoint) {
    $url = "https://$ip:8443/enduser-mobile-web/1/enduserAPI$endpoint";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    }
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => $error];
    if ($httpcode !== 200) return ['error' => "HTTP $httpcode"];
    return json_decode($response, true);
}

// --- AJAX handlers ---
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax'];
    LOGSTART("AJAX: $action");

    if ($action === 'save_config') {
        $config = json_decode($_POST['config'], true);
        if ($config === null) {
            LOGERR("Config save failed: invalid JSON");
            LOGEND("");
            echo json_encode(['success' => false, 'message' => $L['BASIC.MSG_SAVE_ERROR']]);
            exit;
        }
        $config['poll_interval'] = max(5, min(300, intval($config['poll_interval'])));
        $config['mqtt_prefix'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $config['mqtt_prefix']);
        if (empty($config['mqtt_prefix'])) $config['mqtt_prefix'] = 'somfy';
        if (!isset($config['devices'])) $config['devices'] = [];
        $result = file_put_contents($configfile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($result !== false) {
            LOGINF("Config saved (" . count($config['devices']) . " devices, prefix: " . $config['mqtt_prefix'] . ")");
        } else {
            LOGERR("Config save failed: write error");
        }
        LOGEND("");
        echo json_encode(['success' => $result !== false, 'message' => $result !== false ? $L['BASIC.MSG_SAVED'] : $L['BASIC.MSG_SAVE_ERROR']]);
        exit;
    }

    if ($action === 'test_tahoma') {
        $ip = $_POST['ip'] ?? '';
        $token = $_POST['token'] ?? '';
        if (!$ip || !$token) {
            LOGERR("Test connection: missing IP or token");
            LOGEND("");
            echo json_encode(['success' => false, 'message' => $L['BASIC.MSG_TAHOMA_NOT_CONFIGURED']]);
            exit;
        }
        LOGINF("Testing TaHoma connection to $ip");
        $result = tahoma_api_call($ip, $token, 'GET', '/setup/devices');
        $success = is_array($result) && !isset($result['error']);
        if ($success) {
            LOGINF("TaHoma connection OK (" . count($result) . " devices)");
        } else {
            LOGERR("TaHoma connection failed: " . ($result['error'] ?? 'unknown'));
        }
        LOGEND("");
        echo json_encode([
            'success' => $success,
            'message' => $success ? $L['BASIC.MSG_TEST_OK'] : ($L['BASIC.MSG_TEST_FAIL'] . ' ' . ($result['error'] ?? '')),
        ]);
        exit;
    }

    if ($action === 'discover_devices') {
        $config = json_decode(file_get_contents($configfile), true);
        $ip = $config['tahoma_ip'] ?? '';
        $token = $config['tahoma_token'] ?? '';
        if (!$ip || !$token) {
            LOGERR("Discovery: TaHoma not configured");
            LOGEND("");
            echo json_encode(['success' => false, 'message' => $L['BASIC.MSG_TAHOMA_NOT_CONFIGURED']]);
            exit;
        }
        LOGINF("Discovering devices from TaHoma at $ip");
        $result = tahoma_api_call($ip, $token, 'GET', '/setup/devices');
        if (!is_array($result) || isset($result['error'])) {
            LOGERR("Discovery failed: " . ($result['error'] ?? 'unknown'));
            LOGEND("");
            echo json_encode(['success' => false, 'message' => $L['BASIC.MSG_TEST_FAIL'] . ' ' . ($result['error'] ?? '')]);
            exit;
        }

        // Filter to shade devices (those with core:ClosureState)
        $devices = [];
        foreach ($result as $dev) {
            $has_closure = false;
            foreach ($dev['states'] ?? [] as $state) {
                if ($state['name'] === 'core:ClosureState') {
                    $has_closure = true;
                    break;
                }
            }
            if ($has_closure) {
                $devices[] = [
                    'name' => $dev['label'] ?? 'Unknown',
                    'device_url' => $dev['deviceURL'] ?? '',
                    'type' => $dev['definition']['uiClass'] ?? 'Unknown',
                ];
            }
        }
        LOGINF("Discovery: " . count($devices) . " shade devices found (of " . count($result) . " total)");
        LOGEND("");
        echo json_encode(['success' => true, 'devices' => $devices]);
        exit;
    }

    if ($action === 'daemon_status') {
        LOGDEB("Daemon status check");
        LOGEND("");
        echo json_encode(['running' => daemon_is_running($pidfile)]);
        exit;
    }

    if ($action === 'daemon_restart') {
        LOGINF("Daemon restart requested");
        if (daemon_is_running($pidfile)) {
            $pid = trim(file_get_contents($pidfile));
            exec("kill $pid 2>/dev/null");
            LOGINF("Stopped old daemon (PID $pid)");
            sleep(1);
        }
        $daemon = "$pluginbindir/somfy_daemon.py";
        $logdir = LBPLOGDIR;
        exec("$python $daemon --configdir $pluginconfigdir --logdir $logdir > /dev/null 2>&1 &");
        sleep(2);
        $running = daemon_is_running($pidfile);
        if ($running) { LOGINF("Daemon started"); } else { LOGERR("Daemon failed to start"); }
        LOGEND("");
        echo json_encode(['success' => true, 'running' => $running, 'message' => $L['BASIC.MSG_DAEMON_RESTARTED']]);
        exit;
    }

    if ($action === 'daemon_stop') {
        LOGINF("Daemon stop requested");
        if (daemon_is_running($pidfile)) {
            $pid = trim(file_get_contents($pidfile));
            exec("kill $pid 2>/dev/null");
            LOGINF("Daemon stopped (PID $pid)");
            sleep(1);
        }
        LOGEND("");
        echo json_encode(['success' => true, 'message' => $L['BASIC.MSG_DAEMON_STOPPED']]);
        exit;
    }

    LOGERR("Unknown AJAX action: $action");
    LOGEND("");
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// --- Load config ---
$config = ['tahoma_ip' => '', 'tahoma_token' => '', 'mqtt_prefix' => 'somfy', 'poll_interval' => 30, 'devices' => []];
if (file_exists($configfile)) {
    $loaded = json_decode(file_get_contents($configfile), true);
    if ($loaded !== null) $config = $loaded;
}

$daemon_running = daemon_is_running($pidfile);

$statusProps = ['closure', 'state', 'moving', 'online'];

$template_title = $L['BASIC.PLUGIN_TITLE'];
$helplink = "https://github.com/wkulhanek/loxone-somfy";
$helptemplate = "";

LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>

<style>
.somfy-msg { padding: 10px; margin: 10px 0; border-radius: 4px; display: none; }
.somfy-msg-ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.somfy-msg-err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.somfy-topics { background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 0.85em; margin: 5px 0; white-space: pre-wrap; word-break: break-all; }
</style>

<div id="somfy-msg" class="somfy-msg"></div>

<!-- ========== TAHOMA CONNECTION ========== -->
<div data-role="collapsible" data-collapsed="<?= ($config['tahoma_ip'] && $config['tahoma_token']) ? 'true' : 'false' ?>" data-content-theme="a">
    <h3><?= $L['BASIC.H_TAHOMA'] ?></h3>

    <p class="hint"><?= $L['BASIC.DESC_TAHOMA_HELP'] ?></p>

    <div data-role="fieldcontain">
        <label for="tahoma-ip"><?= $L['BASIC.LBL_TAHOMA_IP'] ?></label>
        <input type="text" id="tahoma-ip" data-mini="true"
               value="<?= htmlspecialchars($config['tahoma_ip']) ?>"
               placeholder="192.168.1.100">
        <p class="hint"><?= $L['BASIC.DESC_TAHOMA_IP'] ?></p>
    </div>

    <div data-role="fieldcontain">
        <label for="tahoma-token"><?= $L['BASIC.LBL_TAHOMA_TOKEN'] ?></label>
        <input type="password" id="tahoma-token" data-mini="true"
               value="<?= htmlspecialchars($config['tahoma_token']) ?>"
               placeholder="Bearer token">
        <p class="hint"><?= $L['BASIC.DESC_TAHOMA_TOKEN'] ?></p>
    </div>

    <div data-role="controlgroup" data-type="horizontal" data-mini="true">
        <a href="javascript:void(0)" onclick="testTahoma()" data-role="button" data-icon="gear">
            <?= $L['BASIC.BTN_TEST_CONNECTION'] ?>
        </a>
        <a href="javascript:void(0)" onclick="saveTahomaSettings()" data-role="button" data-icon="check">
            <?= $L['BASIC.BTN_SAVE_CONNECTION'] ?>
        </a>
    </div>
    <div id="test-tahoma-result" style="margin-top: 8px;"></div>
</div>

<!-- ========== SETTINGS ========== -->
<div data-role="collapsible" data-collapsed="false" data-content-theme="a">
    <h3><?= $L['BASIC.H_SETTINGS'] ?></h3>

    <div data-role="fieldcontain">
        <label for="mqtt_prefix"><?= $L['BASIC.LBL_MQTT_PREFIX'] ?></label>
        <input type="text" id="mqtt_prefix" data-mini="true"
               value="<?= htmlspecialchars($config['mqtt_prefix']) ?>"
               placeholder="somfy">
        <p class="hint"><?= $L['BASIC.DESC_MQTT_PREFIX'] ?></p>
    </div>

    <div data-role="fieldcontain">
        <label for="poll_interval"><?= $L['BASIC.LBL_POLL_INTERVAL'] ?></label>
        <input type="number" id="poll_interval" data-mini="true"
               value="<?= intval($config['poll_interval']) ?>"
               min="5" max="300">
        <p class="hint"><?= $L['BASIC.DESC_POLL_INTERVAL'] ?></p>
    </div>

    <a href="javascript:void(0)" onclick="saveSettings()" data-role="button" data-inline="true" data-mini="true" data-icon="check">
        <?= $L['BASIC.BTN_SAVE_SETTINGS'] ?>
    </a>

    <h4><?= $L['BASIC.H_DAEMON'] ?></h4>
    <p>Status:
        <strong id="daemon-status" style="color: <?= $daemon_running ? 'green' : 'red' ?>">
            <?= $daemon_running ? $L['BASIC.LBL_DAEMON_RUNNING'] : $L['BASIC.LBL_DAEMON_STOPPED'] ?>
        </strong>
    </p>
    <div data-role="controlgroup" data-type="horizontal" data-mini="true">
        <a href="javascript:void(0)" onclick="daemonRestart()" data-role="button" data-icon="refresh">
            <?= $L['BASIC.BTN_RESTART'] ?>
        </a>
        <a href="javascript:void(0)" onclick="daemonStop()" data-role="button" data-icon="power">
            <?= $L['BASIC.BTN_STOP'] ?>
        </a>
    </div>
</div>

<!-- ========== DEVICES ========== -->
<div data-role="collapsible" data-collapsed="false" data-content-theme="a">
    <h3><?= $L['BASIC.H_DEVICES'] ?></h3>

    <a href="javascript:void(0)" onclick="discoverDevices()" data-role="button" data-inline="true" data-mini="true" data-icon="search">
        <?= $L['BASIC.BTN_DISCOVER'] ?>
    </a>

    <ul data-role="listview" data-inset="true" id="device-list">
        <li data-role="list-divider"><?= $L['BASIC.H_DEVICES'] ?></li>
    </ul>

    <a href="javascript:void(0)" onclick="showDeviceForm(-1)" data-role="button" data-inline="true" data-mini="true" data-icon="plus">
        <?= $L['BASIC.BTN_ADD_DEVICE'] ?>
    </a>

    <div id="device-form" style="display:none; margin-top: 15px;">
        <div data-role="collapsible" data-collapsed="false" data-content-theme="a" id="device-form-collapsible">
            <h3 id="device-form-title"><?= $L['BASIC.BTN_ADD_DEVICE'] ?></h3>
            <input type="hidden" id="device-edit-index" value="-1">

            <div data-role="fieldcontain">
                <label for="device-name"><?= $L['BASIC.LBL_DEVICE_NAME'] ?></label>
                <input type="text" id="device-name" data-mini="true" placeholder="Roof Shade" oninput="autoSlug()">
            </div>

            <div data-role="fieldcontain">
                <label for="device-id"><?= $L['BASIC.LBL_DEVICE_ID'] ?></label>
                <input type="text" id="device-id" data-mini="true" placeholder="roof_shade">
                <p class="hint"><?= $L['BASIC.DESC_DEVICE_ID'] ?></p>
            </div>

            <div data-role="fieldcontain">
                <label for="device-url"><?= $L['BASIC.LBL_DEVICE_URL'] ?></label>
                <input type="text" id="device-url" data-mini="true" placeholder="io://xxxx-xxxx-xxxx/12345678">
                <p class="hint"><?= $L['BASIC.DESC_DEVICE_URL'] ?></p>
            </div>

            <div data-role="fieldcontain">
                <label for="device-enabled"><?= $L['BASIC.LBL_DEVICE_ENABLED'] ?></label>
                <select id="device-enabled" data-role="flipswitch" data-mini="true">
                    <option value="0">Off</option>
                    <option value="1" selected>On</option>
                </select>
            </div>

            <div data-role="controlgroup" data-type="horizontal" data-mini="true">
                <a href="javascript:void(0)" onclick="saveDevice()" data-role="button" data-icon="check">
                    <?= $L['BASIC.BTN_SAVE'] ?>
                </a>
                <a href="javascript:void(0)" onclick="hideDeviceForm()" data-role="button" data-icon="delete">
                    <?= $L['BASIC.BTN_CANCEL'] ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Discover results popup -->
    <div id="discover-results" style="display:none; margin-top: 15px;">
        <div data-role="collapsible" data-collapsed="false" data-content-theme="a">
            <h3><?= $L['BASIC.H_DISCOVERED'] ?></h3>
            <ul data-role="listview" data-inset="true" id="discover-list"></ul>
        </div>
    </div>
</div>

<!-- ========== MQTT TOPICS ========== -->
<div data-role="collapsible" data-collapsed="true" data-content-theme="a">
    <h3><?= $L['BASIC.H_MQTT_TOPICS'] ?></h3>
    <p><?= $L['BASIC.DESC_MQTT_TOPICS'] ?></p>
    <div id="mqtt-topics-container"></div>

    <div data-role="fieldcontain">
        <label>Download XML-Template for uploading to Loxone:</label>
        <a href="index.php?export=loxone" data-role="button" data-inline="true" data-mini="true">VI_MQTT_UDP_Somfy.xml</a>
    </div>
</div>

<script>
var config = <?= json_encode($config) ?>;
var L = {
    confirm_delete: <?= json_encode($L['BASIC.MSG_CONFIRM_DELETE']) ?>,
    invalid_url: <?= json_encode($L['BASIC.MSG_INVALID_URL']) ?>,
    invalid_id: <?= json_encode($L['BASIC.MSG_INVALID_ID']) ?>,
    edit: <?= json_encode($L['BASIC.BTN_EDIT']) ?>,
    delete_btn: <?= json_encode($L['BASIC.BTN_DELETE']) ?>,
    add_device: <?= json_encode($L['BASIC.BTN_ADD_DEVICE']) ?>,
    no_devices: "No devices configured yet.",
    no_discovered: <?= json_encode($L['BASIC.MSG_DISCOVER_NONE']) ?>,
    status_topics: <?= json_encode($L['BASIC.LBL_STATUS_TOPICS']) ?>,
    cmd_topics: <?= json_encode($L['BASIC.LBL_CMD_TOPICS']) ?>,
    daemon_running: <?= json_encode($L['BASIC.LBL_DAEMON_RUNNING']) ?>,
    daemon_stopped: <?= json_encode($L['BASIC.LBL_DAEMON_STOPPED']) ?>
};

var statusProps = <?= json_encode($statusProps) ?>;

function showMessage(text, isError) {
    var el = document.getElementById('somfy-msg');
    el.textContent = text;
    el.className = 'somfy-msg ' + (isError ? 'somfy-msg-err' : 'somfy-msg-ok');
    el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 5000);
}

function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// --- TaHoma Connection ---

function testTahoma() {
    var ip = $('#tahoma-ip').val().trim();
    var token = $('#tahoma-token').val().trim();
    var el = document.getElementById('test-tahoma-result');
    el.innerHTML = '<p>Testing...</p>';
    $.ajax({
        url: 'index.php', method: 'POST',
        data: { ajax: 'test_tahoma', ip: ip, token: token },
        dataType: 'json',
        success: function(resp) {
            var color = resp.success ? 'green' : 'red';
            el.innerHTML = '<p style="color:' + color + '"><strong>' + escHtml(resp.message) + '</strong></p>';
        },
        error: function() { el.innerHTML = '<p style="color:red"><strong>Error</strong></p>'; }
    });
}

function saveTahomaSettings() {
    config.tahoma_ip = $('#tahoma-ip').val().trim();
    config.tahoma_token = $('#tahoma-token').val().trim();
    saveConfig();
}

// --- Device Discovery ---

function discoverDevices() {
    showMessage('Discovering devices...', false);
    $.ajax({
        url: 'index.php', method: 'POST',
        data: { ajax: 'discover_devices' },
        dataType: 'json',
        success: function(resp) {
            if (resp.success && resp.devices) {
                var list = $('#discover-list');
                list.empty();
                if (resp.devices.length === 0) {
                    list.append('<li>' + L.no_discovered + '</li>');
                } else {
                    for (var i = 0; i < resp.devices.length; i++) {
                        var d = resp.devices[i];
                        list.append(
                            '<li><a href="javascript:void(0)">' +
                            '<h3>' + escHtml(d.name) + '</h3>' +
                            '<p>URL: <strong>' + escHtml(d.device_url) + '</strong> Type: ' + escHtml(d.type) + '</p>' +
                            '</a>' +
                            '<a href="javascript:void(0)" onclick="addDiscoveredDevice(' + i + ')" data-icon="plus">Add</a>' +
                            '</li>'
                        );
                    }
                }
                list.listview('refresh');
                window._discoveredDevices = resp.devices;
                $('#discover-results').show();
            } else {
                showMessage(resp.message || 'Discovery failed', true);
            }
        },
        error: function() { showMessage('Discovery request failed', true); }
    });
}

function addDiscoveredDevice(index) {
    var d = window._discoveredDevices[index];
    var slug = slugify(d.name);
    config.devices.push({ id: slug, name: d.name, device_url: d.device_url, enabled: true });
    saveConfig(function() { renderDeviceList(); });
}

// --- Device List ---

function renderDeviceList() {
    var list = $('#device-list');
    list.find('li:not([data-role="list-divider"])').remove();

    if (config.devices.length === 0) {
        list.append('<li><em>' + L.no_devices + '</em></li>');
    } else {
        for (var i = 0; i < config.devices.length; i++) {
            var dev = config.devices[i];
            var enabled = dev.enabled ? '<span style="color:green">&#9679;</span>' : '<span style="color:gray">&#9679;</span>';
            var li = '<li>' +
                '<a href="javascript:void(0)" onclick="showDeviceForm(' + i + ')">' +
                    '<h3>' + escHtml(dev.name) + ' ' + enabled + '</h3>' +
                    '<p>ID: <strong>' + escHtml(dev.id) + '</strong> &nbsp; URL: <strong>' + escHtml(dev.device_url) + '</strong></p>' +
                '</a>' +
                '<a href="javascript:void(0)" onclick="deleteDevice(' + i + ')" data-icon="delete" data-theme="a">' +
                    L.delete_btn +
                '</a>' +
            '</li>';
            list.append(li);
        }
    }
    list.listview('refresh');
    renderMqttTopics();
}

function renderMqttTopics() {
    var container = document.getElementById('mqtt-topics-container');
    container.innerHTML = '';
    var prefix = config.mqtt_prefix || 'somfy';

    if (config.devices.length === 0) {
        container.innerHTML = '<p><em>' + L.no_devices + '</em></p>';
        return;
    }

    for (var i = 0; i < config.devices.length; i++) {
        var dev = config.devices[i];
        var html = '<h4>' + escHtml(dev.name) + ' (' + escHtml(dev.id) + ')</h4>';
        html += '<p><strong>' + L.status_topics + ':</strong></p><div class="somfy-topics">';
        for (var j = 0; j < statusProps.length; j++) html += prefix + '/' + dev.id + '/status/' + statusProps[j] + '\n';
        html += '</div>';
        html += '<p><strong>' + L.cmd_topics + ':</strong></p><div class="somfy-topics">';
        html += prefix + '/' + dev.id + '/cmd  (payload: open / close / stop)\n';
        html += '</div>';
        container.innerHTML += html;
    }
}

function slugify(name) {
    var map = {'ä':'ae','ö':'oe','ü':'ue','ß':'ss'};
    var s = name.toLowerCase().replace(/[äöüß]/g, function(c) { return map[c]; });
    return s.replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function autoSlug() {
    var editIndex = parseInt(document.getElementById('device-edit-index').value);
    if (editIndex >= 0) return;
    $('#device-id').val(slugify(document.getElementById('device-name').value)).trigger('change');
}

function showDeviceForm(index) {
    $('#device-form').show();
    document.getElementById('device-edit-index').value = index;

    if (index >= 0 && index < config.devices.length) {
        var dev = config.devices[index];
        $('#device-form-title').text(L.edit + ': ' + dev.name);
        $('#device-name').val(dev.name);
        $('#device-id').val(dev.id);
        $('#device-url').val(dev.device_url);
        $('#device-enabled').val(dev.enabled ? '1' : '0').flipswitch('refresh');
    } else {
        $('#device-form-title').text(L.add_device);
        $('#device-name').val('');
        $('#device-id').val('');
        $('#device-url').val('');
        $('#device-enabled').val('1').flipswitch('refresh');
    }
}

function hideDeviceForm() { $('#device-form').hide(); }

function validateDeviceForm() {
    var url = $('#device-url').val().trim();
    var id = $('#device-id').val().trim();
    if (!url) { showMessage(L.invalid_url, true); return false; }
    if (!/^[a-z0-9_]+$/.test(id)) { showMessage(L.invalid_id, true); return false; }
    return true;
}

function saveDevice() {
    if (!validateDeviceForm()) return;
    var index = parseInt(document.getElementById('device-edit-index').value);
    var dev = {
        id: $('#device-id').val().trim(),
        name: $('#device-name').val().trim(),
        device_url: $('#device-url').val().trim(),
        enabled: $('#device-enabled').val() === '1'
    };
    if (index >= 0 && index < config.devices.length) {
        config.devices[index] = dev;
    } else {
        config.devices.push(dev);
    }
    saveConfig(function() { hideDeviceForm(); renderDeviceList(); });
}

function deleteDevice(index) {
    if (!confirm(L.confirm_delete)) return;
    config.devices.splice(index, 1);
    saveConfig(function() { renderDeviceList(); });
}

function saveSettings() {
    config.mqtt_prefix = $('#mqtt_prefix').val().trim();
    config.poll_interval = parseInt($('#poll_interval').val()) || 30;
    saveConfig(function() { renderMqttTopics(); });
}

function saveConfig(callback) {
    $.ajax({
        url: 'index.php', method: 'POST',
        data: { ajax: 'save_config', config: JSON.stringify(config) },
        dataType: 'json',
        success: function(resp) {
            showMessage(resp.message, !resp.success);
            if (callback) callback();
        },
        error: function() { showMessage('Error', true); }
    });
}

function daemonRestart() {
    $.ajax({
        url: 'index.php', method: 'POST', data: { ajax: 'daemon_restart' }, dataType: 'json',
        success: function(resp) { showMessage(resp.message, !resp.success); updateDaemonStatus(resp.running); }
    });
}

function daemonStop() {
    $.ajax({
        url: 'index.php', method: 'POST', data: { ajax: 'daemon_stop' }, dataType: 'json',
        success: function(resp) { showMessage(resp.message, !resp.success); updateDaemonStatus(false); }
    });
}

function updateDaemonStatus(running) {
    var el = document.getElementById('daemon-status');
    el.style.color = running ? 'green' : 'red';
    el.textContent = running ? L.daemon_running : L.daemon_stopped;
}

$(document).ready(function() { renderDeviceList(); });
</script>

<?php
LBWeb::lbfooter();
?>
