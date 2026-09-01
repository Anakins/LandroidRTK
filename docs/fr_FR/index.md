# Worx Vision

Plugin Jeedom pour piloter les tondeuses robot **Worx Landroid Vision** (Cloud 2WD/4WD, RTK) via l'API cloud officielle Worx.

Ce plugin ne gère **volontairement pas** les anciens modèles Worx (déjà couverts par le plugin `worxLandroid`) : seuls les appareils dont le champ `model` contient "Vision" sont pris en compte lors de la synchronisation.

## Prérequis

1. **Dépendances** : après activation du plugin, va dans Plugins > Worx Vision > Dépendances et lance l'installation. Cela crée un environnement virtuel Python (`resources/venv`) et installe la librairie `pyworxcloud`.

2. **Identifiants Worx** : renseigne l'email et le mot de passe du compte Worx directement dans la page de configuration du plugin (Plugins > Worx Vision > Configuration). Le mot de passe est saisi dans un champ masqué (points), stocké dans la configuration Jeedom comme n'importe quel autre paramètre de plugin.

## Utilisation

1. Renseigne l'email dans la page de configuration du plugin.
2. Clique sur **Synchroniser les tondeuses** : le plugin liste les tondeuses Vision du compte et crée un équipement par tondeuse (identifié de façon stable par son numéro de série). Une resynchronisation n'écrase jamais un équipement déjà présent ni sa configuration Jeedom (nom, page, widgets...) — elle ajoute seulement ce qui manque.
3. Chaque équipement dispose de commandes info (statut, batterie, erreur, hauteur de coupe, forme/angle de tonte, pluie...) et de commandes action (Start, Stop, Maison, Bordures, Rafraichir).
4. Le statut est rafraîchi automatiquement toutes les 5 minutes (cron interne du plugin), et peut aussi être forcé via le bouton "Rafraichir".

## Limitations connues

- Le motif/angle de tonte est en **lecture seule** : la librairie `pyworxcloud` ne permet pas actuellement de le modifier via l'API, seulement de le lire dans le payload de configuration brut de l'appareil.
- Le mapping du code de motif de tonte (`Parallèle` = code 1) est déduit empiriquement, pas documenté officiellement par Worx.
