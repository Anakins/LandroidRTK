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
            LandroidRTKScheduler_refreshTiming();
            LandroidRTKScheduler_updateNotifTitlePlaceholder(_eqLogic.name);
        }
    };
})();

/* ------------------------------------------------------------------ */
/* Notifications : titre par défaut suggéré = nom de l'équipement,     */
/* en direct (mêmes principes que RoboProg).                           */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_updateNotifTitlePlaceholder(_name) {
    var name = (typeof _name === 'string' ? _name : ($('.eqLogicAttr[data-l1key="name"]').val() || '')).trim();
    var placeholder = name ? (name.toUpperCase() + ' - TONTE') : '(nom de la tondeuse) - TONTE';
    $('#table_notifications .notif_title, .notificationTemplate .notif_title').attr('placeholder', placeholder);
}
$(document).on('input', '.eqLogicAttr[data-l1key="name"]', function () {
    LandroidRTKScheduler_updateNotifTitlePlaceholder();
});

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
                LandroidRTKScheduler_loadConditionsStatus();
                $('#schedule_conditions_status').show();
            } else {
                $('#schedule_conditions_status').hide();
            }
        }
    });
}

function LandroidRTKScheduler_loadConditionsStatus() {
    var $body = $('#schedule_conditions_status_body');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {action: 'conditionsStatus', id: LandroidRTKScheduler_currentEqLogicId, apikey: LandroidRTKApikey},
        dataType: 'json',
        success: function (data) {
            if (data.state != 'ok' || !data.result || !data.result.rows) {
                return;
            }
            $body.empty();
            $.each(data.result.rows, function (i, row) {
                var icon = row.ok
                    ? '<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> OK</span>'
                    : '<span style="color:#a94442;"><i class="fas fa-times-circle"></i> Non</span>';
                $body.append(
                    '<tr><td>' + row.label + '</td><td>' + icon
                    + (row.detail ? ' <span class="text-muted" style="font-size:0.9em;">(' + row.detail + ')</span>' : '')
                    + '</td></tr>'
                );
            });
        }
    });
}

$('#scheduletab').on('click', '#bt_refreshConditionsStatus', function () {
    LandroidRTKScheduler_loadConditionsStatus();
});

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
    $('#sched_mow_duration_minutes').val(_config.mow_duration_minutes != null ? _config.mow_duration_minutes : 120);
    $('#sched_timing_settle_minutes').val(_config.timing_settle_minutes != null ? _config.timing_settle_minutes : 30);
    LandroidRTKScheduler_refreshSettlePreview();
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
    $('#sched_temperature_min').val(_config.temperature_min != null ? _config.temperature_min : 8);
    $('#sched_temperature_max').val(_config.temperature_max != null ? _config.temperature_max : 40);

    $('#sched_condition_id_cmd_id').val(_config.condition_id_cmd_id || '');
    $('#sched_condition_cmd_id').val(_config.condition_cmd_id || '');

    LandroidRTKScheduler_fillRotation(_config);

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
    $tr.find('.notif_no_mow').prop('checked', _notif.notify_no_mow != '0');
    $tr.find('.notif_error').prop('checked', _notif.notify_error == '1');
    $tr.find('.notif_mow_end').prop('checked', _notif.notify_mow_end == '1');
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

function LandroidRTKScheduler_refreshLatestStartPreview() {
    var $preview = $('#sched_latest_start_preview');
    var timeStart = $('#sched_time_start_cmd_id').val();
    var timeEnd = $('#sched_time_end_cmd_id').val();
    var margin = $('#sched_margin_minutes').val();
    if (!timeStart || !timeEnd) {
        $preview.html('');
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'latestStartPreview',
            apikey: LandroidRTKApikey,
            time_start: timeStart,
            time_end: timeEnd,
            margin_minutes: margin,
        },
        dataType: 'json',
        success: function (data) {
            if (data.state != 'ok') {
                $preview.html('');
                return;
            }
            var r = data.result;
            if (r.valid) {
                $preview.html('<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> Dernier départ : ' + r.value + '</span>');
            } else {
                $preview.html('<span style="color:#a94442;"><i class="fas fa-times-circle"></i> ' + (r.error || 'Erreur') + '</span>');
            }
        }
    });
}

$(document).on('change keyup', '#sched_time_start_cmd_id, #sched_time_end_cmd_id, #sched_margin_minutes', function () {
    LandroidRTKScheduler_refreshLatestStartPreview();
});

