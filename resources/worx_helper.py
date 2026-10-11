#!/usr/bin/env python3
"""
worx_helper.py — Utilisé en interne par le plugin Jeedom "Worx Vision".
Ne pas appeler directement en usage courant (voir worx_control.py autonome
si besoin d'un script indépendant).

Modes :
    list                        Liste les tondeuses "Vision" du compte (JSON)
    status <serial>             Statut détaillé d'une tondeuse (JSON)
    dump <serial> <dossier>     Export de diagnostic anonymisé (zip) dans <dossier>
    action <cmd> <serial>       Exécute une action (start/pause/home/edge)

Authentification :
    WORX_EMAIL (variable d'environnement, obligatoire)
    WORX_PASSWORD (variable d'environnement, obligatoire — transmise depuis
    la configuration du plugin Jeedom, jamais stockée dans un fichier)
"""

import asyncio
import json
import os
import sys
import zipfile
from datetime import datetime

try:
    from pyworxcloud import WorxCloud
except ImportError as exc:
    print(json.dumps({"error": f"pyworxcloud manquant: {exc}"}))
    sys.exit(1)

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import worx_map  # noqa: E402

STATUS_LABELS_FR = {
    "home": "Dans la station",
    "docked": "Dans la station",
    "charging": "En charge",
    "mowing": "Tond la pelouse",
    "mowing border": "Coupe les bordures",
    "edge_cut": "Coupe les bordures",
    "border_cut": "Coupe les bordures",
    "start_sequence": "Démarrage",
    "leaving_home": "Départ de la station",
    "leaving home": "Départ de la station",
    "going_home": "Retour à la station",
    "going home": "Retour à la station",
    "searching_home": "Recherche de la station",
    "searching_wire": "Recherche du fil",
    "follow_wire": "Suivi du fil",
    "searching_zone": "Recherche de zone",
    "zone_training": "Apprentissage de zone",
    "pause": "En pause",
    "lifted": "Soulevée",
    "trapped": "Bloquée",
    "blade_blocked": "Lame bloquée",
    "remote_control": "Contrôle à distance",
    "debug": "Débogage",
    "error": "Erreur",
    "rain_delay": "Attente pluie",
    "offline": "Hors ligne",
    # Statuts supplémentaires connus de pyworxcloud (v6.x), qui
    # s'affichaient jusque-là en anglais brut. ATTENTION : ne jamais
    # modifier les libellés "Dans la station" et "En charge" ci-dessus :
    # la programmation (LandroidRTKScheduler) les compare tels quels pour
    # savoir si le robot est déjà à la station.
    "idle": "Inactive",
    "start sequence": "Démarrage",
    "digital fence escape": "Sortie de la limite virtuelle",
    "zoning": "Déplacement vers la zone",
    "cutting edge": "Coupe les bordures",
    "searching area": "Recherche de la zone",
    "searching zone": "Recherche de zone",
    "border crossing": "Franchissement de bordure",
    "exploring lawn": "Exploration de la pelouse",
    "blade blocked": "Lame bloquée",
    "unknown": "Inconnu",
}

# Traduction des messages d'erreur renvoyés par l'API Worx. Deux niveaux :
#   1. par IDENTIFIANT d'erreur (ERROR_LABELS_BY_ID) : le plus fiable, car
#      l'identifiant est le code réel envoyé par le robot, alors que la
#      description anglaise dépend de la version de pyworxcloud ;
#   2. par DESCRIPTION anglaise (ERROR_LABELS_FR), en repli.
# Les identifiants sont ceux de pyworxcloud (utils/state.py). Les libellés
# des erreurs qui étaient déjà traduites avant la 1.4.0 sont inchangés.
ERROR_LABELS_BY_ID = {
    0: "Aucune erreur",
    1: "Tondeuse bloquée",
    2: "Tondeuse soulevée",
    3: "Fil périphérique absent",
    4: "Hors du fil périphérique",
    5: "Délai pluie",
    6: "Fermer le capot pour tondre",
    7: "Fermer le capot pour rentrer",
    8: "Moteur de lame bloqué",
    9: "Moteur de roue bloqué",
    10: "Tondeuse bloquée, délai dépassé",
    11: "Tondeuse renversée",
    12: "Batterie faible",
    13: "Fil inversé",
    14: "Erreur de charge",
    15: "Délai dépassé pour rentrer à la station",
    16: "Tondeuse verrouillée",
    17: "Température de la batterie anormale",
    18: "Modèle factice",
    19: "Trappe de la batterie ouverte trop longtemps",
    20: "Erreur de synchronisation du fil",
    21: "Erreur de message (msg num)",
    100: "Erreur d'accostage à la station",
    101: "Erreur HBI",
    102: "Erreur de mise à jour (OTA)",
    103: "Erreur de carte",
    104: "Pente trop forte",
    105: "Zone inaccessible",
    106: "Station de charge inaccessible",
    108: "Données des capteurs insuffisantes",
    109: "Démarrage de l'apprentissage refusé",
    110: "Erreur de caméra (lumière insuffisante ?)",
    111: "Exploration de la pelouse nécessaire",
    112: "Échec de l'exploration de la carte",
    113: "Erreur du lecteur RFID",
    114: "Erreur des phares",
    115: "Station de charge absente",
    116: "Réglage de la hauteur de coupe bloqué",
}

