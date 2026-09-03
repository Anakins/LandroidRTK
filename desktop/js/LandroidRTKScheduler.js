/* JS de la Programmation automatique de tonte (Landroid RTK) */
/* Fichier VOLONTAIREMENT séparé de LandroidRTK.js.                  */

var LandroidRTKScheduler_currentEqLogicId = null;

/* ------------------------------------------------------------------ */
/* Greffe sur printEqLogic() (déjà défini dans LandroidRTK.js) sans le */
/* modifier : on l'enveloppe pour ajouter le chargement de la          */
/* programmation à chaque affichage d'un équipement.                   */
/* ------------------------------------------------------------------ */
(function () {
    var original = (typeof printEqLogic === 'function') ? printEqLogic : function () {};
    printEqLogic = function (_eqLogic) {
        original(_eqLogic);
        if (_eqLogic && _eqLogic.id) {
            LandroidRTKScheduler_currentEqLogicId = _eqLogic.id;
            LandroidRTKScheduler_loadConfig(_eqLogic.id);
        }
    };
})();

/* ------------------------------------------------------------------ */
/* Chargement de la configuration existante dans le formulaire         */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_loadConfig(_eqLogic_id) {
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {action: 'getSchedule', id: _eqLogic_id, apikey: LandroidRTKApikey},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                return;
            }
            LandroidRTKScheduler_fillForm(data.result);
            // Vérification silencieuse à l'ouverture de l'onglet (sans
            // envoyer de notif de test) : si la programmation est active
            // mais qu'un équipement requis a disparu depuis (plugin
            // météo tiers désinstallé, capteur supprimé...), on le
            // signale tout de suite ici, en plus de l'avertissement déjà
            // envoyé dans le Centre de Messages Jeedom par le cron.
            if (data.result.enabled == '1') {
                LandroidRTKScheduler_checkCurrentConfigSilently();
            }
        }
    });
}

function LandroidRTKScheduler_checkCurrentConfigSilently() {
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'validateSchedule',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
            config: JSON.stringify(LandroidRTKScheduler_buildConfig()),
        },
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok' && data.result && data.result.errors && data.result.errors.length) {
                LandroidRTKScheduler_showResults(data.result.errors, data.result.warnings || []);
            }
        }
    });
}

function LandroidRTKScheduler_fillForm(_config) {
    $('#sched_enabled').prop('checked', _config.enabled == '1');
    $('#sched_time_start_cmd_id').val(_config.time_start_cmd_id || '800');
    $('#sched_time_end_cmd_id').val(_config.time_end_cmd_id || '1700');
    $('#sched_margin_minutes').val(_config.margin_minutes != null ? _config.margin_minutes : 0);
    $('#sched_spacing_days').val(_config.spacing_days || 1);

    $('#sched_rain_own_enabled').prop('checked', _config.rain_own_enabled == '1');
    $('#sched_rain_extra_cmd_id').val(_config.rain_extra_cmd_id || '');
    $('#sched_rain_extra_operator').val(_config.rain_extra_operator || '==');
    $('#sched_rain_extra_value').val(_config.rain_extra_value || '');
    $('#sched_rain_interrupt_minutes').val(_config.rain_interrupt_minutes != null ? _config.rain_interrupt_minutes : 60);

    $('#sched_humidity_cmd_id').val(_config.humidity_cmd_id || '');
    $('#sched_humidity_threshold').val(_config.humidity_threshold != null ? _config.humidity_threshold : 65);
    $('#sched_humidity_duration_minutes').val(_config.humidity_duration_minutes != null ? _config.humidity_duration_minutes : 180);
    $('#sched_battery_min_percent').val(_config.battery_min_percent != null ? _config.battery_min_percent : 30);
    $('#sched_temperature_cmd_id').val(_config.temperature_cmd_id || '');
    $('#sched_temperature_min').val(_config.temperature_min != null ? _config.temperature_min : 10);
    $('#sched_temperature_max').val(_config.temperature_max != null ? _config.temperature_max : 40);

    $('#sched_condition_id_cmd_id').val(_config.condition_id_cmd_id || '');
    $('#sched_condition_cmd_id').val(_config.condition_cmd_id || '');

    $('#table_notifications tbody').empty();
    if (_config.notifications && _config.notifications.length) {
        for (var i = 0; i < _config.notifications.length; i++) {
            LandroidRTKScheduler_addNotificationRow(_config.notifications[i]);
        }
    }

    LandroidRTKScheduler_hideResults();
    LandroidRTKScheduler_refreshAllPreviews();
    LandroidRTKScheduler_refreshNextMow();
}

