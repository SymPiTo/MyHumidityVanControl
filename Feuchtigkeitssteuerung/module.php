<?php

declare(strict_types=1);

class MyHumidityVanControl extends IPSModule {

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften registrieren
        $this->RegisterPropertyInteger("SensorID", 0);
        $this->RegisterPropertyInteger("TargetID", 0);
        $this->RegisterPropertyBoolean("Active", true);
        
        // Timer für die maximale Laufzeit (5 Minuten = 300.000 Millisekunden)
        // Er ruft die öffentliche Funktion StopFan auf
        $this->RegisterTimer("MaxRunTimer", 0, 'MYHUM_StopFan($_IPS[\'TARGET\']);');
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
            $this->RegisterMessage($sensorId, 10603);
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
            // $Data[0] enthält den aktuellen Wert der Variable
            $this->CheckHumidity($Data[0]);
        }
    }

    private function CheckHumidity(float $currentValue) {
        $targetId = $this->ReadPropertyInteger("TargetID");
        
        // Prüfen, ob der Timer gerade läuft
        $timerActive = $this->GetTimerInterval("MaxRunTimer") > 0;

        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            
            // LOGIK A: Einschalten
            // Wenn Feuchte > 50% UND der Lüfter-Timer NICHT läuft
            if ($currentValue > 50 && !$timerActive) {
                $this->SendDebug("Control", "Feuchtigkeit ($currentValue%) > 50% -> Lüfter AN", 0);
                $this->StarFan();
            } 
            
            // LOGIK B: Ausschalten über Feuchtigkeit
            // Wenn Feuchte < 48% UND der Lüfter-Timer läuft noch
            elseif ($currentValue < 48 && $timerActive) {
                $this->SendDebug("Control", "Feuchtigkeit ($currentValue%) < 48% -> Lüfter AUS", 0);
                $this->StopFan();
            }
        }
    }

    /**
     * Diese Funktion ist öffentlich, damit der Timer sie aufrufen kann.
     * Sie kann auch manuell über die Konsole aufgerufen werden.
     */
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
    
    public function StarFan() {
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