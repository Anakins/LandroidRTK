# Changelog

## 1.1.0 (2026-09-05)
- Mode festif (état "Activé"/"Désactivé" + boutons Activer/Désactiver), juste au-dessus du bouton Rafraichir.
- Lecture de l'état de la coupe intelligente des bordures ("Activée"/"Désactivée"), juste en dessous de la hauteur de coupe.
- **Programmation automatique de tonte** (nouvel onglet dédié) : plage horaire, espacement entre tontes, sécurité pluie (capteur natif du robot et/ou capteur externe), seuil et délai d'humidité, plage de température min/max (protection gel et canicule), seuil de batterie minimum, condition météo (via un plugin météo tiers), notifications personnalisables (Discord, appli mobile...).
- Tableau "État des conditions de démarrage" en haut de l'onglet Programmation (quand elle est active) : détail condition par condition (OK/Non) pour comprendre en un coup d'œil ce qui bloque un démarrage.
- Aperçu en direct de l'heure limite de démarrage ("Dernier départ : HH:MM") à côté du réglage de marge, recalculé à chaque modification.
- Interruption automatique en cas de pluie pendant une tonte en cours, avec délai d'attente réglable (20 à 120 min) avant nouvelle tentative.
- Vérification systématique de la batterie avant tout démarrage (y compris après une reprise post-pluie) : évite d'envoyer un ordre de démarrage que Worx refuserait silencieusement faute de batterie suffisante.
- Notification "pas de tonte" (avec la raison) envoyée à la fermeture de la fenêtre de tonte du jour plutôt qu'en pleine nuit, avec une case "Reçoit 'pas de tonte'" par destinataire pour choisir qui la reçoit.
- Notification d'erreur robot (case "Reçoit 'erreur robot'" par destinataire) : envoyée si une même erreur persiste plus de 3 minutes, avec le libellé de l'erreur.
- Détection automatique des équipements manquants (ex: plugin météo tiers désinstallé) : la programmation ne tente jamais de démarrer dans ce cas, avec avertissement dans le Centre de Messages Jeedom et directement dans l'onglet Programmation.
- Widget dashboard dédié : boutons Activer/Désactiver, curseurs réglables (marge, espacement, seuil d'humidité), estimation de la prochaine tonte.
- Boutons de débogage (forcer la dernière tonte à hier, marquer la tonte du jour comme faite, réinitialiser l'anti-doublon des notifications "pas de tonte") pour faciliter les tests.

## 1.0.0
- Version initiale : synchronisation des tondeuses Worx Vision, commandes info/action, cron de rafraîchissement automatique.
