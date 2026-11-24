<?php
declare(strict_types=1);

function fixtureTable(string $table,array $lines):string {return 'CREATE TABLE `'.$table."` (\n  ".implode(",\n  ",$lines)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";}
function snapshotSql():string {
 $sql=fixtureTable('users',[
  '`id` bigint unsigned NOT NULL AUTO_INCREMENT','`email` varchar(254) NOT NULL',
  '`username` varchar(50) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL',
  '`display_name` varchar(150) NOT NULL','`password_hash` varchar(255) DEFAULT NULL',
  "`is_active` tinyint(1) NOT NULL DEFAULT '1'","`auth_version` bigint unsigned NOT NULL DEFAULT '1'",
  '`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP','`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'PRIMARY KEY (`id`)','UNIQUE KEY `email` (`email`)','UNIQUE KEY `uq_users_username` (`username`)',
  "CONSTRAINT `chk_users_username_format` CHECK ((`username` is null) or regexp_like(`username`,_utf8mb4'^[A-Za-z0-9_.-]{3,50}$',_utf8mb4'c'))"
 ]);
 $sql.=fixtureTable('drivers',array_merge([
  '`id` bigint unsigned NOT NULL AUTO_INCREMENT','`staff_code` varchar(50) NOT NULL','`display_name` varchar(150) NOT NULL',
  '`contact_phone` varchar(30) DEFAULT NULL',"`is_active` tinyint(1) NOT NULL DEFAULT '1'",'`created_by` bigint unsigned NOT NULL','`updated_by` bigint unsigned NOT NULL',
  '`created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)','`updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)',
  'PRIMARY KEY (`id`)', 'CONSTRAINT `fk_driver_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)'
 ]));
 $sql.=fixtureTable('roles',['`id` smallint unsigned NOT NULL','PRIMARY KEY (`id`)']);
 $sql.=fixtureTable('schema_migrations',['`id` bigint unsigned NOT NULL','PRIMARY KEY (`id`)']);
 $sql.=fixtureTable('auth_login_buckets',[
  '`bucket_scope` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','`bucket_digest` binary(32) NOT NULL','`window_started_at` bigint unsigned NOT NULL',
  "`attempt_count` smallint unsigned NOT NULL DEFAULT '0'",'`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
  '`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'PRIMARY KEY (`bucket_scope`,`bucket_digest`)',"CONSTRAINT `chk_auth_login_bucket_scope` CHECK (`bucket_scope` in (_ascii'identifier',_ascii'source'))"
 ]);
 $sql.=fixtureTable('vehicles',['`id` bigint unsigned NOT NULL','`registration_number` varchar(30) NOT NULL',
  '`registration_province` varchar(100) DEFAULT NULL','`fuel_type` varchar(100) DEFAULT NULL','PRIMARY KEY (`id`)',
  'UNIQUE KEY `uq_vehicle_registration_number` (`registration_number`)']);
 $sql.=fixtureTable('booking_requests',['`id` bigint unsigned NOT NULL','`requested_vehicle_id` bigint unsigned DEFAULT NULL',
  '`a4_request_data` json DEFAULT NULL','PRIMARY KEY (`id`)','KEY `idx_booking_requested_vehicle` (`requested_vehicle_id`)',
  'CONSTRAINT `fk_booking_requested_vehicle` FOREIGN KEY (`requested_vehicle_id`) REFERENCES `vehicles` (`id`)']);
 return $sql;
}