/* ------------------------------------------------------------------ */
/* Liste dynamique des notifications                                   */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_addNotificationRow(_notif) {
    _notif = _notif || {};
    var $tr = $('.notificationTemplate').clone();
    $tr.removeClass('notificationTemplate').show();
    $tr.find('.notif_cmd_id').val(_notif.cmd_id || '');
    $tr.find('.notif_title').val(_notif.title || '');
    $tr.find('.notif_html').prop('checked', _notif.html == '1');
    $('#table_notifications tbody').append($tr);
    LandroidRTKScheduler_refreshPreview($tr.find('.notif_cmd_id'));
}

$(document).on('click', '#bt_addNotification', function (e) {
    e.preventDefault();
    LandroidRTKScheduler_addNotificationRow({});
});

$(document).on('click', '#table_notifications .bt_removeRow', function (e) {
    e.preventDefault();
    $(this).closest('tr').remove();
});

$(document).on('click', '#table_notifications .bt_testNotifRow', function (e) {
    e.preventDefault();
    var $tr = $(this).closest('tr');
    var cmd_raw = $tr.find('.notif_cmd_id').val();
    if (!cmd_raw) {
        $.fn.showAlert({message: '{{Renseigne une commande avant de tester cette ligne}}', level: 'warning'});
        return;
    }
    var config = LandroidRTKScheduler_buildConfig();
    config.notifications = [{cmd_id: cmd_raw, title: $tr.find('.notif_title').val(), html: $tr.find('.notif_html').is(':checked') ? '1' : '0'}];
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {action: 'testSchedule', id: LandroidRTKScheduler_currentEqLogicId, apikey: LandroidRTKApikey, config: JSON.stringify(config)},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state == 'ok' && (!data.result.errors || !data.result.errors.length)) {
                $.fn.showAlert({message: '{{Message de test envoyé}}', level: 'success'});
            } else {
                var errs = (data.result && data.result.errors) ? data.result.errors : ['{{Échec de l\'envoi}}'];
                $.fn.showAlert({message: errs.join(' '), level: 'danger'});
            }
        }
    });
});

/* ------------------------------------------------------------------ */
/* Aperçu en direct de la valeur d'une commande, affiché juste à côté  */
/* du champ (icône verte + valeur, ou icône rouge + "Erreur").          */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_appendConditionLabel($preview, rawValue) {
    var code = rawValue.toString().match(/\d+/);
    if (!code) {
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {action: 'conditionCodeLabel', apikey: LandroidRTKApikey, code: code[0]},
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok' && data.result.label) {
                $preview.append(' <span class="text-muted">— ' + data.result.label + '</span>');
            }
        }
    });
}

function LandroidRTKScheduler_appendRainComparison($preview, currentValue) {
    var operator = $('#sched_rain_extra_operator').val();
    var expected = $('#sched_rain_extra_value').val();
    if (expected === '') {
        return;
    }
    $preview.append(' <span class="text-muted">— comparaison : "' + currentValue + '" ' + (operator == '!=' ? '≠' : '==') + ' "' + expected + '"</span>');
}

