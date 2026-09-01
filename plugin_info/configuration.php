<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
$LandroidRTK_apikey = LandroidRTK::getApiKey();
?>

<div class="alert alert-info" style="margin: 10px;">
    <i class="fas fa-info-circle"></i>
    Astuce : Worx applique une limite au nombre de connexions à son service
    cloud sur une courte période. Redémarrer le démon de façon répétée et
    rapprochée (par exemple plusieurs fois en quelques minutes) peut donc
    entraîner un blocage temporaire de l'accès à ton compte (également
    visible dans l'application Worx elle-même le temps que ça se lève).
    En usage normal, ce n'est pas un souci : laisse simplement le démon
    tourner en continu plutôt que de le relancer souvent.
</div>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-cloud"></i> Compte Worx</legend>

        <div class="form-group">
            <label class="col-sm-3 control-label">Email du compte Worx</label>
            <div class="col-sm-9">
                <input class="configKey form-control" data-l1key="email" placeholder="ex : worx.copper958@passmail.net"/>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Mot de passe du compte Worx</label>
            <div class="col-sm-9">
                <input type="password" class="configKey form-control" data-l1key="password" placeholder="••••••••"/>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-sync"></i> Synchronisation</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">Tondeuses Vision</label>
            <div class="col-sm-9">
                <a class="btn btn-primary" id="bt_syncDevices">
                    <i class="fas fa-sync"></i> Synchroniser les tondeuses
                </a>
                <span id="sync_result" style="margin-left: 10px;"></span>
            </div>
        </div>
    </fieldset>
</form>

<script>
var LandroidRTKApikey = <?= json_encode($LandroidRTK_apikey) ?>;

$('#bt_syncDevices').off('click').on('click', function () {
    $('#sync_result').html('<i class="fas fa-spinner fa-spin"></i> Synchronisation en cours...');
    $.ajax({
        type: 'POST',
        url: 'plugins/LandroidRTK/core/ajax/LandroidRTK.ajax.php',
        data: {action: 'syncDevices', apikey: LandroidRTKApikey},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#sync_result').html('<span class="label label-danger">Échec (voir logs LandroidRTK)</span>');
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#sync_result').html('<span class="label label-danger">Échec : ' + (data.result || '') + '</span>');
                return;
            }
            var res = data.result;
            var cls = res.success ? 'label-success' : 'label-danger';
            $('#sync_result').html('<span class="label ' + cls + '">' + res.message + '</span>');
        }
    });
});
</script>

<?php include_file('core', 'plugin.template', 'js'); ?>

