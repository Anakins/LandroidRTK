<?php

require_once dirname(__FILE__) . '/LandroidRTKCmd.class.php';
require_once dirname(__FILE__) . '/LandroidRTKScheduler.class.php';

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
        // Signal WiFi (dBm). Masquée par défaut sur le widget : elle est
        // affichée sous forme de barres dans la barre d'état du haut.
        array('logicalId' => 'wifi_rssi',     'name' => 'Signal WiFi',      'type' => 'numeric', 'unite' => 'dBm', 'order' => 20, 'visible' => 0),
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
    public static function ensureCommands($eqLogic, $only_missing = false) {
        foreach (self::INFO_COMMANDS as $def) {
            $cmd = $eqLogic->getCmd(null, $def['logicalId']);
            if (is_object($cmd) && $only_missing) {
                // Mode "mise à jour du plugin" : on ne crée que ce qui
                // manque, sans toucher à AUCUNE commande existante.
                continue;
            }
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
            if (isset($def['visible'])) {
                $cmd->setIsVisible(intval($def['visible']));
            }
            $cmd->setTemplate('dashboard', 'core::default');
            $cmd->setTemplate('mobile', 'core::default');
            $cmd->save();
        }

        foreach (self::ACTION_COMMANDS as $def) {
            $cmd = $eqLogic->getCmd(null, $def['logicalId']);
            if (is_object($cmd) && $only_missing) {
                continue;
            }
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
        if (isset($data['wifi_rssi']) && is_numeric($data['wifi_rssi'])) {
            $this->checkAndUpdateCmd('wifi_rssi', intval($data['wifi_rssi']));
        }
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
    /* Barre d'état du widget (batterie + WiFi)                          */
    /* ---------------------------------------------------------------- */

    /** Nombre de barres WiFi (0 à 4) pour un RSSI en dBm, null si inconnu. */
    public static function wifiBars($rssi) {
        if ($rssi === null || $rssi === '' || !is_numeric($rssi)) {
            return null;
        }
        $r = floatval($rssi);
        if ($r >= 0) {
            return null;
        }
        if ($r >= -55) {
            return 4;
        }
        if ($r >= -65) {
            return 3;
        }
        if ($r >= -75) {
            return 2;
        }
        if ($r >= -85) {
            return 1;
        }
        return 0;
    }

    /** Classe de couleur du signal WiFi : bon dès -65 dBm, moyen dès -75 dBm. */
    public static function wifiClass($rssi) {
        if ($rssi === null || $rssi === '' || !is_numeric($rssi) || floatval($rssi) >= 0) {
            return 'lrtk-unk';
        }
        $r = floatval($rssi);
        if ($r >= -65) {
            return 'lrtk-good';
        }
        if ($r >= -75) {
            return 'lrtk-warn';
        }
        return 'lrtk-bad';
    }

    /** Classe de couleur de la batterie : bonne dès 40 %, moyenne dès 20 %. */
    public static function batteryClass($percent) {
        if ($percent === null || $percent === '' || !is_numeric($percent)) {
            return 'lrtk-unk';
        }
        $p = floatval($percent);
        if ($p >= 40) {
            return 'lrtk-good';
        }
        if ($p >= 20) {
            return 'lrtk-warn';
        }
        return 'lrtk-bad';
    }

    private function cmdValueOrNull($logicalId) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            return array(null, 0);
        }
        $v = $cmd->execCmd();
        if ($v === '' || $v === null) {
            $v = null;
        }
        return array($v, intval($cmd->getId()));
    }

    /** Construit le HTML de la barre d'état (style + barre + script de mise à jour en direct). */
    public function buildStatusBarHtml() {
        list($battery, $battery_id) = $this->cmdValueOrNull('battery');
        list($rssi, $wifi_id) = $this->cmdValueOrNull('wifi_rssi');
        list($charging, $charging_id) = $this->cmdValueOrNull('charging');

        $pct = is_numeric($battery) ? max(0, min(100, floatval($battery))) : null;
        $bat_class = self::batteryClass($pct);
        $bat_fill = $pct === null ? 0 : round(19 * $pct / 100, 1);
        $bat_txt = $pct === null ? '—' : (intval(round($pct)) . '%');
        $bat_title = $pct === null ? 'Batterie : inconnue' : ('Batterie : ' . intval(round($pct)) . ' %');
        $is_charging = (is_string($charging) && $charging === 'Oui');

        $bars = self::wifiBars($rssi);
        $wifi_class = self::wifiClass($rssi);
        $wifi_txt = $bars === null ? '' : (intval($rssi) . ' dBm');
        $wifi_title = $bars === null ? 'Signal WiFi : inconnu' : ('Signal WiFi : ' . intval($rssi) . ' dBm');

        $bar_svg = '';
        $geoms = array(array(0, 8, 3, 4), array(5, 5.5, 3, 6.5), array(10, 3, 3, 9), array(15, 0, 3, 12));
        foreach ($geoms as $i => $g) {
            $off = ($bars === null || $i >= $bars) ? ' lrtk-off' : '';
            $bar_svg .= '<rect class="lrtk-w' . ($i + 1) . $off . '" x="' . $g[0] . '" y="' . $g[1] . '" width="' . $g[2] . '" height="' . $g[3] . '" rx="0.8" fill="currentColor"/>';
        }

        $id = intval($this->getId());
        $css = '<style>'
            . '.lrtk-bar{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:2px 8px;margin:0 0 3px 0;font-size:12px;line-height:16px;border-bottom:1px solid rgba(128,128,128,.25);}'
            . '.lrtk-bar .lrtk-item{display:inline-flex;align-items:center;gap:4px;}'
            . '.lrtk-bar .lrtk-ico{display:inline-flex;}'
            . '.lrtk-bar .lrtk-good{color:#2fb344;}.lrtk-bar .lrtk-warn{color:#f59f00;}.lrtk-bar .lrtk-bad{color:#e03131;}.lrtk-bar .lrtk-unk{color:#868e96;}'
            . '.lrtk-bar .lrtk-off{opacity:.25;}'
            . '.lrtk-bar .lrtk-txt{opacity:.85;}'
            . '.lrtk-bar .lrtk-bolt{display:' . ($is_charging ? 'inline' : 'none') . ';}'
            . '</style>';

        $html = $css
            . '<div class="lrtk-bar" data-lrtk-eq="' . $id . '">'
            . '<span class="lrtk-item lrtk-bat" title="' . htmlspecialchars($bat_title, ENT_QUOTES) . '">'
            . '<span class="lrtk-ico ' . $bat_class . '"><svg viewBox="0 0 26 12" width="26" height="12" aria-hidden="true">'
            . '<rect x="0.5" y="0.5" width="22" height="11" rx="2.5" fill="none" stroke="currentColor"/>'
            . '<rect class="lrtk-bat-fill" x="2" y="2" width="' . $bat_fill . '" height="8" rx="1.2" fill="currentColor"/>'
            . '<rect x="23.5" y="3.5" width="2" height="5" rx="1" fill="currentColor"/></svg></span>'
            . '<span class="lrtk-txt lrtk-bat-txt">' . htmlspecialchars($bat_txt, ENT_QUOTES) . '</span>'
            . '<span class="lrtk-bolt" title="En charge">⚡</span>'
            . '</span>'
            . '<span class="lrtk-item lrtk-wifi" title="' . htmlspecialchars($wifi_title, ENT_QUOTES) . '">'
            . '<span class="lrtk-txt lrtk-wifi-txt">' . htmlspecialchars($wifi_txt, ENT_QUOTES) . '</span>'
            . '<span class="lrtk-ico lrtk-wifi-ico ' . $wifi_class . '"><svg viewBox="0 0 18 12" width="18" height="12" aria-hidden="true">' . $bar_svg . '</svg></span>'
            . '</span>'
            . '</div>';

        // Mise à jour en direct : on se greffe sur le mécanisme natif
        // jeedom.cmd.update[id] SANS écraser le gestionnaire déjà posé par
        // le widget par défaut (il est rappelé en premier).
        $js = '<script>(function(){'
            . 'try{if(typeof jeedom==="undefined"||!jeedom.cmd||!jeedom.cmd.update){return;}'
            . 'var EQ=' . $id . ';'
            . 'function bars(r){r=parseFloat(r);if(isNaN(r)||r>=0){return null;}return r>=-55?4:(r>=-65?3:(r>=-75?2:(r>=-85?1:0)));}'
            . 'function wcls(r){r=parseFloat(r);if(isNaN(r)||r>=0){return "lrtk-unk";}return r>=-65?"lrtk-good":(r>=-75?"lrtk-warn":"lrtk-bad");}'
            . 'function bcls(p){p=parseFloat(p);if(isNaN(p)){return "lrtk-unk";}return p>=40?"lrtk-good":(p>=20?"lrtk-warn":"lrtk-bad");}'
            . 'function each(f){var els=document.querySelectorAll(\'.lrtk-bar[data-lrtk-eq="\'+EQ+\'"]\');for(var i=0;i<els.length;i++){f(els[i]);}}'
            . 'function setBattery(v){each(function(b){var p=parseFloat(v);var ok=!isNaN(p);if(ok){p=Math.max(0,Math.min(100,p));}'
            . 'b.querySelector(".lrtk-bat-fill").setAttribute("width",ok?(19*p/100).toFixed(1):0);'
            . 'b.querySelector(".lrtk-bat-txt").textContent=ok?(Math.round(p)+"%"):"—";'
            . 'b.querySelector(".lrtk-bat .lrtk-ico").className="lrtk-ico "+bcls(ok?p:null);'
            . 'b.querySelector(".lrtk-bat").title=ok?("Batterie : "+Math.round(p)+" %"):"Batterie : inconnue";});}'
            . 'function setWifi(v){each(function(b){var n=bars(v);var r=parseFloat(v);'
            . 'for(var i=1;i<=4;i++){var e=b.querySelector(".lrtk-w"+i);if(e){e.setAttribute("class","lrtk-w"+i+((n===null||i>n)?" lrtk-off":""));}}'
            . 'b.querySelector(".lrtk-wifi-ico").className="lrtk-ico lrtk-wifi-ico "+wcls(v);'
            . 'b.querySelector(".lrtk-wifi-txt").textContent=n===null?"":(Math.round(r)+" dBm");'
            . 'b.querySelector(".lrtk-wifi").title=n===null?"Signal WiFi : inconnu":("Signal WiFi : "+Math.round(r)+" dBm");});}'
            . 'function setCharging(v){each(function(b){b.querySelector(".lrtk-bolt").style.display=(String(v)==="Oui")?"inline":"none";});}'
            . 'function hook(id,fn){if(!id){return;}var prev=jeedom.cmd.update[id];if(prev&&prev.__lrtk){prev=prev.__prev;}'
            . 'var w=function(o){if(typeof prev==="function"){try{prev(o);}catch(e){}}try{fn(o&&(o.display_value!==undefined?o.display_value:o.value));}catch(e){}};'
            . 'w.__lrtk=true;w.__prev=prev;jeedom.cmd.update[id]=w;}'
            . 'hook(' . $battery_id . ',setBattery);hook(' . $wifi_id . ',setWifi);hook(' . $charging_id . ',setCharging);'
            . '}catch(e){}})();</script>';

        return array('bar' => $html, 'script' => $js);
    }

    /**
     * Widget dashboard/mobile : widget par défaut de Jeedom, auquel on
     * ajoute la barre d'état en haut. En cas de moindre problème, on
     * renvoie le widget par défaut inchangé.
     */
    public function toHtml($_version = 'dashboard') {
        $html = parent::toHtml($_version);
        if (!is_string($html) || $html === '' || ($_version != 'dashboard' && $_version != 'mobile')) {
            return $html;
        }
        if (strpos($html, 'lrtk-bar') !== false) {
            return $html;
        }
        try {
            $parts = $this->buildStatusBarHtml();
            $count = 0;
            $new = preg_replace('/^(\s*<div\b[^>]*\beqLogic-widget\b[^>]*>)/s', '$1' . str_replace(array('\\', '$'), array('\\\\', '\\$'), $parts['bar']), $html, 1, $count);
            if ($new === null || $count !== 1) {
                return $html;
            }
            $end = strrpos($new, '</div>');
            if ($end === false) {
                return $html;
            }
            return substr($new, 0, $end) . $parts['script'] . substr($new, $end);
        } catch (\Throwable $e) {
            log::add('LandroidRTK', 'debug', 'toHtml(): barre d\'état ignorée (' . get_class($e) . ': ' . $e->getMessage() . ')');
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

    private static function trailFile($eqLogic_id, $day) {
        return jeedom::getTmpFolder('LandroidRTK') . '/trail_' . intval($eqLogic_id) . '_' . $day . '.jsonl';
    }

    private static function validPos($pos) {
        return is_array($pos) && count($pos) >= 2
            && is_numeric($pos[0]) && is_numeric($pos[1])
            && abs(floatval($pos[0])) <= 90 && abs(floatval($pos[1])) <= 180
            && !(floatval($pos[0]) == 0 && floatval($pos[1]) == 0);
    }

    /**
     * Mémorise la dernière position / le cap du robot, et ajoute un point à
     * la trace du jour quand il a réellement bougé (jamais à la station :
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
            $day = date('Y-m-d');
            $last_key = 'LandroidRTK::lastpt::' . $id;
            $last = json_decode((string) cache::byKey($last_key)->getValue('{}'), true);
            if (is_array($last) && isset($last['day']) && $last['day'] === $day && isset($last['lat'])) {
                if (self::distanceM(array($last['lat'], $last['lon']), array($lat, $lon)) < self::TRAIL_MIN_DISTANCE_M) {
                    return;
                }
                if (isset($last['n']) && intval($last['n']) >= self::TRAIL_MAX_POINTS) {
                    return;
                }
            } else {
                $last = array('day' => $day, 'n' => 0);
                // Nouveau jour : on supprime les traces des jours précédents.
                foreach (glob(jeedom::getTmpFolder('LandroidRTK') . '/trail_' . $id . '_*.jsonl') ?: array() as $old) {
                    if (basename($old) !== basename(self::trailFile($id, $day))) {
                        @unlink($old);
                    }
                }
            }
            @file_put_contents(self::trailFile($id, $day), json_encode(array($now, $lat, $lon)) . "\n", FILE_APPEND | LOCK_EX);
            cache::set($last_key, json_encode(array('day' => $day, 'lat' => $lat, 'lon' => $lon, 'n' => (isset($last['n']) ? intval($last['n']) : 0) + 1)));
        } catch (\Throwable $e) {
            log::add('LandroidRTK', 'debug', 'rememberRtkPosition(): ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** Enregistre la carte RTK poussée par le démon. */
    public static function storeMap($serial, $map_id, $map) {
        $eqLogic = self::byLogicalId($serial, 'LandroidRTK');
        if (!is_object($eqLogic) || !is_array($map)) {
            return false;
        }
        cache::set('LandroidRTK::map::' . intval($eqLogic->getId()), json_encode(array(
            'map_id' => (string) $map_id,
            'fetched_at' => time(),
            'map' => $map,
        )));
        return true;
    }

    /** Données pour l'onglet Carte (réservé aux utilisateurs connectés). */
    public function getMapData() {
        $id = intval($this->getId());
        $stored = json_decode((string) cache::byKey('LandroidRTK::map::' . $id)->getValue('null'), true);
        $pos = json_decode((string) cache::byKey('LandroidRTK::pos::' . $id)->getValue('null'), true);

        $trail = array();
        $file = self::trailFile($id, date('Y-m-d'));
        if (is_file($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
            if (count($lines) > self::TRAIL_MAX_POINTS) {
                $lines = array_slice($lines, -self::TRAIL_MAX_POINTS);
            }
            foreach ($lines as $line) {
                $pt = json_decode($line, true);
                if (is_array($pt) && count($pt) === 3) {
                    $trail[] = $pt;
                }
            }
        }

        $daemon = self::deamon_info();
        return array(
            'map' => (is_array($stored) && isset($stored['map'])) ? $stored['map'] : null,
            'map_fetched_at' => (is_array($stored) && isset($stored['fetched_at'])) ? $stored['fetched_at'] : null,
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
        if (isset($data['wifi_rssi']) && is_numeric($data['wifi_rssi'])) {
            $eqLogic->checkAndUpdateCmd('wifi_rssi', intval($data['wifi_rssi']));
        }
        self::rememberRtkPosition($eqLogic, $data);

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
