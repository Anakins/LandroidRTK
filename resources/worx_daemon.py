#!/usr/bin/env python3
"""
worx_daemon.py — Reste connecté en permanence à l'API Worx (MQTT) et pousse
chaque mise à jour reçue (dont les erreurs) en temps réel vers Jeedom, via
la route ajax dédiée du plugin (core/ajax/LandroidRTK.ajax.php, action
daemonPush), sécurisée par un secret partagé.

Lancé/arrêté par le plugin PHP (core/class/LandroidRTK.class.php::deamon_start
/deamon_stop), pas destiné à un usage manuel direct.

Variables d'environnement attendues (injectées par le PHP) :
    WORX_EMAIL, WORX_PASSWORD
    LANDROIDRTK_AJAX_URL     ex: http://127.0.0.1/plugins/LandroidRTK/core/ajax/LandroidRTK.ajax.php
    LANDROIDRTK_DAEMON_SECRET
"""

import asyncio
import json
import logging
import os
import sys
import time
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from worx_helper import get_password, device_status, is_vision_model  # noqa: E402

from pyworxcloud import WorxCloud  # noqa: E402

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [worx_daemon] %(levelname)s: %(message)s",
    stream=sys.stderr,
)
logger = logging.getLogger("worx_daemon")

RECONNECT_DELAY = 30
POLL_INTERVAL = 10  # secondes entre deux vérifications de l'état en mémoire


def push_to_jeedom(serial, data, error_message=None):
    ajax_url = os.environ.get("LANDROIDRTK_AJAX_URL")
    secret = os.environ.get("LANDROIDRTK_DAEMON_SECRET")
    if not ajax_url or not secret:
        logger.error("LANDROIDRTK_AJAX_URL / LANDROIDRTK_DAEMON_SECRET non définis.")
        return

    payload = dict(data)
    if error_message:
        payload["error_message"] = error_message

    body = urllib.parse.urlencode({
        "action": "daemonPush",
        "secret": secret,
        "serial": serial,
        "data": json.dumps(payload, default=str),
    }).encode("utf-8")

    try:
        req = urllib.request.Request(ajax_url, data=body, method="POST")
        with urllib.request.urlopen(req, timeout=5) as resp:
            resp.read()
    except Exception as exc:
        logger.error(f"Échec push vers Jeedom pour {serial}: {exc}")


def push_log_to_jeedom(message):
    """
    Fait remonter un message dans les logs Jeedom (LandroidRTK, niveaux
    info+default), pour que les problèmes de connexion du démon soient
    visibles depuis l'interface Jeedom, pas seulement dans le fichier de
    log Python local.
    """
    ajax_url = os.environ.get("LANDROIDRTK_AJAX_URL")
    secret = os.environ.get("LANDROIDRTK_DAEMON_SECRET")
    if not ajax_url or not secret:
        return
    body = urllib.parse.urlencode({
        "action": "daemonLog",
        "secret": secret,
        "message": message,
    }).encode("utf-8")
    try:
        req = urllib.request.Request(ajax_url, data=body, method="POST")
        with urllib.request.urlopen(req, timeout=5) as resp:
            resp.read()
    except Exception as exc:
        logger.error(f"Échec push du log vers Jeedom: {exc}")


async def main():
    email = os.environ.get("WORX_EMAIL")
    if not email:
        logger.error("WORX_EMAIL non défini, arrêt.")
        sys.exit(1)

    last_error = {}
    was_disconnected = False
    last_disconnect_log_time = 0
    disconnect_since = None
    RECONNECT_LOG_INTERVAL = 3600  # 1h : ne pas spammer, juste un rappel régulier

    while True:
        try:
            password = get_password()

            async with WorxCloud(email, password, "worx") as cloud:
                await cloud.authenticate()
                await cloud.connect()

                logger.info("Connecté, écoute des mises à jour en temps réel...")
                if was_disconnected:
                    duree = time.time() - disconnect_since
                    push_log_to_jeedom(
                        f"Démon Worx Vision reconnecté avec succès (après "
                        f"{int(duree // 60)} min de déconnexion)."
                    )
                    was_disconnected = False
                    disconnect_since = None

                while True:
                    for key, device in cloud.devices.items():
                        if not is_vision_model(device):
                            continue

                        serial = getattr(device, "serial_number", None) or key
                        data = device_status(device)

                        error_message = None
                        if data["error_active"] and last_error.get(serial) != data["error_label"]:
                            error_message = f"{data['name']} signale une erreur : {data['error_label']}"
                        last_error[serial] = data["error_label"] if data["error_active"] else None

                        push_to_jeedom(serial, data, error_message)

                    await asyncio.sleep(POLL_INTERVAL)

        except Exception as exc:
            exc_name = type(exc).__name__
            if exc_name == "TooManyRequestsError":
                # Limite de débit Worx atteinte : réessayer toutes les 30s
                # ne ferait qu'aggraver/prolonger le blocage. On attend
                # bien plus longtemps avant de retenter.
                delay = 300
            else:
                delay = RECONNECT_DELAY
            logger.error(f"Connexion perdue ou erreur ({exc_name}: {exc}), nouvelle tentative dans {delay}s.")

            now = time.time()
            if not was_disconnected:
                # Première coupure de cette série : on prévient tout de
                # suite, puis on se limite à un rappel par heure.
                was_disconnected = True
                disconnect_since = now
                last_disconnect_log_time = now
                push_log_to_jeedom(f"Démon Worx Vision déconnecté ({exc_name}). Nouvelles tentatives en cours...")
            elif now - last_disconnect_log_time >= RECONNECT_LOG_INTERVAL:
                last_disconnect_log_time = now
                duree = now - disconnect_since
                push_log_to_jeedom(
                    f"Démon Worx Vision toujours déconnecté depuis "
                    f"{int(duree // 3600)}h{int((duree % 3600) // 60):02d} ({exc_name})."
                )

            time.sleep(delay)


if __name__ == "__main__":
    asyncio.run(main())