ERROR_LABELS_FR = {
    "no error": "Aucune erreur",
    "unknown": "Erreur inconnue",
    "trapped": "Tondeuse bloquée",
    "lifted": "Tondeuse soulevée",
    "wire missing": "Fil périphérique absent",
    "outside wire": "Hors du fil périphérique",
    "mower tilted": "Tondeuse penchée",
    "upside down": "Tondeuse renversée",
    "battery low": "Batterie faible",
    "reverse wire": "Fil inversé",
    "battery trapped": "Batterie bloquée",
    "charge error": "Erreur de charge",
    "timeout finding home": "Délai dépassé pour rentrer à la station",
    "blade motor blocked": "Moteur de lame bloqué",
    "blade motor blocked (cutter)": "Moteur de lame bloqué",
    "collision sensor blocked": "Capteur de collision bloqué",
    "collision sensor error": "Erreur du capteur de collision",
    "wire sync": "Erreur de synchronisation du fil",
    "mower lifted": "Tondeuse soulevée",
    "alarm - mower lifted": "Alarme : tondeuse soulevée",
    "rain delay": "Délai pluie",
    "close door to mow": "Fermer le capot pour tondre",
    "close door to go home": "Fermer le capot pour rentrer",
    "wheel motor blocked": "Moteur de roue bloqué",
    "trapped timeout": "Tondeuse bloquée, délai dépassé",
    "locked": "Tondeuse verrouillée",
    "battery temperature error": "Température de la batterie anormale",
    "dummy model": "Modèle factice",
    "battery trunk open timeout": "Trappe de la batterie ouverte trop longtemps",
    "msg num": "Erreur de message (msg num)",
    "charging station docking error": "Erreur d'accostage à la station",
    "hbi error": "Erreur HBI",
    "ota error": "Erreur de mise à jour (OTA)",
    "map error": "Erreur de carte",
    "excessive slope": "Pente trop forte",
    "unreachable zone": "Zone inaccessible",
    "unreachable charging station": "Station de charge inaccessible",
    "insufficient sensor data": "Données des capteurs insuffisantes",
    "training start disallowed": "Démarrage de l'apprentissage refusé",
    "camera error": "Erreur de caméra (lumière insuffisante ?)",
    "mapping exploration required": "Exploration de la pelouse nécessaire",
    "mapping exploration failed": "Échec de l'exploration de la carte",
    "rfid reader error": "Erreur du lecteur RFID",
    "headlight error": "Erreur des phares",
    "missing charging station": "Station de charge absente",
    "blade height adjustment blocked": "Réglage de la hauteur de coupe bloqué",
}


def translate_error_label(raw_description, error_id=None):
    """Traduit une erreur en libellé court en français.

    Ordre : identifiant d'erreur connu, puis description anglaise connue,
    puis repli propre (description brute avec majuscule initiale). Ne change
    jamais la notion d'erreur active (voir device_status) : seule
    l'étiquette affichée est concernée.
    """
    if isinstance(error_id, int) and not isinstance(error_id, bool):
        label = ERROR_LABELS_BY_ID.get(error_id)
        if label is not None:
            return label
    if not raw_description:
        return "Aucune erreur"
    key = str(raw_description).strip().lower()
    if key in ERROR_LABELS_FR:
        return ERROR_LABELS_FR[key]
    return str(raw_description).strip().capitalize()

