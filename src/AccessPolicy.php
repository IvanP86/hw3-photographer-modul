<?php

class AccessPolicy
{
    public function isAllowed($environment, $bitrixAvailable)
    {
        if ($environment === 'local') {
            return true;
        }

        if (!$bitrixAvailable) {
            return false;
        }

        global $USER;
        if (!is_object($USER) || !method_exists($USER, 'IsAuthorized') || !$USER->IsAuthorized()) {
            return false;
        }

        $userId = (int) $USER->GetID();
        return in_array($userId, array(1, 357), true);
    }
}
