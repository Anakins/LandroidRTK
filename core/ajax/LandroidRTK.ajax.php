<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/LandroidRTK.class.php';

// L'appel à l'API Worx (auth + connexion MQTT) peut prendre du temps ; on
// laisse de la marge pour éviter un timeout PHP brutal et incatchable
// (le timeout shell "60" dans callHelper() reste la vraie limite de sécurité).
set_time_limit(90);

try {
    /*
     * Le démon (processus Python indépendant, sans session navigateur)
     * appelle cette route toutes les ~10s avec un secret partagé, sans
     * passer par isConnect(). Volontairement SILENCIEUX en cas de succès
     * (appelé en continu, journaliser à chaque fois saturerait le disque
     * sur la durée) — seules les erreurs sont journalisées.
     */
    if (init('action') == 'daemonPush') {
        $secret = init('secret');
        $expected = LandroidRTK::getApiKey();
        if ($secret == '' || $secret != $expected) {
            log::add('LandroidRTK', 'error', 'daemonPush: secret invalide reçu du démon.');
            throw new Exception('403 - Secret invalide');
        }
        $serial = init('serial');
        $payload = json_decode(init('data', '{}'), true);
        if (!is_array($payload)) {
            log::add('LandroidRTK', 'error', 'daemonPush: payload JSON invalide reçu du démon.');
            throw new Exception('Payload JSON invalide');
        }
        $ok = LandroidRTK::pushFromDaemon($serial, $payload);
        if (!$ok) {
            log::add('LandroidRTK', 'error', 'daemonPush: échec de la mise à jour pour le numéro de série ' . $serial . ' (équipement introuvable ?).');
        }
        ajax::success(array('success' => $ok));
    }

    if (init('action') == 'daemonLog') {
        $secret = init('secret');
        $expected = LandroidRTK::getApiKey();
        if ($secret == '' || $secret != $expected) {
            throw new Exception('403 - Secret invalide');
        }
        $message = init('message');
        if ($message != '') {
            LandroidRTK::logImportant($message);
        }
        ajax::success(array('success' => true));
    }

    $apikey = init('apikey');
    $expected_apikey = LandroidRTK::getApiKey();
    $authorized_by_apikey = ($apikey != '' && $apikey == $expected_apikey);

    if (!$authorized_by_apikey && !isConnect('admin')) {
        $cookie_names = implode(',', array_keys($_COOKIE));
        $session_keys = isset($_SESSION) ? implode(',', array_keys($_SESSION)) : '(session absente)';
        $user_login = isset($_SESSION['user_login']) ? $_SESSION['user_login'] : '(non défini)';
        $user_profile = isset($_SESSION['user_profile']) ? $_SESSION['user_profile'] : '(non défini)';
        log::add('LandroidRTK', 'error', 'ajax.php: autorisation échouée (ni apikey valide, ni session admin). Cookies reçus=[' . $cookie_names . '] Clés session=[' . $session_keys . '] user_login=' . $user_login . ' user_profile=' . $user_profile);
        throw new Exception(__('401 - Accès non autorisé (session invalide ou profil non admin, et apikey absente/invalide)', __FILE__));
    }

    if (init('action') == 'syncDevices') {
        $result = LandroidRTK::syncDevices();
        ajax::success($result);
    }

    if (init('action') == 'refreshStatus') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic)) {
            log::add('LandroidRTK', 'error', 'ajax.php: refreshStatus, équipement introuvable id=' . init('id'));
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $ok = $eqLogic->refreshStatus();
        ajax::success(array('success' => $ok));
    }

    log::add('LandroidRTK', 'error', 'ajax.php: action inconnue reçue: ' . init('action'));
    throw new Exception(__('Aucune méthode correspondante', __FILE__));
} catch (\Throwable $e) {
    // \Throwable (pas seulement \Exception) : capture aussi les erreurs
    // fatales PHP (TypeError, Error...) qui sinon tuent le script sans
    // laisser aucune trace ni réponse JSON exploitable côté navigateur.
    log::add('LandroidRTK', 'error', 'ajax.php: erreur (' . get_class($e) . '): ' . $e->getMessage() . ' dans ' . $e->getFile() . ':' . $e->getLine());
    ajax::error($e->getMessage(), 500);
}

