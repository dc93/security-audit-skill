<?php
// Restores a "remember me" preferences blob from the client.

class Preferences
{
    public $theme = 'default';
    public $logFile = null;

    public function __destruct()
    {
        if ($this->logFile !== null) {
            // Intended to flush a debug log on shutdown.
            file_put_contents($this->logFile, "session end\n", FILE_APPEND);
        }
    }
}

function loadPreferences(): Preferences
{
    if (!empty($_COOKIE['prefs'])) {
        $prefs = unserialize(base64_decode($_COOKIE['prefs']));
        if ($prefs instanceof Preferences) {
            return $prefs;
        }
    }
    return new Preferences();
}