CUT_PATTERN_LABELS = {
    0: "Naturel (non confirmé)",
    1: "Parallèle",
    2: "Damier (non confirmé)",
    3: "Diamant (non confirmé)",
}


def get_password():
    """
    Lit le mot de passe depuis la variable d'environnement WORX_PASSWORD
    (transmise par le plugin PHP depuis la configuration Jeedom).
    Lève RuntimeError si absente.
    """
    password = os.environ.get("WORX_PASSWORD", "")
    if not password:
        raise RuntimeError(
            "Mot de passe Worx manquant. Renseigne-le dans la configuration "
            "du plugin Worx Vision."
        )
    return password


def is_vision_model(device):
    """
    Ne cible QUE la gamme "Vision Cloud" (2WD/4WD, avec RTK/GPS) — PAS
    l'ancien "Landroid Vision" (caméra seule, sans RTK), déjà couvert par
    le plugin worxLandroidS et jamais testé avec ce script (structure
    d'API potentiellement différente : pas de config RTK notamment).
    """
    model = (getattr(device, "model", "") or "").lower()
    return "vision" in model and "cloud" in model


def detect_model_type(device):
    """
    Déduit une catégorie simple à partir du champ "model" renvoyé par l'API
    Worx (ex: "Landroid Vision Cloud 4WD600 (WR340E)").
    Retourne : "vision_4wd", "vision_2wd", ou "vision_generic" si on ne peut
    pas distinguer la motricité.
    """
    model = (getattr(device, "model", "") or "").upper()
    if "4WD" in model:
        return "vision_4wd"
    if "2WD" in model:
        return "vision_2wd"
    return "vision_generic"


def extract_cut_config(device):
    raw_cfg = getattr(device, "raw_cfg", {}) or {}
    zones = ((raw_cfg.get("rtk") or {}).get("zs")) or []
    if not zones:
        return {}
    return ((zones[0].get("cfg") or {}).get("cut")) or {}


def device_status(device):
    battery = getattr(device, "battery", {}) or {}
    status = getattr(device, "status", {}) or {}
    status_desc = status.get("description", "") if isinstance(status, dict) else str(status)
    status_key = status_desc.lower().strip()
    status_label = STATUS_LABELS_FR.get(status_key)
    if status_label is None:
        # Repli robuste : essaie avec underscores <-> espaces interchangés,
        # au cas où l'API renvoie une variante de séparateur non prévue.
        status_label = STATUS_LABELS_FR.get(status_key.replace(" ", "_"))
    if status_label is None:
        status_label = STATUS_LABELS_FR.get(status_key.replace("_", " "))
    if status_label is None:
        status_label = status_desc or "Inconnu"

    error = getattr(device, "error", {}) or {}
    error_desc = error.get("description", "no error") if isinstance(error, dict) else str(error)
    error_id = error.get("id") if isinstance(error, dict) else None
    # Traduction : n'influence QUE le libellé affiché. La notion d'erreur
    # active (error_active, plus bas) reste calculée sur la description
    # brute, exactement comme avant.
    error_label_fr = translate_error_label(error_desc, error_id)
    status_id = status.get("id") if isinstance(status, dict) else None

    cut_cfg = extract_cut_config(device)
    cut_pattern_code = cut_cfg.get("t")
    cut_pattern_label = (
        CUT_PATTERN_LABELS.get(cut_pattern_code, f"Code inconnu ({cut_pattern_code})")
        if cut_pattern_code is not None else None
    )
    module_config = getattr(device, "module_config", {}) or {}
    cutting_height = (module_config.get("EA") or {}).get("h")

    raw_dat = getattr(device, "raw_dat", {}) or {}
    rain = raw_dat.get("rain") or {}
    rainsensor = getattr(device, "rainsensor", {}) or {}

    party_mode = getattr(device, "partymode_enabled", None)
    # Coupe intelligente des bordures (smart trim) : absente de pyworxcloud
    # en tant qu'attribut dédié. CONFIRMÉ par test isolé (mode festif
    # inchangé, seule la coupe intelligente basculée) : le champ "ob" dans
    # la config de coupe de la zone RTK vaut 1 quand elle est activée, 0
    # sinon.
    smart_trim_raw = cut_cfg.get("ob")
    smart_trim = (bool(smart_trim_raw) if smart_trim_raw is not None else None)

    return {
        "serial_number": getattr(device, "serial_number", None),
        "name": getattr(device, "name", None),
        "online": bool(getattr(device, "online", False)),
        "battery_percent": battery.get("percent"),
        "charging": bool(battery.get("charging")),
        "locked": bool(getattr(device, "locked", False)),
        "status_label": status_label,
        "status_raw": status_desc,
        "error_label": error_label_fr,
        "error_raw": error_desc,
        "error_active": error_desc not in ("no error", "", None),
        "cutting_height": cutting_height,
        "cut_pattern_label": cut_pattern_label,
        "cut_angle": cut_cfg.get("d"),
        "rain_delay": rainsensor.get("delay"),
        "rain_detected": bool(rain.get("s")),
        "party_mode_enabled": (bool(party_mode) if party_mode is not None else None),
        "smart_trim_enabled": (bool(smart_trim) if smart_trim is not None else None),
        # DIAGNOSTIC TEMPORAIRE : à retirer une fois qu'on aura confirmé le
        # sens exact de ces champs (temps restant / surface tondue ?).
        "_debug_raw_cut": (raw_dat.get("cut") or {}),
        "_debug_area_mowed_total": getattr(device, "area_mowed", None),
        # Les dumps bruts "_debug_raw_cfg" / "_debug_raw_dat" ont été retirés en
        # 1.4.0 : ils contenaient la position RTK précise du jardin et se
        # retrouvaient dans les journaux de debug. Pour un diagnostic, utiliser
        # le bouton « Exporter les données de diagnostic » (onglet Carte).
        "status_id": status_id,
        "error_id": error_id,
        "wifi_rssi": worx_map.rssi_value(device),
        "rtk_pos": worx_map.rtk_position(device),
        "rtk_heading": worx_map.rtk_heading(device),
        "rtk_map_id": worx_map.rtk_map_id(device),
    }


