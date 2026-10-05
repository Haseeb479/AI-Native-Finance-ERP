<?php

return [
    'remote_disk' => env('BACKUP_REMOTE_DISK'),
    'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),
    'createdb_binary' => env('BACKUP_CREATEDB_BINARY', 'createdb'),
    'dropdb_binary' => env('BACKUP_DROPDB_BINARY', 'dropdb'),
    'restore' => [
        'host' => env('BACKUP_RESTORE_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('BACKUP_RESTORE_DB_PORT', env('DB_PORT', 5432)),
        'username' => env('BACKUP_RESTORE_DB_USERNAME', env('DB_USERNAME')),
        'password' => env('BACKUP_RESTORE_DB_PASSWORD', env('DB_PASSWORD')),
        'maintenance_database' => env('BACKUP_RESTORE_DB_MAINTENANCE_DATABASE', 'postgres'),
    ],
];