function LandroidRTKScheduler_refreshPreview($input) {
    var raw = $input.val();
    var $preview;
    var inputId = $input.attr('id');
    if (inputId) {
        // Cas standard : le span d'aperçu référence l'input via data-input,
        // peu importe où il se trouve dans le DOM (permet de l'afficher à
        // droite du champ plutôt qu'en dessous).
        $preview = $('.cmdValuePreview[data-input="#' + inputId + '"]');
    }
    if (!$preview || !$preview.length) {
        // Cas des lignes de notification (input sans id fixe, clonées) :
        // le span est un frère du input-group, dans la même cellule.
        $preview = $input.closest('td').find('.cmdValuePreview');
    }
    if (!raw) {
        $preview.html('');
        return;
    }
    var isTime = $input.hasClass('cmdOrValue');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'previewValue',
            apikey: LandroidRTKApikey,
            raw: raw,
            isTime: isTime ? '1' : '0',
            min: $preview.data('min') != null ? $preview.data('min') : '',
            max: $preview.data('max') != null ? $preview.data('max') : '',
        },
        dataType: 'json',
        success: function (data) {
            if (data.state != 'ok') {
                $preview.html('');
                return;
            }
            var r = data.result;
            if (r.valid === null) {
                $preview.html('');
            } else if (r.valid) {
                $preview.html('<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> ' + r.value + '</span>');
                if ($input.attr('id') == 'sched_condition_id_cmd_id' && r.value != null) {
                    LandroidRTKScheduler_appendConditionLabel($preview, r.value);
                }
                if ($input.attr('id') == 'sched_rain_extra_cmd_id') {
                    LandroidRTKScheduler_appendRainComparison($preview, r.value);
                }
            } else {
                $preview.html('<span style="color:#a94442;"><i class="fas fa-times-circle"></i> ' + (r.error || 'Erreur') + (r.value ? ' (' + r.value + ')' : '') + '</span>');
            }
        }
    });
}

$(document).on('change blur', '#scheduletab input[type=text].form-control, #scheduletab .notif_cmd_id', function () {
    LandroidRTKScheduler_refreshPreview($(this));
});

$(document).on('change blur', '#sched_rain_extra_operator, #sched_rain_extra_value', function () {
    LandroidRTKScheduler_refreshPreview($('#sched_rain_extra_cmd_id'));
});

function LandroidRTKScheduler_refreshAllPreviews() {
    $('#scheduletab input[type=text].form-control, #scheduletab .notif_cmd_id').each(function () {
        LandroidRTKScheduler_refreshPreview($(this));
    });
}

/* ------------------------------------------------------------------ */
/* Estimation de la prochaine tonte                                    */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_refreshNextMow() {
    var config = LandroidRTKScheduler_buildConfig();
    if (config.enabled != '1') {
        $('#schedule_next_mow').html('<i class="fas fa-info-circle"></i> Active la programmation ci-dessus pour voir l\'estimation de la prochaine tonte.').removeClass('alert-info').addClass('alert-warning').show();
        return;
    }
    $('#schedule_next_mow').removeClass('alert-warning').addClass('alert-info');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'nextMowEstimate',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
            config: JSON.stringify(config),
        },
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok' && data.result && data.result.text) {
                $('#schedule_next_mow').html('<i class="fas fa-hourglass-half"></i> ' + data.result.text).show();
            } else {
                $('#schedule_next_mow').hide();
            }
        }
    });
}

/* ------------------------------------------------------------------ */
/* Sélecteur natif de commande Jeedom (jeedom.cmd.getSelectModal), le   */
/* même mécanisme que tous les scénarios/plugins Jeedom (confirmé       */
/* directement depuis le code source officiel du plugin Alarme et du   */
/* core). "result.human" renvoie le tag #[Objet][Équipement][Commande]#.*/
/* ------------------------------------------------------------------ */
$(document).on('click', '.bt_openCmdPicker', function (e) {
    e.preventDefault();
    var $bt = $(this);
    var $target;
    if ($bt.data('target-self')) {
        $target = $bt.closest('td').find('input');
    } else {
        $target = $($bt.data('target'));
    }
    var filter = {type: $bt.data('cmdtype') || 'info'};
    if ($bt.data('cmdsubtype')) {
        filter.subType = $bt.data('cmdsubtype');
    }
    jeedom.cmd.getSelectModal({cmd: filter}, function (result) {
        if (result && result.human) {
            $target.value(result.human);
            LandroidRTKScheduler_refreshPreview($target);
        }
    });
});

