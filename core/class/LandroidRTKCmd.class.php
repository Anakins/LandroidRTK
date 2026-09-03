<?php

class LandroidRTKCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return null;
        }

        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            log::add('LandroidRTK', 'error', 'Commande orpheline sans équipement associé.');
            return null;
        }

        $logicalId = $this->getLogicalId();

        if ($logicalId == 'sync') {
            $eqLogic->refreshStatus();
            return null;
        }

        if (in_array($logicalId, array('schedule_margin_set', 'schedule_spacing_set', 'schedule_humidity_threshold_set', 'schedule_activate', 'schedule_deactivate'))) {
            $value = isset($_options['slider']) ? $_options['slider'] : (isset($_options['select']) ? $_options['select'] : null);
            LandroidRTKScheduler::handleWidgetAction($eqLogic, $logicalId, $value);
            return null;
        }

        $eqLogic->doAction($logicalId);
        return null;
    }
}
