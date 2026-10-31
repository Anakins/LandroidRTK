<?php

require_once dirname(__FILE__) . '/LandroidRTKCmd.class.php';
require_once dirname(__FILE__) . '/LandroidRTKScheduler.class.php';
require_once dirname(__FILE__) . '/LandroidRTKMapSvg.class.php';
require_once dirname(__FILE__) . '/LandroidRTKMapPng.class.php';

class LandroidRTK extends eqLogic {

    public function preRemove() {
        $listener = listener::byClassAndFunction('LandroidRTKScheduler', 'onCmdChange', array('eqLogic_id' => intval($this->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
    }

    /*
     * Log un événement important sous DEUX niveaux ("info" et "default")
     * : les boutons "Niveau log" de Jeedom (Aucun/Défaut/Debug/Info/...)
     * sont des catégories indépendantes, pas des seuils de gravité
     * croissants — un message écrit uniquement en "info" n'apparaît pas
     * si l'utilisateur a sélectionné "Défaut" (le réglage le plus
     * courant), et inversement. On couvre donc les deux pour les
     * événements qui doivent toujours être visibles.
     */
    public static function logImportant($message) {
        log::add('LandroidRTK', 'info', $message);
        // On mise aussi sur message::add() (Centre de Messages), un
        // mécanisme séparé du système de seuils de log::add() qui a
        // montré des résultats peu fiables/peu clairs. Garantit que ces
        // événements restent visibles quelle que soit la configuration.
        try {
            message::add('LandroidRTK', $message);
        } catch (\Throwable $e) {
        }
    }

    /*
     * Liste des commandes "info" à créer automatiquement sur chaque
     * équipement synchronisé. Identifiées par un logicalId STABLE : à
     * chaque synchronisation, on ne crée que celles qui manquent, on ne
     * touche JAMAIS à celles qui existent déjà (conf/widget utilisateur
     * préservée).
     */
    // IMPORTANT : INFO_COMMANDS, ACTION_COMMANDS et MODEL_LABELS sont des
    // constantes de classe (const) et non des propriétés statiques. Le
    // cœur de Jeedom (DB::getFields) parcourt TOUTES les propriétés d'une
    // classe héritant d'eqLogic via la réflexion PHP, sans filtrer par
    // visibilité ni exclure le statique, et ne saute que celles dont le
    // nom commence par "_" : toute propriété statique 'normale' est donc
    // prise à tort pour une colonne de la table eqLogic à insérer
    // ("Unknown column ... in 'field list'"). Une constante n'est pas une
    // propriété pour l'API de réflexion, donc ce problème ne peut pas se
    // produire.
    const INFO_COMMANDS = array(
        array('logicalId' => 'last_sync',     'name' => 'Dernière synchro',  'type' => 'string',  'unite' => '',  'order' => 0),
        array('logicalId' => 'serial',        'name' => 'Numéro de série',  'type' => 'string',  'unite' => '',  'order' => 1),
        array('logicalId' => 'online',        'name' => 'En ligne',         'type' => 'string',  'unite' => '',  'order' => 2),
        array('logicalId' => 'status',        'name' => 'Statut',           'type' => 'string',  'unite' => '',  'order' => 3),
        array('logicalId' => 'error',         'name' => 'Erreur',           'type' => 'string',  'unite' => '',  'order' => 4),
        array('logicalId' => 'battery',       'name' => 'Batterie',         'type' => 'numeric', 'unite' => '%', 'order' => 5),
        array('logicalId' => 'charging',      'name' => 'En charge',        'type' => 'string',  'unite' => '',  'order' => 6),
        array('logicalId' => 'locked',        'name' => 'Verrouillé',       'type' => 'string',  'unite' => '',  'order' => 7),
        array('logicalId' => 'height',        'name' => 'Hauteur de coupe', 'type' => 'numeric', 'unite' => 'mm', 'order' => 8),
        array('logicalId' => 'smart_trim',    'name' => 'Coupe intelligente des bordures', 'type' => 'string', 'unite' => '', 'order' => 9),
        array('logicalId' => 'pattern',       'name' => 'Forme tonte',      'type' => 'string',  'unite' => '',  'order' => 10),
        array('logicalId' => 'angle',         'name' => 'Angle forme',      'type' => 'numeric', 'unite' => '°', 'order' => 11),
        array('logicalId' => 'rain_delay',    'name' => 'Délai pluie',      'type' => 'numeric', 'unite' => 'h', 'order' => 12),
        array('logicalId' => 'rain_detected', 'name' => 'Pluie détectée',   'type' => 'string',  'unite' => '',  'order' => 13),
        array('logicalId' => 'party_mode',    'name' => 'Mode festif',      'type' => 'string',  'unite' => '',  'order' => 18),
    );

    /*
     * Commandes "action". Le logicalId correspond EXACTEMENT au mot-clé
     * attendu par worx_helper.py (mode "action").
     */
    const ACTION_COMMANDS = array(
        array('logicalId' => 'start',      'name' => 'Start',                  'action' => 'start',     'order' => 14, 'lineBreak' => '0'),
        array('logicalId' => 'pause',      'name' => 'Stop',                   'action' => 'pause',     'order' => 15, 'lineBreak' => '0'),
        array('logicalId' => 'home',       'name' => 'Maison',                 'action' => 'home',      'order' => 16, 'lineBreak' => '0'),
        array('logicalId' => 'edge',       'name' => 'Bordures',               'action' => 'edge',      'order' => 17, 'lineBreak' => '1'),
        array('logicalId' => 'party_on',   'name' => 'Activer mode festif',    'action' => 'party_on',  'order' => 18, 'lineBreak' => '0'),
        array('logicalId' => 'party_off',  'name' => 'Désactiver mode festif', 'action' => 'party_off', 'order' => 18, 'lineBreak' => '1'),
        array('logicalId' => 'sync',       'name' => 'Rafraichir',             'action' => '__sync__',  'order' => 19, 'lineBreak' => '1'),
    );

    /* ---------------------------------------------------------------- */
    /* Chemins / configuration                                          */
    /* ---------------------------------------------------------------- */

    public static function getPluginDir() {
        return dirname(__FILE__) . '/../..';
    }

    public static function getVenvPython() {
        return self::getPluginDir() . '/resources/python_venv/bin/python3';
    }

    public static function getHelperScript() {
        return self::getPluginDir() . '/resources/worx_helper.py';
    }

    public static function getPassword() {
        return config::byKey('password', 'LandroidRTK', '');
    }

    public static function getEmail() {
        return config::byKey('email', 'LandroidRTK', '');
    }

    /**
     * Construit et exécute la commande shell vers worx_helper.py.
     * Retourne le tableau PHP décodé depuis le JSON, ou null en cas
     * d'échec (avec log d'erreur explicite dans tous les cas).
     */
    private static function callHelper($args) {
        $email = self::getEmail();
        $password = self::getPassword();
        if ($email == '' || $password == '') {
            log::add('LandroidRTK', 'error', 'Email ou mot de passe Worx manquant. Allez dans la configuration du plugin pour les renseigner.');
            return null;
        }

        $python = self::getVenvPython();
        if (!file_exists($python)) {
            log::add('LandroidRTK', 'error', 'Environnement Python introuvable (' . $python . '). Vérifiez que les dépendances du plugin sont bien installées (page Dépendances).');
            return null;
        }

        $cmd_parts = array(
            'timeout',
            '60',
            'env',
            'WORX_EMAIL=' . escapeshellarg($email),
            'WORX_PASSWORD=' . escapeshellarg($password),
            escapeshellarg($python),
            escapeshellarg(self::getHelperScript()),
        );
        foreach ($args as $arg) {
            $cmd_parts[] = escapeshellarg($arg);
        }
        $cmd_parts[] = '2>&1';
        $cmd = implode(' ', $cmd_parts);

        log::add('LandroidRTK', 'debug', 'callHelper(): exécution: ' . implode(' ', $args));
        exec($cmd, $output, $return_code);
        $raw = implode("\n", $output);
        log::add('LandroidRTK', 'debug', 'callHelper(): code retour=' . $return_code . ' sortie=' . substr(self::redactRawOutput($raw), 0, 500));

        if ($return_code == 124) {
            log::add('LandroidRTK', 'error', 'Worx: délai dépassé (60s) en contactant l\'API Worx (réseau lent, ou identifiants incorrects bloquant l\'authentification).');
            return null;
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            log::add('LandroidRTK', 'error', 'Réponse invalide du script Worx (code=' . $return_code . ') : ' . $raw);
            return null;
        }
        if (isset($decoded['error'])) {
            log::add('LandroidRTK', 'error', 'Worx: ' . $decoded['error']);
            return null;
        }
        return $decoded;
    }

    /* ---------------------------------------------------------------- */
    /* Synchronisation (bouton "Synchroniser")                          */
    /* ---------------------------------------------------------------- */

    /**
     * Liste les tondeuses Vision du compte Worx, crée les eqLogic
     * manquants (sans jamais toucher à ceux déjà existants) et s'assure
     * que chaque eqLogic a bien toutes ses commandes (créées seulement
     * si manquantes).
     * Retourne un résumé texte pour affichage côté interface.
     */
    // Libellés lisibles par type de modèle, utilisés pour le nom par défaut
    // et pour choisir l'icône (voir resources/img/<model_type>.png).
    const MODEL_LABELS = array(
        'vision_4wd'     => 'Landroid Vision 4WD',
        'vision_2wd'     => 'Landroid Vision 2WD',
        'vision_generic' => 'Landroid Vision',
    );

    public static function syncDevices() {
        self::logImportant('syncDevices(): démarrage de la synchronisation');
        $devices = self::callHelper(array('list'));
        if ($devices === null) {
            log::add('LandroidRTK', 'error', 'syncDevices(): échec - impossible de récupérer la liste des tondeuses depuis l\'API Worx (voir le détail juste au-dessus dans les logs).');
            return array('success' => false, 'message' => 'Échec de la récupération de la liste des tondeuses (voir logs Worx Vision).');
        }
        if (!is_array($devices)) {
            log::add('LandroidRTK', 'error', 'syncDevices(): réponse inattendue (pas un tableau) reçue de l\'API Worx');
            return array('success' => false, 'message' => 'Réponse inattendue lors du listing.');
        }

        self::logImportant('syncDevices(): ' . count($devices) . ' tondeuse(s) Vision trouvée(s) chez Worx');
        foreach ($devices as $dev) {
            log::add(
                'LandroidRTK',
                'info',
                'syncDevices(): trouvée -> ' .
                (isset($dev['name']) ? $dev['name'] : '?') . ' | ' .
                (isset($dev['model']) ? $dev['model'] : '?') . ' | S/N: ' .
                (isset($dev['serial_number']) ? $dev['serial_number'] : '?')
            );
        }

        $created = 0;
        $existing = 0;

        foreach ($devices as $dev) {
            $serial = $dev['serial_number'];
            if (empty($serial)) {
                continue;
            }

            $model_type = isset($dev['model_type']) ? $dev['model_type'] : 'vision_generic';
            if (!isset(self::MODEL_LABELS[$model_type])) {
                $model_type = 'vision_generic';
            }

            $eqLogic = self::byLogicalId($serial, 'LandroidRTK');
            $is_new = !is_object($eqLogic);

            if ($is_new) {
                $eqLogic = new LandroidRTK();
                $eqLogic->setEqType_name('LandroidRTK');
                $eqLogic->setLogicalId($serial);

                // Nom : celui donné dans l'app Worx s'il existe et diffère
                // du numéro de série ; sinon un nom lisible basé sur le
                // modèle détecté (ex: "Landroid Vision 4WD").
                $api_name = isset($dev['name']) ? trim($dev['name']) : '';
                if ($api_name != '' && $api_name != $serial) {
                    $eqLogic->setName($api_name);
                } else {
                    $eqLogic->setName(self::MODEL_LABELS[$model_type]);
                }

                $eqLogic->setConfiguration('model_type', $model_type);
                $eqLogic->setConfiguration('model', isset($dev['model']) ? $dev['model'] : '');
                $eqLogic->setIsEnable(1);
                $eqLogic->setIsVisible(1);
                $eqLogic->save();
                $created++;
                self::logImportant('Nouvelle tondeuse Vision détectée et créée : ' . $eqLogic->getName() . ' (' . $serial . ', ' . $model_type . ')');
            } else {
                $existing++;
                // On met à jour le type de modèle et le libellé du modèle
                // même sur un équipement déjà existant (utile si la
                // détection a été améliorée depuis), sans jamais toucher au
                // nom ni au reste de la config choisie par l'utilisateur.
                $current_model_type = $eqLogic->getConfiguration('model_type');
                $api_model = isset($dev['model']) ? $dev['model'] : '';
                $current_model = $eqLogic->getConfiguration('model');
                log::add('LandroidRTK', 'debug', 'syncDevices(): équipement existant ' . $serial . ' - model_type actuel=[' . $current_model_type . '] détecté=[' . $model_type . '] model actuel=[' . $current_model . '] détecté=[' . $api_model . ']');

                $changed = false;
                if ($current_model_type != $model_type) {
                    $eqLogic->setConfiguration('model_type', $model_type);
                    $changed = true;
                }
                if ($api_model != '' && $current_model != $api_model) {
                    $eqLogic->setConfiguration('model', $api_model);
                    $changed = true;
                }
                if ($changed) {
                    $eqLogic->save();
                    self::logImportant('syncDevices(): configuration model_type/model mise à jour et sauvegardée pour ' . $serial);
                } else {
                    log::add('LandroidRTK', 'debug', 'syncDevices(): aucun changement nécessaire pour ' . $serial);
                }
            }

            self::ensureCommands($eqLogic);

            // Récupère immédiatement le statut réel plutôt que d'attendre
            // le prochain passage du cron (5 min) : évite un équipement
            // fraîchement créé mais totalement vide.
            $eqLogic->refreshStatus();
        }

        $message = $created . ' tondeuse(s) ajoutée(s), ' . $existing . ' déjà présente(s).';
        self::logImportant('Synchronisation Worx Vision terminée : ' . $message);
        return array('success' => true, 'message' => $message);
    }

    /**
     * Crée les commandes manquantes sur un eqLogic (jamais de doublon,
     * jamais de modification d'une commande déjà existante).
     */
    public static function ensureCommands($eqLogic) {
        foreach (self::INFO_COMMANDS as $def) {
            $cmd = $eqLogic->getCmd(null, $def['logicalId']);
            if (is_object($cmd)) {
                // Commande déjà existante : on ne touche à rien sauf
                // l'ordre, la mise en forme (retour à la ligne) et le type
                // générique (qu'on force à vide pour éviter que Jeedom ne
                // tente une détection automatique erronée qui casse
                // l'affichage du widget résumé), sans jamais écraser un nom
                // personnalisé par l'utilisateur.
                $needs_save = false;
                if ($cmd->getOrder() != $def['order'] || $cmd->getDisplay('forceReturnLineAfter') != '1') {
                    $cmd->setOrder($def['order']);
                    $cmd->setDisplay('forceReturnLineAfter', '1');
                    $needs_save = true;
                }
                if ($cmd->getGeneric_type() != '') {
                    $cmd->setGeneric_type('');
                    $needs_save = true;
                }
                if ($cmd->getIsHistorized() != '0') {
                    $cmd->setIsHistorized(0);
                    $needs_save = true;
                }
                // Correction ciblée : si le nom est encore "État" (valeur
                // erronée d'une version précédente du plugin), on le
                // remet à "Statut". On ne touche à rien d'autre pour ne
                // jamais écraser un renommage volontaire de l'utilisateur.
                if ($def['logicalId'] == 'status' && $cmd->getName() == 'État') {
                    $cmd->setName('Statut');
                    $needs_save = true;
                }
                if ($needs_save) {
                    $cmd->save();
                }
                continue;
            }
            $cmd = new LandroidRTKCmd();
            $cmd->setLogicalId($def['logicalId']);
            $cmd->setName($def['name']);
            $cmd->setType('info');
            $cmd->setSubType($def['type']);
            $cmd->setUnite($def['unite']);
            $cmd->setIsHistorized(0);
            $cmd->setGeneric_type('');
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setOrder($def['order']);
            $cmd->setDisplay('forceReturnLineAfter', '1');
            $cmd->setTemplate('dashboard', 'core::default');
            $cmd->setTemplate('mobile', 'core::default');
            $cmd->save();
        }

        foreach (self::ACTION_COMMANDS as $def) {
            $cmd = $eqLogic->getCmd(null, $def['logicalId']);
            if (is_object($cmd)) {
                $needs_save = false;
                if ($cmd->getOrder() != $def['order'] || $cmd->getDisplay('forceReturnLineAfter') != $def['lineBreak']) {
                    $cmd->setOrder($def['order']);
                    $cmd->setDisplay('forceReturnLineAfter', $def['lineBreak']);
                    $needs_save = true;
                }
                if ($cmd->getGeneric_type() != '') {
                    $cmd->setGeneric_type('');
                    $needs_save = true;
                }
                if ($needs_save) {
                    $cmd->save();
                }
                continue;
            }
            $cmd = new LandroidRTKCmd();
            $cmd->setLogicalId($def['logicalId']);
            $cmd->setName($def['name']);
            $cmd->setType('action');
            $cmd->setSubType('other');
            $cmd->setGeneric_type('');
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setOrder($def['order']);
            $cmd->setDisplay('forceReturnLineAfter', $def['lineBreak']);
            $cmd->setTemplate('dashboard', 'core::default');
            $cmd->setTemplate('mobile', 'core::default');
            $cmd->save();
        }
    }

    /* ---------------------------------------------------------------- */
    /* Statut (bouton "Rafraichir" ou cron)                              */
    /* ---------------------------------------------------------------- */

    public function refreshStatus() {
        log::add('LandroidRTK', 'debug', 'refreshStatus(): appel pour ' . $this->getLogicalId());
        $data = self::callHelper(array('status', $this->getLogicalId()));
        if ($data === null) {
            log::add('LandroidRTK', 'error', 'refreshStatus(): callHelper() a échoué pour ' . $this->getLogicalId() . ' (voir logs ci-dessus pour la raison précise)');
            return false;
        }
        log::add('LandroidRTK', 'debug', 'refreshStatus(): données reçues = ' . json_encode(self::redactForLog($data)));

        $this->checkAndUpdateCmd('last_sync', date('d/m H:i:s'));
        $this->checkAndUpdateCmd('serial', $data['serial_number']);
        $this->checkAndUpdateCmd('online', $data['online'] ? 'Oui' : 'Non');
        $this->checkAndUpdateCmd('status', $data['status_label']);
        $this->checkAndUpdateCmd('error', $data['error_label']);
        LandroidRTKScheduler::checkErrorNotification($this, !empty($data['error_active']), $data['error_label']);        $this->checkAndUpdateCmd('battery', $data['battery_percent']);
        $this->checkAndUpdateCmd('charging', $data['charging'] ? 'Oui' : 'Non');
        $this->checkAndUpdateCmd('locked', $data['locked'] ? 'Oui' : 'Non');
        if (isset($data['cutting_height'])) {
            $this->checkAndUpdateCmd('height', $data['cutting_height']);
        }
        if (isset($data['cut_pattern_label'])) {
            $this->checkAndUpdateCmd('pattern', $data['cut_pattern_label']);
        }
        if (isset($data['cut_angle'])) {
            $this->checkAndUpdateCmd('angle', $data['cut_angle']);
        }
        if (isset($data['rain_delay'])) {
            $this->checkAndUpdateCmd('rain_delay', $data['rain_delay']);
        }
        $this->checkAndUpdateCmd('rain_detected', $data['rain_detected'] ? 'Oui' : 'Non');
        if (isset($data['smart_trim_enabled']) && $data['smart_trim_enabled'] !== null) {
            $this->checkAndUpdateCmd('smart_trim', $data['smart_trim_enabled'] ? 'Activée' : 'Désactivée');
        }
        if (isset($data['party_mode_enabled']) && $data['party_mode_enabled'] !== null) {
            $this->checkAndUpdateCmd('party_mode', $data['party_mode_enabled'] ? 'Activé' : 'Désactivé');
        }
        self::logDockTransition($this, $data);
        self::rememberRtkPosition($this, $data);

        if (!empty($data['error_active'])) {
            log::add('LandroidRTK', 'error', $this->getHumanName() . ' signale une erreur : ' . $data['error_label']);
        }

        log::add('LandroidRTK', 'debug', 'refreshStatus(): terminé avec succès pour ' . $this->getLogicalId());
        return true;
    }

    /**
     * Exécute une action (start/pause/home/edge) sur cette tondeuse.
     */
    public function doAction($action) {
        $result = self::callHelper(array('action', $action, $this->getLogicalId()));
        if ($result === null) {
            return false;
        }
        // On rafraîchit le statut juste après pour refléter le changement.
        $this->refreshStatus();
        return true;
    }

    /* ---------------------------------------------------------------- */
    /* Cron (rafraîchissement périodique automatique)                   */
    /* ---------------------------------------------------------------- */

    /* ---------------------------------------------------------------- */
    /* Confidentialité des journaux                                      */
    /* ---------------------------------------------------------------- */

    /**
     * Retire des données destinées à un journal tout ce qui contient une
     * coordonnée (position RTK précise au centimètre du jardin).
     */
    public static function redactForLog($data) {
        if (!is_array($data)) {
            return $data;
        }
        foreach (array('rtk_pos', 'rtk_heading', 'rtk_map_id') as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '***';
            }
        }
        foreach (array_keys($data) as $key) {
            if (strpos((string) $key, '_debug_raw') === 0) {
                $data[$key] = '***';
            }
        }
        return $data;
    }

    /** Idem pour une sortie brute (texte JSON) du script Python. */
    public static function redactRawOutput($raw) {
        $raw = (string) $raw;
        $out = preg_replace('/"rtk_pos":\s*\[[^\]]*\]/', '"rtk_pos": "***"', $raw);
        return $out === null ? $raw : $out;
    }

    /* ---------------------------------------------------------------- */
    /* Vignette de carte dans le widget                                  */
    /* ---------------------------------------------------------------- */

    /**
     * Vignette de la carte : une simple image <img> dessinée par le serveur
     * (core/php/LandroidRTKMap.php), SANS balise <script> dans le widget.
     * Elle se recharge toute seule toutes les 30 s grâce aux attributs
     * onload/onerror de l'image, et reste invisible (aucune place prise)
     * tant qu'aucune carte n'est disponible. $column = true : version
     * « colonne de droite » du tableau du haut ; sinon bloc pleine largeur.
     * Retourne '' si l'option « Masquer la carte » est cochée.
     */
    public function buildMapImageHtml($column = false) {
        if (intval($this->getConfiguration('hideMapWidget', 0)) === 1) {
            return '';
        }
        $id = intval($this->getId());
        $url = 'plugins/LandroidRTK/core/php/LandroidRTKMap.php?id=' . $id;
        // Rechargement : même adresse + horodatage ; arrêt si l'image n'est plus dans la page.
        $reload = "var i=this;setTimeout(function(){if(document.body.contains(i)){i.src='" . $url . "&t='+Date.now();}},%d)";
        // À la 1re apparition, on demande à Jeedom de recalculer la taille/position du widget
        // (sa hauteur vient de changer) ; plus ensuite, pour ne pas faire bouger le dashboard toutes les 30 s.
        $relayout = "try{jeedomUtils.positionEqLogic(" . $id . ")}catch(e){}";
        $onload = "var m=this.parentNode,f=(m.style.display==='none');this.style.display='block';m.style.display='block';"
            . sprintf($reload, 30000) . ";if(f){" . $relayout . "}";
        $onerror = "var m=this.parentNode,f=(m.style.display!=='none');this.style.display='none';m.style.display='none';"
            . sprintf($reload, 60000) . ";if(f){" . $relayout . "}";
        $box_style = $column
            ? 'display:none;flex:0 1 45%;min-width:0;text-align:center;line-height:0;align-self:center;'
            : 'display:none;margin:4px 0 0 0;text-align:center;line-height:0;';
        return '<div class="lrtk-map" style="' . $box_style . '">'
            . '<img src="' . $url . '&t=' . time() . '" alt="" '
            . 'style="display:none;max-width:100%;max-height:320px;width:auto;height:auto;border-radius:6px;" '
            . 'onload="' . htmlspecialchars($onload, ENT_QUOTES) . '" '
            . 'onerror="' . htmlspecialchars($onerror, ENT_QUOTES) . '"></div>';
    }

    /**
     * Fin (offset juste après le </div> fermant) de l'élément <div> dont
     * l'ouverture commence à $start. Ignore le contenu des <script> et des
     * commentaires. null si le HTML n'est pas équilibré.
     */
    private static function divEnd($html, $start) {
        if (!preg_match('/\G<div\b[^>]*>/', $html, $m, 0, $start)) {
            return null;
        }
        $pos = $start + strlen($m[0]);
        $depth = 1;
        $re = '/<script\b.*?<\/script>|<!--.*?-->|<div\b[^>]*>|<\/div\s*>/is';
        while ($depth > 0) {
            if (!preg_match($re, $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
                return null;
            }
            $tag = strtolower(substr($t[0][0], 0, 4));
            $pos = $t[0][1] + strlen($t[0][0]);
            if ($tag === '<div') {
                if (substr($t[0][0], -2) !== '/>') {
                    $depth++;
                }
            } elseif ($tag === '</di') {
                $depth--;
            }
        }
        return $pos;
    }

    /**
     * Cherche dans le widget le bloc contigu des commandes d'information du
     * haut (Dernière synchro … Pluie détectée). Retourne array(début, fin)
     * ou null si on ne les trouve pas toutes, d'un seul tenant (ex. ordre
     * modifié par l'utilisateur) : on ne risque alors aucun remaniement.
     */
    private function findInfoBlock($html) {
        $ranges = array();
        foreach (self::INFO_COMMANDS as $def) {
            if ($def['logicalId'] === 'party_mode') {
                continue;
            }
            $cmd = $this->getCmd(null, $def['logicalId']);
            if (!is_object($cmd)) {
                continue;
            }
            if (!preg_match('/<div\b[^>]*\bdata-cmd_id="' . intval($cmd->getId()) . '"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $end = self::divEnd($html, $m[0][1]);
            if ($end === null) {
                return null;
            }
            $ranges[] = array($m[0][1], $end);
        }
        if (count($ranges) < 4) {
            return null;
        }
        usort($ranges, function ($a, $b) { return $a[0] - $b[0]; });
        // Entre deux commandes : uniquement des espaces, des <br/> et les
        // séparateurs de ligne du widget par défaut (<div class="break"></div>).
        $sep = '(?:\\s|<br\\s*\\/?>|<div\\s+class="break"\\s*><\\/div>)*';
        for ($i = 1; $i < count($ranges); $i++) {
            $gap = substr($html, $ranges[$i - 1][1], $ranges[$i][0] - $ranges[$i - 1][1]);
            if ($ranges[$i][0] < $ranges[$i - 1][1] || !preg_match('/^' . $sep . '$/i', $gap)) {
                return null;
            }
        }
        $start = $ranges[0][0];
        $end = $ranges[count($ranges) - 1][1];
        if (preg_match('/\G' . $sep . '/i', $html, $m, 0, $end)) {
            $end += strlen($m[0]);   // le séparateur de fin de ligne suit le bloc
        }
        return array($start, $end);
    }

    /**
     * Widget dashboard/mobile : widget par défaut de Jeedom, avec la carte
     * en colonne de droite des informations du haut (tableau en deux
     * colonnes). Si ces informations ne sont pas trouvées d'un seul tenant,
     * la carte est ajoutée tout en bas. En cas de moindre problème, on
     * renvoie le widget par défaut inchangé.
     */
    public function toHtml($_version = 'dashboard') {
        $html = parent::toHtml($_version);
        if (!is_string($html) || $html === '' || ($_version != 'dashboard' && $_version != 'mobile')) {
            return $html;
        }
        if (strpos($html, 'lrtk-map') !== false) {
            return $html;
        }
        try {
            if (intval($this->getConfiguration('hideMapWidget', 0)) === 1
                || !preg_match('/^\s*<div\b[^>]*\beqLogic-widget\b/s', $html)) {
                return $html;
            }
            $block = $this->findInfoBlock($html);
            if ($block !== null) {
                $seg = substr($html, $block[0], $block[1] - $block[0]);
                // Le tableau occupe toute la largeur de la zone des commandes (qui est
                // elle-même un conteneur flex : sans flex-basis 100 % il se réduirait à
                // son contenu et resterait au milieu d'un grand widget). La colonne de
                // gauche reproduit le comportement de cette zone (retours à la ligne
                // « div.break » du widget par défaut), celle de droite centre la carte.
                $table = '<div class="lrtk-top" style="display:flex;flex:0 0 100%;width:100%;box-sizing:border-box;align-items:center;gap:6px;">'
                    . '<div class="lrtk-left" style="flex:1 1 0;min-width:0;align-self:flex-start;display:flex;flex-wrap:wrap;justify-content:space-evenly;align-items:flex-start;">' . $seg . '</div>'
                    . $this->buildMapImageHtml(true) . '</div>';
                return substr($html, 0, $block[0]) . $table . substr($html, $block[1]);
            }
            // Repli : en fin de widget, juste avant la fermeture de la <div> racine.
            $box = $this->buildMapImageHtml(false);
            $new = preg_replace('/(<\/div>)((?:\s*<script\b(?:(?!<\/script>).)*<\/script>)*\s*)$/s', str_replace(array('\\', '$'), array('\\\\', '\\$'), $box) . '$1$2', $html, 1, $count);
            return ($new === null || $count !== 1) ? $html : $new;
        } catch (\Throwable $e) {
            log::add('LandroidRTK', 'debug', 'toHtml(): vignette de carte ignorée (' . get_class($e) . ': ' . $e->getMessage() . ')');
            return $html;
        }
    }

    /* ---------------------------------------------------------------- */
    /* Carte RTK : géométrie, position, trace du jour                    */
    /* ---------------------------------------------------------------- */

    // Largeur de coupe (m) par modèle (valeurs de l'intégration Home Assistant
    // de référence) ; 0,18 m par défaut.
    const CUTTING_WIDTH_BY_MODEL = array(
        'WR202E' => 0.18, 'WR206E' => 0.18, 'WR208E' => 0.18, 'WR303E' => 0.18,
        'WR305E' => 0.18, 'WR308E' => 0.18, 'WR365E' => 0.18, 'WR365E1' => 0.18,
        'WR213E' => 0.22, 'WR216E' => 0.22, 'WR312E' => 0.22, 'WR318E' => 0.22,
        'WR330E' => 0.22, 'WR340E' => 0.22, 'WR341E' => 0.22, 'WR342E' => 0.22,
        'WR344E' => 0.22,
        'WR310' => 0.229, 'WR320' => 0.229, 'WR340' => 0.229, 'WR341' => 0.229,
        'WR344' => 0.229, 'WR346' => 0.229,
    );
    const DEFAULT_CUTTING_WIDTH = 0.18;
    const TRAIL_MIN_DISTANCE_M = 0.25;
    const TRAIL_MAX_POINTS = 12000;
    // La trace repart de zéro chaque jour (la tondeuse peut rentrer charger
    // et repartir plusieurs fois dans la journée : tout reste sur la carte).

    public function getCuttingWidthM() {
        $model = strtoupper((string) $this->getConfiguration('model', ''));
        if (preg_match('/WR\d{3}[A-Z]?\d?/', $model, $m)) {
            $code = $m[0];
            if (isset(self::CUTTING_WIDTH_BY_MODEL[$code])) {
                return self::CUTTING_WIDTH_BY_MODEL[$code];
            }
            foreach (self::CUTTING_WIDTH_BY_MODEL as $known => $width) {
                if (strpos($code, $known) === 0) {
                    return $width;
                }
            }
        }
        return self::DEFAULT_CUTTING_WIDTH;
    }

    /** Distance approximative (m) entre deux points [lat, lon]. */
    public static function distanceM($a, $b) {
        $mean = deg2rad(($a[0] + $b[0]) / 2);
        $x = ($b[1] - $a[1]) * 111320 * max(cos($mean), 0.1);
        $y = ($b[0] - $a[0]) * 110540;
        return sqrt($x * $x + $y * $y);
    }

    private static function trailFile($eqLogic_id, $sid) {
        return jeedom::getTmpFolder('LandroidRTK') . '/trail_' . intval($eqLogic_id) . '_' . $sid . '.jsonl';
    }

    /** Identifiant de la session de trace en cours (la plus récente), ou null. */
    private static function currentTrailSid($id) {
        $last = json_decode((string) cache::byKey('LandroidRTK::lastpt::' . $id)->getValue('{}'), true);
        if (is_array($last) && !empty($last['sid'])) {
            return (string) $last['sid'];
        }
        $best = null;
        foreach (glob(jeedom::getTmpFolder('LandroidRTK') . '/trail_' . $id . '_*.jsonl') ?: array() as $f) {
            if ($best === null || filemtime($f) >= filemtime($best)) {
                $best = $f;
            }
        }
        return $best ? preg_replace('/^trail_\d+_(.*)\.jsonl$/', '$1', basename($best)) : null;
    }

    // Les images PNG envoyées avec les notifications sont conservées 30 jours
    // (la notification de l'application les relit sur le disque), puis effacées.
    const MAP_PNG_KEEP_DAYS = 30;

    /** Images PNG de l'équipement, la plus récente en premier. */
    private static function pngFiles($eqLogic_id) {
        $all = array();
        foreach (self::mapDirs() as $dir) {
            if (!$dir) {
                continue;
            }
            $files = array_merge(glob($dir . '/lastmow_' . intval($eqLogic_id) . '_*.png') ?: array(), glob($dir . '/lastmow_' . intval($eqLogic_id) . '.png') ?: array());
            foreach ($files as $f) {
                $all[$f] = filemtime($f);
            }
        }
        arsort($all);
        return array_keys($all);
    }

    /** Image PNG la plus récente (envoyée avec la notification de fin de tonte). */
    public static function lastMowPng($eqLogic_id) {
        $f = self::pngFiles($eqLogic_id);
        return $f ? $f[0] : null;
    }

    private static function purgeOldPng($eqLogic_id) {
        foreach (self::pngFiles($eqLogic_id) as $f) {
            if (time() - filemtime($f) > self::MAP_PNG_KEEP_DAYS * 86400) {
                @unlink($f);
            }
        }
    }

    /**
     * Génère le PNG de la carte (avec la trace du jour) dans le premier
     * dossier accessible en écriture. Un nom différent à chaque image, pour
     * que les anciennes notifications gardent la leur.
     * Retourne array('ok'=>bool,'path'=>?,'reason'=>?).
     */
    public function saveMapPng() {
        $data = $this->getMapData();
        $reason = 'aucun dossier accessible en écriture';
        foreach (self::mapDirs() as $dir) {
            if (!$dir) {
                continue;
            }
            $path = $dir . '/lastmow_' . intval($this->getId()) . '_' . date('Ymd-His') . '.png';
            $r = LandroidRTKMapPng::render($data, $path);
            if ($r['ok']) {
                self::purgeOldPng(intval($this->getId()));
                return array('ok' => true, 'path' => $path, 'reason' => '');
            }
            $reason = $r['reason'];
            if (strpos($reason, 'GD') !== false || strpos($reason, 'carte') !== false) {
                break;
            }
        }
        return array('ok' => false, 'path' => null, 'reason' => $reason);
    }

    /**
     * Journalise (niveau info) chaque sortie et chaque retour du robot à la
     * station, avec la durée passée dehors : pratique pour connaître la
     * durée réelle d'une tonte. Aucune coordonnée dans le message.
     */
    public static function logDockTransition($eqLogic, $data) {
        try {
            if (!is_array($data) || !isset($data['status_label']) || !is_string($data['status_label']) || $data['status_label'] === '') {
                return;
            }
            $label = $data['status_label'];
            LandroidRTKScheduler::trackTiming($eqLogic, $label, isset($data['battery_percent']) ? $data['battery_percent'] : null);
            $docked = in_array($label, array('Dans la station', 'En charge'), true);
            $key = 'LandroidRTK::dock::' . intval($eqLogic->getId());
            $prev = json_decode((string) cache::byKey($key)->getValue('{}'), true);
            $now = time();
            if (!is_array($prev) || !isset($prev['docked'])) {
                cache::set($key, json_encode(array('docked' => $docked, 'ts' => $now)));
                return;
            }
            if ($prev['docked'] === $docked) {
                return;
            }
            if ($docked) {
                $min = max(0, intval(round(($now - intval($prev['ts'])) / 60)));
                log::add('LandroidRTK', 'info', $eqLogic->getHumanName() . ' : robot rentré à la station (statut « ' . $label . ' ») après ' . $min . ' min hors de la station (sorti à ' . date('H:i', intval($prev['ts'])) . ').');
            } else {
                log::add('LandroidRTK', 'info', $eqLogic->getHumanName() . ' : robot sorti de la station (statut « ' . $label . ' »).');
            }
            cache::set($key, json_encode(array('docked' => $docked, 'ts' => $now)));
        } catch (\Throwable $e) {
            log::add('LandroidRTK', 'debug', 'logDockTransition(): ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    private static function validPos($pos) {
        return is_array($pos) && count($pos) >= 2
            && is_numeric($pos[0]) && is_numeric($pos[1])
            && abs(floatval($pos[0])) <= 90 && abs(floatval($pos[1])) <= 180
            && !(floatval($pos[0]) == 0 && floatval($pos[1]) == 0);
    }

    /**
     * Mémorise la dernière position / le cap du robot, et ajoute un point à
     * la trace de la tonte quand il a réellement bougé (jamais à la station :
     * le signal RTK y dérive de quelques mètres sans que le robot bouge).
     * Aucune coordonnée n'est écrite dans les journaux.
     */
    public static function rememberRtkPosition($eqLogic, $data) {
        try {
            if (!is_array($data) || !isset($data['rtk_pos']) || !self::validPos($data['rtk_pos'])) {
                return;
            }
            $id = intval($eqLogic->getId());
            $lat = round(floatval($data['rtk_pos'][0]), 7);
            $lon = round(floatval($data['rtk_pos'][1]), 7);
            $now = time();
            $docked = (isset($data['status_id']) && is_numeric($data['status_id']) && intval($data['status_id']) === 1);
            $heading = (isset($data['rtk_heading']) && is_numeric($data['rtk_heading'])) ? floatval($data['rtk_heading']) : null;

            $pos_key = 'LandroidRTK::pos::' . $id;
            $prev = json_decode((string) cache::byKey($pos_key)->getValue('{}'), true);
            if (!is_array($prev)) {
                $prev = array();
            }
            $moved = isset($prev['lat']) ? self::distanceM(array($prev['lat'], $prev['lon']), array($lat, $lon)) : 999;
            $turned = ($heading !== null && isset($prev['heading']) && $prev['heading'] !== null)
                ? abs(fmod(abs($heading - $prev['heading']) + 180, 360) - 180) : (($heading !== null) ? 999 : 0);
            $changed_status = (!isset($prev['docked']) || $prev['docked'] !== $docked);
            $stale = (!isset($prev['ts']) || ($now - intval($prev['ts'])) >= 300);
            if ($moved >= 0.1 || $turned >= 5 || $changed_status || $stale) {
                cache::set($pos_key, json_encode(array(
                    'lat' => $lat, 'lon' => $lon, 'heading' => $heading,
                    'docked' => $docked, 'ts' => $now,
                    'status' => isset($data['status_label']) ? (string) $data['status_label'] : null,
                )));
            }

            if ($docked) {
                return;
            }
            $last_key = 'LandroidRTK::lastpt::' . $id;
            $last = json_decode((string) cache::byKey($last_key)->getValue('{}'), true);
            if (is_array($last) && !empty($last['sid']) && isset($last['lat'], $last['ts']) && date('Ymd', intval($last['ts'])) === date('Ymd', $now)) {
                if (self::distanceM(array($last['lat'], $last['lon']), array($lat, $lon)) < self::TRAIL_MIN_DISTANCE_M) {
                    return;
                }
                if (isset($last['n']) && intval($last['n']) >= self::TRAIL_MAX_POINTS) {
                    return;
                }
                $sid = (string) $last['sid'];
            } else {
                $sid = date('Ymd-His', $now);
                $last = array('sid' => $sid, 'n' => 0);
                // Nouveau jour : on supprime la trace précédente (les images PNG déjà envoyées sont conservées).
                foreach (glob(jeedom::getTmpFolder('LandroidRTK') . '/trail_' . $id . '_*.jsonl') ?: array() as $old) {
                    if (basename($old) !== basename(self::trailFile($id, $sid))) {
                        @unlink($old);
                    }
                }
            }
            $written = @file_put_contents(self::trailFile($id, $sid), json_encode(array($now, $lat, $lon)) . "\n", FILE_APPEND | LOCK_EX);
            $err_key = 'LandroidRTK::trailerr::' . $id;
            if ($written === false) {
                // Sans ce signal, une trace jamais enregistrée resterait invisible.
                cache::set($err_key, json_encode(array('text' => 'écriture impossible dans ' . jeedom::getTmpFolder('LandroidRTK') . ' (droits ?)', 'ts' => $now)));
                return;
            }
            if (cache::byKey($err_key)->getValue('') !== '') {
                cache::set($err_key, '');
            }
            cache::set($last_key, json_encode(array('sid' => $sid, 'lat' => $lat, 'lon' => $lon, 'ts' => $now, 'n' => (isset($last['n']) ? intval($last['n']) : 0) + 1)));
        } catch (\Throwable $e) {
            log::add('LandroidRTK', 'debug', 'rememberRtkPosition(): ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Dossiers où stocker la carte : data/map/ du plugin d'abord (persistant,
     * comme le fait un plugin caméra pour ses images), puis le dossier
     * temporaire de Jeedom si les droits ne le permettent pas.
     */
    public static function mapDirs() {
        return array(
            realpath(dirname(__FILE__) . '/../..') . '/data/map',
            jeedom::getTmpFolder('LandroidRTK'),
        );
    }

    public static function mapFile($dir, $eqLogic_id) {
        return $dir . '/map_' . intval($eqLogic_id) . '.json';
    }

    /** Mémorise le texte de diagnostic de la carte envoyé par le démon (écrit seulement s'il change). */
    public static function rememberMapStatus($eqLogic, $text) {
        if (!is_string($text) || $text === '') {
            return;
        }
        $key = 'LandroidRTK::mapstatus::' . intval($eqLogic->getId());
        $prev = json_decode((string) cache::byKey($key)->getValue('{}'), true);
        if (is_array($prev) && isset($prev['text']) && $prev['text'] === $text) {
            return;
        }
        cache::set($key, json_encode(array('text' => substr($text, 0, 600), 'ts' => time())));
    }

    /** Enregistre la carte RTK poussée par le démon. */
    public static function storeMap($serial, $map_id, $map) {
        $eqLogic = self::byLogicalId($serial, 'LandroidRTK');
        if (!is_object($eqLogic) || !is_array($map)) {
            return false;
        }
        $id = intval($eqLogic->getId());
        $json = json_encode(array(
            'map_id' => (string) $map_id,
            'fetched_at' => time(),
            'map' => $map,
        ));
        if (!is_string($json) || $json === '') {
            log::add('LandroidRTK', 'error', 'storeMap: carte RTK non encodable en JSON (' . json_last_error_msg() . ').');
            return false;
        }
        foreach (self::mapDirs() as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                continue;
            }
            $file = self::mapFile($dir, $id);
            $tmp = $file . '.tmp';
            // Écriture atomique : une lecture simultanée ne voit jamais un fichier à moitié écrit.
            if (@file_put_contents($tmp, $json, LOCK_EX) === strlen($json) && @rename($tmp, $file)) {
                @chmod($file, 0664);
                // Une éventuelle ancienne copie dans l'autre dossier ne doit pas masquer la nouvelle.
                foreach (self::mapDirs() as $other) {
                    if ($other !== $dir && is_file(self::mapFile($other, $id))) {
                        @unlink(self::mapFile($other, $id));
                    }
                }
                return true;
            }
            @unlink($tmp);
        }
        // Dernier recours : le cache Jeedom.
        log::add('LandroidRTK', 'error', 'storeMap: aucun dossier inscriptible pour la carte, utilisation du cache Jeedom (vérifiez les droits de plugins/LandroidRTK/data).');
        cache::set('LandroidRTK::map::' . $id, $json);
        return true;
    }

    /** Relit la carte stockée : array('data' => ..., 'source' => 'file'|'cache'|null, 'bytes' => n). */
    private static function loadStoredMap($id) {
        foreach (self::mapDirs() as $dir) {
            $file = self::mapFile($dir, $id);
            if (is_file($file)) {
                $raw = @file_get_contents($file);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($data)) {
                    return array('data' => $data, 'source' => 'file', 'bytes' => strlen($raw), 'dir' => $dir);
                }
            }
        }
        $raw = (string) cache::byKey('LandroidRTK::map::' . intval($id))->getValue('');
        $data = ($raw !== '') ? json_decode($raw, true) : null;
        if (is_array($data)) {
            return array('data' => $data, 'source' => 'cache', 'bytes' => strlen($raw), 'dir' => null);
        }
        return array('data' => null, 'source' => null, 'bytes' => 0, 'dir' => null);
    }

    /** Données pour l'onglet Carte (réservé aux utilisateurs connectés). */
    public function getMapData() {
        $id = intval($this->getId());
        $loaded = self::loadStoredMap($id);
        $stored = $loaded['data'];
        $pos = json_decode((string) cache::byKey('LandroidRTK::pos::' . $id)->getValue('null'), true);

        $trail = array();
        $today = date('Ymd');
        $sid = self::currentTrailSid($id);
        $file = $sid ? self::trailFile($id, $sid) : null;
        if ($file && is_file($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
            if (count($lines) > self::TRAIL_MAX_POINTS) {
                $lines = array_slice($lines, -self::TRAIL_MAX_POINTS);
            }
            foreach ($lines as $line) {
                $pt = json_decode($line, true);
                // Seuls les points d'aujourd'hui sont affichés : la veille s'efface dès minuit,
                // sans attendre le premier mouvement du robot (la carte, elle, reste).
                if (is_array($pt) && count($pt) === 3 && is_numeric($pt[0]) && date('Ymd', intval($pt[0])) === $today) {
                    $trail[] = $pt;
                }
            }
        }

        $trail_total = count($trail);
        $trail_last_ts = $trail_total ? $trail[$trail_total - 1][0] : null;

        $terr = json_decode((string) cache::byKey('LandroidRTK::trailerr::' . $id)->getValue('null'), true);
        $mstat = json_decode((string) cache::byKey('LandroidRTK::mapstatus::' . $id)->getValue('null'), true);
        $daemon = self::deamon_info();
        return array(
            // Diagnostic de la trace : combien de points enregistrés (affichés ou non côté navigateur), et la dernière erreur d'écriture.
            'trail_diag' => array(
                'stored' => $trail_total,
                'last_ts' => $trail_last_ts,
                'error' => (is_array($terr) && isset($terr['text'])) ? $terr['text'] : null,
            ),
            'map_status' => (is_array($mstat) && isset($mstat['text'])) ? $mstat['text'] : null,
            'map_status_ts' => (is_array($mstat) && isset($mstat['ts'])) ? $mstat['ts'] : null,
            'map' => (is_array($stored) && isset($stored['map'])) ? $stored['map'] : null,
            'map_fetched_at' => (is_array($stored) && isset($stored['fetched_at'])) ? $stored['fetched_at'] : null,
            // Diagnostic (aucune coordonnée) : d'où vient la carte, quelle taille, quelle structure.
            'map_diag' => array(
                'source' => $loaded['source'],
                'bytes' => $loaded['bytes'],
                'dir' => $loaded['dir'],
                'map_id' => (is_array($stored) && isset($stored['map_id'])) ? $stored['map_id'] : null,
                'keys' => (is_array($stored) && isset($stored['map']) && is_array($stored['map'])) ? array_slice(array_keys($stored['map']), 0, 20) : array(),
                'layers' => (is_array($stored) && isset($stored['map']['layers']) && is_array($stored['map']['layers']))
                    ? array_map(function ($l) { return is_array($l) ? count($l) : 0; }, $stored['map']['layers']) : array(),
            ),
            'pos' => is_array($pos) ? $pos : null,
            'trail' => $trail,
            'cutting_width_m' => $this->getCuttingWidthM(),
            'daemon_ok' => (isset($daemon['state']) && $daemon['state'] == 'ok'),
            'now' => time(),
        );
    }

    /* ---------------------------------------------------------------- */
    /* Export de diagnostic (anonymisé) dans le dossier du plugin        */
    /* ---------------------------------------------------------------- */

    public static function diagnosticDirs() {
        return array(
            realpath(dirname(__FILE__) . '/../..') . '/data/diagnostic',
            jeedom::getTmpFolder('LandroidRTK'),
        );
    }

    public function dumpDiagnostic() {
        $dirs = self::diagnosticDirs();
        $target = null;
        $fallback = false;
        foreach ($dirs as $i => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                $target = $dir;
                $fallback = ($i > 0);
                break;
            }
        }
        if ($target === null) {
            return array('success' => false, 'message' => 'Aucun dossier inscriptible pour l\'export (droits du dossier data/ du plugin).');
        }
        $res = self::callHelper(array('dump', $this->getLogicalId(), $target));
        if ($res === null || empty($res['file'])) {
            return array('success' => false, 'message' => 'Échec de l\'export (voir les logs LandroidRTK).');
        }
        return array(
            'success' => true,
            'file' => $res['file'],
            'name' => basename($res['file']),
            'dir' => $target,
            'fallback' => $fallback,
            'map_disponible' => !empty($res['map_disponible']),
        );
    }

    /** Chemin d'un export existant (nom validé strictement), ou null. */
    public static function findDiagnosticFile($name) {
        if (!preg_match('/^landroidrtk_diagnostic_\d{8}_\d{6}\.zip$/', (string) $name)) {
            return null;
        }
        foreach (self::diagnosticDirs() as $dir) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public static function getModelImageUrl($model_type) {
        if (!isset(self::MODEL_LABELS[$model_type])) {
            $model_type = 'vision_generic';
        }
        return 'plugins/LandroidRTK/desktop/img/' . $model_type . '.png';
    }

    public static function cron5() {
        // Programmation automatique de tonte (fichier séparé, voir
        // LandroidRTKScheduler.class.php) : doit tourner à chaque passage
        // de ce cron, indépendamment du reste ci-dessous.
        LandroidRTKScheduler::cronScheduler();

        // Si le démon tourne, il pousse déjà les mises à jour en continu
        // (toutes les ~10s) : inutile de ré-authentifier séparément toutes
        // les 5 minutes en plus, ça ne fait que doubler la charge sur
        // l'API Worx et déclenche des erreurs "trop de requêtes". Le cron
        // ne sert donc que de filet de sécurité si le démon est arrêté.
        $daemon_info = self::deamon_info();
        if (isset($daemon_info['state']) && $daemon_info['state'] == 'ok') {
            return;
        }
        foreach (self::byType('LandroidRTK', true) as $eqLogic) {
            $eqLogic->refreshStatus();
        }
    }

    /* ---------------------------------------------------------------- */
    /* Démon temps réel                                                  */
    /* ---------------------------------------------------------------- */

    public static function getApiKey() {
        return config::byKey('api', 'LandroidRTK', '');
    }

    public static function getDaemonPidFile() {
        return jeedom::getTmpFolder('LandroidRTK') . '/daemon.pid';
    }

    public static function getDaemonLogFile() {
        return log::getPathToLog('LandroidRTK_daemon');
    }

    /**
     * Reçoit une mise à jour poussée par le démon Python et met à jour
     * les commandes de l'équipement correspondant (identifié par son
     * numéro de série = logicalId).
     */
    public static function pushFromDaemon($serial, $data) {
        $eqLogic = self::byLogicalId($serial, 'LandroidRTK');
        if (!is_object($eqLogic)) {
            return false;
        }

        $eqLogic->checkAndUpdateCmd('last_sync', date('d/m H:i:s'));

        if (isset($data['online'])) {
            $eqLogic->checkAndUpdateCmd('online', $data['online'] ? 'Oui' : 'Non');
        }
        if (isset($data['status_label'])) {
            $eqLogic->checkAndUpdateCmd('status', $data['status_label']);
        }
        if (isset($data['error_label'])) {
            $eqLogic->checkAndUpdateCmd('error', $data['error_label']);
        }
        if (isset($data['battery_percent'])) {
            $eqLogic->checkAndUpdateCmd('battery', $data['battery_percent']);
        }
        if (isset($data['charging'])) {
            $eqLogic->checkAndUpdateCmd('charging', $data['charging'] ? 'Oui' : 'Non');
        }
        if (isset($data['locked'])) {
            $eqLogic->checkAndUpdateCmd('locked', $data['locked'] ? 'Oui' : 'Non');
        }
        if (isset($data['cutting_height'])) {
            $eqLogic->checkAndUpdateCmd('height', $data['cutting_height']);
        }
        if (isset($data['cut_pattern_label'])) {
            $eqLogic->checkAndUpdateCmd('pattern', $data['cut_pattern_label']);
        }
        if (isset($data['cut_angle'])) {
            $eqLogic->checkAndUpdateCmd('angle', $data['cut_angle']);
        }
        if (isset($data['rain_delay'])) {
            $eqLogic->checkAndUpdateCmd('rain_delay', $data['rain_delay']);
        }
        if (isset($data['rain_detected'])) {
            $eqLogic->checkAndUpdateCmd('rain_detected', $data['rain_detected'] ? 'Oui' : 'Non');
        }
        if (isset($data['smart_trim_enabled']) && $data['smart_trim_enabled'] !== null) {
            $eqLogic->checkAndUpdateCmd('smart_trim', $data['smart_trim_enabled'] ? 'Activée' : 'Désactivée');
        }
        if (isset($data['party_mode_enabled']) && $data['party_mode_enabled'] !== null) {
            $eqLogic->checkAndUpdateCmd('party_mode', $data['party_mode_enabled'] ? 'Activé' : 'Désactivé');
        }
        self::logDockTransition($eqLogic, $data);
        self::rememberRtkPosition($eqLogic, $data);
        self::rememberMapStatus($eqLogic, isset($data['map_status']) ? $data['map_status'] : null);

        if (!empty($data['error_message'])) {
            log::add('LandroidRTK', 'error', $eqLogic->getHumanName() . ' : ' . $data['error_message']);
        }

        return true;
    }

    public static function deamon_info() {
        $return = array();
        $return['log'] = self::getDaemonLogFile();
        $return['state'] = 'nok';

        $pid_file = self::getDaemonPidFile();
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && is_dir('/proc/' . $pid)) {
                $return['state'] = 'ok';
            } else {
                // pid file présent mais process mort : on nettoie
                @unlink($pid_file);
            }
        }

        // "launchable" détermine si le démon PEUT être démarré (affiché
        // dans la colonne "Configuration" de l'interface) : nok tant que
        // les identifiants et les dépendances ne sont pas prêts.
        $return['launchable'] = 'ok';

        if (self::getEmail() == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Email du compte Worx non configuré', __FILE__);
            return $return;
        }
        if (self::getPassword() == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Mot de passe du compte Worx non configuré', __FILE__);
            return $return;
        }
        if (!file_exists(self::getVenvPython())) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Dépendances manquantes', __FILE__);
            return $return;
        }

        return $return;
    }

    public static function deamon_start() {
        self::logImportant('deamon_start(): démarrage demandé');

        self::deamon_stop();

        $info = self::deamon_info();
        if (isset($info['launchable']) && $info['launchable'] != 'ok') {
            $msg = 'Démarrage impossible : ' . (isset($info['launchable_message']) ? $info['launchable_message'] : 'raison inconnue');
            log::add('LandroidRTK', 'error', 'deamon_start(): ' . $msg);
            message::add('LandroidRTK', $msg);
            throw new Exception($msg);
        }

        $email = self::getEmail();
        $password = self::getPassword();
        $python = self::getVenvPython();

        $pid_file = self::getDaemonPidFile();
        $log_file = self::getDaemonLogFile();
        $jeedom_url = config::byKey('daemonJeedomUrl', 'LandroidRTK', 'http://127.0.0.1');
        $ajax_url = rtrim($jeedom_url, '/') . '/plugins/LandroidRTK/core/ajax/LandroidRTK.ajax.php';

        log::add('LandroidRTK', 'debug', 'deamon_start(): python=' . $python . ' ajax_url=' . $ajax_url);

        $env = 'WORX_EMAIL=' . escapeshellarg($email) . ' ' .
               'WORX_PASSWORD=' . escapeshellarg($password) . ' ' .
               'LANDROIDRTK_AJAX_URL=' . escapeshellarg($ajax_url) . ' ' .
               'LANDROIDRTK_DAEMON_SECRET=' . escapeshellarg(self::getApiKey());

        $cmd = $env . ' nohup ' . escapeshellarg($python) . ' ' .
               escapeshellarg(self::getPluginDir() . '/resources/worx_daemon.py') .
               ' >> ' . escapeshellarg($log_file) . ' 2>&1 & echo $!';

        log::add('LandroidRTK', 'debug', 'deamon_start(): commande = ' . $cmd);

        $pid = trim(shell_exec($cmd));
        log::add('LandroidRTK', 'debug', 'deamon_start(): pid obtenu = ' . $pid);

        if ($pid == '' || !ctype_digit($pid)) {
            $msg = 'Échec du démarrage du démon (pas de PID valide retourné).';
            log::add('LandroidRTK', 'error', 'deamon_start(): ' . $msg);
            message::add('LandroidRTK', $msg);
            throw new Exception($msg);
        }

        file_put_contents($pid_file, $pid);

        // On vérifie que le process est toujours vivant après un court délai
        // (utile si le script Python plante immédiatement, ex: import
        // manquant, erreur de syntaxe...).
        sleep(2);
        if (!is_dir('/proc/' . $pid)) {
            @unlink($pid_file);
            $msg = 'Le démon a démarré (pid ' . $pid . ') puis s\'est arrêté immédiatement. Regardez le log du démon pour la raison exacte.';
            log::add('LandroidRTK', 'error', 'deamon_start(): ' . $msg);
            message::add('LandroidRTK', $msg);
            throw new Exception($msg);
        }

        self::logImportant('deamon_start(): démon démarré avec succès (pid ' . $pid . ')');
        message::add('LandroidRTK', 'Démon Worx Vision démarré (pid ' . $pid . ')');
    }

    public static function deamon_stop() {
        log::add('LandroidRTK', 'debug', 'deamon_stop(): appelé');
        $pid_file = self::getDaemonPidFile();
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '') {
                log::add('LandroidRTK', 'debug', 'deamon_stop(): kill du pid ' . $pid);
                shell_exec('kill ' . intval($pid) . ' 2>/dev/null');
            }
            @unlink($pid_file);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Dépendances (venv Python + pyworxcloud)                          */
    /* Convention confirmée par le code réel de worxLandroidS :         */
    /* méthodes STATIQUES sur la classe principale, pas des fonctions   */
    /* procédurales dans install.php.                                   */
    /* ---------------------------------------------------------------- */

    public static function dependancy_info() {
        $return = array();
        $return['log'] = log::getPathToLog('LandroidRTK_update');
        $return['progress_file'] = jeedom::getTmpFolder('LandroidRTK') . '/dependance';

        $venv_python = self::getVenvPython();
        if (file_exists($venv_python)) {
            $check = trim(shell_exec(escapeshellarg($venv_python) . ' -c "import pyworxcloud" 2>&1'));
            $return['state'] = ($check == '') ? 'ok' : 'nok';
        } else {
            $return['state'] = 'nok';
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove('LandroidRTK_update');
        return array(
            'script' => self::getPluginDir() . '/resources/install_apt.sh ' . jeedom::getTmpFolder('LandroidRTK') . '/dependance',
            'log' => log::getPathToLog('LandroidRTK_update'),
        );
    }
}
