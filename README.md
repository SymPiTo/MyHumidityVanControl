# MyHumidityVanControl
Dieses IP-Symcon Modul ermöglicht eine intelligente Steuerung eines Lüfters 
basierend auf der Luftfeuchtigkeit. 
Es ist speziell für die Nutzung mit Zigbee2MQTT (Z2M) Aktoren optimiert und 
enthält Sicherheitsfunktionen wie eine Hysterese und eine automatische Laufzeitbegrenzung.

### Funktionen
- Das Modul überwacht eine ausgewählte Feuchtigkeitsvariable und steuert einen Aktor nach folgender Logik:

- Einschalten: Wenn die Feuchtigkeit > 50 % steigt, wird der Lüfter eingeschaltet.

- Laufzeitbegrenzung: Mit dem Einschalten startet ein 5-Minuten-Timer. 
  Nach Ablauf dieser Zeit schaltet der Lüfter automatisch aus (Schutz vor Dauerbetrieb).

- Ausschalten (Hysterese): Sinkt die Feuchtigkeit unter 48 %, bevor der Timer abgelaufen ist, schaltet der Lüfter sofort aus.

- Reaktivierung: Sollte nach Ablauf des 5-Minuten-Timers die Feuchtigkeit immer noch über 50 % liegen, 
  startet der Lüfter beim nächsten Update des Sensors erneut.

### Vorasusetzungen
- IP-Symcon 5.5 oder neuer.

- Eine installierte Zigbee2MQTT Instanz in IP-Symcon für den Lüfter-Aktor.

- Ein Feuchtigkeitssensor (z. B. Zigbee, HomeMatic, etc.), der seine Werte an IP-Symcon liefert.

### Installation
1. Öffne die IP-Symcon Konsole.

2. Navigiere zu Module Control (Kern-Instanzen -> Modules).

3. Füge die URL deines GitHub-Repositories hinzu.

4. Erstelle eine neue Instanz des Moduls MyHumidityVanControl.

### Konfiguration
Feld                    Beschreibung  

Modul aktivieren        Schaltet die gesamte Automatik an oder aus.

Feuchtigkeits-Sensor    Die Variable, die den aktuellen Luftfeuchtigkeitswert liefert.

Lüfter Aktor            Die Zigbee2MQTT-Instanz des Geräts, das geschaltet werden soll.

### Technische Details
Nachrichten (Messages)
Das Modul nutzt das VM_UPDATE Ereignis (ID 10603). Dadurch reagiert der Lüfter auf jedes Sendeintervall des Sensors, 
auch wenn sich der Wert nicht geändert hat. Dies stellt sicher, dass die Logik auch nach einem Timer-Ablauf sofort wieder greift, 
wenn die Feuchtigkeit noch zu hoch ist.

Timer
Der interne Name des Timers ist MaxRunTimer. Er ist auf 300.000 ms (5 Minuten) eingestellt.

Debug
Detaillierte Informationen über Schaltvorgänge und Messwerte können über das Debug-Fenster der Instanz eingesehen werden.

