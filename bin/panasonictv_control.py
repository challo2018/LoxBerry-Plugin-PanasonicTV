#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Panasonic TV Control CLI & Helper Utility for LoxBerry.
Provides connection testing, key sending, macro execution, WOL, PIN pairing, and SSDP UPnP Discovery.
"""

import sys
import os
import re
import json
import time
import socket
import argparse
import logging
from logging.handlers import RotatingFileHandler
import subprocess
import urllib.request
from typing import Optional, Dict, Any, List

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
        except Exception:
            pass
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

# Setup logger
logger = logging.getLogger("panasonictv_control")
logger.setLevel(logging.INFO)

log_cfg = load_config().get("system", {})
log_lvl_val = log_cfg.get("loglevel", 6)
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
logger.setLevel(level_map.get(log_lvl_val, logging.INFO))

# Add rotating file handler
try:
    log_path = get_log_file()
    fh = RotatingFileHandler(log_path, maxBytes=3*1024*1024, backupCount=2, encoding="utf-8")
    fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s"))
    logger.addHandler(fh)
except Exception:
    pass

# Stderr handler for CLI visibility
sh = logging.StreamHandler(sys.stderr)
sh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s"))
logger.addHandler(sh)


def get_mac_from_arp(ip: str) -> str:
    """Read MAC address for an IP from Linux ARP table /proc/net/arp or ip neigh."""
    if os.path.exists("/proc/net/arp"):
        try:
            with open("/proc/net/arp", "r") as f:
                for line in f:
                    parts = line.split()
                    if len(parts) >= 4 and parts[0] == ip:
                        mac = parts[3].strip()
                        if mac and mac != "00:00:00:00:00:00":
                            return mac.upper()
        except Exception:
            pass
    try:
        out = subprocess.check_output(["ip", "neigh", "show", ip], stderr=subprocess.DEVNULL, timeout=1.0).decode()
        m = re.search(r'([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})', out)
        if m:
            return m.group(1).upper()
    except Exception:
        pass
    try:
        out = subprocess.check_output(["arp", "-an", ip], stderr=subprocess.DEVNULL, timeout=1.0).decode()
        m = re.search(r'([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})', out)
        if m:
            return m.group(1).upper()
    except Exception:
        pass
    return ""

def discover_panasonic_tvs(timeout: float = 2.5) -> List[Dict[str, Any]]:
    """Discover Panasonic TVs on the local network via SSDP multicast and port probing."""
    ssdp_addr = "239.255.255.250"
    ssdp_port = 1900

    targets = [
        "urn:panasonic-com:device:p00Container:1",
        "urn:schemas-upnp-org:device:MediaRenderer:1",
        "ssdp:all"
    ]

    devices = []
    seen_ips = set()

    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 2)
        sock.settimeout(timeout)

        for target in targets:
            msg = (
                f"M-SEARCH * HTTP/1.1\r\n"
                f"HOST: {ssdp_addr}:{ssdp_port}\r\n"
                f'MAN: "ssdp:discover"\r\n'
                f"MX: 2\r\n"
                f"ST: {target}\r\n"
                f"\r\n"
            ).encode("utf-8")
            try:
                sock.sendto(msg, (ssdp_addr, ssdp_port))
            except Exception:
                pass

        start_time = time.time()
        while time.time() - start_time < timeout:
            try:
                data, addr = sock.recvfrom(4096)
                ip = addr[0]
                if ip in seen_ips:
                    continue

                resp = data.decode("utf-8", errors="ignore")
                is_panasonic = "panasonic" in resp.lower() or ":55000" in resp

                dev = {
                    "ip": ip,
                    "port": 55000,
                    "name": "Panasonic TV",
                    "model": "VIERA",
                    "model_number": "",
                    "model_full": "",
                    "mac": ""
                }

                loc_match = re.search(r'LOCATION:\s*(\S+)', resp, re.IGNORECASE)
                if loc_match:
                    loc_url = loc_match.group(1)
                    try:
                        req = urllib.request.Request(loc_url, headers={"User-Agent": "LoxBerry"})
                        with urllib.request.urlopen(req, timeout=1.5) as r:
                            xml_str = r.read().decode("utf-8", errors="ignore")
                            if "panasonic" in xml_str.lower() or is_panasonic:
                                is_panasonic = True
                                fn_match = re.search(r'<friendlyName>(.*?)</friendlyName>', xml_str, re.IGNORECASE)
                                if fn_match:
                                    dev["name"] = fn_match.group(1).strip()
                                mn_match = re.search(r'<modelName>(.*?)</modelName>', xml_str, re.IGNORECASE)
                                if mn_match:
                                    dev["model"] = mn_match.group(1).strip()
                                mnum_match = re.search(r'<modelNumber>(.*?)</modelNumber>', xml_str, re.IGNORECASE)
                                if mnum_match:
                                    dev["model_number"] = mnum_match.group(1).strip()
                                mac_match = re.search(r'([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})', xml_str)
                                if mac_match:
                                    dev["mac"] = mac_match.group(1).upper()
                    except Exception:
                        pass

                if not is_panasonic:
                    # Check if port 55000 is open
                    try:
                        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe_s:
                            probe_s.settimeout(0.4)
                            if probe_s.connect_ex((ip, 55000)) == 0:
                                is_panasonic = True
                    except Exception:
                        pass

                if is_panasonic:
                    seen_ips.add(ip)
                    if not dev["mac"]:
                        dev["mac"] = get_mac_from_arp(ip)

                    # Compute most accurate model string
                    if dev.get("model_number"):
                        if dev.get("model") and dev["model"].lower() not in dev["model_number"].lower():
                            dev["model_full"] = f"{dev['model']} {dev['model_number']}".strip()
                        else:
                            dev["model_full"] = dev["model_number"]
                    elif dev.get("name") and "viera" in dev.get("name", "").lower():
                        dev["model_full"] = dev["name"]
                    else:
                        dev["model_full"] = dev.get("model") or dev.get("name") or "Panasonic TV"

                    devices.append(dev)

            except socket.timeout:
                break
            except Exception:
                continue

        sock.close()
    except Exception as e:
        logger.error(f"Error during SSDP discovery: {e}")

    return devices

def send_wol(mac_address: str) -> bool:
    """Send Wake-On-LAN magic packet."""
    try:
        clean_mac = mac_address.replace(":", "").replace("-", "").replace(".", "").strip()
        if len(clean_mac) != 12:
            raise ValueError(f"Invalid MAC address format: '{mac_address}'")
        payload = bytes.fromhex("FF" * 6 + clean_mac * 16)
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
            s.sendto(payload, ("255.255.255.255", 9))
            s.sendto(payload, ("255.255.255.255", 7))
        logger.info(f"Wake-on-LAN magic packet sent to {mac_address}")
        return True
    except Exception as e:
        logger.error(f"Error sending WOL: {e}")
        return False

def get_remote_control(host: str, port: int = 55000, app_id: str = "", enc_key: str = ""):
    """Instantiate RemoteControl from panasonic_viera library."""
    try:
        import panasonic_viera
    except ImportError:
        logger.error("panasonic_viera library not found. Run pip3 install panasonic-viera")
        raise

    if app_id and enc_key:
        return panasonic_viera.RemoteControl(host=host, port=port, app_id=app_id, encryption_key=enc_key)
    else:
        return panasonic_viera.RemoteControl(host=host, port=port)

def probe_tv(host: str, port: int = 55000, timeout: float = 1.2) -> bool:
    """Quick socket check to see if the TV control port is open and listening."""
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

def send_key(rc, key: str) -> bool:
    """Send remote key to TV."""
    try:
        key_upper = key.strip().upper()
        if not key_upper.startswith("NRC_"):
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
                "RED": "NRC_RED-ONOFF",
                "GREEN": "NRC_GREEN-ONOFF",
                "YELLOW": "NRC_YELLOW-ONOFF",
                "BLUE": "NRC_BLUE-ONOFF",
                "HDMI1": "NRC_HDMI1-ONOFF",
                "HDMI2": "NRC_HDMI2-ONOFF",
                "HDMI3": "NRC_HDMI3-ONOFF",
                "HDMI4": "NRC_HDMI4-ONOFF",
            }
            key_name = aliases.get(key_upper, f"NRC_{key_upper}-ONOFF")
        else:
            key_name = key_upper

        rc.send_key(key_name)
        logger.info(f"Sent key: {key_name}")
        return True
    except Exception as e:
        logger.error(f"Failed to send key {key}: {e}")
        return False

def execute_macro(rc, steps: List[str]) -> bool:
    """Execute a sequence of keys with delays (e.g., SLEEP:500)."""
    try:
        for step in steps:
            step = step.strip()
            if step.upper().startswith("SLEEP:"):
                try:
                    ms = int(step.split(":", 1)[1])
                    time.sleep(ms / 1000.0)
                except ValueError:
                    time.sleep(0.5)
            else:
                send_key(rc, step)
                time.sleep(0.15)
        return True
    except Exception as e:
        logger.error(f"Failed to execute macro: {e}")
        return False

def main():
    parser = argparse.ArgumentParser(description="Panasonic TV Control Utility")
    parser.add_argument("--host", help="TV Hostname or IP")
    parser.add_argument("--port", type=int, default=55000, help="TV Port (Default 55000)")
    parser.add_argument("--mac", help="TV MAC address for WOL")
    parser.add_argument("--app-id", default="", help="Pairing Application ID")
    parser.add_argument("--enc-key", default="", help="Pairing Encryption Key")
    
    subparsers = parser.add_subparsers(dest="action", help="Action to perform")
    
    subparsers.add_parser("discover", help="Discover Panasonic TVs on local network via SSDP")
    subparsers.add_parser("probe", help="Check if TV network control port is active")
    subparsers.add_parser("wol", help="Send Wake-on-LAN magic packet")
    subparsers.add_parser("status", help="Get current status (power, volume, mute)")
    
    key_parser = subparsers.add_parser("key", help="Send a remote key")
    key_parser.add_argument("keyname", help="Name or NRC code of the key (e.g. MUTE, NRC_POWER-ONOFF)")
    
    vol_parser = subparsers.add_parser("volume", help="Set volume level (0-100)")
    vol_parser.add_argument("level", type=int, help="Volume level (0-100)")

    mute_parser = subparsers.add_parser("mute", help="Set mute state (true/false)")
    mute_parser.add_argument("state", choices=["true", "false", "toggle"], help="Mute state")
    
    macro_parser = subparsers.add_parser("macro", help="Execute named macro (e.g. home_theater, tv_speakers)")
    macro_parser.add_argument("macroname", help="Name of macro from config")

    pin_req_parser = subparsers.add_parser("pin_request", help="Request PIN code to show on TV screen")
    pin_auth_parser = subparsers.add_parser("pin_authorize", help="Authorize entered PIN code")
    pin_auth_parser.add_argument("pin", help="PIN code displayed on TV")

    args = parser.parse_args()

    cfg = load_config()
    tv_cfg = cfg.get("tv", {})
    host = args.host or tv_cfg.get("host")
    port = args.port or tv_cfg.get("port", 55000)
    mac = args.mac or tv_cfg.get("mac", "")
    app_id = args.app_id or tv_cfg.get("app_id", "")
    enc_key = args.enc_key or tv_cfg.get("encryption_key", "")

    result: Dict[str, Any] = {"success": False}

    if args.action == "discover":
        devices = discover_panasonic_tvs(timeout=2.5)
        result = {"success": True, "devices": devices}
        print(json.dumps(result))
        sys.exit(0)

    if args.action == "wol":
        if not mac:
            result = {"success": False, "error": "No MAC address provided"}
        else:
            ok = send_wol(mac)
            result = {"success": ok, "action": "wol", "mac": mac}
        print(json.dumps(result))
        sys.exit(0 if result["success"] else 1)

    if not host:
        result = {"success": False, "error": "No host/IP specified in arguments or configuration"}
        print(json.dumps(result))
        sys.exit(1)

    if args.action == "probe":
        is_alive = probe_tv(host, port)
        result = {"success": True, "online": is_alive, "host": host, "port": port}
        print(json.dumps(result))
        sys.exit(0)

    try:
        rc = get_remote_control(host, port, app_id, enc_key)
    except Exception as e:
        result = {"success": False, "error": f"Failed to initialize remote control: {e}"}
        print(json.dumps(result))
        sys.exit(1)

    if args.action == "pin_request":
        try:
            rc.request_pin_code()
            result = {"success": True, "message": "PIN request sent to TV. Enter PIN shown on screen."}
        except Exception as e:
            result = {"success": False, "error": f"PIN request failed: {e}"}

    elif args.action == "pin_authorize":
        try:
            rc.authorize_pin_code(args.pin.strip())
            result = {
                "success": True,
                "app_id": rc.app_id,
                "encryption_key": rc.enc_key,
                "message": "Authorization successful! Save these credentials."
            }
        except Exception as e:
            result = {"success": False, "error": f"PIN authorization failed: {e}"}

    elif args.action == "status":
        is_alive = probe_tv(host, port, timeout=1.2)
        if not is_alive:
            result = {
                "success": True,
                "online": False,
                "power": "OFF",
                "volume": 0,
                "mute": False
            }
        else:
            vol = None
            mute = None
            vol_err = None
            mute_err = None
            try:
                vol = safe_get_volume(rc)
            except Exception as e:
                vol_err = str(e)
                logger.debug(f"Volume query error: {e}")

            try:
                mute = safe_get_mute(rc)
            except Exception as e:
                mute_err = str(e)
                logger.debug(f"Mute query error: {e}")

            result = {
                "success": True,
                "online": True,
                "power": "ON",
                "volume": vol if vol is not None else 0,
                "mute": mute if mute is not None else False
            }
            if vol_err or mute_err:
                result["details"] = {
                    "volume_error": vol_err,
                    "mute_error": mute_err
                }

    elif args.action == "key":
        ok = send_key(rc, args.keyname)
        result = {"success": ok, "key": args.keyname}

    elif args.action == "volume":
        try:
            vol = max(0, min(100, args.level))
            ok = safe_set_volume(rc, vol)
            result = {"success": ok, "volume": vol}
        except Exception as e:
            result = {"success": False, "error": str(e)}

    elif args.action == "mute":
        try:
            if args.state == "toggle":
                cur_mute = safe_get_mute(rc)
                target_mute = not bool(cur_mute)
            else:
                target_mute = (args.state == "true")
            ok = safe_set_mute(rc, target_mute)
            result = {"success": ok, "mute": target_mute}
        except Exception as e:
            result = {"success": False, "error": str(e)}

    elif args.action == "macro":
        macros = cfg.get("macros", {})
        steps = macros.get(args.macroname)
        if not steps:
            result = {"success": False, "error": f"Macro '{args.macroname}' not found in configuration"}
        else:
            ok = execute_macro(rc, steps)
            result = {"success": ok, "macro": args.macroname}

    else:
        result = {"success": False, "error": f"Unknown action '{args.action}'"}

    # Print output as a single clean line of JSON to stdout
    print(json.dumps(result))
    sys.exit(0 if result["success"] else 1)

if __name__ == "__main__":
    main()
