<?php
/**
 * Copyright 2016 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace fkooman\VPN\Server\ZeroTier;

use PDO;
use PDOException;

/**
 * Create a link between the user and their ZeroTier client identifiers.
 */
class ClientDb
{
    /** @var PDO */
    private $db;

    /** @var string */
    private $prefix;

    public function __construct(PDO $db, $prefix = '')
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public function register($userId, $clientId)
    {
        $stmt = $this->db->prepare(
            sprintf(
                'INSERT INTO %s (
                    user_id,
                    client_id
                 ) 
                 VALUES(
                    :user_id, 
                    :client_id
                 )',
                $this->prefix.'zt_clients'
            )
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }

        return true;
    }

    public static function createTableQueries($prefix)
    {
        $query = array(
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    user_id VARCHAR(255) NOT NULL,
                    client_id VARCHAR(255) NOT NULL,
                    UNIQUE(user_id, client_id)
                )',
                $prefix.'zt_clients'
            ),
        );

        return $query;
    }

    public function initDatabase()
    {
        $queries = self::createTableQueries($this->prefix);
        foreach ($queries as $q) {
            $this->db->query($q);
        }
    }
}