/* ------------------------------------------------------------------ */
/* Construction du JSON de configuration à partir du formulaire        */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_buildConfig() {
    var notifications = [];
    $('#table_notifications tbody tr').each(function () {
        var $tr = $(this);
        notifications.push({
            cmd_id: $tr.find('.notif_cmd_id').val(),
            title: $tr.find('.notif_title').val(),
            html: $tr.find('.notif_html').is(':checked') ? '1' : '0',
        });
    });

    return {
        enabled: $('#sched_enabled').is(':checked') ? '1' : '0',
        time_start_cmd_id: $('#sched_time_start_cmd_id').val(),
        time_end_cmd_id: $('#sched_time_end_cmd_id').val(),
        margin_minutes: $('#sched_margin_minutes').val(),
        spacing_days: $('#sched_spacing_days').val(),
        rain_own_enabled: $('#sched_rain_own_enabled').is(':checked') ? '1' : '0',
        rain_extra_cmd_id: $('#sched_rain_extra_cmd_id').val(),
        rain_extra_operator: $('#sched_rain_extra_operator').val(),
        rain_extra_value: $('#sched_rain_extra_value').val(),
        rain_interrupt_minutes: $('#sched_rain_interrupt_minutes').val(),
        humidity_cmd_id: $('#sched_humidity_cmd_id').val(),
        humidity_threshold: $('#sched_humidity_threshold').val(),
        humidity_duration_minutes: $('#sched_humidity_duration_minutes').val(),
        battery_min_percent: $('#sched_battery_min_percent').val(),
        temperature_cmd_id: $('#sched_temperature_cmd_id').val(),
        temperature_min: $('#sched_temperature_min').val(),
        temperature_max: $('#sched_temperature_max').val(),
        condition_id_cmd_id: $('#sched_condition_id_cmd_id').val(),
        condition_cmd_id: $('#sched_condition_cmd_id').val(),
        notifications: notifications,
    };
}

/* ------------------------------------------------------------------ */
/* Affichage des résultats (erreurs / avertissements / succès)         */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_hideResults() {
    $('#schedule_test_errors, #schedule_test_warnings, #schedule_test_success').hide();
}

function LandroidRTKScheduler_formatList(_items) {
    var html = '';
    for (var i = 0; i < _items.length; i++) {
        html += '<div>- ' + _items[i] + '</div>';
    }
    return html;
}

function LandroidRTKScheduler_showResults(_errors, _warnings) {
    LandroidRTKScheduler_hideResults();
    var hasErrors = _errors && _errors.length;
    var hasWarnings = _warnings && _warnings.length;
    if (hasErrors) {
        $('#schedule_test_errors').html('<i class="fas fa-times-circle"></i> ' + LandroidRTKScheduler_formatList(_errors)).show();
    }
    if (hasWarnings) {
        $('#schedule_test_warnings').html('<i class="fas fa-exclamation-triangle"></i> ' + LandroidRTKScheduler_formatList(_warnings)).show();
    }
    if (!hasErrors) {
        $('#schedule_test_success').show();
    }
}

/* ------------------------------------------------------------------ */
/* Bouton "Tester"                                                     */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_testSchedule', function (e) {
    e.preventDefault();
    var $bt = $(this);
    $bt.find('i').removeClass('fa-flask').addClass('fa-spinner fa-spin');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'testSchedule',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
            config: JSON.stringify(LandroidRTKScheduler_buildConfig()),
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-flask');
            LandroidRTKScheduler_showResults(['Erreur de communication avec le serveur, voir la console/logs.'], []);
        },
        success: function (data) {
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-flask');
            if (data.state != 'ok') {
                LandroidRTKScheduler_showResults([data.result || 'Échec du test (erreur serveur, voir logs LandroidRTK).'], []);
                return;
            }
            LandroidRTKScheduler_showResults(data.result.errors, data.result.warnings);
        }
    });
});

