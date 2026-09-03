<?php
if (!isConnect()) {
    throw new Exception('401 Unauthorized');
}
?>
<div role="tabpanel" class="tab-pane" id="scheduletab">
    <div style="text-align:right; margin:10px;">
        <a class="btn btn-default" id="bt_testSchedule"><i class="fas fa-flask"></i> Tester la configuration</a>
        <a class="btn btn-success" id="bt_saveSchedule"><i class="fas fa-check-circle"></i> Sauvegarder la programmation</a>
    </div>
    <div class="alert alert-warning" style="margin:10px;">
        <i class="fas fa-exclamation-triangle"></i>
        Cet onglet a son <strong>propre bouton "Sauvegarder"</strong> ci-dessus — le bouton "Sauvegarder" tout en haut de la page (natif Jeedom) ne s'applique qu'à l'onglet "Équipement" et n'enregistrera pas cette programmation.
    </div>

    <div id="schedule_next_mow" class="alert alert-info" style="display:none; margin:10px;"></div>

    <div class="alert alert-default" style="margin:10px; border:1px solid #ddd;">
        <strong><i class="fas fa-tools"></i> Outils sur la dernière tonte enregistrée</strong>
        <div style="margin-top:8px;">
            <a class="btn btn-warning btn-sm" id="bt_debugMowYesterday"><i class="fas fa-bug"></i> [Débogage] Régler la dernière tonte à hier</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 10px 0;">Permet de tester le déclenchement le jour même sans attendre l'espacement complet. À utiliser uniquement pour vérifier que le robot démarre bien selon les seuils paramétrés.</span>
        </div>
        <div>
            <a class="btn btn-default btn-sm" id="bt_markMowToday"><i class="fas fa-check"></i> Marquer la tonte d'aujourd'hui comme faite</a>
            <span class="help-block" style="display:inline-block; margin:4px 0 0 0;">À utiliser si tu as lancé une tonte manuellement (hors programmation) : évite que le robot ne reparte une seconde fois le même jour à cause d'une dernière tonte trop ancienne en mémoire.</span>
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
                <div class="col-sm-3"></div>
                <div class="col-sm-9">
                    <span class="help-block">Le robot ne démarrera plus une tonte si elle risque de se terminer après (heure de fin − cette marge). Ex : fin=20h, marge=180min → dernier départ possible 17h.
                    <br>💡 Astuce : en combinant l'heure de fin avec le <strong>coucher du soleil</strong> (fourni par un plugin météo externe) et une marge proche du <strong>temps de tonte habituel</strong> de ta pelouse, tu évites que le robot termine (ou soit encore dehors) une fois la nuit tombée.
                    <br>🌧️ Cette même durée sert aussi à détecter une pluie survenant <strong>pendant</strong> une tonte en cours : si c'est le cas, la tonte du jour est considérée comme non effectuée, et le robot ne pourra pas redémarrer avant le délai réglé ci-dessous (section Pluie) — même si l'humidité redescend entre temps — le temps que le sol absorbe la pluie.</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-calendar-alt"></i> Espacement</legend>
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
                    <span class="help-block">Utilise le capteur pluie natif de la tondeuse (lecture seule ici). Pour que ça fonctionne de façon fiable, pense à régler le <strong>délai pluie à 0</strong> directement dans l'application Worx.</span>
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
                    <input type="number" id="sched_rain_interrupt_minutes" class="form-control" min="40" max="120" style="width:80px;">
                    <span>min (40 à 120, soit 40min à 2h)</span>
                </div>
                <div class="col-sm-3"></div>
                <div class="col-sm-9">
                    <span class="help-block">Si la pluie interrompt une tonte en cours (voir ci-dessus), le robot attend ce délai avant de retenter — même si l'humidité redescend sous le seuil entre temps — le temps que le sol absorbe l'eau. Le minimum de 40 min laisse le temps à un plugin météo externe de rafraîchir sa mesure d'humidité (certains ne se mettent à jour que toutes les 30 min). L'humidité est quand même re-testée normalement à chaque cycle une fois ce délai écoulé. Valeur par défaut : 60 min (1h).</span>
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
            <legend><i class="fas fa-battery-half"></i> Batterie</legend>
            <div class="help-block" style="margin:0 15px 10px;">La commande de batterie du robot ("Batterie", créée automatiquement par le plugin) est utilisée directement — rien à sélectionner ici. Évite un cas piégeux : si la batterie du robot est trop faible, l'app Worx refuse en interne de démarrer la tonte, mais le plugin ne le sait pas et considérerait à tort que la tonte a eu lieu. Ce seuil bloque donc le déclenchement (avec revérification de toutes les conditions une fois la batterie remontée) plutôt que d'envoyer un ordre de démarrage qui échouerait silencieusement côté robot.</div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Seuil minimum</label>
                <div class="col-sm-6" style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="sched_battery_min_percent" class="form-control" min="20" max="100" style="width:80px;">
                    <span>% (20 à 100)</span>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><i class="fas fa-thermometer-half"></i> Température (optionnel)</legend>
            <div class="help-block" style="margin:0 15px 10px;">Optionnel. Doit provenir d'un capteur externe (station météo) ou d'un plugin météo tiers — la valeur n'est jamais saisie à la main puisqu'elle changerait sans arrêt. Si tu sélectionnes une commande, le robot ne tondra pas si la température est en dehors de la plage seuil min/max. Si le champ reste vide, la température n'est pas prise en compte du tout.</div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Commande de température</label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <input type="text" id="sched_temperature_cmd_id" class="form-control" placeholder="tag de commande">
                        <span class="input-group-btn"><a class="btn btn-success bt_openCmdPicker" data-target="#sched_temperature_cmd_id" data-cmdtype="info" data-cmdsubtype="numeric"><i class="fa fa-list-alt"></i></a></span>
                    </div>
                    <span class="help-block">Capteur externe (station météo) ou plugin météo — ex: température extérieure. Doit obligatoirement pointer vers une commande Jeedom (pas de valeur fixe saisie à la main). Laisser vide pour ignorer ce critère.</span>
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
            <legend><i class="fas fa-cloud-sun"></i> Météo</legend>
            <div class="help-block" style="margin:0 15px 10px;">
                Nécessite un plugin météo tiers fournissant un code numérique de type OpenWeatherMap/WeatherAPI — par exemple
                <strong>"Weather Forecast, CAP alerts"</strong> (disponible sur le Market) ou le plugin météo officiel Jeedom.
                Ces plugins peuvent aussi fournir l'humidité extérieure à utiliser ci-dessus.
                <strong>Rappel : le robot ne tond automatiquement que par beau temps</strong>, c'est-à-dire les codes
                <a href="https://openweathermap.org/api/weather-conditions" target="_blank">800 à 804 (OpenWeatherMap)</a> ou
                <a href="https://www.weatherapi.com/docs/weather_conditions.json" target="_blank">1000 à 1009 (WeatherAPI)</a>
                — clique sur ces liens pour voir la liste complète des codes de chaque service.
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
                    <tr><th style="width:35%;">Commande</th><th style="width:25%;">Titre</th><th style="width:15%;">Format HTML (&lt;br/&gt;)</th><th></th></tr>
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
        <td>
            <a class="btn btn-default btn-xs bt_testNotifRow"><i class="fas fa-paper-plane"></i></a>
            <a class="btn btn-danger btn-xs bt_removeRow"><i class="fas fa-trash"></i></a>
        </td>
    </tr>
</table>