def _public_attributes(device):
    """Attributs publics non appelables d'un robot, pour le diagnostic."""
    out = {}
    for attr in dir(device):
        if attr.startswith("_") or attr in ("raw_cfg", "raw_dat", "raw_data", "last_status"):
            continue
        try:
            value = getattr(device, attr)
        except Exception:
            continue
        if callable(value):
            continue
        out[attr] = value
    return out


async def dump_diagnostic(cloud, device, outdir, anonymize=True):
    """Écrit un export de diagnostic (zip) dans `outdir`.

    Contenu : configuration brute (cfg), données temps réel brutes (dat),
    carte RTK telle que renvoyée par le cloud, et attributs de l'objet
    pyworxcloud. Par défaut les coordonnées sont décalées (la géométrie est
    conservée, la localisation réelle disparaît) et les identifiants
    (numéro de série, adresse MAC, nom, e-mail...) sont masqués.
    """
    os.makedirs(outdir, exist_ok=True)
    map_id = worx_map.rtk_map_id(device)
    map_data = await worx_map.fetch_rtk_map(cloud, map_id) if map_id else None

    parts = {
        "cfg.json": getattr(device, "raw_cfg", None),
        "dat.json": getattr(device, "raw_dat", None),
        "map.json": map_data,
        "device.json": _public_attributes(device),
    }
    parts = {name: worx_map._jsonable(value) for name, value in parts.items()}

    origin = (worx_map.find_origin(parts["map.json"])
              or worx_map.find_origin(parts["dat.json"]))
    if anonymize:
        parts = {name: worx_map.anonymize(value, origin) for name, value in parts.items()}

    try:
        import importlib.metadata as md
        version = md.version("pyworxcloud")
    except Exception:
        version = "inconnue"
    parts["info.json"] = {
        "plugin": "LandroidRTK",
        "pyworxcloud": version,
        "date": datetime.now().isoformat(timespec="seconds"),
        "anonymise": bool(anonymize),
        "map_disponible": map_data is not None,
        "modele": parts["device.json"].get("model") if isinstance(parts["device.json"], dict) else None,
    }

    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    path = os.path.join(outdir, f"landroidrtk_diagnostic_{stamp}.zip")
    readme = (
        "Export de diagnostic du plugin Jeedom LandroidRTK.\n"
        "Les coordonnees ont ete decalees (geometrie conservee) et les\n"
        "identifiants masques : voir info.json (champ 'anonymise').\n"
    )
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as zf:
        for name, value in parts.items():
            zf.writestr(name, json.dumps(value, indent=2, ensure_ascii=False, default=str))
        zf.writestr("LISEZMOI.txt", readme)
    return {"ok": True, "file": path, "files": sorted(parts), "map_disponible": map_data is not None}


