<?php

declare(strict_types=1);

class MyHumidityVanControl extends IPSModule {

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften registrieren
        $this->RegisterPropertyInteger("SensorID", 0);  // Feuchtigkeitssensor Variable
        $this->RegisterPropertyInteger("TargetID", 0);  // Lüfter Instanz    
        $this->RegisterPropertyInteger("SwitchID", 0); // Manueller Schalter (Instanz)
        $this->RegisterPropertyInteger("LimitOn", 50);
        $this->RegisterPropertyInteger("LimitOff", 45);
        $this->RegisterPropertyInteger("RunTime", 5);
        $this->RegisterPropertyBoolean("Active", true);
        
        // Timer für die maximale Laufzeit
        $this->RegisterTimer("MaxRunTimer", 0, 'MHVC_StopFan($_IPS[\'TARGET\']);');

        // Visualisierungstyp auf 1 setzen für HTML-SDK
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Alle Nachrichten abmelden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Registrierung Feuchtigkeitssensor
        $sensorId = $this->ReadPropertyInteger("SensorID");
        if ($sensorId > 0 && IPS_VariableExists($sensorId)) {
            $this->RegisterMessage($sensorId, 10603); // VM_UPDATE
        }

        // Registrierung manueller Schalter
        $switchInstanceId = $this->ReadPropertyInteger("SwitchID");
        if ($switchInstanceId > 0 && IPS_InstanceExists($switchInstanceId)) {
            $switchVarId = @IPS_GetObjectIDByIdent("state", $switchInstanceId);
            if ($switchVarId !== false) {
                $this->RegisterMessage($switchVarId, 10603);
            }
        }

        // Registrierung Status des Lüfters (um Kachel bei externer Schaltung zu aktualisieren)
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
            if ($stateVarId !== false) {
                $this->RegisterMessage($stateVarId, 10603);
            }
        }

        $this->SetTimerInterval("MaxRunTimer", 0);

        if (!$this->ReadPropertyBoolean("Active")) {
            $this->StopFan();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message == 10603) {
            $sensorId = $this->ReadPropertyInteger("SensorID");
            $switchInstanceId = $this->ReadPropertyInteger("SwitchID");
            $switchVarId = @IPS_GetObjectIDByIdent("state", $switchInstanceId);
            
            $targetId = $this->ReadPropertyInteger("TargetID");
            $targetStateId = @IPS_GetObjectIDByIdent("state", $targetId);

            // Fall A: Feuchtigkeitssensor
            if ($SenderID == $sensorId && $this->ReadPropertyBoolean("Active")) {
                $this->CheckHumidity($Data[0]);
            }

            // Fall B: Manueller Schalter
            if ($SenderID == $switchVarId) {
                $this->ToggleFan();
            }

            // IMMER: Kachel aktualisieren, wenn sich relevante Werte ändern
            if ($SenderID == $sensorId || $SenderID == $switchVarId || $SenderID == $targetStateId) {
                $this->UpdateTile();
            }
        }
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "SetAuto":
                IPS_SetProperty($this->InstanceID, "Active", $Value);
                IPS_ApplyChanges($this->InstanceID);
                break;

            case "ToggleFan":
                if ($Value) {
                    $this->StartFan();
                } else {
                    $this->StopFan();
                }
                break;
        }
    }

    private function CheckHumidity(float $currentValue) {
        $targetId = $this->ReadPropertyInteger("TargetID");
        $limitOn = $this->ReadPropertyInteger("LimitOn");
        $limitOff = $this->ReadPropertyInteger("LimitOff");
        $timerActive = $this->GetTimerInterval("MaxRunTimer") > 0;

        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            if ($currentValue > $limitOn && !$timerActive) {
                $this->StartFan();
            } elseif ($currentValue < $limitOff && $timerActive) {
                $this->StopFan();
            }
        }
    }

    public function ToggleFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId <= 0 || !IPS_InstanceExists($targetId)) return;

        $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
        if ($stateVarId === false) return;

        $isCurrentlyOn = GetValueBoolean($stateVarId);
        $isModuleActive = $this->ReadPropertyBoolean("Active");

        if (!$isCurrentlyOn) {
            Z2M_WriteValueBoolean($targetId, 'state', true);
            if ($isModuleActive) {
                $runTime = $this->ReadPropertyInteger("RunTime");
                $this->SetTimerInterval("MaxRunTimer", $runTime * 60 * 1000);
            } else {
                $this->SetTimerInterval("MaxRunTimer", 0);
            }
        } else {
            $this->StopFan();
        } // <--- Hier fehlte die schließende Klammer!
    }

    public function StopFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            Z2M_WriteValueBoolean($targetId, 'state', false);
        }
        $this->SetTimerInterval("MaxRunTimer", 0);
    }
    
    public function StartFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        $runTime = $this->ReadPropertyInteger("RunTime");
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            Z2M_WriteValueBoolean($targetId, 'state', true);
            $this->SetTimerInterval("MaxRunTimer", $runTime * 60 * 1000);
        }
    }

    public function GetVisualizationTile() {
        // Nutzt die zentrale Funktion für die initiale Darstellung
        return $this->GetFullUpdateMessage();
    }

    private function UpdateTile() {
        // Sendet ein Live-Update an die Kachel
        $this->UpdateVisualizationTile($this->GetFullUpdateMessage());
    }

    private function GetFullUpdateMessage() {
        $sensorId = $this->ReadPropertyInteger("SensorID");
        $targetId = $this->ReadPropertyInteger("TargetID");
        
        // Status des Lüfters aus der Ziel-Instanz holen
        $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
        $fanState = ($stateVarId !== false) ? GetValueBoolean($stateVarId) : false;

        // Datenpaket für die module.html
        $data = [
            "humidity"   => ($sensorId > 0 && IPS_VariableExists($sensorId)) ? GetValue($sensorId) : 0,
            "fanState"   => $fanState,
            "autoActive" => $this->ReadPropertyBoolean("Active"),
            "sensorId"   => $sensorId
        ];

        return json_encode($data);
    }
}