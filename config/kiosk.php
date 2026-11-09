<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PIN Length
    |--------------------------------------------------------------------------
    |
    | Number of digits of the kiosk PIN.
    |
    */

    'pin_length' => 6,

    /*
    |--------------------------------------------------------------------------
    | Session Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds of inactivity after which a person that unlocked the kiosk with
    | the PIN has to enter the PIN again.
    |
    */

    'session_timeout' => 120,

    /*
    |--------------------------------------------------------------------------
    | PIN Attempts
    |--------------------------------------------------------------------------
    |
    | Maximum wrong PIN attempts per person and minute. In addition the wrong
    | attempts of all persons at one device are limited, so that common PINs
    | can not be tried for many persons. The device is locked for the given
    | seconds after the first wrong attempt of the window.
    |
    */

    'pin_max_attempts' => 5,

    'device_max_failed_pins' => 15,

    'device_lock_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | Trivial PINs
    |--------------------------------------------------------------------------
    |
    | PINs that are too easy to guess. Repeated digits (000000) and ascending
    | or descending sequences (123456, 654321) are always rejected.
    |
    */

    'trivial_pins' => ['121212', '112233', '123123', '696969', '131313', '159753'],

];