async def run():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "usage: worx_helper.py <list|status|action> ..."}))
        sys.exit(2)

    mode = sys.argv[1]
    email = os.environ.get("WORX_EMAIL")
    if not email:
        print(json.dumps({"error": "WORX_EMAIL non défini"}))
        sys.exit(1)
    try:
        password = get_password()
    except RuntimeError as exc:
        print(json.dumps({"error": str(exc)}))
        sys.exit(1)

    async with WorxCloud(email, password, "worx") as cloud:
        try:
            await cloud.authenticate()
        except Exception as exc:
            if type(exc).__name__ == "TooManyRequestsError":
                print(json.dumps({
                    "error": "Trop de requêtes envoyées à l'API Worx récemment "
                    "(limite de débit atteinte). Réessaie dans quelques minutes."
                }))
            else:
                print(json.dumps({"error": f"Échec d'authentification Worx: {exc}"}))
            sys.exit(1)
        await cloud.connect()
        devices = cloud.devices

        if mode == "list":
            out = []
            for key, dev in devices.items():
                if not is_vision_model(dev):
                    continue
                serial = getattr(dev, "serial_number", None) or key
                try:
                    await cloud.update(serial)
                    dev = devices[key]
                except Exception:
                    pass  # si l'update échoue, on garde les infos déjà connues
                out.append({
                    "device_key": key,
                    "serial_number": getattr(dev, "serial_number", None),
                    "name": getattr(dev, "name", None),
                    "model": getattr(dev, "model", None),
                    "model_type": detect_model_type(dev),
                })
            print(json.dumps(out, ensure_ascii=False))
            return

        if len(sys.argv) < 3:
            print(json.dumps({"error": "numéro de série manquant"}))
            sys.exit(2)

        if mode == "action":
            # Ordre spécifique à ce mode : action <cmd> <serial>
            if len(sys.argv) < 4:
                print(json.dumps({"error": "usage: worx_helper.py action <cmd> <serial>"}))
                sys.exit(2)
            action = sys.argv[2]
            wanted = sys.argv[3]
        else:
            # Tous les autres modes : <mode> <serial>
            action = None
            wanted = sys.argv[2]

        target_key, target_dev = None, None
        for key, dev in devices.items():
            if key == wanted or getattr(dev, "serial_number", None) == wanted:
                target_key, target_dev = key, dev
                break

        if target_dev is None:
            print(json.dumps({"error": f"Tondeuse '{wanted}' introuvable"}))
            sys.exit(1)

        serial = getattr(target_dev, "serial_number", None) or target_key

        if mode == "dump":
            outdir = sys.argv[3] if len(sys.argv) > 3 else os.getcwd()
            await cloud.update(serial)
            result = await dump_diagnostic(cloud, devices[target_key], outdir)
            print(json.dumps(result, ensure_ascii=False))
            return

        if mode == "status":
            await cloud.update(serial)
            print(json.dumps(device_status(devices[target_key]), ensure_ascii=False, default=str))
            return

        if mode == "action":
            if action == "start":
                await cloud.start(serial)
            elif action == "pause":
                await cloud.pause(serial)
            elif action == "home":
                await cloud.home(serial)
            elif action == "edge":
                await cloud.ots(serial, boundary=True, runtime=20)
            elif action == "party_on":
                await cloud.set_party_mode(serial, True)
            elif action == "party_off":
                await cloud.set_party_mode(serial, False)
            else:
                print(json.dumps({"error": f"action inconnue: {action}"}))
                sys.exit(2)
            print(json.dumps({"ok": True, "action": action, "serial": serial}))
            return

        print(json.dumps({"error": f"mode inconnu: {mode}"}))
        sys.exit(2)


if __name__ == "__main__":
    asyncio.run(run())
