<?php
// includes/Permission.php

class SimplePermission {
    private static $permissions = [];
    private static $initialized = false;
    
    public static function init($user_id) {
        if (self::$initialized) {
            return;
        }
        
        // Try to get permissions from session first
        if (isset($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions'])) {
            self::$permissions = $_SESSION['user_permissions'];
        } else {
            // Fallback to database lookup
            self::$permissions = self::loadUserPermissions($user_id);
            // Store in session for future requests
            $_SESSION['user_permissions'] = self::$permissions;
        }
        
        self::$initialized = true;
    }
    
    public static function can($permission) {
        if (empty(self::$permissions)) {
            return false;
        }
        
        return in_array($permission, self::$permissions) || in_array('*', self::$permissions);
    }
    
    public static function any($permissions) {
        foreach ((array)$permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }
        return false;
    }
    
    public static function all($permissions) {
        foreach ((array)$permissions as $permission) {
            if (!self::can($permission)) {
                return false;
            }
        }
        return true;
    }
    
    public static function getPermissions() {
        return self::$permissions;
    }
    
    public static function reload($user_id) {
        self::$initialized = false;
        self::$permissions = [];
        if (isset($_SESSION['user_permissions'])) {
            unset($_SESSION['user_permissions']);
        }
        self::init($user_id);
    }
    
    private static function loadUserPermissions($user_id) {
        global $mysqli;
        
        $permissions = [];
        
        try {
            // Same logic as above version...
            $role_sql = "SELECT urp.role_id FROM user_role_permissions urp WHERE urp.user_id = ? AND urp.is_active = 1";
            $role_stmt = $mysqli->prepare($role_sql);
            $role_stmt->bind_param('i', $user_id);
            $role_stmt->execute();
            $role_result = $role_stmt->get_result();
            $role_row = $role_result->fetch_assoc();
            
            $role_id = $role_row['role_id'] ?? 0;
            
            if ($role_id > 0) {
                $sql = "SELECT p.permission_name 
                        FROM role_permissions rp
                        JOIN permissions p ON rp.permission_id = p.permission_id
                        WHERE rp.role_id = ? AND rp.permission_value = 1";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('i', $role_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    if (!empty($row['permission_name'])) {
                        $permissions[] = $row['permission_name'];
                    }
                }
            }
            
            // Handle user permission overrides...
            $user_perm_sql = "SELECT p.permission_name, up.permission_value
                             FROM user_permissions up
                             JOIN permissions p ON up.permission_id = p.permission_id
                             WHERE up.user_id = ?";
            $user_perm_stmt = $mysqli->prepare($user_perm_sql);
            $user_perm_stmt->bind_param('i', $user_id);
            $user_perm_stmt->execute();
            $user_perm_result = $user_perm_stmt->get_result();
            
            while ($user_row = $user_perm_result->fetch_assoc()) {
                $perm_name = $user_row['permission_name'];
                $perm_value = $user_row['permission_value'];
                
                if ($perm_value == 1) {
                    if (!in_array($perm_name, $permissions)) {
                        $permissions[] = $perm_name;
                    }
                } else {
                    $key = array_search($perm_name, $permissions);
                    if ($key !== false) {
                        unset($permissions[$key]);
                    }
                }
            }
            
            if (empty($permissions)) {
                $permissions = ['dashboard.php'];
            }
            
        } catch (Exception $e) {
            error_log("Error loading permissions: " . $e->getMessage());
            $permissions = ['dashboard.php'];
        }
        
        return $permissions;
    }
}
?>