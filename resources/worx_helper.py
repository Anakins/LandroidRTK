#!/usr/bin/env python3
"""
worx_helper.py — Utilisé en interne par le plugin Jeedom "Worx Vision".
Ne pas appeler directement en usage courant (voir worx_control.py autonome
si besoin d'un script indépendant).

Modes :
    list                        Liste les tondeuses "Vision" du compte (JSON)
    status <serial>             Statut détaillé d'une tondeuse (JSON)
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

try:
    from pyworxcloud import WorxCloud
except ImportError as exc:
    print(json.dumps({"error": f"pyworxcloud manquant: {exc}"}))
    sys.exit(1)

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
}

# Traduction des messages d'erreur bruts renvoyés par l'API Worx (champ
# "description" de device.error) vers un libellé court en français.
# Liste non exhaustive (pas de documentation officielle complète disponible
# côté Worx/Positec) : les codes non répertoriés s'affichent tels quels,
# avec juste la première lettre en majuscule.
ERROR_LABELS_FR = {
    "no error": "Aucune erreur",
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
}


def translate_error_label(raw_description):
    """Traduit une description d'erreur brute vers un libellé court en
    français, avec repli propre si le code n'est pas répertorié."""
    if not raw_description:
        return "Aucune erreur"
    key = raw_description.strip().lower()
    if key in ERROR_LABELS_FR:
        return ERROR_LABELS_FR[key]
    return raw_description.strip().capitalize()

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
    error_label_fr = translate_error_label(error_desc)

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
        # DIAGNOSTIC TEMPORAIRE : à retirer une fois qu'on aura confirmé le
        # sens exact de ces champs (temps restant / surface tondue ?).
        "_debug_raw_cut": (raw_dat.get("cut") or {}),
        "_debug_area_mowed_total": getattr(device, "area_mowed", None),
    }


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
            else:
                print(json.dumps({"error": f"action inconnue: {action}"}))
                sys.exit(2)
            print(json.dumps({"ok": True, "action": action, "serial": serial}))
            return

        print(json.dumps({"error": f"mode inconnu: {mode}"}))
        sys.exit(2)


if __name__ == "__main__":
    asyncio.run(run())
