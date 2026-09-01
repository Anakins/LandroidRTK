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

        $eqLogic->doAction($logicalId);
        return null;
    }
}
