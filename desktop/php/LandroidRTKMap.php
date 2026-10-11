<?php
if (!isConnect()) {
    throw new Exception('401 Unauthorized');
}
?>
<div role="tabpanel" class="tab-pane" id="maptab">
    <div style="text-align:left; margin:10px;">
        <a class="btn btn-default" id="bt_lrtkMapRefresh"><i class="fas fa-sync"></i> {{Rafraîchir}}</a>
        <a class="btn btn-default" id="bt_lrtkMapPng" style="margin-left:5px;" title="{{Enregistre la carte avec la trace actuelle en image PNG (celle qui est jointe à la notification de fin de tonte)}}"><i class="fas fa-image"></i> {{Enregistrer l'image (PNG)}}</a>
        <label class="checkbox-inline" style="margin-left:10px;"><input type="checkbox" id="cb_lrtkMapAuto" checked> {{Rafraîchir automatiquement (toutes les 15 s)}}</label>
        <a class="btn btn-default pull-right" id="bt_lrtkDiag" title="{{Génère un fichier zip anonymisé (coordonnées décalées, identifiants masqués) à transmettre pour le diagnostic de la carte}}"><i class="fas fa-file-archive"></i> {{Exporter les données de diagnostic}}</a>
    </div>

    <div id="lrtk_map_status" class="alert alert-info" style="margin:10px;">{{Chargement de la carte…}}</div>

    <div id="lrtk_map_container" style="margin:10px; max-width:960px;"></div>

    <div style="margin:10px; font-size:0.9em; opacity:0.85;">
        <span style="display:inline-block; width:12px; height:12px; background:#008b3a; border:2px solid #ff6045; vertical-align:middle;"></span> {{Zone de tonte}} &nbsp;
        <span style="display:inline-block; width:12px; height:12px; background:#b66b36; vertical-align:middle;"></span> {{Exclusion}} &nbsp;
        <span style="display:inline-block; width:12px; height:12px; background:#005726; vertical-align:middle;"></span> {{Passages du jour}} &nbsp;
        <span style="display:inline-block; width:12px; height:12px; background:#f47b20; border-radius:3px; vertical-align:middle;"></span> {{Robot}} &nbsp;
        <span style="display:inline-block; width:12px; height:12px; background:#70380f; border-radius:50%; vertical-align:middle;"></span> {{Station}}
    </div>

    <div class="alert alert-warning" style="margin:10px;">
        <i class="fas fa-lock"></i>
        {{Les coordonnées RTK sont précises au centimètre : elles restent sur votre Jeedom, ne sont jamais écrites dans les logs, et la carte est dessinée sans fond de plan externe. Ne partagez pas de capture d'écran de cette carte.}}
    </div>

    <div id="lrtk_diag_result" class="alert alert-success" style="display:none; margin:10px;"></div>
</div>
