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


MAP_API_VERSION_HEADER = {"X-Api-Version": "2026-06-09"}


def _finite(value):
    if isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) else None


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


async def fetch_rtk_map_status(cloud, map_id):
    """Télécharge la géométrie de la carte RTK.

    Retourne (donnees, raison) : `donnees` est un dict, ou None avec une
    `raison` lisible (jamais de coordonnée ni de jeton) pour l'afficher dans
    l'onglet Carte. Ne lève jamais d'exception.
    """
    if not map_id:
        return None, "identifiant de carte absent"
    try:
        api = getattr(cloud, "_api", None)
        if api is None:
            return None, "interface interne de pyworxcloud introuvable (cloud._api)"
        await api.check_token()
        endpoint = getattr(getattr(api, "cloud", None), "ENDPOINT", None)
        if not endpoint:
            return None, "adresse du service Worx introuvable (cloud.ENDPOINT)"
        url = f"https://{endpoint}/api/v2/maps/{map_id}"
        session = await api._ensure_session()
        data = None
        # Avec l'en-tête de version, Worx ajoute les zones sans visibilité (zones bleu-vert de
        # l'application). Si cette requête échoue, on retombe sur la requête sans en-tête.
        for extra in (MAP_API_VERSION_HEADER, {}):
            try:
                if AGET is not None and HEADERS is not None:
                    headers = dict(HEADERS(api.access_token))
                    headers.update(extra)
                    data = await AGET(url, headers, session=session)
                else:
                    headers = {"Accept": "application/json",
                               "Authorization": f"Bearer {api.access_token}"}
                    headers.update(extra)
                    async with session.get(url, headers=headers) as resp:
                        if resp.status >= 400:
                            if not extra:
                                return None, f"le service Worx a répondu HTTP {resp.status}"
                            continue
                        data = await resp.json()
            except Exception as exc:
                if not extra:
                    return None, f"requête refusée ou échouée ({type(exc).__name__})"
                continue
            if isinstance(data, dict) and data:
                break
    except Exception as exc:
        return None, f"requête refusée ou échouée ({type(exc).__name__})"
    if not isinstance(data, dict):
        return None, f"réponse inattendue du service Worx (type {type(data).__name__})"
    if not data:
        return None, "réponse vide du service Worx"
    return data, None


async def fetch_rtk_map(cloud, map_id):
    """Comme fetch_rtk_map_status, sans la raison. Retourne un dict ou None."""
    data, _reason = await fetch_rtk_map_status(cloud, map_id)
    return data


def coverage_from(now=None):
    """Début de la journée locale, en UTC, au format attendu par l'API
    (« AAAA-MM-JJ HH:MM:SS ») : l'API renvoie les heures en UTC."""
    from datetime import datetime, timezone
    now = now or datetime.now().astimezone()
    midnight = now.replace(hour=0, minute=0, second=0, microsecond=0)
    return midnight.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


async def fetch_coverage_status(cloud, map_id, from_str):
    """Surface déjà tondue depuis `from_str` (polygones calculés par Worx, comme
    la zone vert foncé de l'application). Retourne (donnees, raison) ; ne lève
    jamais d'exception. `donnees` = {"coverage": [polygones], "from", "to"}."""
    if not map_id:
        return None, "identifiant de carte absent"
    try:
        api = getattr(cloud, "_api", None)
        if api is None:
            return None, "interface interne de pyworxcloud introuvable (cloud._api)"
        await api.check_token()
        endpoint = getattr(getattr(api, "cloud", None), "ENDPOINT", None)
        if not endpoint:
            return None, "adresse du service Worx introuvable (cloud.ENDPOINT)"
        from urllib.parse import quote
        url = f"https://{endpoint}/api/v2/maps/{map_id}/coverage?from={quote(from_str)}"
        session = await api._ensure_session()
        if AGET is not None and HEADERS is not None:
            data = await AGET(url, HEADERS(api.access_token), session=session)
        else:
            headers = {"Accept": "application/json",
                       "Authorization": f"Bearer {api.access_token}"}
            async with session.get(url, headers=headers) as resp:
                if resp.status >= 400:
                    return None, f"le service Worx a répondu HTTP {resp.status}"
                data = await resp.json()
    except Exception as exc:
        return None, f"requête refusée ou échouée ({type(exc).__name__})"
    if not isinstance(data, dict) or not isinstance(data.get("coverage"), list):
        return None, "réponse inattendue du service Worx (coverage absent)"
    return data, None


# Formes de coupe de la zone de tonte (champ « cut_type » de la carte), relevées sur un Landroid Vision 4WD :
# 0 Naturel (sans angle), 1 Parallèle, 4 Diamant, 5 Damier.
CUT_TYPES = (0, 1, 4, 5)


