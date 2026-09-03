<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/LandroidRTK.class.php';

try {
    // Même mécanisme que core/ajax/LandroidRTK.ajax.php : la session
    // isConnect('admin') s'est révélée peu fiable dans cet environnement
    // (longuement diagnostiqué ailleurs dans le plugin) — on accepte donc
    // aussi la clé API du plugin en secours.
    $apikey = init('apikey');
    $expected_apikey = LandroidRTK::getApiKey();
    $authorized_by_apikey = ($apikey != '' && $apikey == $expected_apikey);
    if (!$authorized_by_apikey && !isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    // --- Vérifie la config envoyée (sans l'enregistrer) : utilisé par le
    //     bouton "Tester" (envoie de vrais messages de test) ET par la
    //     case "Activer" (sans envoi de test, juste pour débloquer/bloquer
    //     la case à cocher en direct). ---
    if (init('action') == 'testSchedule' || init('action') == 'validateSchedule') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        $config = json_decode(init('config'), true);
        if (!is_array($config)) {
            throw new Exception('Configuration invalide (JSON illisible)');
        }
        $send_test_notif = (init('action') == 'testSchedule');
        $result = LandroidRTKScheduler::checkConfig($eqLogic, $config, $send_test_notif);
        ajax::success(array(
            'valid' => empty($result['errors']),
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
        ));
    }

    if (init('action') == 'saveSchedule') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        $config = json_decode(init('config'), true);
        if (!is_array($config)) {
            throw new Exception('Configuration invalide (JSON illisible)');
        }

        // Jamais confiance uniquement au JS : si l'utilisateur essaie
        // d'ACTIVER, on revalide côté serveur avant d'autoriser.
        $errors = array();
        if (!empty($config['enabled']) && $config['enabled'] == '1') {
            $result = LandroidRTKScheduler::checkConfig($eqLogic, $config, false);
            $errors = $result['errors'];
            if (!empty($errors)) {
                $config['enabled'] = '0';
            }
        }

        LandroidRTKScheduler::saveConfig($eqLogic, $config);
        LandroidRTKScheduler::syncWidgetCommands($eqLogic);

        ajax::success(array(
            'saved' => true,
            'enabled' => (!empty($config['enabled']) && $config['enabled'] == '1'),
            'errors' => $errors,
        ));
    }

    if (init('action') == 'getSchedule') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        ajax::success(LandroidRTKScheduler::getConfig($eqLogic));
    }

    // --- Liste de toutes les commandes de Jeedom, groupées par
    //     équipement, pour peupler les menus déroulants de sélection
    //     (heure, pluie, humidité, météo, notifications). ---
    if (init('action') == 'listCommands') {
        $out = array();
        foreach (eqLogic::all() as $eq) {
            if (!$eq->getIsEnable()) {
                continue;
            }
            $cmds = array();
            foreach ($eq->getCmd() as $cmd) {
                $cmds[] = array(
                    'id' => $cmd->getId(),
                    'name' => $cmd->getName(),
                    'type' => $cmd->getType(),
                    'subType' => $cmd->getSubType(),
                );
            }
            if (!empty($cmds)) {
                $out[] = array('eqName' => $eq->getName(), 'cmds' => $cmds);
            }
        }
        ajax::success($out);
    }

    if (init('action') == 'conditionCodeLabel') {
        $code = init('code');
        ajax::success(array('label' => LandroidRTKScheduler::getConditionCodeLabel($code)));
    }

    if (init('action') == 'previewValue') {
        $raw = init('raw');
        $is_time = (init('isTime') == '1');
        $min = init('min') !== '' ? floatval(init('min')) : null;
        $max = init('max') !== '' ? floatval(init('max')) : null;
        ajax::success(LandroidRTKScheduler::previewValue($raw, $is_time, $min, $max));
    }

    // [Débogage] Force la dernière tonte à "hier" pour tester le
    // déclenchement le jour même sans attendre l'espacement complet.
    if (init('action') == 'debugMowYesterday') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        $state = LandroidRTKScheduler::debugSetLastMowYesterday($eqLogic);
        ajax::success(array('last_mow_date' => $state['last_mow_date']));
    }

    // Marque la tonte du jour comme faite (tonte manuelle hors
    // programmation) afin d'éviter un second déclenchement le même jour.
    if (init('action') == 'markMowToday') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        $state = LandroidRTKScheduler::markLastMowToday($eqLogic);
        ajax::success(array('last_mow_date' => $state['last_mow_date']));
    }

    if (init('action') == 'nextMowEstimate') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        $config = json_decode(init('config'), true);
        if (!is_array($config)) {
            throw new Exception('Configuration invalide (JSON illisible)');
        }
        ajax::success(LandroidRTKScheduler::estimateNextMow($eqLogic, $config));
    }

    if (init('action') == 'latestStartPreview') {
        $result = LandroidRTKScheduler::previewLatestStart(init('time_start'), init('time_end'), init('margin_minutes'));
        ajax::success($result);
    }

    if (init('action') == 'conditionsStatus') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable');
        }
        $config = LandroidRTKScheduler::getConfig($eqLogic);
        ajax::success(LandroidRTKScheduler::getConditionsStatus($eqLogic, $config));
    }

    throw new Exception(__('Aucune méthode correspondante', __FILE__));
} catch (\Throwable $e) {
    log::add('LandroidRTK', 'error', 'Scheduler ajax.php: erreur (' . get_class($e) . '): ' . $e->getMessage());
    ajax::error($e->getMessage(), 500);
}
