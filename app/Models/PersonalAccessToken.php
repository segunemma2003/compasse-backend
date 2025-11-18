<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function getConnectionName()
    {
        $connection = Config::get('database.default');
        
        if ($connection === 'tenant') {
            return $connection;
        }
        
        return $connection ?: 'mysql';
    }
    
    public function newQuery()
    {
        $connection = $this->getConnectionName();
        $this->setConnection($connection);
        return parent::newQuery();
    }
    
    public static function findToken($token)
    {
        $connection = Config::get('database.default', 'mysql');
        
        if (!Config::has("database.connections.{$connection}")) {
            $connection = 'mysql';
        }
        
        $db = DB::connection($connection);
        
        if (strpos($token, '|') === false) {
            $found = $db->table('personal_access_tokens')
                ->where('token', hash('sha256', $token))
                ->first();
            if ($found) {
                $instance = new static();
                $instance->setConnection($connection);
                $instance->setRawAttributes((array) $found, true);
                $instance->exists = true;
                return $instance;
            }
            return null;
        }

        [$id, $token] = explode('|', $token, 2);

        $found = $db->table('personal_access_tokens')
            ->where('id', $id)
            ->first();

        if (! $found) {
            return null;
        }

        $hashedToken = hash('sha256', $token);

        if (! hash_equals($found->token, $hashedToken)) {
            return null;
        }

        $instance = new static();
        $instance->setConnection($connection);
        $instance->setRawAttributes((array) $found, true);
        $instance->exists = true;
        return $instance;
    }
    
    public static function find($id, $columns = ['*'])
    {
        $connection = Config::get('database.default', 'mysql');
        $instance = (new static)->setConnection($connection);
        return $instance->where($instance->getKeyName(), $id)->first($columns);
    }

    protected function performInsert(\Illuminate\Database\Eloquent\Builder $query)
    {
        if (isset($this->attributes['id']) && !$this->exists) {
            unset($this->attributes['id']);
        }
        
        $connection = $this->getConnectionName();
        $this->setConnection($connection);
        
        return parent::performInsert($query);
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        $connection = $this->getConnectionName();
        $this->setConnection($connection);
        
        return parent::performUpdate($query);
    }
}