def build_cut_metadata(meta, cut_type, angle):
    """Réglages de zone avec la forme et l'angle voulus (seuls ces champs changent).

    Même règle que l'application : Naturel n'a ni angle ni largeur de motif ; la largeur de
    motif (pattern_width) existe pour Parallèle et Damier, pas pour Diamant.
    Retourne (nouveaux réglages, raison d'erreur ou None).
    """
    if cut_type not in CUT_TYPES:
        return None, f"forme inconnue ({cut_type})"
    new = dict(meta or {})
    new["cut_type"] = cut_type
    if cut_type == 0:
        new.pop("cut_direction", None)
        new.pop("pattern_width", None)
        return new, None
    if angle is None:
        return None, "angle manquant pour cette forme"
    try:
        angle = int(angle)
    except (TypeError, ValueError):
        return None, "angle illisible"
    if not 0 <= angle <= 359:
        return None, "angle hors limites (0 à 359)"
    new["cut_direction"] = angle
    if cut_type in (1, 5):
        new.setdefault("pattern_width", 0)
    else:
        new.pop("pattern_width", None)
    return new, None


def mowing_zone(map_data):
    """Zone de tonte (type 2) de la carte, ou None."""
    layers = (map_data or {}).get("layers") or {}
    for b in layers.get("boundaries") or []:
        for z in (b or {}).get("zones") or []:
            if ((z or {}).get("summary") or {}).get("type") == 2:
                return z
    return None


async def set_cut_settings(cloud, map_id, cut_type, angle, wait_s=24):
    """Change la forme et l'angle de coupe de la zone de tonte (lecture, modification d'un seul champ
    à la fois dans les réglages, renvoi, puis relecture pour vérifier).

    Les contours ne sont JAMAIS renvoyés : seule la couche « zone » avec ses réglages. La version de la
    carte ne change pas (constaté), donc la surface tondue du jour est conservée.
    Retourne un dict {"ok": bool, "reason": str|None, "cut_type": int|None, "cut_angle": int|None,
    "changed": bool}. Ne lève jamais d'exception ; aucune coordonnée dans la réponse.
    """
    import asyncio

    out = {"ok": False, "reason": None, "cut_type": None, "cut_angle": None, "changed": False}
    if not map_id:
        out["reason"] = "identifiant de carte absent"
        return out
    try:
        api = getattr(cloud, "_api", None)
        endpoint = getattr(getattr(api, "cloud", None), "ENDPOINT", None)
        if api is None or not endpoint:
            out["reason"] = "interface interne de pyworxcloud introuvable"
            return out
        session = await api._ensure_session()
        url = f"https://{endpoint}/api/v2/maps/{map_id}"

        async def call(method, body=None):
            await api.check_token()
            headers = {"Accept": "application/json", "Authorization": f"Bearer {api.access_token}"}
            headers.update(MAP_API_VERSION_HEADER)
            kw = {"json": body} if body is not None else {}
            target = url + ("/layers" if method == "PUT" else "")
            async with session.request(method, target, headers=headers, **kw) as resp:
                text = await resp.text()
                return resp.status, text

        status, text = await call("GET")
        if status != 200:
            out["reason"] = f"lecture de la carte refusée (HTTP {status})"
            return out
        zone = mowing_zone(json.loads(text))
        if zone is None:
            out["reason"] = "zone de tonte introuvable dans la carte"
            return out
        meta = dict(zone.get("metadata") or {})
        new, err = build_cut_metadata(meta, cut_type, angle)
        if err:
            out["reason"] = err
            return out
        out["cut_type"] = new.get("cut_type")
        out["cut_angle"] = new.get("cut_direction")
        if new == meta:
            out["ok"] = True
            return out  # déjà réglé : rien à envoyer
        summary = zone.get("summary") or {}
        payload = {"layers": [{"layer": {"type": "zone", "id": summary.get("id"), "zone_type": summary.get("type")},
                               "name": zone.get("name"), "metadata": new}]}
        status, _text = await call("PUT", payload)
        if status >= 300:
            out["reason"] = f"modification refusée par Worx (HTTP {status})"
            return out
        out["changed"] = True
        # Vérification : relecture jusqu'à ce que la zone porte bien les nouveaux réglages.
        waited = 0
        while True:
            await asyncio.sleep(3)
            waited += 3
            status, text = await call("GET")
            if status == 200:
                z2 = mowing_zone(json.loads(text))
                m2 = dict((z2 or {}).get("metadata") or {})
                if m2.get("cut_type") == new.get("cut_type") and m2.get("cut_direction") == new.get("cut_direction"):
                    out["ok"] = True
                    out["cut_type"] = m2.get("cut_type")
                    out["cut_angle"] = m2.get("cut_direction")
                    return out
            if waited >= wait_s:
                out["reason"] = "réglage envoyé mais non confirmé à la relecture"
                return out
    except Exception as exc:
        out["reason"] = f"échec ({type(exc).__name__})"
    return out


def cfg_debug_keys(device):
    """Noms (jamais les valeurs) des clés de la configuration, pour trouver
    où se trouve l'identifiant de carte. Aucune coordonnée."""
    cfg = getattr(device, "raw_cfg", None)
    if not isinstance(cfg, dict):
        return "configuration du robot non reçue (raw_cfg absent)"
    parts = ["cfg=" + ",".join(sorted(str(k) for k in cfg.keys())[:40])]
    rtk = cfg.get("rtk")
    if isinstance(rtk, dict):
        parts.append("cfg.rtk=" + ",".join(sorted(str(k) for k in rtk.keys())))
    else:
        parts.append("cfg.rtk absent")
    return " ; ".join(parts)


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