function LandroidRTKScheduler_refreshAllPreviews() {
    $('#scheduletab input[type=text].form-control, #scheduletab .notif_cmd_id').each(function () {
        LandroidRTKScheduler_refreshPreview($(this));
    });
    LandroidRTKScheduler_refreshLatestStartPreview();
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
                var pat = LandroidRTKScheduler_patternHtml(data.result.pattern);
                $('#schedule_next_mow').html('<i class="fas fa-hourglass-half"></i> ' + data.result.text + (pat ? '<br><i class="fas fa-th"></i> ' + pat : '')).show();
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
/* Alternance des formes de coupe (5 lignes au plus)                    */
/* ------------------------------------------------------------------ */
var LandroidRTKScheduler_ROTATION_MAX = 5;
var LandroidRTKScheduler_PATTERNS = [[1, 'Parallèle'], [4, 'Diamant'], [5, 'Damier'], [0, 'Naturel (sans angle)']];

function LandroidRTKScheduler_addRotationRow(_row) {
    _row = _row || {};
    if ($('#table_rotation tbody tr').length >= LandroidRTKScheduler_ROTATION_MAX) {
        return;
    }
    var $sel = $('<select class="form-control rot_type" style="width:170px;">');
    for (var i = 0; i < LandroidRTKScheduler_PATTERNS.length; i++) {
        $sel.append($('<option>').val(LandroidRTKScheduler_PATTERNS[i][0]).text(LandroidRTKScheduler_PATTERNS[i][1]));
    }
    $sel.val(_row.type != null ? String(_row.type) : '1');
    var $angle = $('<input type="number" class="form-control rot_angle" min="0" max="359" step="1" style="width:100px;">');
    if (_row.angle != null && _row.angle !== '') {
        $angle.val(_row.angle);
    }
    var $tr = $('<tr>')
        .append($('<td class="rot_num">'))
        .append($('<td>').append($sel))
        .append($('<td>').append($angle))
        .append($('<td class="rot_check" style="white-space:nowrap;">'))
        .append($('<td>').append($('<a class="btn btn-xs btn-danger bt_removeRotation" title="Supprimer cette ligne"><i class="fas fa-trash"></i></a>')));
    $('#table_rotation tbody').append($tr);
    LandroidRTKScheduler_updateRotationUi();
}

/* Lignes du tableau : {rows: [{type, angle}], valid: nombre de lignes valides, invalid: nombre de lignes incomplètes} */
function LandroidRTKScheduler_rotationRows() {
    var rows = [], valid = 0, invalid = 0;
    $('#table_rotation tbody tr').each(function () {
        var type = parseInt($(this).find('.rot_type').val(), 10);
        var raw = $(this).find('.rot_angle').val();
        var angle = (raw === '' || raw == null) ? null : Number(raw);
        var ok = (type === 0) || (angle !== null && isFinite(angle) && angle >= 0 && angle <= 359 && Math.floor(angle) === angle);
        rows.push({type: type, angle: type === 0 ? null : angle});
        if (ok) { valid++; } else { invalid++; }
    });
    return {rows: rows, valid: valid, invalid: invalid};
}

function LandroidRTKScheduler_updateRotationUi() {
    var $rows = $('#table_rotation tbody tr');
    $rows.each(function (i) {
        var $tr = $(this);
        $tr.find('.rot_num').text(i + 1);
        var natural = parseInt($tr.find('.rot_type').val(), 10) === 0;
        $tr.find('.rot_angle').prop('disabled', natural);
        if (natural) { $tr.find('.rot_angle').val(''); }
        var raw = $tr.find('.rot_angle').val();
        var a = Number(raw);
        var bad = !natural && (raw === '' || !isFinite(a) || a < 0 || a > 359 || Math.floor(a) !== a);
        $tr.find('.rot_angle').css('border-color', bad ? '#d9534f' : '');
        // Même rendu que les autres champs : ✔ vert ou ✖ rouge à droite, avec la raison.
        $tr.find('.rot_check').html(bad
            ? '<span style="color:#a94442;"><i class="fas fa-times-circle"></i> {{Angle entier de 0 à 359}}</span>'
            : '<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> ' + (natural ? '{{Naturel : pas d\'angle}}' : '{{OK}}') + '</span>');
    });
    $('#bt_addRotation').toggle($rows.length < LandroidRTKScheduler_ROTATION_MAX);
    var st = LandroidRTKScheduler_rotationRows();
    var $cb = $('#sched_rotation_enabled');
    if (st.valid === 0) {
        $cb.prop('checked', false).prop('disabled', true);
        $('#sched_rotation_hint').text('Ajoutez au moins une forme valide pour pouvoir activer l\'alternance.');
    } else if (st.invalid > 0) {
        $cb.prop('disabled', false);
        $('#sched_rotation_hint').text('Complétez l\'angle des lignes en rouge (ou supprimez-les) avant de sauvegarder.');
    } else {
        $cb.prop('disabled', false);
        $('#sched_rotation_hint').text('');
    }
}

function LandroidRTKScheduler_fillRotation(_config) {
    $('#table_rotation tbody').empty();
    var list = (_config && Array.isArray(_config.pattern_rotation)) ? _config.pattern_rotation : [];
    for (var i = 0; i < list.length && i < LandroidRTKScheduler_ROTATION_MAX; i++) {
        LandroidRTKScheduler_addRotationRow(list[i]);
    }
    $('#sched_rotation_enabled').prop('checked', _config && _config.pattern_rotation_enabled == '1');
    LandroidRTKScheduler_updateRotationUi();
    LandroidRTKScheduler_refreshPatternInfo();
}

/* Forme prévue pour la prochaine tonte (alternance) ou forme actuelle du robot */
function LandroidRTKScheduler_patternHtml(_info) {
    if (!_info || !_info.text) { return ''; }
    return (_info.mode === 'next' ? 'Forme prévue pour la prochaine tonte' : 'Forme actuelle du robot') + ' : <b>' + $('<div>').text(_info.text).html() + '</b>';
}

function LandroidRTKScheduler_refreshPatternInfo() {
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {action: 'patternInfo', id: LandroidRTKScheduler_currentEqLogicId, apikey: LandroidRTKApikey,
               config: JSON.stringify(LandroidRTKScheduler_buildConfig())},
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok' && data.result && data.result.text) {
                $('#sched_rotation_next').html('<i class="fas fa-th"></i> ' + LandroidRTKScheduler_patternHtml(data.result));
            } else {
                $('#sched_rotation_next').empty();
            }
        }
    });
}

