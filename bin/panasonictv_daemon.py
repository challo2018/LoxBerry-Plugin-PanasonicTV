#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Panasonic TV Background Daemon for LoxBerry.
Handles 2-way MQTT communication:
- Periodically queries TV status and publishes to MQTT
- Subscribes to MQTT set/command topics and controls the TV
- Logs status, commands and errors to LoxBerry daemon log
"""

import sys
import os
import time
import json
import signal
import socket
import logging
from logging.handlers import RotatingFileHandler
from typing import Dict, Any, Optional, List

def get_config_path() -> str:
    if "LBPCONFIG" in os.environ:
        cfg = os.path.join(os.environ["LBPCONFIG"], "panasonictv", "panasonictv.json")
        if os.path.exists(cfg):
            return cfg
    lb_home = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")
    lb_std = os.path.join(lb_home, "config", "plugins", "panasonictv", "panasonictv.json")
    if os.path.exists(lb_std):
        return lb_std
    script_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.abspath(os.path.join(script_dir, "..", "config", "panasonictv.json"))

def load_config() -> Dict[str, Any]:
    cfg_file = get_config_path()
    if os.path.exists(cfg_file):
        try:
            with open(cfg_file, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception as e:
            sys.stderr.write(f"Error loading config {cfg_file}: {e}\n")
    return {}

def get_log_file() -> str:
    if "LBPLOG" in os.environ:
        d = os.path.join(os.environ["LBPLOG"], "panasonictv")
        os.makedirs(d, exist_ok=True)
        return os.path.join(d, "daemon.log")
    lb_home = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")
    d = os.path.join(lb_home, "log", "plugins", "panasonictv")
    try:
        os.makedirs(d, exist_ok=True)
        return os.path.join(d, "daemon.log")
    except Exception:
        return "/tmp/panasonictv_daemon.log"

# Setup Logger
logger = logging.getLogger("panasonictv_daemon")
logger.setLevel(logging.INFO)

def update_logger_level(level_setting):
    level_map = {
        1: logging.CRITICAL,
        2: logging.CRITICAL,
        3: logging.ERROR,
        4: logging.WARNING,
        5: logging.INFO,
        6: logging.INFO,
        7: logging.DEBUG,
        "DEBUG": logging.DEBUG,
        "INFO": logging.INFO,
        "WARNING": logging.WARNING,
        "ERROR": logging.ERROR
    }
    lvl = level_map.get(level_setting, logging.INFO)
    logger.setLevel(lvl)

# Add Handlers
log_formatter = logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s")
try:
    log_path = get_log_file()
    fh = RotatingFileHandler(log_path, maxBytes=5*1024*1024, backupCount=2, encoding="utf-8")
    fh.setFormatter(log_formatter)
    logger.addHandler(fh)
except Exception:
    pass

if sys.stdout.isatty():
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(log_formatter)
    logger.addHandler(sh)

try:
    import paho.mqtt.client as mqtt
except ImportError:
    logger.error("paho-mqtt library is missing. Run pip3 install paho-mqtt")
    sys.exit(1)

try:
    import panasonic_viera
except ImportError:
    logger.warning("panasonic-viera library not available at start; will retry on demand.")
    panasonic_viera = None

RUNNING = True

def signal_handler(signum, frame):
    global RUNNING
    logger.info(f"Received shutdown signal ({signum}). Exiting...")
    RUNNING = False

signal.signal(signal.SIGTERM, signal_handler)
signal.signal(signal.SIGINT, signal_handler)

def get_mqtt_gateway_credentials() -> Dict[str, Any]:
    """
    Retrieve MQTT Gateway credentials from LoxBerry system configs.
    Checks general.json (system MQTT configuration), then mqttgateway.json, then general.cfg.
    """
    creds = {
        "brokerhost": "localhost",
        "brokerport": 1883,
        "brokeruser": "",
        "brokerpass": ""
    }
    lb_home = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")
    lbs_config = os.environ.get("LBSCONFIG", os.path.join(lb_home, "config", "system"))

    # 1. Primary location: general.json -> "Mqtt" section
    general_candidates = [
        os.path.join(lbs_config, "general.json"),
        os.path.join(lb_home, "config", "system", "general.json"),
    ]
    for p in general_candidates:
        if p and os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    data = json.load(f)
                mqtt_sec = data.get("Mqtt") or data.get("mqtt") or {}
                if mqtt_sec:
                    for k, v in mqtt_sec.items():
                        kl = k.lower()
                        if kl == "brokerhost": creds["brokerhost"] = str(v)
                        elif kl == "brokerport": creds["brokerport"] = int(v)
                        elif kl == "brokeruser": creds["brokeruser"] = str(v)
                        elif kl == "brokerpass": creds["brokerpass"] = str(v)
                    if creds.get("brokeruser"):
                        logger.info(f"Loaded LoxBerry MQTT credentials from {p} (user: {creds['brokeruser']})")
                        return creds
            except Exception as e:
                logger.warning(f"Error parsing {p}: {e}")

    # 2. Secondary location: mqttgateway.json -> "Main" section
    gw_candidates = [
        os.path.join(lbs_config, "mqttgateway.json"),
        os.path.join(lb_home, "config", "system", "mqttgateway.json"),
    ]
    for p in gw_candidates:
        if p and os.path.exists(p):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    data = json.load(f)
                sec = data.get("Main") or data.get("main") or data
                for k, v in sec.items():
                    kl = k.lower()
                    if kl == "brokerhost": creds["brokerhost"] = str(v)
                    elif kl == "brokerport": creds["brokerport"] = int(v)
                    elif kl == "brokeruser": creds["brokeruser"] = str(v)
                    elif kl == "brokerpass": creds["brokerpass"] = str(v)
                if creds.get("brokeruser"):
                    logger.info(f"Loaded LoxBerry MQTT Gateway credentials from {p} (user: {creds['brokeruser']})")
                    return creds
            except Exception as e:
                logger.warning(f"Error parsing {p}: {e}")

    # 3. Fallback: general.cfg (legacy INI)
    ini_candidates = [
        os.path.join(lbs_config, "general.cfg"),
        os.path.join(lb_home, "config", "system", "general.cfg"),
    ]
    for p in ini_candidates:
        if p and os.path.exists(p):
            try:
                import configparser
                cfg = configparser.ConfigParser()
                cfg.read(p)
                for s in ["MQTT", "Mqtt", "mqtt"]:
                    if cfg.has_section(s):
                        creds["brokerhost"] = cfg.get(s, "brokerhost", fallback=creds["brokerhost"])
                        creds["brokerport"] = int(cfg.get(s, "brokerport", fallback=str(creds["brokerport"])))
                        creds["brokeruser"] = cfg.get(s, "brokeruser", fallback=creds["brokeruser"])
                        creds["brokerpass"] = cfg.get(s, "brokerpass", fallback=creds["brokerpass"])
                        if creds.get("brokeruser"):
                            logger.info(f"Loaded LoxBerry MQTT credentials from {p}")
                            return creds
            except Exception as e:
                logger.warning(f"Error parsing {p}: {e}")

    return creds

def send_wol(mac_address: str) -> bool:
    """Send Wake-On-LAN magic packet."""
    try:
        clean_mac = mac_address.replace(":", "").replace("-", "").replace(".", "").strip()
        if len(clean_mac) != 12:
            return False
        payload = bytes.fromhex("FF" * 6 + clean_mac * 16)
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
            s.sendto(payload, ("255.255.255.255", 9))
            s.sendto(payload, ("255.255.255.255", 7))
        logger.info(f"Wake-On-LAN magic packet sent to {mac_address}")
        return True
    except Exception as e:
        logger.error(f"Failed to send WOL: {e}")
        return False

def probe_tv_port(host: str, port: int = 55000, timeout: float = 1.0) -> bool:
    if not host:
        return False
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((host, port))
            return True
    except Exception:
        return False

def safe_get_volume(rc) -> Optional[int]:
    """Safely get volume level with fallback method names."""
    for fn in ["get_volume", "getVolume"]:
        if hasattr(rc, fn):
            try:
                v = getattr(rc, fn)()
                if v is not None:
                    return int(v)
            except Exception as e:
                logger.debug(f"{fn} call failed: {e}")
    return None

def safe_set_volume(rc, vol: int) -> bool:
    """Safely set volume level."""
    for fn in ["set_volume", "setVolume"]:
        if hasattr(rc, fn):
            try:
                getattr(rc, fn)(vol)
                return True
            except Exception as e:
                logger.warning(f"{fn} call failed: {e}")
    return False

def safe_get_mute(rc) -> Optional[bool]:
    """Safely get mute status with fallback method names (get_mute, getMute, get_muted)."""
    for fn in ["get_mute", "getMute", "get_muted", "getMuted"]:
        if hasattr(rc, fn):
            try:
                m = getattr(rc, fn)()
                if m is not None:
                    return bool(m)
            except Exception as e:
                logger.debug(f"{fn} call failed: {e}")
    return None

def safe_set_mute(rc, enable: bool) -> bool:
    """Safely set mute status."""
    for fn in ["set_mute", "setMute", "set_muted", "setMuted"]:
        if hasattr(rc, fn):
            try:
                getattr(rc, fn)(enable)
                return True
            except Exception as e:
                logger.warning(f"{fn} call failed: {e}")
    return False


class PanasonicDaemon:
    def __init__(self):
        self.config = load_config()
        self.tv_cfg = self.config.get("tv", {})
        self.mqtt_cfg = self.config.get("mqtt", {})
        self.poll_cfg = self.config.get("polling", {})
        self.macros = self.config.get("macros", {})
        self.sys_cfg = self.config.get("system", {})

        update_logger_level(self.sys_cfg.get("loglevel", 6))

        self.host = self.tv_cfg.get("host", "")
        self.port = self.tv_cfg.get("port", 55000)
        self.mac = self.tv_cfg.get("mac", "")
        self.app_id = self.tv_cfg.get("app_id", "")
        self.enc_key = self.tv_cfg.get("encryption_key", "")
        self.topic_prefix = self.mqtt_cfg.get("topic_prefix", "panasonictv").rstrip("/")

        self.interval_active = self.poll_cfg.get("interval_active", 5)
        self.interval_standby = self.poll_cfg.get("interval_standby", 30)

        self.last_state = {
            "online": None,
            "power": None,
            "volume": None,
            "mute": None
        }

        self.rc = None
        self.mqtt_client: Optional[mqtt.Client] = None
        
        # Only initialize remote if TV port responds right now
        if self.host and probe_tv_port(self.host, self.port, timeout=0.8):
            self._init_remote()

    def _init_remote(self):
        global panasonic_viera
        if not panasonic_viera:
            try:
                import panasonic_viera
            except ImportError:
                return

        if self.host:
            try:
                if self.app_id and self.enc_key:
                    self.rc = panasonic_viera.RemoteControl(
                        host=self.host,
                        port=self.port,
                        app_id=self.app_id,
                        encryption_key=self.enc_key
                    )
                else:
                    self.rc = panasonic_viera.RemoteControl(
                        host=self.host,
                        port=self.port
                    )
                logger.info(f"Connected RemoteControl to TV at {self.host}:{self.port}")
            except Exception as e:
                logger.debug(f"TV connection initialization note: {e}")
                self.rc = None

    def reload_config(self):
        logger.info("Reloading configuration...")
        self.config = load_config()
        self.tv_cfg = self.config.get("tv", {})
        self.mqtt_cfg = self.config.get("mqtt", {})
        self.poll_cfg = self.config.get("polling", {})
        self.macros = self.config.get("macros", {})
        self.sys_cfg = self.config.get("system", {})

        update_logger_level(self.sys_cfg.get("loglevel", 6))

        self.host = self.tv_cfg.get("host", "")
        self.port = self.tv_cfg.get("port", 55000)
        self.mac = self.tv_cfg.get("mac", "")
        self.app_id = self.tv_cfg.get("app_id", "")
        self.enc_key = self.tv_cfg.get("encryption_key", "")
        self.topic_prefix = self.mqtt_cfg.get("topic_prefix", "panasonictv").rstrip("/")
        self.interval_active = self.poll_cfg.get("interval_active", 5)
        self.interval_standby = self.poll_cfg.get("interval_standby", 30)
        self.rc = None

    def setup_mqtt(self):
        client_id = f"loxberry_panasonictv_{os.getpid()}"
        try:
            self.mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id)
        except AttributeError:
            self.mqtt_client = mqtt.Client(client_id)

        self.mqtt_client.on_connect = self.on_mqtt_connect
        self.mqtt_client.on_message = self.on_mqtt_message
        self.mqtt_client.on_disconnect = self.on_mqtt_disconnect

        lwt_topic = f"{self.topic_prefix}/status/daemon"
        self.mqtt_client.will_set(lwt_topic, payload="offline", qos=1, retain=True)

        if self.mqtt_cfg.get("use_loxberry_gateway", True):
            creds = get_mqtt_gateway_credentials()
            host = creds["brokerhost"]
            port = creds["brokerport"]
            user = creds["brokeruser"]
            password = creds["brokerpass"]
        else:
            host = self.mqtt_cfg.get("broker_host", "localhost")
            port = int(self.mqtt_cfg.get("broker_port", 1883))
            user = self.mqtt_cfg.get("broker_user", "")
            password = self.mqtt_cfg.get("broker_pass", "")

        if user:
            logger.info(f"Configuring MQTT authentication for user '{user}'")
            self.mqtt_client.username_pw_set(user, password)
        else:
            logger.warning("No MQTT broker username found. Attempting anonymous connection...")

        logger.info(f"Connecting to MQTT Broker {host}:{port}...")
        try:
            self.mqtt_client.connect(host, port, keepalive=60)
            self.mqtt_client.loop_start()
        except Exception as e:
            logger.error(f"Failed to connect to MQTT broker: {e}")

    def on_mqtt_connect(self, client, userdata, flags, rc, *args, **kwargs):
        # In paho-mqtt v2, rc is a ReasonCode object (rc == 0 or rc.is_failure == False)
        is_failure = False
        if hasattr(rc, "is_failure"):
            is_failure = rc.is_failure
        elif isinstance(rc, int):
            is_failure = (rc != 0)

        if is_failure:
            logger.error(f"MQTT connection refused by broker: {rc}")
            return

        logger.info("Connected to MQTT broker successfully.")
        client.publish(f"{self.topic_prefix}/status/daemon", "online", qos=1, retain=True)

        sub_topics = [
            f"{self.topic_prefix}/set/#",
            f"{self.topic_prefix}/command/#"
        ]
        for t in sub_topics:
            client.subscribe(t, qos=0)
            logger.info(f"Subscribed to topic: {t}")

    def on_mqtt_disconnect(self, client, userdata, *args, **kwargs):
        reason = "unknown"
        if len(args) >= 2:
            reason = f"flags={args[0]}, code={args[1]}"
        elif len(args) == 1:
            reason = f"rc={args[0]}"
        logger.warning(f"Disconnected from MQTT broker ({reason}). Reconnecting in background...")

    def on_mqtt_message(self, client, userdata, msg, *args, **kwargs):
        topic = msg.topic
        try:
            payload = msg.payload.decode("utf-8").strip()
        except Exception:
            payload = ""

        logger.info(f"MQTT Message received: {topic} -> '{payload}'")

        subpath = topic
        if topic.startswith(self.topic_prefix + "/"):
            subpath = topic[len(self.topic_prefix) + 1:]

        parts = subpath.split("/")
        if len(parts) >= 2 and parts[0] in ["set", "command"]:
            cmd_type = parts[1].lower()
            self.handle_command(cmd_type, payload)

    def handle_command(self, cmd_type: str, payload: str):
        if not self.rc and probe_tv_port(self.host, self.port, timeout=0.8):
            self._init_remote()

        logger.info(f"Executing command '{cmd_type}' with payload '{payload}'")

        if cmd_type == "power":
            p_upper = payload.upper()
            if p_upper in ["ON", "1", "TRUE"]:
                if self.mac:
                    send_wol(self.mac)
                if self.rc:
                    try:
                        self.rc.send_key("NRC_POWER-ONOFF")
                    except Exception:
                        pass
            elif p_upper in ["OFF", "0", "FALSE"]:
                if self.rc:
                    try:
                        self.rc.send_key("NRC_POWER-ONOFF")
                    except Exception as e:
                        logger.error(f"Error turning off TV: {e}")
            elif p_upper in ["TOGGLE"]:
                is_on = probe_tv_port(self.host, self.port, 0.5)
                if is_on and self.rc:
                    self.rc.send_key("NRC_POWER-ONOFF")
                elif not is_on and self.mac:
                    send_wol(self.mac)

        elif cmd_type == "wol":
            if self.mac:
                send_wol(self.mac)
            else:
                logger.warning("WOL requested but no MAC address configured.")

        elif cmd_type == "volume":
            if not self.rc:
                logger.warning("Cannot adjust volume: TV not responding.")
                return
            try:
                if payload.startswith("+") or payload.startswith("-"):
                    cur_vol = safe_get_volume(self.rc) or 0
                    delta = int(payload)
                    new_vol = max(0, min(100, cur_vol + delta))
                    safe_set_volume(self.rc, new_vol)
                elif payload.upper() == "UP":
                    self.rc.send_key("NRC_VOLUP-ONOFF")
                elif payload.upper() == "DOWN":
                    self.rc.send_key("NRC_VOLDOWN-ONOFF")
                else:
                    vol = int(payload)
                    safe_set_volume(self.rc, max(0, min(100, vol)))
            except Exception as e:
                logger.error(f"Error adjusting volume: {e}")

        elif cmd_type == "mute":
            if not self.rc:
                logger.warning("Cannot set mute: TV not responding.")
                return
            try:
                p_lower = payload.lower()
                if p_lower == "toggle":
                    cur_mute = safe_get_mute(self.rc)
                    safe_set_mute(self.rc, not bool(cur_mute))
                elif p_lower in ["true", "1", "on"]:
                    safe_set_mute(self.rc, True)
                elif p_lower in ["false", "0", "off"]:
                    safe_set_mute(self.rc, False)
            except Exception as e:
                logger.error(f"Error setting mute: {e}")

        elif cmd_type == "key":
            if not self.rc:
                logger.warning(f"Cannot send key '{payload}': TV not responding.")
                return
            try:
                k = payload.strip().upper()
                if not k.startswith("NRC_"):
                    aliases = {
                        "POWER": "NRC_POWER-ONOFF",
                        "MUTE": "NRC_MUTE-ONOFF",
                        "VOLUP": "NRC_VOLUP-ONOFF",
                        "VOLDOWN": "NRC_VOLDOWN-ONOFF",
                        "UP": "NRC_UP-ONOFF",
                        "DOWN": "NRC_DOWN-ONOFF",
                        "LEFT": "NRC_LEFT-ONOFF",
                        "RIGHT": "NRC_RIGHT-ONOFF",
                        "ENTER": "NRC_ENTER-ONOFF",
                        "RETURN": "NRC_RETURN-ONOFF",
                        "BACK": "NRC_RETURN-ONOFF",
                        "HOME": "NRC_HOME-ONOFF",
                        "MENU": "NRC_MENU-ONOFF",
                        "EXIT": "NRC_CANCEL-ONOFF",
                        "CANCEL": "NRC_CANCEL-ONOFF",
                        "VIERA_LINK": "NRC_VIERA_LINK-ONOFF",
                        "TOOLS": "NRC_VTOOLS-ONOFF",
                        "GUIDE": "NRC_EPG-ONOFF",
                        "HDMI1": "NRC_HDMI1-ONOFF",
                        "HDMI2": "NRC_HDMI2-ONOFF",
                        "HDMI3": "NRC_HDMI3-ONOFF",
                        "HDMI4": "NRC_HDMI4-ONOFF",
                    }
                    k = aliases.get(k, f"NRC_{k}-ONOFF")
                self.rc.send_key(k)
                logger.info(f"Sent remote key {k}")
            except Exception as e:
                logger.error(f"Error sending key: {e}")

        elif cmd_type in ["audio_output", "macro"]:
            if not self.rc:
                logger.warning(f"Cannot execute macro '{payload}': TV not responding.")
                return

            target_macro = payload.lower().strip()
            if target_macro in ["heimkino", "home_theater", "receiver"]:
                macro_name = "home_theater"
            elif target_macro in ["tv", "fernseher", "tv_speakers", "speakers"]:
                macro_name = "tv_speakers"
            else:
                macro_name = target_macro

            steps = self.macros.get(macro_name)
            if not steps:
                logger.warning(f"Macro '{macro_name}' not defined in config.")
                return

            logger.info(f"Executing audio macro '{macro_name}' ({len(steps)} steps)")
            for step in steps:
                step_str = step.strip()
                if step_str.upper().startswith("SLEEP:"):
                    try:
                        ms = int(step_str.split(":", 1)[1])
                        time.sleep(ms / 1000.0)
                    except ValueError:
                        time.sleep(0.5)
                else:
                    try:
                        self.rc.send_key(step_str)
                    except Exception as e:
                        logger.error(f"Error sending step key {step_str}: {e}")
                    time.sleep(0.15)

        # Immediate state update after handling command
        time.sleep(0.3)
        self.poll_tv_state()

    def poll_tv_state(self):
        if not self.host or not self.mqtt_client:
            return

        is_online = probe_tv_port(self.host, self.port, timeout=1.0)
        current_state = {
            "online": is_online,
            "power": "ON" if is_online else "OFF",
            "volume": self.last_state.get("volume") if self.last_state.get("volume") is not None else 0,
            "mute": self.last_state.get("mute") if self.last_state.get("mute") is not None else False
        }

        if is_online:
            if not self.rc:
                self._init_remote()
            if self.rc:
                vol = safe_get_volume(self.rc)
                if vol is not None:
                    current_state["volume"] = vol
                
                m = safe_get_mute(self.rc)
                if m is not None:
                    current_state["mute"] = m
        else:
            # When TV goes offline / standby, reset RC instance
            if self.rc:
                self.rc = None

        has_changed = (
            current_state["online"] != self.last_state["online"] or
            current_state["power"] != self.last_state["power"] or
            current_state["volume"] != self.last_state["volume"] or
            current_state["mute"] != self.last_state["mute"]
        )

        if has_changed:
            self.last_state = current_state.copy()
            logger.info(f"TV State change: Online={current_state['online']} Power={current_state['power']} Vol={current_state['volume']} Mute={current_state['mute']}")

            prefix = self.topic_prefix
            self.mqtt_client.publish(f"{prefix}/status/online", "true" if is_online else "false", qos=0, retain=True)
            self.mqtt_client.publish(f"{prefix}/status/power", current_state["power"], qos=0, retain=True)
            self.mqtt_client.publish(f"{prefix}/status/volume", str(current_state["volume"]), qos=0, retain=True)
            self.mqtt_client.publish(f"{prefix}/status/mute", "true" if current_state["mute"] else "false", qos=0, retain=True)
            self.mqtt_client.publish(f"{prefix}/status/json", json.dumps(current_state), qos=0, retain=True)

    def run(self):
        self.setup_mqtt()
        logger.info(f"Panasonic TV Daemon started for {self.host}:{self.port}. Entering main loop...")
        last_poll = 0.0

        while RUNNING:
            now = time.time()
            interval = self.interval_active if self.last_state.get("online") else self.interval_standby
            if now - last_poll >= interval:
                self.poll_tv_state()
                last_poll = now

            time.sleep(0.5)

        logger.info("Exiting daemon loop. Shutting down MQTT client...")
        if self.mqtt_client:
            try:
                self.mqtt_client.publish(f"{self.topic_prefix}/status/daemon", "offline", qos=1, retain=True)
                self.mqtt_client.loop_stop()
                self.mqtt_client.disconnect()
            except Exception:
                pass

def main():
    daemon = PanasonicDaemon()
    daemon.run()

if __name__ == "__main__":
    main()
