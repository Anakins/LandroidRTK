<?php

function LandroidRTK_install() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    // Ne régénère la clé QUE si elle n'existe pas encore : sinon, chaque
    // simple désactivation/réactivation invaliderait la clé déjà utilisée
    // par un démon en cours d'exécution (d'où des 403 côté démon).
    if (config::byKey('api', $pluginId, '') == '') {
        config::save('api', config::genKey(), $pluginId);
        config::save("api::{$pluginId}::mode", 'localhost');
        config::save("api::{$pluginId}::restricted", 1);
    }
    // Heartbeat natif Jeedom : alerte automatique dans le centre de
    // messages si aucun équipement n'a communiqué depuis 24h (1440 min).
    // Configuré seulement s'il n'existe pas déjà, pour respecter un choix
    // que l'utilisateur aurait fait lui-même dans l'interface.
    if (config::byKey('heartbeat', $pluginId, '') === '') {
        config::save('heartbeat', 1440, $pluginId);
    }
    // Fixe notre PROPRE seuil de log (indépendant du réglage global du
    // système, qui bloque manifestement tout ce qui est sous "error").
    // "100" = seuil le plus bas (debug et au-dessus) : rien de ce qu'on
    // journalise n'est filtré. Appliqué SANS condition à chaque
    // install/update, pour écraser une éventuelle ancienne valeur mal
    // enregistrée (avec une portée incorrecte) lors d'un essai précédent.
    config::save('log::level::LandroidRTK', '{"100":"1","200":"0","300":"0","400":"0","1000":"0","default":"0"}');
    LandroidRTK::logImportant('Installation du plugin Worx Vision');
}

function LandroidRTK_update() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    if (config::byKey('api', $pluginId, '') == '') {
        config::save('api', config::genKey(), $pluginId);
        config::save("api::{$pluginId}::mode", 'localhost');
        config::save("api::{$pluginId}::restricted", 1);
    }
    if (config::byKey('heartbeat', $pluginId, '') === '') {
        config::save('heartbeat', 1440, $pluginId);
    }
    config::save('log::level::LandroidRTK', '{"100":"1","200":"0","300":"0","400":"0","1000":"0","default":"0"}');
    LandroidRTK::logImportant('Mise à jour du plugin Worx Vision');
}

function LandroidRTK_remove() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    config::remove('api', $pluginId);
    config::remove("api::{$pluginId}::mode");
    config::remove("api::{$pluginId}::restricted");
    LandroidRTK::logImportant('Suppression du plugin Worx Vision');
}

/*
 * Pas de fonctions LandroidRTK_dependancy_info()/LandroidRTK_dependancy_install()
 * libres ici : la vraie convention (confirmée par le code réel de
 * worxLandroidS : "worxLandroidS::dependancy_info()") est d'implémenter ces
 * méthodes en STATIQUE sur la classe principale du plugin (voir
 * core/class/LandroidRTK.class.php), pas comme fonctions procédurales.
 */
