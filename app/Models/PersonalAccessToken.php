<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Illuminate\Support\Facades\Config;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Get the database connection for the model.
     * This ensures Sanctum uses the current database connection (tenant or main)
     */
    public function getConnectionName()
    {
        // Use the current default database connection
        // This will be the tenant database if tenant middleware has run,
        // or the main database for superadmin routes
        $connection = Config::get('database.default');
        
        // Ensure the connection is properly configured
        if ($connection === 'tenant') {
            // If tenant connection is set, use it
            return $connection;
        }
        
        // Default to mysql for main database
        return $connection ?: 'mysql';
    }
    
    /**
     * Override newQuery to ensure we use the correct connection
     */
    public function newQuery()
    {
        $connection = $this->getConnectionName();
        
        // Set the connection on the model instance
        $this->setConnection($connection);
        
        return parent::newQuery();
    }
    
    /**
     * Override the static findToken method to ensure we use the correct connection
     * This is called by Sanctum's authentication middleware
     */
    public static function findToken($token)
    {
        // Get the current connection (tenant or main)
        $connection = Config::get('database.default', 'mysql');
        
        // Ensure connection is configured
        if (!Config::has("database.connections.{$connection}")) {
            // Fallback to mysql if tenant connection not configured
            $connection = 'mysql';
        }
        
        // Use DB facade directly to ensure we query the correct database
        $db = \Illuminate\Support\Facades\DB::connection($connection);
        
        // Ensure we're using the correct database
        if (strpos($token, '|') === false) {
            $found = $db->table('personal_access_tokens')
                ->where('token', hash('sha256', $token))
                ->first();
            if ($found) {
                $instance = new static();
                $instance->setConnection($connection);
                $instance->setRawAttributes((array) $found, true);
                return $instance;
            }
            return null;
        }

        [$id, $token] = explode('|', $token, 2);

        // Use the query builder with correct connection to find the token
        $found = $db->table('personal_access_tokens')
            ->where('id', $id)
            ->first();

        if (! $found) {
            return null;
        }

        // Verify the token hash matches
        $hashedToken = hash('sha256', $token);

        if (! hash_equals($found->token, $hashedToken)) {
            return null;
        }

        // Create model instance from the found record
        $instance = new static();
        $instance->setConnection($connection);
        $instance->setRawAttributes((array) $found, true);
        return $instance;
    }
    
    /**
     * Override the find method to ensure we use the correct connection
     */
    public static function find($id, $columns = ['*'])
    {
        $connection = Config::get('database.default', 'mysql');
        $instance = (new static)->setConnection($connection);
        return $instance->where($instance->getKeyName(), $id)->first($columns);
    }
}