$(document).on('click', '#bt_addRotation', function (e) {
    e.preventDefault();
    LandroidRTKScheduler_addRotationRow({type: 1});
});
$(document).on('click', '.bt_removeRotation', function (e) {
    e.preventDefault();
    $(this).closest('tr').remove();
    LandroidRTKScheduler_updateRotationUi();
    LandroidRTKScheduler_refreshPatternInfo();
});
$(document).on('change input', '#table_rotation .rot_type, #table_rotation .rot_angle', function () {
    LandroidRTKScheduler_updateRotationUi();
});
$(document).on('change', '#sched_rotation_enabled, #table_rotation .rot_type, #table_rotation .rot_angle', function () {
    LandroidRTKScheduler_refreshPatternInfo();
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
            notify_no_mow: $tr.find('.notif_no_mow').is(':checked') ? '1' : '0',
            notify_error: $tr.find('.notif_error').is(':checked') ? '1' : '0',
            notify_mow_end: $tr.find('.notif_mow_end').is(':checked') ? '1' : '0',
        });
    });

    return {
        enabled: $('#sched_enabled').is(':checked') ? '1' : '0',
        time_start_cmd_id: $('#sched_time_start_cmd_id').val(),
        time_end_cmd_id: $('#sched_time_end_cmd_id').val(),
        margin_minutes: $('#sched_margin_minutes').val(),
        mow_duration_minutes: $('#sched_mow_duration_minutes').val(),
        timing_settle_minutes: $('#sched_timing_settle_minutes').val(),
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
        notify_no_mow_reason: $('#sched_notify_no_mow_reason').is(':checked') ? '1' : '0',
        temperature_cmd_id: $('#sched_temperature_cmd_id').val(),
        temperature_min: $('#sched_temperature_min').val(),
        temperature_max: $('#sched_temperature_max').val(),
        condition_id_cmd_id: $('#sched_condition_id_cmd_id').val(),
        condition_cmd_id: $('#sched_condition_cmd_id').val(),
        pattern_rotation_enabled: $('#sched_rotation_enabled').is(':checked') ? '1' : '0',
        pattern_rotation: LandroidRTKScheduler_rotationRows().rows,
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
    // Alternance des formes : pas de sauvegarde avec une ligne incomplète, ni activée sans aucune forme valide.
    var rot = LandroidRTKScheduler_rotationRows();
    if (rot.invalid > 0) {
        LandroidRTKScheduler_showResults(['Alternance des formes : complétez l\'angle des lignes en rouge (ou supprimez-les) avant de sauvegarder.'], []);
        return;
    }
    if ($('#sched_rotation_enabled').is(':checked') && rot.valid === 0) {
        $('#sched_rotation_enabled').prop('checked', false);
        LandroidRTKScheduler_showResults(['Alternance des formes : ajoutez au moins une forme pour l\'activer.'], []);
        return;
    }
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
            if (data.result.rotation_errors && data.result.rotation_errors.length) {
                $('#sched_rotation_enabled').prop('checked', false);
                LandroidRTKScheduler_updateRotationUi();
            }
            if (data.result.errors && data.result.errors.length) {
                $('#sched_enabled').prop('checked', false);
                LandroidRTKScheduler_showResults(data.result.errors.concat(data.result.rotation_errors || []), []);
                $.fn.showAlert({message: '{{Programmation sauvegardée, mais pas activée (configuration incomplète)}}', level: 'warning'});
            } else if (data.result.rotation_errors && data.result.rotation_errors.length) {
                LandroidRTKScheduler_showResults(data.result.rotation_errors, []);
                $.fn.showAlert({message: '{{Sauvegardé, mais l\'alternance des formes n\'est pas activée}}', level: 'warning'});
            } else {
                LandroidRTKScheduler_hideResults();
                $.fn.showAlert({message: '{{Programmation sauvegardée}}', level: 'success'});
                LandroidRTKScheduler_refreshNextMow();
                LandroidRTKScheduler_refreshPatternInfo();
                if ($('#sched_enabled').is(':checked')) {
                    $('#schedule_conditions_status').show();
                    LandroidRTKScheduler_loadConditionsStatus();
                } else {
                    $('#schedule_conditions_status').hide();
                }
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

/* ------------------------------------------------------------------ */
/* "Réinitialiser l'anti-doublon des notifications 'pas de tonte'"     */
/* ------------------------------------------------------------------ */
$(document).on('click', '#bt_resetNotifThrottle', function (e) {
    e.preventDefault();
    if (!confirm('{{Réinitialiser l\'anti-doublon des notifications "pas de tonte" ? La prochaine vérification (sous 5 min) pourra renvoyer une notification aujourd\'hui, même si une a déjà été envoyée.}}')) {
        return;
    }
    var $bt = $(this);
    $bt.find('i').removeClass('fa-bell-slash').addClass('fa-spinner fa-spin');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: {
            action: 'resetNotifThrottle',
            id: LandroidRTKScheduler_currentEqLogicId,
            apikey: LandroidRTKApikey,
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-bell-slash');
        },
        success: function (data) {
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-bell-slash');
            if (data.state != 'ok') {
                $.fn.showAlert({message: '{{Échec (voir logs LandroidRTK)}}', level: 'danger'});
                return;
            }
            $.fn.showAlert({message: '{{Anti-doublon réinitialisé}}', level: 'success'});
        }
    });
});


