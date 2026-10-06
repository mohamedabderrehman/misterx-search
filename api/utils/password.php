<?php

class Password {
    /**
     * Hash password
     */
    public static function hash($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }
    
    /**
     * Verify password
     */
    public static function verify($password, $hash) {
        return password_verify($password, $hash);
    }
}

