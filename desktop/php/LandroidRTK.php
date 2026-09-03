<?php

if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('LandroidRTK');
sendVarToJS('eqType', $plugin->getId());
sendVarToJS('LandroidRTKApikey', LandroidRTK::getApiKey());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">

    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
            <div class="cursor logoSecondary" id="bt_syncLandroidRTK">
                <i class="fas fa-sync"></i>
                <br>
                <span>{{Synchroniser}}</span>
            </div>
        </div>

        <legend><i class="fas fa-robot"></i> {{Mes tondeuses Vision}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement trouvé, cliquez sur Synchroniser}}</div>';
        } else {
            echo '<div class="eqLogicThumbnailContainer">';
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                $model_type = $eqLogic->getConfiguration('model_type', 'vision_generic');
                $img_url = LandroidRTK::getModelImageUrl($model_type);
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '" data-model-type="' . $model_type . '">';
                echo '<img src="' . $img_url . '" height="100" width="100">';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fa fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
            <li role="presentation"><a href="#scheduletab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-clock"></i> {{Programmation}}</a></li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-7">
                            <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        $options = '';
                                        foreach ((jeeObject::buildTree(null, false)) as $object) {
                                            $options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                        }
                                        echo $options;
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Options}}</label>
                                <div class="col-sm-6">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <legend><i class="fas fa-info"></i> {{Informations}}</legend>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Marque}}</label>
                                <div class="col-sm-6">
                                    <span class="label label-info">Worx</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Modèle}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Numéro de série Worx}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="logicalId" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-5 control-label">{{Visuel}}</label>
                                <div class="col-sm-6">
                                    <img id="LandroidRTK_full_image" src="" style="max-height:100px;max-width:100%;">
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br />
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
                            <th style="min-width:220px;width:350px;">{{Nom}}</th>
                            <th style="min-width:140px;width:160px;">{{Type}}</th>
                            <th style="width:200px;">{{Etat}}</th>
                            <th style="min-width:80px;width:200px;">{{Actions}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="cmdTemplate" style="display: none;">
                            <td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>
                            <td><span class="cmdAttr" data-l1key="name"></span></td>
                            <td><span class="cmdAttr" data-l1key="type"></span> / <span class="cmdAttr" data-l1key="subType"></span></td>
                            <td class="cmd_state"></td>
                            <td>
                                <a class="btn btn-default btn-xs bt_testCmd"><i class="fas fa-flask"></i> {{Tester}}</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php include_file('desktop', 'LandroidRTKScheduler', 'php', 'LandroidRTK'); ?>
        </div>
    </div>
</div>

<?php include_file('desktop', 'LandroidRTK', 'js', 'LandroidRTK'); ?>
<?php include_file('desktop', 'LandroidRTKScheduler', 'js', 'LandroidRTK'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
