<?php
if (!isConnect()) {
    throw new Exception('401 Unauthorized');
}
?>
<div role="tabpanel" class="tab-pane" id="scheduletab">
    <div style="text-align:left; margin:10px;">
        <a class="btn btn-default" id="bt_testSchedule"><i class="fas fa-flask"></i> Tester la configuration</a>
        <a class="btn btn-success" id="bt_saveSchedule"><i class="fas fa-check-circle"></i> Sauvegarder la programmation</a>
    </div>
    <div class="alert alert-warning" style="margin:10px;">
        <i class="fas fa-exclamation-triangle"></i>
        Cet onglet a son <strong>propre bouton "Sauvegarder"</strong> ci-dessus, en haut à gauche — le bouton "Sauvegarder" en haut à droite de la page (natif Jeedom) ne s'applique qu'à l'onglet "Équipement" et n'enregistrera pas cette programmation.
    </div>

    <div id="schedule_next_mow" class="alert alert-info" style="display:none; margin:10px;"></div>

    <div id="schedule_conditions_status" style="display:none; margin:10px; border:1px solid #ddd; border-radius:4px; padding:10px; background:#fff;">
        <strong><i class="fas fa-list-check"></i> État des conditions de démarrage</strong>
        <a class="pull-right cursor" id="bt_refreshConditionsStatus" title="Rafraîchir"><i class="fas fa-sync"></i></a>
        <table class="table table-condensed" style="margin-top:8px; margin-bottom:0; table-layout:fixed; width:100%;">
            <thead>
                <tr>
                    <th style="width:50%;">Condition</th>
                    <th style="width:50%;">État</th>
                </tr>
            </thead>
            <tbody id="schedule_conditions_status_body"></tbody>
        </table>
    </div>

    <div class="alert alert-default" style="margin:10px; border:1px solid #ddd;">
        <strong><i class="fas fa-tools"></i> Outils sur la dernière tonte enregistrée</strong>
        <div style="margin-top:8px;">
            <a class="btn btn-warning btn-sm" id="bt_debugMowYesterday"><i class="fas fa-bug"></i> [Débogage] Régler la dernière tonte à hier</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 10px 0;">Permet de tester le déclenchement le jour même sans attendre l'espacement complet. À utiliser uniquement pour vérifier que le robot démarre bien selon les seuils paramétrés.</span>
        </div>
        <div>
            <a class="btn btn-default btn-sm" id="bt_markMowToday"><i class="fas fa-check"></i> Marquer la tonte d'aujourd'hui comme faite</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 0 0;">À utiliser si vous avez lancé une tonte manuellement (hors programmation) : évite que le robot ne reparte une seconde fois le même jour à cause d'une dernière tonte trop ancienne en mémoire.</span>
        </div>
    </div>

    <div id="schedule_errors" class="alert alert-danger" style="display:none; margin:10px;"></div>

    <form class="form-horizontal" id="schedule_form">
        <fieldset>
            <legend><i class="fas fa-clock"></i> Activation</legend>
            <div class="form-group">
                <label class="col-sm-3 control-label">Activer la programmation</label>
                <div class="col-sm-6">
                    <input type="checkbox" id="sched_enabled">
                    <span class="help-block">Ne peut être activée que si le bouton "Tester" ci-dessous ne renvoie aucune erreur.</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-sun"></i> Plage horaire</legend>
            <div class="form-group">
                <label class="col-sm-3 control-label">Heure de début</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_time_start_cmd_id" class="form-control cmdOrValue" placeholder="800">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_time_start_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Soit un tag de commande (ex: lever du soleil du plugin météo officiel), soit une heure fixe au format HMM/HHMM sans séparateur (ex: <strong>800</strong> = 08h00, <strong>1730</strong> = 17h30).</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_time_start_cmd_id"></span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Heure de fin</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_time_end_cmd_id" class="form-control cmdOrValue" placeholder="1700">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_time_end_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Doit être postérieure à l'heure de début, avec au moins 60 minutes d'écart.</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_time_end_cmd_id"></span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Marge avant l'heure de fin</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_margin_minutes" class="form-control" min="0" max="600" style="width:80px;">
                    <span>min (0 à 600)</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span id="sched_latest_start_preview" class="text-muted"></span>
                </div>
                <div class="col-sm-9">
                    <span class="help-block">Le robot ne démarrera plus une tonte si elle risque de se terminer après (heure de fin − cette marge). Ex : fin=20h, marge=180min → dernier départ possible 17h.
                    <br>💡 Astuce : en combinant l'heure de fin avec le <strong>coucher du soleil</strong> (fourni par un plugin météo externe) et une marge proche du <strong>temps de tonte habituel</strong> de votre pelouse, vous évitez que le robot termine (ou soit encore dehors) une fois la nuit tombée.
                    <br>🌧️ Sert aussi à détecter une pluie pendant une tonte en cours : la tonte du jour est alors annulée, et le robot attend le délai réglé ci-dessous (section Pluie) avant de repartir.</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-calendar-alt"></i> Espacement jours de tontes</legend>
            <div class="form-group">
                <label class="col-sm-3 control-label">Tondre tous les</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_spacing_days" class="form-control" min="1" max="28" style="width:80px;">
                    <span>jours (1 à 28)</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-cloud-rain"></i> Pluie</legend>

            <div class="form-group">
                <label class="col-sm-3 control-label">Retour à la base en cas de pluie (capteur du robot)</label>
                <div class="col-sm-6">
                    <input type="checkbox" id="sched_rain_own_enabled">
                    <span class="help-block">Utilise le capteur pluie natif de la tondeuse (lecture seule ici). Pour que ça fonctionne de façon fiable, pensez à régler le <strong>délai pluie à 0</strong> directement dans l'application Worx.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">Capteur de pluie externe (optionnel)</label>
                <div class="col-sm-4">
                    <div class="input-group">
                        <input type="text" id="sched_rain_extra_cmd_id" class="form-control">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_rain_extra_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                </div>
                <div class="col-sm-1">
                    <select id="sched_rain_extra_operator" class="form-control">
                        <option value="==">==</option>
                        <option value="!=">≠</option>
                    </select>
                </div>
                <div class="col-sm-2">
                    <input type="text" id="sched_rain_extra_value" class="form-control" placeholder="valeur">
                </div>
                <div class="col-sm-2" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_rain_extra_cmd_id"></span>
                </div>
                <div class="col-sm-12">
                    <span class="help-block">Utilisé seulement si une commande est renseignée. Exemple : pour un pluviomètre Netatmo, utiliser "≠" avec la valeur "0" (il pleut quand le niveau de pluie est différent de 0).</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-3 control-label">Délai avant redémarrage après pluie</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_rain_interrupt_minutes" class="form-control" min="20" max="120" style="width:80px;">
                    <span>min (20 à 120)</span>
                </div>
                <div class="col-sm-3"></div>
                <div class="col-sm-9">
                    <span class="help-block">Délai minimum nécessaire à l'absorption de la pluie par le sol, et/ou au rafraîchissement des plugins météo (certains toutes les 30 min). Par défaut : 60 min.</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-tint"></i> Humidité</legend>
            <div class="help-block" style="margin:0 15px 10px;">Permet de ne tondre que quand le sol est relativement sec depuis un certain temps (la tondeuse n'abîme pas la pelouse en manœuvrant).</div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Commande d'humidité</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_humidity_cmd_id" class="form-control">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_humidity_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Doit renvoyer un nombre entre 0 et 100 (ex: capteur d'humidité extérieur d'une station météo).</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_humidity_cmd_id" data-min="0" data-max="100"></span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Seuil max</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_humidity_threshold" class="form-control" min="0" max="100" style="width:80px;">
                    <span>% (0 à 100)</span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Délai sous ce seuil</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_humidity_duration_minutes" class="form-control" min="0" max="300" style="width:80px;">
                    <span>min (0 à 300)</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-thermometer-half"></i> Température (optionnel)</legend>
            <div class="help-block" style="margin:0 15px 10px;">Optionnel. Doit provenir d'un capteur externe (station météo) ou d'un plugin météo tiers. Si le champ reste vide, la température n'est pas prise en compte du tout.</div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Commande de température</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_temperature_cmd_id" class="form-control" placeholder="tag de commande">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_temperature_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Capteur externe (station météo) ou plugin météo — ex: température extérieure. Doit obligatoirement pointer vers une commande Jeedom. Laisser vide pour ignorer ce critère.</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_temperature_cmd_id" data-min="-50" data-max="80"></span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Seuil minimum</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_temperature_min" class="form-control" min="6" max="18" style="width:80px;">
                    <span>°C (6 à 18)</span>
                </div>
                <div class="col-sm-3"></div>
                <div class="col-sm-9">
                    <span class="help-block">Protection gel : le robot ne tond pas en dessous de ce seuil, pour ne pas abîmer une pelouse potentiellement gelée. Par défaut : 10°C.</span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Seuil maximum</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_temperature_max" class="form-control" min="30" max="50" style="width:80px;">
                    <span>°C (30 à 50)</span>
                </div>
                <div class="col-sm-3"></div>
                <div class="col-sm-9">
                    <span class="help-block">Protection canicule : le robot ne tond pas au-dessus de ce seuil. Par défaut : 40°C.</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-battery-half"></i> Batterie</legend>
            <div class="help-block" style="margin:0 15px 10px;">Seuil minimum requis pour démarrer le robot, ou le redémarrer après une pluie.</div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Seuil minimum</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_battery_min_percent" class="form-control" min="20" max="100" style="width:80px;">
                    <span>% (20 à 100)</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-cloud-sun"></i> Météo</legend>
            <div class="help-block" style="margin:0 15px 10px;">
                Nécessite un plugin météo tiers fournissant un code numérique de type OpenWeatherMap/WeatherAPI — par exemple
                <strong>"Weather Forecast, CAP alerts"</strong> (disponible sur le Market) ou le plugin météo officiel Jeedom.
                Ces plugins peuvent aussi fournir l'humidité extérieure à utiliser ci-dessus.
                <br><strong>Rappel : le robot ne tond automatiquement que par beau temps</strong>, c'est-à-dire les codes
                <a href="https://openweathermap.org/api/weather-conditions" target="_blank">800 à 804 (OpenWeatherMap)</a> ou
                <a href="https://www.weatherapi.com/docs/weather_conditions.json" target="_blank">1000 à 1009 (WeatherAPI)</a>
                — cliquez sur ces liens pour voir la liste complète des codes de chaque service.
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Commande "condition_id"</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_condition_id_cmd_id" class="form-control">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_condition_id_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Code numérique OpenWeatherMap/WeatherAPI (ex: 800 = ciel dégagé). Fourni par le plugin météo officiel Jeedom ou tout équivalent.</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_condition_id_cmd_id"></span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Commande "condition" (texte)</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_condition_cmd_id" class="form-control">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_condition_cmd_id" data-cmdtype="info"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Description textuelle (ex: "ciel dégagé"), utilisée uniquement dans le texte des notifications.</span>
                </div>
                <div class="col-sm-3" style="padding-top:7px;">
                    <span class="cmdValuePreview text-muted" data-input="#sched_condition_cmd_id"></span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-bell"></i> Notifications</legend>
            <table class="table table-condensed" id="table_notifications">
                <thead>
                    <tr><th style="width:30%;">Commande</th><th style="width:22%;">Titre</th><th style="width:13%;">HTML (&lt;br/&gt;)</th><th style="width:20%;">Reçoit "pas de tonte"</th><th></th></tr>
                </thead>
                <tbody></tbody>
            </table>
            <a class="btn btn-default btn-sm" id="bt_addNotification"><i class="fas fa-plus"></i> Ajouter une notification</a>
        </fieldset>

        <div id="schedule_test_errors" class="alert alert-danger" style="display:none; margin:10px;"></div>
        <div id="schedule_test_warnings" class="alert alert-warning" style="display:none; margin:10px;"></div>
        <div id="schedule_test_success" class="alert alert-success" style="display:none; margin:10px;">
            <i class="fas fa-check-circle"></i> Configuration valide — la programmation peut être activée.
        </div>
    </form>
</div>

<!-- Gabarit (jamais affiché directement, cloné en JS) -->
<table style="display:none;">
    <tr class="notificationTemplate">
        <td>
            <div class="input-group">
                <input type="text" class="form-control notif_cmd_id">
                <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target-self="1" data-cmdtype="action"><i class="fa fa-list-alt"></i></a></span>
            </div>
            <span class="cmdValuePreview text-muted"></span>
        </td>
        <td><input type="text" class="form-control notif_title" placeholder="(nom de la tondeuse) - TONTE"></td>
        <td style="text-align:center;"><input type="checkbox" class="notif_html"></td>
        <td style="text-align:center;"><input type="checkbox" class="notif_no_mow" checked></td>
        <td>
            <a class="btn btn-default btn-xs bt_testNotifRow"><i class="fas fa-paper-plane"></i></a>
            <a class="btn btn-danger btn-xs bt_removeRow"><i class="fas fa-trash"></i></a>
        </td>
    </tr>
</table>
