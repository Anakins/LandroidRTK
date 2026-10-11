#!/usr/bin/env python3
"""
worx_map.py — Données de carte RTK pour le plugin Jeedom LandroidRTK.

Regroupe :
  - la lecture de la position / du cap / de l'identifiant de carte d'un
    robot (à partir des attributs de pyworxcloud) ;
  - la récupération de la géométrie de la carte RTK auprès du cloud Worx
    (point d'accès privé /api/v2/maps/<id>, non documenté officiellement :
    toute la logique est donc défensive et ne lève jamais d'exception) ;
  - l'anonymisation des données pour l'export de diagnostic.

CONFIDENTIALITÉ : les coordonnées RTK sont précises au centimètre. Rien
dans ce module n'écrit de coordonnée dans un journal (logs).
"""

import json
import math

try:  # API interne de pyworxcloud (présente en 6.4.x), avec repli aiohttp
    from pyworxcloud.utils.requests import AGET, HEADERS
except Exception:  # pragma: no cover - dépend de la version installée
    AGET = None
    HEADERS = None


def _finite(value):
    if isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) else None


def rssi_value(device):
    """Force du signal WiFi en dBm (valeur négative), ou None si inconnue."""
    value = _finite(getattr(device, "rssi", None))
    if value is None:
        return None
    value = int(round(value))
    # Valeurs plausibles uniquement : 0 ou positif = "pas de mesure".
    return value if -127 <= value < 0 else None


def rtk_map_id(device):
    """Identifiant de la carte RTK, tel que rapporté dans la config du robot."""
    cfg = getattr(device, "raw_cfg", None)
    if not isinstance(cfg, dict):
        return None
    rtk = cfg.get("rtk")
    if not isinstance(rtk, dict):
        return None
    map_id = rtk.get("map")
    return str(map_id) if map_id not in (None, "") else None


def rtk_position(device):
    """Position [latitude, longitude] rapportée par le robot, ou None."""
    dat = getattr(device, "raw_dat", None)
    if not isinstance(dat, dict):
        return None
    rtk = dat.get("rtk")
    if not isinstance(rtk, dict):
        return None
    pos = rtk.get("pos")
    if not isinstance(pos, (list, tuple)) or len(pos) < 2:
        return None
    lat, lon = _finite(pos[0]), _finite(pos[1])
    if lat is None or lon is None:
        return None
    if not (-90 <= lat <= 90 and -180 <= lon <= 180) or (lat == 0 and lon == 0):
        return None
    return [lat, lon]


def rtk_heading(device):
    """Cap en degrés, dans le sens horaire depuis le nord, ou None.

    Le robot rapporte un lacet (yaw) dans le sens trigonométrique depuis
    l'est ; même conversion que l'intégration Home Assistant de référence.
    """
    orientation = getattr(device, "orientation", None)
    if not isinstance(orientation, dict):
        return None
    yaw = _finite(orientation.get("yaw"))
    if yaw is None or abs(yaw) > 360:
        return None
    return round((90.0 - yaw) % 360.0, 1)


async def fetch_rtk_map(cloud, map_id):
    """Télécharge la géométrie de la carte RTK. Retourne un dict ou None."""
    if not map_id:
        return None
    try:
        api = getattr(cloud, "_api", None)
        if api is None:
            return None
        await api.check_token()
        endpoint = getattr(getattr(api, "cloud", None), "ENDPOINT", None)
        if not endpoint:
            return None
        url = f"https://{endpoint}/api/v2/maps/{map_id}"
        session = await api._ensure_session()
        if AGET is not None and HEADERS is not None:
            data = await AGET(url, HEADERS(api.access_token), session=session)
        else:
            headers = {"Accept": "application/json",
                       "Authorization": f"Bearer {api.access_token}"}
            async with session.get(url, headers=headers) as resp:
                if resp.status >= 400:
                    return None
                data = await resp.json()
    except Exception:
        return None
    return data if isinstance(data, dict) else None


# ---------------------------------------------------------------------
# Anonymisation (export de diagnostic)
# ---------------------------------------------------------------------

# Clés dont la valeur identifie le propriétaire ou le matériel.
_SENSITIVE_KEYS = {
    "sn", "serial", "serial_number", "mac", "mac_address", "uuid",
    "email", "name", "address", "id_user", "user_id", "owner", "phone",
    "ssid", "bssid", "imei", "iccid", "token", "access_token", "password",
}

# Décalage appliqué aux coordonnées : la géométrie (distances, formes) est
# conservée, la localisation réelle disparaît.
FAKE_ORIGIN = (45.0, 5.0)


def _is_latlon_pair(value):
    return (
        isinstance(value, (list, tuple))
        and len(value) >= 2
        and all(_finite(v) is not None for v in value[:2])
        and -90 <= float(value[0]) <= 90
        and -180 <= float(value[1]) <= 180
    )


def find_origin(obj):
    """Premier couple latitude/longitude plausible rencontré (origine du décalage)."""
    stack = [obj]
    while stack:
        item = stack.pop(0)
        if isinstance(item, dict):
            lat = _finite(item.get("latitude"))
            lon = _finite(item.get("longitude"))
            if lat is not None and lon is not None:
                return (lat, lon)
            pos = item.get("pos")
            if _is_latlon_pair(pos):
                return (float(pos[0]), float(pos[1]))
            if _is_latlon_pair(item.get("coo")):
                return (float(item["coo"][0]), float(item["coo"][1]))
            stack.extend(item.values())
        elif isinstance(item, (list, tuple)):
            if item and all(_is_latlon_pair(p) for p in item):
                return (float(item[0][0]), float(item[0][1]))
            stack.extend(item)
    return None


def anonymize(obj, origin):
    """Copie profonde de `obj` avec coordonnées décalées et identifiants masqués."""
    lat0, lon0 = origin if origin else (0.0, 0.0)
    # Un degré de longitude ne vaut pas la même distance à toutes les
    # latitudes : on compense pour que les distances en mètres restent
    # exactes une fois la carte déplacée à la latitude fictive.
    k = (math.cos(math.radians(lat0)) / math.cos(math.radians(FAKE_ORIGIN[0]))) if origin else 1.0

    def shift_lat(lat):
        return round(float(lat) - lat0 + FAKE_ORIGIN[0], 7)

    def shift_lon(lon):
        return round((float(lon) - lon0) * k + FAKE_ORIGIN[1], 7)

    def shift(pair):
        return [shift_lat(pair[0]), shift_lon(pair[1])] + list(pair[2:])

    def walk(item, key=None):
        if isinstance(item, dict):
            out = {}
            lat = _finite(item.get("latitude"))
            lon = _finite(item.get("longitude"))
            for key_, v in item.items():
                if isinstance(key_, str) and key_.lower() in _SENSITIVE_KEYS:
                    out[key_] = "***" if v not in (None, "") else v
                elif key_ == "latitude" and lat is not None and lon is not None:
                    out[key_] = shift_lat(lat)
                elif key_ == "longitude" and lat is not None and lon is not None:
                    out[key_] = shift_lon(lon)
                else:
                    out[key_] = walk(v, key_)
            return out
        if isinstance(item, (list, tuple)):
            if _is_latlon_pair(item) and key in ("pos", "coo", "points", None, "gps"):
                return shift(item)
            return [walk(v, key) for v in item]
        return item

    return walk(obj)


def _jsonable(value):
    return json.loads(json.dumps(value, default=str))
