#!/bin/bash
# install.sh — installe les dépendances du plugin Worx Vision (venv Python +
# pyworxcloud). Script 100% autonome, sans téléchargement externe, avec un
# affichage par étapes façon "[ NN% ] : message... [ OK ]/[ KO ]".

BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
VENV_DIR="${BASEDIR}/python_venv"
HR="======================================================================"

# Fichier de progression lu par Jeedom pour afficher un temps restant
# réaliste. Le core Jeedom passe ce chemin en argument ($1 du script) —
# on le capture tout de suite dans une variable dédiée, avant que la
# fonction step() ne redéfinisse son propre $1 local.
PROGRESS_FILE="${1:-/tmp/LandroidRTK_progress_fallback}"

ok()  { echo -e "[  OK  ]"; }
ko()  { echo -e "[  KO  ]"; }

step() {
    echo "$1" > "$PROGRESS_FILE"
    printf "[ %3s%% ] : %s...\n" "$1" "$2"
}

run_step() {
    # $1 = pourcentage, $2 = libellé, le reste = commande à exécuter
    local pct="$1"; local label="$2"; shift 2
    step "$pct" "$label"
    if "$@" > /tmp/LandroidRTK_step_output.$$ 2>&1; then
        printf "[ %3s%% ] : %s : " "$pct" "$label"
        ok
    else
        printf "[ %3s%% ] : %s : " "$pct" "$label"
        ko
        echo "--- Détail de l'erreur ---"
        cat /tmp/LandroidRTK_step_output.$$
        echo "---------------------------"
        rm -f /tmp/LandroidRTK_step_output.$$
        echo "$HR"
        echo "== KO == Installation échouée =="
        echo "$HR"
        rm -f "$PROGRESS_FILE"
        exit 1
    fi
    rm -f /tmp/LandroidRTK_step_output.$$
}

echo "$HR"
echo "== $(date '+%d/%m/%Y %H:%M:%S') == Installation des dépendances de Worx Vision"
echo "$HR"
echo

run_step  10 "Mise à jour des paquets système (apt)" sudo apt-get update
run_step  30 "Installation des prérequis Python" sudo apt-get install -y python3 python3-venv python3-pip python3-dev

step 50 "Création de l'environnement virtuel Python"
if [ -d "$VENV_DIR" ]; then
    echo "Déjà présent, on réutilise : $VENV_DIR"
    printf "[  50%% ] : Création de l'environnement virtuel Python : "
    ok
else
    if sudo python3 -m venv "$VENV_DIR" > /tmp/LandroidRTK_step_output.$$ 2>&1; then
        printf "[  50%% ] : Création de l'environnement virtuel Python : "
        ok
    else
        printf "[  50%% ] : Création de l'environnement virtuel Python : "
        ko
        cat /tmp/LandroidRTK_step_output.$$
        rm -f /tmp/LandroidRTK_step_output.$$
        rm -f "$PROGRESS_FILE"
        exit 1
    fi
    rm -f /tmp/LandroidRTK_step_output.$$
fi
echo "Version Python du venv : $("$VENV_DIR/bin/python3" --version 2>&1)"

run_step  70 "Mise à jour de pip et wheel" sudo "$VENV_DIR/bin/python3" -m pip install --upgrade pip wheel
run_step  90 "Installation de pyworxcloud" sudo "$VENV_DIR/bin/python3" -m pip install --force-reinstall --upgrade pyworxcloud
run_step  95 "Attribution des droits (www-data)" sudo chown -R www-data:www-data "$VENV_DIR"

step 99 "Vérification finale"
if sudo "$VENV_DIR/bin/python3" -c "import pyworxcloud" > /tmp/LandroidRTK_step_output.$$ 2>&1; then
    printf "[  99%% ] : Vérification finale : "
    ok
else
    printf "[  99%% ] : Vérification finale : "
    ko
    cat /tmp/LandroidRTK_step_output.$$
    rm -f /tmp/LandroidRTK_step_output.$$
    echo "$HR"
    echo "== KO == Installation échouée =="
    echo "$HR"
    rm -f "$PROGRESS_FILE"
    exit 1
fi
rm -f /tmp/LandroidRTK_step_output.$$

echo "[ 100% ] : Terminé !"
echo
echo "$HR"
echo "== OK == Installation Réussie =="
echo "$HR"
rm -f "$PROGRESS_FILE"
exit 0