/* ------------------------------------------------------------------ */
/* Mesure du temps de tonte                                            */
/* ------------------------------------------------------------------ */
function LandroidRTKScheduler_fmtDur(sec) {
    var m = Math.round(sec / 60);
    return m >= 60 ? (Math.floor(m / 60) + ' h ' + ('0' + (m % 60)).slice(-2)) : (m + ' min');
}
function LandroidRTKScheduler_fmtDate(ts) {
    var d = new Date(ts * 1000);
    var p = function (n) { return ('0' + n).slice(-2); };
    return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}
function LandroidRTKScheduler_renderTiming(r) {
    var $b = $('#table_timing tbody').empty();
    (r.history || []).forEach(function (h) {
        $b.append($('<tr>')
            .append($('<td>').text(LandroidRTKScheduler_fmtDate(h.start)))
            .append($('<td>').text(h.pattern === null || h.pattern === undefined ? '?' : h.pattern))
            .append($('<td>').text(h.angle === null || h.angle === undefined ? (h.pattern === 'Naturel' ? '—' : '?') : h.angle + '°'))
            .append($('<td>').text(LandroidRTKScheduler_fmtDur(h.out_s)))
            .append($('<td>').text(LandroidRTKScheduler_fmtDur(h.total_s)))
            .append($('<td>').text(Math.max(0, (h.returns || 1) - 1)))
            .append($('<td>').append($('<a class="bt_timingDelete" style="cursor:pointer; text-decoration:none;" title="{{Supprimer cette mesure}}">🗑️</a>').attr('data-start', h.start))));
    });
    if (!(r.history || []).length) {
        $b.append($('<tr>').append($('<td colspan="7" class="text-muted">').text('{{Aucune mesure pour le moment.}}')));
    }
    $('#bt_timingStart').toggle(!r.running);
    $('#bt_timingCancel').toggle(!!r.running);
    $('#timing_status').text(r.running
        ? '{{Mesure en cours depuis}} ' + LandroidRTKScheduler_fmtDate(r.started_at) + ((r.pattern || r.angle) ? ' (' + (r.pattern ? r.pattern : '') + (r.pattern && r.angle ? ', ' : '') + (r.angle ? '{{angle}} ' + r.angle + '°' : '') + ')' : '') + ' — ' + r.returns + ' {{retour(s) à la station}}' + (r.docked ? ', {{actuellement à la station}}' : '')
        : '');
}
function LandroidRTKScheduler_timingCall(action, done, extra) {
    if (!LandroidRTKScheduler_currentEqLogicId) { return; }
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTKScheduler.ajax.php',
        data: $.extend({action: action, id: LandroidRTKScheduler_currentEqLogicId, apikey: LandroidRTKApikey}, extra || {}),
        dataType: 'json',
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({message: data.result || '{{Échec (voir logs LandroidRTK)}}', level: 'danger'});
                return;
            }
            LandroidRTKScheduler_renderTiming(data.result);
            if (done) { done(); }
        }
    });
}
function LandroidRTKScheduler_refreshTiming() { LandroidRTKScheduler_timingCall('timingInfo'); }
setInterval(function () { if ($('#table_timing').is(':visible')) { LandroidRTKScheduler_refreshTiming(); } }, 60000);

