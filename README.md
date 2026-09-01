# LandroidRTK — Plugin Jeedom

Pilotage des tondeuses robot **Worx Landroid Vision Cloud** (2WD/4WD, avec RTK/GPS) via l'API cloud officielle Worx.

> ⚠️ Ce plugin cible **uniquement** la gamme *Vision Cloud* (modèles récents avec RTK). Les anciens modèles Worx (sans RTK, sans "Cloud" dans leur nom) sont déjà couverts par le plugin [worxLandroidS](https://market.jeedom.com/index.php?v=d&p=market_display&id=worxLandroidS) et ne sont volontairement **pas** gérés ici.

## Fonctionnalités

- Synchronisation automatique des tondeuses de ton compte Worx (numéro de série, modèle, image dédiée selon 2WD/4WD)
- Suivi en temps réel via un démon connecté en permanence (MQTT) : batterie, statut, erreurs, position de coupe...
- Commandes : Démarrer, Stop, Retour à la base, Tonte des bordures
- Statuts et erreurs traduits en français
- Alerte automatique en cas de perte de connexion prolongée (via le heartbeat natif Jeedom)

## Prérequis

- Jeedom core ≥ 4.2
- Un compte Worx avec au moins une tondeuse **Vision Cloud** (2WD ou 4WD)
- Accès réseau sortant vers l'API Worx et GitHub (pour l'installation des dépendances Python)

## Installation

1. Depuis Jeedom : `Plugins > Gestion des plugins > Market`, recherche "Landroid RTK"
2. Installe la version stable (ou bêta si tu veux suivre les développements en cours)
3. Une fois activé, va dans l'onglet **Dépendances** et lance l'installation (création d'un environnement Python dédié + installation de `pyworxcloud`) — ça prend 2 à 5 minutes

## Configuration

1. `Plugins > Landroid RTK > Configuration`
2. Renseigne l'email et le mot de passe de ton compte Worx
3. Clique sur **Synchroniser les tondeuses** — tes tondeuses *Vision Cloud* apparaissent automatiquement comme équipements
4. Démarre le démon (onglet **Démon** de la fiche du plugin) pour un suivi en temps réel

## Commandes disponibles par équipement

| Commande | Type | Description |
|---|---|---|
| Numéro de série | Info | Identifiant Worx |
| En ligne | Info | Connectée ou non |
| Statut | Info | État courant (dans la station, tond la pelouse, en pause...) |
| Erreur | Info | Dernière erreur signalée par la tondeuse |
| Batterie | Info | Niveau de charge (%) |
| Hauteur de coupe, Forme/Angle de tonte | Info | Paramètres de tonte (lecture seule) |
| Délai pluie, Pluie détectée | Info | Gestion capteur pluie |
| Start / Stop / Maison / Bordures | Action | Pilotage de la tondeuse |
| Rafraichir | Action | Force une mise à jour immédiate du statut |

## Limitations connues

- La forme/l'angle de tonte sont **en lecture seule** : l'API Worx cloud ne permet pas (à ce jour, via la librairie `pyworxcloud`) de les modifier à distance.
- Worx applique une limite de requêtes sur son API cloud. Évite de redémarrer le démon de façon répétée et rapprochée, au risque d'un blocage temporaire de ton compte (visible aussi dans l'application Worx officielle le temps que ça se lève).

## Support

- Ouvre une [issue GitHub](../../issues) pour un bug ou une suggestion
- Ou passe par le [forum Jeedom](https://community.jeedom.com), tag `plugin-landroidrtk`

## Licence

AGPL — voir [LICENSE](LICENSE)
