#!/usr/bin/env python3

import argparse
import json
import logging
import logging.handlers
import os
import signal
import subprocess
import sys
import time

import paho.mqtt.client as mqtt
import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

logger = logging.getLogger("somfy")

PIDFILE = "/run/shm/somfy.pid"

LB_LOGLEVEL_MAP = {
    0: logging.CRITICAL + 10,
    1: logging.CRITICAL,
    2: logging.CRITICAL,
    3: logging.ERROR,
    4: logging.WARNING,
    5: logging.INFO,
    6: logging.INFO,
    7: logging.DEBUG,
}

VALID_COMMANDS = ("open", "close", "stop")

STATE_MAP = {
    "core:ClosureState": "closure",
    "core:OpenClosedState": "state",
    "core:MovingState": "moving",
}


def get_loxberry_loglevel():
    try:
        result = subprocess.run(
            ["perl", "-e", "use LoxBerry::System; print LoxBerry::System::pluginloglevel();"],
            capture_output=True, text=True, timeout=5,
        )
        level = int(result.stdout.strip())
        return LB_LOGLEVEL_MAP.get(level, logging.INFO)
    except Exception:
        return None


def get_mqtt_credentials():
    general_json = os.path.join(
        os.environ.get("LBSCONFIG", "/opt/loxberry/config/system"),
        "general.json",
    )
    try:
        with open(general_json) as f:
            cfg = json.load(f)
        m = cfg.get("Mqtt", {})
        return {
            "host": m.get("Brokerhost", "localhost"),
            "port": int(m.get("Brokerport", 1883)),
            "user": m.get("Brokeruser", ""),
            "pass": m.get("Brokerpass", ""),
        }
    except (FileNotFoundError, json.JSONDecodeError, KeyError) as e:
        logger.warning("Could not read MQTT credentials from %s: %s", general_json, e)
        return {"host": "localhost", "port": 1883, "user": "", "pass": ""}


def format_status_value(value):
    if isinstance(value, bool):
        return "1" if value else "0"
    return str(value)


class TaHomaClient:
    def __init__(self, ip, token):
        self.base_url = f"https://{ip}:8443/enduser-mobile-web/1/enduserAPI"
        self.session = requests.Session()
        self.session.verify = False
        self.session.headers["Authorization"] = f"Bearer {token}"

    def get_all_devices(self):
        resp = self.session.get(f"{self.base_url}/setup/devices", timeout=15)
        resp.raise_for_status()
        return resp.json()

    def get_device_states(self, device_url):
        devices = self.get_all_devices()
        for dev in devices:
            if dev.get("deviceURL") == device_url:
                states = {}
                for s in dev.get("states", []):
                    if s["name"] in STATE_MAP:
                        states[STATE_MAP[s["name"]]] = s["value"]
                return states
        return None

    def execute_command(self, device_url, command):
        payload = {
            "label": command,
            "actions": [{
                "deviceURL": device_url,
                "commands": [{"name": command}],
            }],
        }
        resp = self.session.post(
            f"{self.base_url}/exec/apply",
            json=payload, timeout=15,
        )
        resp.raise_for_status()
        return True

    def test_connection(self):
        resp = self.session.get(f"{self.base_url}/setup/devices", timeout=10)
        return resp.status_code == 200


