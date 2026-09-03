# LandroidRTK — Plugin Jeedom

Pilotage des tondeuses robot **Worx Landroid Vision Cloud** (2WD/4WD, avec RTK/GPS) via l'API cloud officielle Worx.

> ⚠️ Ce plugin cible **uniquement** la gamme *Vision Cloud* (modèles récents avec RTK). Les anciens modèles Worx (sans RTK, sans "Cloud" dans leur nom) sont déjà couverts par le plugin [worxLandroidS](https://market.jeedom.com/index.php?v=d&p=market_display&id=worxLandroidS) et ne sont volontairement **pas** gérés ici.

---

## Sommaire

1. [Fonctionnalités principales](#1-fonctionnalités-principales)
2. [Prérequis](#2-prérequis)
3. [Installation](#3-installation)
4. [Configuration du compte Worx](#4-configuration-du-compte-worx)
5. [Commandes disponibles par équipement](#5-commandes-disponibles-par-équipement)
6. [🕒 Programmation automatique de tonte](#6-programmation-automatique-de-tonte) *(fonctionnalité à part, voir plus bas)*
7. [Limitations connues](#7-limitations-connues)
8. [Mise à jour du plugin](#8-mise-à-jour-du-plugin)
9. [Support](#9-support)

---

## 1. Fonctionnalités principales

- Synchronisation automatique des tondeuses du compte Worx (numéro de série, modèle, image dédiée selon 2WD/4WD)
- Suivi en temps réel via un démon connecté en permanence (MQTT) : batterie, statut, erreurs, position de coupe...
- Commandes manuelles : Démarrer, Stop, Retour à la base, Tonte des bordures
- Statuts et erreurs traduits en français
- Alerte automatique en cas de perte de connexion prolongée (via le heartbeat natif Jeedom)
- **Programmation automatique de tonte** basée sur météo/humidité/pluie — voir [section dédiée](#6-programmation-automatique-de-tonte)

## 2. Prérequis

- Jeedom core ≥ 4.2
- Un compte Worx avec au moins une tondeuse **Vision Cloud** (2WD ou 4WD)
- Accès réseau sortant vers l'API Worx et GitHub (pour l'installation des dépendances Python)
- *(Optionnel, pour la Programmation uniquement)* un plugin météo tiers — voir section 6

## 3. Installation

1. Depuis Jeedom : `Plugins > Gestion des plugins > Market`, recherche "Landroid RTK"
2. Installe la version stable (ou bêta si tu veux suivre les développements en cours)
3. Une fois activé, va dans l'onglet **Dépendances** et lance l'installation (création d'un environnement Python dédié + installation de `pyworxcloud`) — ça prend 2 à 5 minutes

## 4. Configuration du compte Worx

1. `Plugins > Landroid RTK > Configuration`
2. Renseigne l'email et le mot de passe de ton compte Worx
3. Clique sur **Synchroniser les tondeuses** — tes tondeuses *Vision Cloud* apparaissent automatiquement comme équipements
4. Démarre le démon (onglet **Démon** de la fiche du plugin) pour un suivi en temps réel

## 5. Commandes disponibles par équipement

| Commande | Type | Description |
|---|---|---|
| Numéro de série | Info | Identifiant Worx |
| En ligne | Info | Connectée ou non |
| Statut | Info | État courant (dans la station, tond la pelouse, en pause...) |
| Erreur | Info | Dernière erreur signalée par la tondeuse |
| Batterie | Info | Niveau de charge (%) |
| Hauteur de coupe, Forme/Angle de tonte | Info | Paramètres de tonte (lecture seule) |
| Délai pluie, Pluie détectée | Info | Capteur pluie natif du robot |
| Dernière synchro | Info | Horodatage de la dernière mise à jour reçue |
| Start / Stop / Maison / Bordures | Action | Pilotage manuel de la tondeuse |
| Rafraichir | Action | Force une mise à jour immédiate du statut |

---

## 6. Programmation automatique de tonte

> Cette fonctionnalité est **entièrement séparée** du reste du plugin, aussi bien dans le code (fichiers `LandroidRTKScheduler.*` dédiés) que dans l'interface (onglet **"Programmation"** propre à chaque équipement). Elle ne modifie jamais les commandes/comportements de base décrits plus haut.

### Principe

Le plugin peut déclencher automatiquement une tonte quand toutes les conditions suivantes sont réunies :
- On est dans la **plage horaire** autorisée (avec une marge de sécurité avant l'heure de fin, pour ne pas finir de nuit)
- Il n'a pas plu récemment (capteur du robot et/ou un capteur externe)
- Le sol est **suffisamment sec** depuis assez longtemps (seuil et délai d'humidité configurables)
- *(Optionnel)* La **température** est comprise entre un seuil minimum (protection gel) et un seuil maximum (protection canicule)
- Le temps est **dégagé** (code météo de type OpenWeatherMap/WeatherAPI)
- La **batterie** du robot est au moins égale à un seuil minimum (voir ci-dessous, pourquoi c'est important)
- Le nombre de jours minimum depuis la dernière tonte est respecté

### Où la configurer

Fiche de l'équipement → onglet **"Programmation"** (à côté de "Equipement" et "Commandes"). **Cet onglet a son propre bouton "Sauvegarder"** en haut à droite, distinct du bouton natif Jeedom en haut de la page (qui ne s'applique qu'à l'onglet "Equipement" et n'a aucun effet ici).

### Étapes de configuration

1. **Plage horaire** : heure de début/fin (soit un tag de commande Jeedom du style `#[Objet][Équipement][Commande]#`, soit une heure fixe au format `HMM`/`HHMM`, ex: `800` = 08h00), et une marge de sécurité en minutes avant l'heure de fin
   > 💡 **Astuce** : utilise le **coucher du soleil** (fourni par un plugin météo externe) comme heure de fin, et règle la marge sur le **temps que met habituellement ton robot à tondre toute la pelouse**. Le robot ne démarrera alors jamais une tonte qui risquerait de se terminer une fois la nuit tombée.
   > 🌧️ Cette même marge sert aussi de "durée de tonte estimée" pour détecter une pluie arrivant **pendant** une tonte en cours : dans ce cas, la tonte du jour est considérée comme non effectuée, et le robot ne pourra pas redémarrer avant le **délai réglable** décrit ci-dessous (par défaut 1h) — même si l'humidité redescend entre temps, le temps que le sol absorbe une grosse pluie. Pendant ce délai, l'humidité continue d'être surveillée normalement : si elle remonte au-dessus du seuil, le cycle d'attente habituel reprend automatiquement. Un court rappel "(tonte interrompue par la pluie)" apparaît alors à côté de la prochaine estimation de tonte.
2. **Espacement** : tondre tous les combien de jours (1 à 28)
3. **Pluie** : le capteur natif du robot (case à cocher, pense à régler le délai pluie à 0 dans l'appli Worx — le plugin vérifie ce réglage automatiquement et avertit si ce n'est pas le cas) et/ou un capteur externe optionnel (avec opérateur `==`/`≠`), et le **délai avant redémarrage après pluie** (40 à 120 min, par défaut 60 min — le minimum de 40 min laisse le temps à un plugin météo externe de rafraîchir sa mesure d'humidité, certains ne se mettant à jour que toutes les 30 min) décrit au point précédent
4. **Humidité** : une commande obligatoire renvoyant un nombre entre 0 et 100, un seuil max, et un délai minimum sous ce seuil
5. **Température** *(optionnelle)* : un tag de commande Jeedom (obligatoirement — pas de valeur fixe saisie à la main, puisqu'elle changerait sans arrêt), provenant d'un capteur externe ou d'un plugin météo, avec deux seuils :
   - **Seuil minimum** (6 à 18°C, par défaut 10°C) : protection gel — le robot ne tond pas en dessous, pour ne pas abîmer une pelouse potentiellement gelée.
   - **Seuil maximum** (30 à 50°C, par défaut 40°C) : protection canicule — le robot ne tond pas au-dessus.

   **Si le champ commande reste vide, la température n'est pas prise en compte du tout.**
6. **Météo** : une commande `condition_id` (code numérique) et une commande `condition` (description texte) — toutes deux obligatoires
7. **Batterie** : rien à configurer côté commande — le plugin utilise directement la commande "Batterie" créée automatiquement sur l'équipement (synchronisée depuis l'API Worx). Seul le seuil minimum est réglable (20 à 100%, par défaut 30%).
   > 🔋 Pourquoi c'est important : si la batterie est trop faible, l'application Worx refuse en interne de démarrer la tonte — mais le plugin l'ignorerait et considérerait à tort qu'elle a bien eu lieu. Ce seuil bloque donc le déclenchement de la commande `start` tant que la batterie n'est pas remontée au-dessus du seuil ; dès que c'est le cas, **toutes** les conditions (humidité, météo, plage horaire...) sont revérifiées depuis le début avant de tondre. Ce contrôle s'applique à chaque tentative de lancement, y compris lors d'une reprise après une interruption pluie.
8. **Notifications** : autant de commandes que voulu (Discord, appli mobile...), avec titre personnalisable et choix du format (HTML avec `<br/>`, ou texte brut pour Discord)
   > 📋 Le contenu de chaque notification (lancement de tonte, tonte annulée...) suit toujours le même ordre : météo (condition) → température (si réglée) → humidité → batterie. Le même détail est aussi écrit dans les logs du plugin (niveaux `info` et `debug`), pour le retrouver facilement en cas de dépannage.

Pour chaque champ "commande", clique sur l'icône ⬜ à côté du champ pour ouvrir le **sélecteur natif Jeedom** (le même que dans les scénarios). Un aperçu de la valeur actuelle s'affiche automatiquement à côté (en vert si valide, en rouge sinon) ; pour le code météo, le libellé anglais correspondant s'affiche aussi.

### Plugin météo recommandé

Cette fonctionnalité nécessite un plugin météo tiers fournissant un `condition_id` numérique. Recommandé : **["Weather Forecast, CAP alerts"](https://market.jeedom.com)** (par jpty) — et peut aussi fournir l'humidité extérieure et/ou la température. Le plugin météo officiel Jeedom fonctionne également.

### Bouton "Tester"

Avant de pouvoir activer la programmation, clique sur **"Tester la configuration"** — il vérifie chaque champ (existence des commandes, plages de valeurs, format des heures...) et affiche toutes les erreurs trouvées. **La case "Activer" ne peut rester cochée que si ce test passe sans erreur** (revérifié aussi côté serveur à la sauvegarde, jamais uniquement côté navigateur).

Chaque ligne de notification a aussi son propre bouton de test individuel (envoie un vrai message, préfixé "[TEST]", avec le contenu réel qu'aurait le message de production).

> ℹ️ **Limitation connue** : il n'existe aucun mécanisme Jeedom générique permettant de vérifier à l'avance qu'une commande de notification accepte bien un "message"/"titre" sans réellement l'exécuter (chaque plugin lit ces paramètres à sa façon, sans déclaration standardisée). Le bouton "Tester" de chaque ligne, qui envoie un vrai message, reste donc la seule façon fiable de vérifier la compatibilité d'une commande.

### Estimation de la prochaine tonte

Une fois la programmation active et valide, un encart en haut de l'onglet indique une estimation de la prochaine tonte (en supposant l'humidité actuelle inchangée) — pratique pour ajuster les réglages sans attendre. Un résumé court de cette même estimation est aussi disponible dans le widget du dashboard.

### Outils sur la "dernière tonte"

Deux boutons dédiés, sous l'encart d'estimation, agissent sur la date de dernière tonte enregistrée par le planificateur :

- **[Débogage] Régler la dernière tonte à hier** : force la date à la veille, pour pouvoir tester le déclenchement le jour même sans attendre l'espacement complet configuré. À utiliser uniquement pour vérifier que le robot démarre bien selon les seuils paramétrés (humidité, météo, température...).
- **Marquer la tonte d'aujourd'hui comme faite** : à utiliser après une tonte lancée **manuellement** (hors programmation). Si la dernière tonte enregistrée remonte à plus longtemps que l'espacement configuré, le planificateur redéclencherait sinon une seconde tonte le même jour dès que les autres conditions sont réunies.

Les deux boutons réinitialisent aussi l'éventuel état "tonte en cours"/"délai post-pluie" (sans jamais toucher au suivi d'humidité, qui reflète l'état réel du capteur).

### Widget dashboard

En plus des commandes manuelles de base, le widget affiche : l'état de la programmation (Oui/Non) avec 2 boutons explicites **Activer**/**Désactiver**, la date de la dernière tonte, un résumé court de la prochaine tonte, ainsi que des curseurs pour ajuster rapidement la marge, l'espacement et le seuil d'humidité sans repasser par l'onglet Programmation.

### Robustesse

- **Équipement supprimé (ex: désinstallation d'un plugin météo tiers)** : si une commande requise par la programmation (humidité, code météo, capteur de pluie externe, heure de début/fin en tag...) disparaît de Jeedom, la programmation ne tente **jamais** de démarrer le robot dans ce cas — aucun risque d'erreur ni de faux "tonte lancée". Un message clair apparaît à la fois dans les logs `LandroidRTK` et dans le **Centre de Messages** de Jeedom (icône cloche), au maximum une fois par jour et par équipement manquant pour ne pas spammer à chaque passage du cron. En plus de ça, l'onglet Programmation affiche automatiquement le même avertissement dès son ouverture (sans avoir besoin de cliquer sur "Tester"), tant que le problème n'est pas corrigé.
- Si les pages de vérification des codes météo (OpenWeatherMap/WeatherAPI) sont injoignables, un avertissement est loggé mais **n'empêche jamais l'activation**.
- Le cron dédié à la programmation tourne toutes les 5 minutes, indépendamment du reste du plugin (pas d'appel supplémentaire à l'API Worx).
- L'état interne (dernière tonte, suivi d'humidité, délai post-pluie...) est **persisté en base de données** à chaque changement : il survit donc à un redémarrage de Jeedom ou du démon, sans aucune action nécessaire.
- Les commandes du widget dashboard (curseurs, boutons Activer/Désactiver...) sont **entièrement re-synchronisées à chaque sauvegarde et à chaque passage du cron** : nom, bornes, lien vers leur commande info associée — même si elles avaient été créées par une version antérieure du plugin avec une configuration différente ou incomplète (auto-réparation, aucune action manuelle nécessaire).

---

## 7. Limitations connues

- La forme/l'angle de tonte sont **en lecture seule** : l'API Worx cloud ne permet pas (à ce jour, via `pyworxcloud`) de les modifier à distance.
- Worx applique une limite de requêtes sur son API cloud. Évite de redémarrer le démon de façon répétée et rapprochée, au risque d'un blocage temporaire de ton compte (visible aussi dans l'application Worx officielle le temps que ça se lève).

## 8. Mise à jour du plugin

**⚠️ Ne fais jamais un `rm -rf` complet du dossier du plugin** pour une mise à jour classique — ça supprimerait aussi l'environnement Python déjà installé (`resources/python_venv`), obligeant à tout réinstaller (plusieurs minutes).

Procédure de mise à jour "légère", qui préserve le venv :

```bash
cd /var/www/html/plugins/
mkdir -p /tmp/LandroidRTK_new
tar -xzf LandroidRTK.tar.gz -C /tmp/LandroidRTK_new
rsync -a --exclude='resources/python_venv' /tmp/LandroidRTK_new/LandroidRTK/ /var/www/html/plugins/LandroidRTK/
chown -R www-data:www-data /var/www/html/plugins/LandroidRTK
rm -rf /tmp/LandroidRTK_new
```

Un `rm -rf` complet (avec réinstallation des dépendances) n'est nécessaire que si le fichier `resources/install_apt.sh` a lui-même changé.

## 9. Support

- Ouvre une [issue GitHub](../../issues) pour un bug ou une suggestion
- Ou passe par le [forum Jeedom](https://community.jeedom.com), tag `plugin-landroidrtk`

## Licence

AGPL — voir [LICENSE](LICENSE)