/* ------------------------------------------------------------------ */
/* Case "Activer" : ne reste cochée que si un test passe               */
/* ------------------------------------------------------------------ */
$(document).on('change', '#sched_enabled', function () {
    var $chk = $(this);
    if (!$chk.is(':checked')) {
        return; // désactiver est toujours possible, pas de vérification
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'validateSchedule',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
            config: JSON.stringify(LandroidRTKScheduler_buildConfig()),
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $chk.prop('checked', false);
        },
        success: function (data) {
            if (data.state != 'ok' || !data.result.valid) {
                $chk.prop('checked', false);
                LandroidRTKScheduler_showResults(
                    (data.result && data.result.errors && data.result.errors.length) ? data.result.errors : ['Configuration incomplète — vérifie les champs ci-dessus.'],
                    (data.result && data.result.warnings) || []
                );
            } else {
                LandroidRTKScheduler_showResults(data.result.errors, data.result.warnings);
            }
        }
    });
});

/* ------------------------------------------------------------------ */
/* Bouton "Sauvegarder"                                                 */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_saveSchedule', function (e) {
    e.preventDefault();
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'saveSchedule',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
            config: JSON.stringify(LandroidRTKScheduler_buildConfig()),
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            LandroidRTKScheduler_showResults(['Erreur de communication avec le serveur, voir la console/logs.'], []);
        },
        success: function (data) {
            if (data.state != 'ok') {
                LandroidRTKScheduler_showResults([data.result || 'Échec de la sauvegarde (erreur serveur, voir logs LandroidRTK).'], []);
                return;
            }
            if (data.result.errors && data.result.errors.length) {
                $('#sched_enabled').prop('checked', false);
                LandroidRTKScheduler_showResults(data.result.errors, []);
                $.fn.showAlert({message: '{{Programmation sauvegardée, mais pas activée (configuration incomplète)}}', level: 'warning'});
            } else {
                LandroidRTKScheduler_hideResults();
                $.fn.showAlert({message: '{{Programmation sauvegardée}}', level: 'success'});
                LandroidRTKScheduler_refreshNextMow();
            }
        }
    });
});

/* ------------------------------------------------------------------ */
/* [Débogage] "Régler la dernière tonte à hier"                        */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_debugMowYesterday', function (e) {
    e.preventDefault();
    if (!confirm('{{[Débogage] Forcer la dernière tonte enregistrée à hier ? Utile uniquement pour tester le déclenchement le jour même.}}')) {
        return;
    }
    var $bt = $(this);
    $bt.find('i').removeClass('fa-bug').addClass('fa-spinner fa-spin');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'debugMowYesterday',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-bug');
        },
        success: function (data) {
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-bug');
            if (data.state != 'ok') {
                $.fn.showAlert({message: '{{Échec (voir logs LandroidRTK)}}', level: 'danger'});
                return;
            }
            $.fn.showAlert({message: '{{Dernière tonte forcée à hier (}}' + data.result.last_mow_date + ')', level: 'warning'});
            LandroidRTKScheduler_refreshNextMow();
        }
    });
});

/* ------------------------------------------------------------------ */
/* "Marquer la tonte d'aujourd'hui comme faite"                        */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_markMowToday', function (e) {
    e.preventDefault();
    if (!confirm('{{Marquer la tonte d\'aujourd\'hui comme faite ? À utiliser après une tonte lancée manuellement, pour éviter un second démarrage automatique le même jour.}}')) {
        return;
    }
    var $bt = $(this);
    $bt.find('i').removeClass('fa-check').addClass('fa-spinner fa-spin');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'markMowToday',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-check');
        },
        success: function (data) {
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-check');
            if (data.state != 'ok') {
                $.fn.showAlert({message: '{{Échec (voir logs LandroidRTK)}}', level: 'danger'});
                return;
            }
            $.fn.showAlert({message: '{{Tonte du jour marquée comme faite}}', level: 'success'});
            LandroidRTKScheduler_refreshNextMow();
        }
    });
});