class SomfyDaemon:
    def __init__(self, config_path, log_dir, loglevel=None):
        self.config_path = config_path
        self.log_dir = log_dir
        self.cli_loglevel = loglevel
        self.config = {}
        self.devices = {}
        self.tahoma = None
        self.mqtt_client = None
        self.running = False
        self.config_mtime = 0

    def setup_logging(self):
        os.makedirs(self.log_dir, exist_ok=True)
        log_file = os.path.join(self.log_dir, "somfy.log")
        handler = logging.handlers.RotatingFileHandler(
            log_file, maxBytes=1_000_000, backupCount=3
        )
        handler.setFormatter(
            logging.Formatter("%(asctime)s %(levelname)s [%(name)s] %(message)s")
        )
        root = logging.getLogger()
        root.addHandler(handler)
        root.addHandler(logging.StreamHandler())

        lb_level = get_loxberry_loglevel()
        if lb_level is not None:
            level = lb_level
        elif self.cli_loglevel is not None:
            level = self.cli_loglevel
        else:
            level = logging.INFO
        root.setLevel(level)
        logger.info("Log level: %s", logging.getLevelName(level))

    def init_tahoma(self):
        ip = self.config.get("tahoma_ip", "")
        token = self.config.get("tahoma_token", "")
        if not ip or not token:
            logger.error("TaHoma IP or token not configured. Please set them in the web UI.")
            return False
        self.tahoma = TaHomaClient(ip, token)
        try:
            if not self.tahoma.test_connection():
                logger.error("TaHoma connection test failed")
                return False
        except Exception as e:
            logger.error("Cannot reach TaHoma gateway at %s: %s", ip, e)
            return False
        logger.info("TaHoma gateway connected at %s", ip)
        return True

    def load_and_apply_config(self):
        logger.debug("Loading config from %s", self.config_path)
        try:
            with open(self.config_path) as f:
                new_config = json.load(f)
        except (FileNotFoundError, json.JSONDecodeError) as e:
            logger.error("Failed to load config: %s", e)
            return False

        self.config_mtime = os.path.getmtime(self.config_path)
        old_prefix = self.config.get("mqtt_prefix", "")
        self.config = new_config

        old_dev_ids = set(self.devices.keys())
        new_dev_ids = set()

        for dev_cfg in new_config.get("devices", []):
            if not all(k in dev_cfg for k in ("id", "device_url")):
                logger.error("Device config missing required fields: %s", dev_cfg)
                continue
            if not dev_cfg.get("enabled", True):
                continue
            new_dev_ids.add(dev_cfg["id"])

        for dev_id in old_dev_ids - new_dev_ids:
            logger.info("Removing device: %s", dev_id)
            self.devices.pop(dev_id, None)
            if self.mqtt_client and self.mqtt_client.is_connected():
                prefix = self.config.get("mqtt_prefix", "somfy")
                self.mqtt_client.publish(f"{prefix}/{dev_id}/status/online", "0", retain=True)

        for dev_cfg in new_config.get("devices", []):
            if not all(k in dev_cfg for k in ("id", "device_url")):
                continue
            if not dev_cfg.get("enabled", True):
                continue
            dev_id = dev_cfg["id"]
            if dev_id not in self.devices:
                logger.info("Adding device: %s (%s)", dev_cfg.get("name", dev_id), dev_cfg["device_url"])
            self.devices[dev_id] = {
                "config": dev_cfg,
                "online": False,
                "last_status": {},
            }

        if self.mqtt_client and self.mqtt_client.is_connected():
            if old_prefix and old_prefix != self.config.get("mqtt_prefix", "somfy"):
                self.mqtt_client.unsubscribe(f"{old_prefix}/+/cmd")
            prefix = self.config.get("mqtt_prefix", "somfy")
            self.mqtt_client.subscribe(f"{prefix}/+/cmd")

        return True

    def connect_mqtt(self):
        creds = get_mqtt_credentials()
        self.mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="somfy-daemon")
        if creds["user"]:
            self.mqtt_client.username_pw_set(creds["user"], creds["pass"])

        self.mqtt_client.on_connect = self._on_mqtt_connect
        self.mqtt_client.on_message = self._on_mqtt_message
        self.mqtt_client.on_disconnect = self._on_mqtt_disconnect

        will_prefix = self.config.get("mqtt_prefix", "somfy")
        self.mqtt_client.will_set(f"{will_prefix}/daemon/status", "offline", retain=True)

        logger.info("Connecting to MQTT broker %s:%d", creds["host"], creds["port"])
        self.mqtt_client.connect(creds["host"], creds["port"], keepalive=60)
        self.mqtt_client.loop_start()

    def _on_mqtt_connect(self, client, userdata, flags, reason_code, properties):
        if reason_code == 0:
            prefix = self.config.get("mqtt_prefix", "somfy")
            logger.info("Connected to MQTT broker")
            client.subscribe(f"{prefix}/+/cmd")
            client.publish(f"{prefix}/daemon/status", "online", retain=True)
        else:
            logger.error("MQTT connection failed: %s", reason_code)

    def _on_mqtt_disconnect(self, client, userdata, flags, reason_code, properties):
        if reason_code != 0:
            logger.warning("Unexpected MQTT disconnect: %s", reason_code)

    def _on_mqtt_message(self, client, userdata, msg):
        prefix = self.config.get("mqtt_prefix", "somfy")
        topic = msg.topic
        payload = msg.payload.decode("utf-8", errors="replace").strip().lower()
        logger.debug("MQTT message: %s = %s", topic, payload)

        if not topic.startswith(prefix + "/"):
            return

        parts = topic[len(prefix) + 1:].split("/")
        if len(parts) != 2 or parts[1] != "cmd":
            return

        dev_id = parts[0]
        if dev_id not in self.devices:
            logger.warning("Command for unknown device: %s", dev_id)
            return

        if payload not in VALID_COMMANDS:
            logger.warning("Unknown command: %s", payload)
            return

        logger.info("Command: %s = %s", dev_id, payload)
        self._execute_command(dev_id, payload)

    def _execute_command(self, dev_id, command):
        dev_entry = self.devices.get(dev_id)
        if not dev_entry:
            return

        device_url = dev_entry["config"]["device_url"]
        try:
            self.tahoma.execute_command(device_url, command)
            logger.info("Command %s/%s succeeded", dev_id, command)
            time.sleep(1)
            self._poll_device(dev_id, dev_entry)
        except Exception as e:
            logger.error("Command %s/%s failed: %s", dev_id, command, e)

    def _poll_device(self, dev_id, dev_entry):
        prefix = self.config.get("mqtt_prefix", "somfy")
        base = f"{prefix}/{dev_id}/status"
        device_url = dev_entry["config"]["device_url"]

        logger.debug("Polling device %s (%s)", dev_id, device_url)
        try:
            states = self.tahoma.get_device_states(device_url)
        except Exception as e:
            logger.warning("Failed to poll device %s: %s", dev_id, e)
            if dev_entry["online"]:
                dev_entry["online"] = False
                self.mqtt_client.publish(f"{base}/online", "0", retain=True)
            return

        if states is None:
            logger.warning("Device %s not found in TaHoma response", dev_id)
            if dev_entry["online"]:
                dev_entry["online"] = False
                self.mqtt_client.publish(f"{base}/online", "0", retain=True)
            return

        dev_entry["online"] = True
        self.mqtt_client.publish(f"{base}/online", "1", retain=True)

        for prop, value in states.items():
            mqtt_val = format_status_value(value)
            dev_entry["last_status"][prop] = mqtt_val
            self.mqtt_client.publish(f"{base}/{prop}", mqtt_val, retain=True)

        logger.debug("Poll %s: %s", dev_id, dev_entry["last_status"])

    def poll_all_devices(self):
        for dev_id, dev_entry in list(self.devices.items()):
            self._poll_device(dev_id, dev_entry)

    def check_config_changed(self):
        try:
            mtime = os.path.getmtime(self.config_path)
            if mtime > self.config_mtime:
                logger.info("Config file changed, reloading...")
                self.load_and_apply_config()
                # Re-init TaHoma client in case IP/token changed
                self.init_tahoma()
        except OSError:
            pass

    def shutdown(self, signum=None, frame=None):
        logger.info("Shutting down...")
        self.running = False

        if self.mqtt_client:
            prefix = self.config.get("mqtt_prefix", "somfy")
            for dev_id in self.devices:
                self.mqtt_client.publish(f"{prefix}/{dev_id}/status/online", "0", retain=True)
            self.mqtt_client.publish(f"{prefix}/daemon/status", "offline", retain=True)
            self.mqtt_client.disconnect()
            self.mqtt_client.loop_stop()

        try:
            os.remove(PIDFILE)
        except OSError:
            pass

        logger.info("Shutdown complete")
        sys.exit(0)

    def run(self):
        self.setup_logging()
        logger.info("Somfy daemon starting")

        with open(PIDFILE, "w") as f:
            f.write(str(os.getpid()))

        signal.signal(signal.SIGTERM, self.shutdown)
        signal.signal(signal.SIGINT, self.shutdown)

        if not self.load_and_apply_config():
            logger.error("Failed to load initial config, exiting")
            sys.exit(1)

        if not self.init_tahoma():
            logger.error("TaHoma connection not available, exiting")
            sys.exit(1)

        self.connect_mqtt()
        time.sleep(1)

        self.running = True
        last_poll = 0

        while self.running:
            now = time.time()
            poll_interval = self.config.get("poll_interval", 30)

            if now - last_poll >= poll_interval:
                self.poll_all_devices()
                last_poll = now

            self.check_config_changed()
            time.sleep(1)


def main():
    parser = argparse.ArgumentParser(description="Somfy TaHoma Shade Control Daemon")
    parser.add_argument("--configdir", default="/opt/loxberry/config/plugins/somfy")
    parser.add_argument("--logdir", default="/opt/loxberry/log/plugins/somfy")
    parser.add_argument("--loglevel", default=None, help="Override log level (DEBUG, INFO, WARNING, ERROR)")
    args = parser.parse_args()

    loglevel = getattr(logging, args.loglevel.upper(), None) if args.loglevel else None
    config_path = os.path.join(args.configdir, "somfy.json")
    daemon = SomfyDaemon(config_path, args.logdir, loglevel=loglevel)
    daemon.run()


if __name__ == "__main__":
    main()
