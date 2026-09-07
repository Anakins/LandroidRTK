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
- Correction : le sélecteur de la commande "code météo" était restreint aux commandes de sous-type "numérique", cachant les commandes texte contenant un nombre (cas fréquent selon les plugins météo).
- **Bug corrigé** : le suivi du délai d'humidité (compteur avant de considérer la pelouse assez sèche) était remis à zéro uniquement pendant la plage horaire de tonte. Si l'humidité remontait au-dessus du seuil en dehors de cette fenêtre (la nuit par exemple), le compteur n'était jamais réinitialisé et pouvait rester périmé, faisant croire à tort que le délai était déjà écoulé. Le suivi tourne désormais à chaque cycle, quelle que soit l'heure.
- Le compteur de délai d'humidité est réinitialisé automatiquement si le seuil est abaissé (plus strict) ; il est conservé si le seuil est relevé (plus permissif).
- La notification de démarrage de tonte affiche désormais depuis combien de temps l'humidité est passée sous le seuil (ex: "65% (depuis 42 min)").
- **Correction** : `resolveCmd()` acceptait un simple ID numérique tapé au clavier comme commande valide (pouvant coïncider par erreur avec une vraie commande). Seul le format tag Jeedom complet `#[Objet][Équipement][Commande]#` est désormais accepté.
- **Nouveau champ "Durée de tonte estimée"** (30 à 360 min, défaut 120), distinct de la marge : la marge était utilisée à tort pour déterminer la fenêtre "tonte en cours" servant à filtrer les fausses alertes pluie (ex: robot sans garage dont le capteur se déclenche des heures après la fin réelle de la tonte). Ce champ dédié corrige cette confusion.
- Ajout d'une ligne "Statut" (tonte en cours, avec temps restant estimé) dans le tableau "État des conditions de démarrage".
- Ajout de courtes descriptions au-dessus du tableau de notifications, expliquant à quoi correspond chaque case à cocher.
- La ligne "Statut" du tableau des conditions regroupe maintenant en un seul endroit l'état pluie en cours, le délai post-pluie restant, et la tonte en cours (auparavant sur deux lignes séparées "Aucune pluie détectée"/"Pas en attente post-pluie").
- **Réaction instantanée** : en plus du cron toutes les 5 minutes (conservé comme filet de sécurité), le plugin réagit désormais immédiatement au changement de valeur de n'importe quelle commande surveillée (capteur pluie externe, humidité, température, condition météo), via le mécanisme `listener` natif de Jeedom — sans attendre le prochain passage cron.
- `getEmoji()` complétée avec les codes WeatherAPI.com manquants pour orage/bruine/pluie/neige (ne couvrait que les codes OpenWeatherMap). Ajout de deux catégories supplémentaires, finalement conservées : 😶‍🌫️ brume/brouillard/poussière/fumée, 🌪️ tornade/vent violent.

## 1.0.0
- Version initiale : synchronisation des tondeuses Worx Vision, commandes info/action, cron de rafraîchissement automatique.
