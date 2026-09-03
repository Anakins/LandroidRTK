/* JS du plugin Worx Vision */

/* ------------------------------------------------------------------ */
/* Bouton "Synchroniser" (page thumbnail, hors édition d'un équipement) */
/* ------------------------------------------------------------------ */
$('#bt_syncLandroidRTK').off('click').on('click', function () {
    var $bt = $(this);
    $bt.find('i').removeClass('fa-sync').addClass('fa-spinner fa-spin');

    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTK.ajax.php',
        data: {action: 'syncDevices', apikey: LandroidRTKApikey},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-sync');
        },
        success: function (data) {
            $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-sync');
            if (data.state != 'ok') {
                $.fn.showAlert({message: '{{Échec de la synchronisation, voir les logs Worx Vision}}', level: 'danger'});
                return;
            }
            var res = data.result;
            var level = res.success ? 'success' : 'danger';
            $.fn.showAlert({message: res.message, level: level});
            if (res.success) {
                setTimeout(function () {
                    location.reload();
                }, 1500);
            }
        }
    });
});

/* ------------------------------------------------------------------ */
/* Mecanisme OFFICIEL Jeedom : printEqLogic(_eqLogic) est appelee       */
/* automatiquement par le core (plugin.template.js) a CHAQUE chargement */
/* des donnees d'un equipement, quelle que soit la facon dont on y      */
/* arrive (clic sur une carte, changement d'onglet, navigation directe  */
/* par URL/hash...). C'est ce qui manquait : on ne depend plus de nos   */
/* propres evenements, fragiles, mais du cycle de vie natif de Jeedom.  */
/* ------------------------------------------------------------------ */
function printEqLogic(_eqLogic) {
    if (typeof _eqLogic === 'undefined') {
        _eqLogic = {};
    }

    // Image du modele, mise a jour au meme moment que les commandes
    if (_eqLogic.configuration && _eqLogic.configuration.model_type) {
        $('#LandroidRTK_full_image').attr('src', 'plugins/LandroidRTK/desktop/img/' + _eqLogic.configuration.model_type + '.png');
    }

    $('#table_cmd tbody tr.cmd').remove();
    if (_eqLogic.cmd) {
        for (var i in _eqLogic.cmd) {
            LandroidRTK_addCmdToTable(_eqLogic.cmd[i]);
        }
    }
}

function LandroidRTK_addCmdToTable(_cmd) {
    if (typeof _cmd === 'undefined') {
        _cmd = {};
    }
    var $tr = $('#table_cmd .cmdTemplate').clone();
    $tr.removeClass('cmdTemplate').addClass('cmd').show();
    if (_cmd.type != 'action') {
        $tr.find('.bt_testCmd').remove();
    }
    $('#table_cmd tbody').append($tr);
    var $lastRow = $('#table_cmd tbody tr:last');
    $lastRow.setValues(_cmd, '.cmdAttr');
    $lastRow.find('.cmd_state').text((_cmd.value !== undefined && _cmd.value !== null) ? _cmd.value : '');
}

$('#commandtab').on('click', '.bt_testCmd', function () {
    var $tr = $(this).closest('tr');
    var cmd_id = $tr.find('.cmdAttr[data-l1key="id"]').text();
    $.ajax({
        type: 'POST',
        url: 'core/ajax/cmd.ajax.php',
        data: {action: 'test', id: cmd_id},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({message: data.result, level: 'danger'});
                return;
            }
            $.fn.showAlert({message: '{{Commande exécutée}}', level: 'success'});
        }
    });
});