$(document).on('click', '#bt_timingStart', function (e) {
    e.preventDefault();
    if (!confirm('{{ATTENTION : ceci LANCE IMMÉDIATEMENT le robot (avec les notifications de départ). Ne le relancez pas ensuite à la main : la mesure part de ce clic. Les conditions météo / humidité / horaires ne sont pas vérifiées. Continuer ?}}')) {
        return;
    }
    LandroidRTKScheduler_timingCall('startTiming', function () {
        $.fn.showAlert({message: '{{Robot lancé : mesure en cours.}}', level: 'warning'});
    });
});
$(document).on('click', '.bt_timingDelete', function (e) {
    e.preventDefault();
    var $tr = $(this).closest('tr');
    if (!confirm('{{Supprimer cette mesure de l\'historique ?}}\n' + $tr.children().slice(0, 4).map(function () { return $(this).text(); }).get().join(' · '))) { return; }
    LandroidRTKScheduler_timingCall('deleteTiming', null, {start: $(this).attr('data-start')});
});
$(document).on('click', '#bt_timingCancel', function (e) {
    e.preventDefault();
    if (!confirm('{{Annuler la mesure en cours ? (le robot n\'est pas arrêté)}}')) { return; }
    LandroidRTKScheduler_timingCall('cancelTiming');
});

function LandroidRTKScheduler_refreshSettlePreview() {
    var raw = $('#sched_timing_settle_minutes').val();
    var v = parseInt(raw, 10);
    var $p = $('#sched_timing_settle_preview');
    if (raw === '' || isNaN(v) || String(v) !== String(raw).trim() || v < 0 || v > 60) {
        $p.html('<span style="color:#a94442;"><i class="fas fa-times-circle"></i> {{Entre 0 et 60 minutes}}</span>');
    } else {
        $p.html('<span style="color:#3c763d;"><i class="fas fa-check-circle"></i> ' + (v === 0 ? '{{Fin dès que le robot est chargé à 100 %}}' : '{{Fin si le robot ne repart pas pendant}} ' + v + ' min') + '</span>');
    }
}
$(document).on('change keyup input', '#sched_timing_settle_minutes', LandroidRTKScheduler_refreshSettlePreview);
