<?php
/*
 * Image SVG de la vignette de carte affichée dans le widget du dashboard.
 * Lecture seule : réservée aux utilisateurs connectés qui ont le droit de
 * voir l'équipement. Répond 404 tant qu'aucune carte n'est disponible.
 */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/LandroidRTK.class.php';
// Démarre la session Jeedom (sans cela isConnect() est toujours faux) et reconnaît un cookie « se souvenir de moi ».
include_file('core', 'authentification', 'php');

function lrtk_map_fail($code) {
    http_response_code($code);
    header('Cache-Control: no-store');
    exit;
}

try {
    if (!isConnect()) {
        lrtk_map_fail(401);
    }
    $eqLogic = eqLogic::byId(init('id'));
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'LandroidRTK') {
        lrtk_map_fail(404);
    }
    if (method_exists($eqLogic, 'hasRight') && !$eqLogic->hasRight('r')) {
        lrtk_map_fail(403);
    }
    $svg = LandroidRTKMapSvg::render($eqLogic->getMapData());
    if ($svg === null) {
        lrtk_map_fail(404);
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo $svg;
} catch (\Throwable $e) {
    log::add('LandroidRTK', 'debug', 'LandroidRTKMap.php : ' . get_class($e) . ' : ' . $e->getMessage());
    lrtk_map_fail(500);
}
