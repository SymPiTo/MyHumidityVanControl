<?php

declare(strict_types=1);

class MyHumidityVanControl extends IPSModule {

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();

        // Statische Eigenschaften (IDs der Geräte)
        $this->RegisterPropertyInteger("SensorID", 0);
        $this->RegisterPropertyInteger("TargetID", 0);
        $this->RegisterPropertyInteger("SwitchID", 0);
        
        // Statusvariablen für die Steuerung (änderbar über Kachel/WebFront)
        $this->RegisterVariableBoolean("Active", "Automatik Aktiv", "~Switch");
        $this->EnableAction("Active");

        $this->RegisterVariableInteger("LimitOn", "Einschaltlimit", "~Humidity.F");
        $this->EnableAction("LimitOn");

        $this->RegisterVariableInteger("LimitOff", "Ausschaltlimit", "~Humidity.F");
        $this->EnableAction("LimitOff");

        $this->RegisterVariableInteger("RunTime", "Laufzeit (Minuten)", "");
        $this->EnableAction("RunTime");

        // Timer für die maximale Laufzeit
        $this->RegisterTimer("MaxRunTimer", 0, 'MHVC_StopFan($_IPS[\'TARGET\']);');

        // Visualisierungstyp auf 1 setzen für HTML-SDK
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Standardwerte setzen, falls die Variablen noch auf 0 stehen (nur beim ersten Mal)
        // Wir prüfen, ob der aktuelle Wert 0 ist, um Benutzereingaben nicht zu überschreiben
        if ($this->GetValue("LimitOn") == 0) $this->SetValue("LimitOn", 50);
        if ($this->GetValue("LimitOff") == 0) $this->SetValue("LimitOff", 45);
        if ($this->GetValue("RunTime") == 0) $this->SetValue("RunTime", 10);
        if ($this->GetValue("Active") == false) {
            // Hier vorsichtig: false ist der Standard für Boolean. 
            // Wenn du willst, dass es ab Start IMMER aktiv ist:
            // $this->SetValue("Active", true);
        }

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

        // Registrierung manueller Schalter (Externes Gerät)
        $switchInstanceId = $this->ReadPropertyInteger("SwitchID");
        if ($switchInstanceId > 0 && IPS_InstanceExists($switchInstanceId)) {
            $switchVarId = @IPS_GetObjectIDByIdent("state", $switchInstanceId);
            if ($switchVarId !== false) {
                $this->RegisterMessage($switchVarId, 10603);
            }
        }

        // Registrierung Status des Lüfters
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
            if ($stateVarId !== false) {
                $this->RegisterMessage($stateVarId, 10603);
            }
        }

        $this->SetTimerInterval("MaxRunTimer", 0);

        if (!$this->GetValue("Active")) {
            $this->StopFan();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message == 10603) { // VM_UPDATE
            $sensorId = $this->ReadPropertyInteger("SensorID");
            $targetId = $this->ReadPropertyInteger("TargetID");
            $switchInstanceId = $this->ReadPropertyInteger("SwitchID");
            
            $switchVarId = ($switchInstanceId > 0) ? @IPS_GetObjectIDByIdent("state", $switchInstanceId) : 0;
            $targetStateId = ($targetId > 0) ? @IPS_GetObjectIDByIdent("state", $targetId) : 0;

            // Fall A: Feuchtigkeitssensor
            if ($SenderID == $sensorId && $this->GetValue("Active")) {
                $this->CheckHumidity((float)$Data[0]);
            }

            // Fall B: Manueller Schalter triggert Toggle
            if ($switchVarId > 0 && $SenderID == $switchVarId) {
                $this->ToggleFan();
            }

            // Kachel aktualisieren bei jeder relevanten Änderung
            $this->UpdateTile();
        }
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
             
            case "LimitOn":
            case "LimitOff":
            case "RunTime":
                // Wert in der Statusvariable speichern
                $this->SetValue($Ident, $Value);
                $this->UpdateTile();
                break;

            case "ToggleFan":
                $Value ? $this->StartFan() : $this->StopFan();
                break;

            case "FanSwitch":
                // Logik für Kachel-Button (Invertiert aktuellen Zustand)
                $targetId = $this->ReadPropertyInteger("TargetID");
                if ($targetId > 0) {
                    $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
                    if ($stateVarId !== false) {
                        $this->RequestAction('ToggleFan', !GetValue($stateVarId));
                    }
                }
                break;
            case "Active": // Das muss mit dem Ident in RegisterVariable und dem JS-Aufruf übereinstimmen
                $this->SetValue($Ident, $Value);
                $this->UpdateTile();
                break;
        }
    }

    private function CheckHumidity(float $currentValue) {
        $limitOn = $this->GetValue("LimitOn");
        $limitOff = $this->GetValue("LimitOff");
        $timerActive = $this->GetTimerInterval("MaxRunTimer") > 0;

        if ($currentValue > $limitOn && !$timerActive) {
            $this->StartFan();
        } elseif ($currentValue < $limitOff && $timerActive) {
            $this->StopFan();
        }
    }

    public function ToggleFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId <= 0 || !IPS_InstanceExists($targetId)) return;

        $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
        if ($stateVarId === false) return;

        if (!GetValueBoolean($stateVarId)) {
            $this->StartFan();
        } else {
            $this->StopFan();
        }
    }

    public function StopFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            Z2M_WriteValueBoolean($targetId, 'state', false);
        }
        $this->SetTimerInterval("MaxRunTimer", 0);
        $this->UpdateTile();
    }
    
    public function StartFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        $runTime = $this->GetValue("RunTime");
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            Z2M_WriteValueBoolean($targetId, 'state', true);
            // Timer nur starten, wenn eine Laufzeit > 0 gesetzt ist
            $this->SetTimerInterval("MaxRunTimer", $runTime * 60 * 1000);
        }
        $this->UpdateTile();
    }

    private function UpdateTile() {
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }
    
    private function GetFullUpdateMessage() {
        $sensorId = $this->ReadPropertyInteger("SensorID");
        $targetId = $this->ReadPropertyInteger("TargetID");
        $stateVarId = ($targetId > 0) ? @IPS_GetObjectIDByIdent("state", $targetId) : false;
        
        $data = [
            "humidity"   => ($sensorId > 0 && IPS_VariableExists($sensorId)) ? GetValue($sensorId) : 0,
            "fanState"   => ($stateVarId !== false) ? GetValueBoolean($stateVarId) : false,
            "autoActive" => $this->GetValue("Active"),
            "limitOn"    => $this->GetValue("LimitOn"),
            "limitOff"   => $this->GetValue("LimitOff"),
            "runTime"    => $this->GetValue("RunTime")
        ];

        return json_encode($data);
    }

    public function GetVisualizationTile() {
        $html = @file_get_contents(__DIR__ . '/module.html');
        if ($html === false) return "Fehler: module.html fehlt!";

        $data = $this->GetFullUpdateMessage();

        $initialScript = '
            <script>
                (function() {
                    var data = ' . $data . ';
                    var tryUpdate = function() {
                        if (typeof handleMessage === "function") {
                            handleMessage(data);
                        } else {
                            setTimeout(tryUpdate, 10);
                        }
                    };
                    if (document.readyState === "complete" || document.readyState === "interactive") {
                        tryUpdate();
                    } else {
                        window.addEventListener("DOMContentLoaded", tryUpdate);
                    }
                })();
            </script>';

        return $html . $initialScript;
    }
}