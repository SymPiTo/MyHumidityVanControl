<?php

declare(strict_types=1);

class MyHumidityVanControl extends IPSModule {

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften registrieren
        $this->RegisterPropertyInteger("SensorID", 0);  // Feuchtigkeitssensor Variable
        $this->RegisterPropertyInteger("TargetID", 0);  //Lüfter Instanz    
        $this->RegisterPropertyInteger("SwitchID", 0); // Manueller Schalter (Instanz)
        $this->RegisterPropertyInteger("LimitOn", 50);
        $this->RegisterPropertyInteger("LimitOff", 45);
        $this->RegisterPropertyBoolean("Active", true);
        
        // Timer für die maximale Laufzeit (5 Minuten = 300.000 Millisekunden)
        // Er ruft die öffentliche Funktion StopFan auf
        $this->RegisterTimer("MaxRunTimer", 0, 'MHVC_StopFan($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Alle Nachrichten für dieses Modul abmelden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Neue Registrierung für die Sensor-ID (10603 = VM_UPDATE)
        $sensorId = $this->ReadPropertyInteger("SensorID");
        if ($sensorId > 0 && IPS_VariableExists($sensorId)) {
            $this->RegisterMessage($sensorId, 10603); //VM_UPDATE
        }

        // 2. Registrierung für manuellen Schalter (Variable innerhalb der Instanz)
        $switchInstanceId = $this->ReadPropertyInteger("SwitchID");
        if ($switchInstanceId > 0 && IPS_InstanceExists($switchInstanceId)) {
            $switchVarId = @IPS_GetObjectIDByIdent("state", $switchInstanceId);
            if ($switchVarId !== false) {
                $this->RegisterMessage($switchVarId, 10603); // VM_UPDATE
            }
        }

        // Timer beim Übernehmen der Änderungen sicherheitshalber stoppen
        $this->SetTimerInterval("MaxRunTimer", 0);

        // Wenn das Modul deaktiviert wird, Lüfter sofort ausmachen
        if (!$this->ReadPropertyBoolean("Active")) {
            $this->StopFan();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
 // $Message 10603 = VM_UPDATE
        if ($Message == 10603) {
            $sensorId = $this->ReadPropertyInteger("SensorID");
            $switchInstanceId = $this->ReadPropertyInteger("SwitchID");
            $switchVarId = @IPS_GetObjectIDByIdent("state", $switchInstanceId);

            // Fall A: Feuchtigkeitssensor (nur wenn Modul aktiv)
            if ($SenderID == $sensorId && $this->ReadPropertyBoolean("Active")) {
                $this->CheckHumidity($Data[0]);
            }

            // Fall B: Manueller Schalter (Toggle Logik)
            if ($SenderID == $switchVarId) {
                $this->ToggleFan();
            }
        }
    }

    private function CheckHumidity(float $currentValue) {
        $targetId = $this->ReadPropertyInteger("TargetID");
        $limitOn = $this->ReadPropertyInteger("LimitOn");
        $limitOff = $this->ReadPropertyInteger("LimitOff");
        
        $timerActive = $this->GetTimerInterval("MaxRunTimer") > 0;

        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            // Einschalten: Aktueller Wert > LimitOn
            if ($currentValue > $limitOn && !$timerActive) {
                $this->SendDebug("Control", "Sensor: $currentValue% > $limitOn% -> AN", 0);
                $this->StartFan();
            } 
            // Ausschalten: Aktueller Wert < LimitOff
            elseif ($currentValue < $limitOff && $timerActive) {
                $this->SendDebug("Control", "Sensor: $currentValue% < $limitOff% -> AUS", 0);
                $this->StopFan();
            }
        }
    }

    /**
     * Diese Funktion ist öffentlich, damit der Timer sie aufrufen kann.
     * Sie kann auch manuell über die Konsole aufgerufen werden.
     */
    public function ToggleFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        if ($targetId <= 0 || !IPS_InstanceExists($targetId)) return;

        // Status des Lüfters ermitteln
        $stateVarId = @IPS_GetObjectIDByIdent("state", $targetId);
        if ($stateVarId === false) return;

        $isCurrentlyOn = GetValueBoolean($stateVarId);
        $isModuleActive = $this->ReadPropertyBoolean("Active");

        if (!$isCurrentlyOn) {
            $this->SendDebug("Manual", "Toggle -> Einschalten", 0);
            Z2M_WriteValueBoolean($targetId, 'state', true);
            
            // Timer nur setzen, wenn Modul aktiv ist
            if ($isModuleActive) {
                $this->SetTimerInterval("MaxRunTimer", 5 * 60 * 1000);
            } else {
                $this->SetTimerInterval("MaxRunTimer", 0);
            }
        } else {
            $this->SendDebug("Manual", "Toggle -> Ausschalten", 0);
            $this->StopFan();
        }
    }

    public function StopFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            $this->SendDebug("Control", "Ausschaltbefehl wird gesendet.", 0);
            
            // Z2M Befehl zum Ausschalten
            Z2M_WriteValueBoolean($targetId, 'state', false);
        }
        
        // Timer stoppen, egal ob er durch Zeitablauf oder Feuchte ausgelöst wurde
        $this->SetTimerInterval("MaxRunTimer", 0);
    }
    
    public function StartFan() {
        $targetId = $this->ReadPropertyInteger("TargetID");
        
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            $this->SendDebug("Control", "Einschaltbefehl wird gesendet.", 0);
            
            // Z2M Befehl zum Einschalten
            Z2M_WriteValueBoolean($targetId, 'state', true);
        }
        
        // Timer auf 5 Minuten starten
        $this->SetTimerInterval("MaxRunTimer", 5 * 60 * 1000);
    }    
}