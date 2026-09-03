<?php

require_once dirname(__FILE__) . '/LandroidRTKCmd.class.php';
require_once dirname(__FILE__) . '/LandroidRTKScheduler.class.php';

class LandroidRTK extends eqLogic {

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
    public static $INFO_COMMANDS = array(
        array('logicalId' => 'last_sync',     'name' => 'Dernière synchro',  'type' => 'string',  'unite' => '',  'order' => 0),
        array('logicalId' => 'serial',        'name' => 'Numéro de série',  'type' => 'string',  'unite' => '',  'order' => 1),
        array('logicalId' => 'online',        'name' => 'En ligne',         'type' => 'string',  'unite' => '',  'order' => 2),
        array('logicalId' => 'status',        'name' => 'Statut',           'type' => 'string',  'unite' => '',  'order' => 3),
        array('logicalId' => 'error',         'name' => 'Erreur',           'type' => 'string',  'unite' => '',  'order' => 4),
        array('logicalId' => 'battery',       'name' => 'Batterie',         'type' => 'numeric', 'unite' => '%', 'order' => 5),
        array('logicalId' => 'charging',      'name' => 'En charge',        'type' => 'string',  'unite' => '',  'order' => 6),
        array('logicalId' => 'locked',        'name' => 'Verrouillé',       'type' => 'string',  'unite' => '',  'order' => 7),
        array('logicalId' => 'height',        'name' => 'Hauteur de coupe', 'type' => 'numeric', 'unite' => 'mm', 'order' => 8),
        array('logicalId' => 'pattern',       'name' => 'Forme tonte',      'type' => 'string',  'unite' => '',  'order' => 9),
        array('logicalId' => 'angle',         'name' => 'Angle forme',      'type' => 'numeric', 'unite' => '°', 'order' => 10),
        array('logicalId' => 'rain_delay',    'name' => 'Délai pluie',      'type' => 'numeric', 'unite' => 'h', 'order' => 11),
        array('logicalId' => 'rain_detected', 'name' => 'Pluie détectée',   'type' => 'string',  'unite' => '',  'order' => 12),
    );

    /*
     * Commandes "action". Le logicalId correspond EXACTEMENT au mot-clé
     * attendu par worx_helper.py (mode "action").
     */
    public static $ACTION_COMMANDS = array(
        array('logicalId' => 'start', 'name' => 'Start',      'action' => 'start',    'order' => 13, 'lineBreak' => '0'),
        array('logicalId' => 'pause', 'name' => 'Stop',       'action' => 'pause',    'order' => 14, 'lineBreak' => '0'),
        array('logicalId' => 'home',  'name' => 'Maison',     'action' => 'home',     'order' => 15, 'lineBreak' => '0'),
        array('logicalId' => 'edge',  'name' => 'Bordures',   'action' => 'edge',     'order' => 16, 'lineBreak' => '1'),
        array('logicalId' => 'sync',  'name' => 'Rafraichir', 'action' => '__sync__', 'order' => 17, 'lineBreak' => '1'),
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
            log::add('LandroidRTK', 'error', 'Email ou mot de passe Worx manquant. Va dans la configuration du plugin pour les renseigner.');
            return null;
        }

        $python = self::getVenvPython();
        if (!file_exists($python)) {
            log::add('LandroidRTK', 'error', 'Environnement Python introuvable (' . $python . '). Vérifie que les dépendances du plugin sont bien installées (page Dépendances).');
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
        log::add('LandroidRTK', 'debug', 'callHelper(): code retour=' . $return_code . ' sortie=' . substr($raw, 0, 500));

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
    public static $MODEL_LABELS = array(
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
            if (!isset(self::$MODEL_LABELS[$model_type])) {
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
                    $eqLogic->setName(self::$MODEL_LABELS[$model_type]);
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
        foreach (self::$INFO_COMMANDS as $def) {
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

        foreach (self::$ACTION_COMMANDS as $def) {
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
        log::add('LandroidRTK', 'debug', 'refreshStatus(): données reçues = ' . json_encode($data));

        $this->checkAndUpdateCmd('last_sync', date('d/m H:i:s'));
        $this->checkAndUpdateCmd('serial', $data['serial_number']);
        $this->checkAndUpdateCmd('online', $data['online'] ? 'Oui' : 'Non');
        $this->checkAndUpdateCmd('status', $data['status_label']);
        $this->checkAndUpdateCmd('error', $data['error_label']);
        $this->checkAndUpdateCmd('battery', $data['battery_percent']);
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

    public static function getModelImageUrl($model_type) {
        if (!isset(self::$MODEL_LABELS[$model_type])) {
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
            $msg = 'Le démon a démarré (pid ' . $pid . ') puis s\'est arrêté immédiatement. Regarde le log du démon pour la raison exacte.';
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
