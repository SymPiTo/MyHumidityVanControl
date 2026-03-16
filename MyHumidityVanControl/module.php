<?php

class MyHumidityVanControl extends IPSModule {

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften registrieren (z.B. Sensor-ID und Schwellwert)
        $this->RegisterPropertyInteger("SensorID", 0);
        $this->RegisterPropertyFloat("Threshold", 60.0);
        $this->RegisterPropertyInteger("TargetID", 0);
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Alle alten Registrierungen löschen
        $oldMessages = $this->GetMessageList();
        foreach ($oldMessages as $id => $msgs) {
            foreach ($msgs as $msg) {
                $this->UnregisterMessage($id, $msg);
            }
        }

        // Neue Registrierung für die Sensor-ID (VM_UPDATE = 10603)
        $sensorId = $this->ReadPropertyInteger("SensorID");
        if ($sensorId > 0 && IPS_VariableExists($sensorId)) {
            $this->RegisterMessage($sensorId, 10603);
        }
        
        $this->SendDebug("ApplyChanges", "Registrierung für ID $sensorId durchgeführt", 0);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        // $Data[0] enthält bei VM_UPDATE den neuen Wert
        if ($Message == 10603) {
            $this->CheckHumidity($Data[0]);
        }
    }

    private function CheckHumidity($currentValue) {
        $threshold = $this->ReadPropertyFloat("Threshold");
        $targetId = $this->ReadPropertyInteger("TargetID");

        if ($targetId > 0 && IPS_VariableExists($targetId)) {
            if ($currentValue > $threshold) {
                $this->SendDebug("Control", "Feuchtigkeit ($currentValue) über Schwellwert ($threshold) -> AN", 0);
                RequestAction($targetId, true);
            } else {
                $this->SendDebug("Control", "Feuchtigkeit ($currentValue) unter Schwellwert ($threshold) -> AUS", 0);
                RequestAction($targetId, false);
            }
        }
    }
}