<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\Migration010DriverValidator;
use App\Database\MigrationPlan;
use App\Database\SafetyException;

try {
    MigrationPlan::load(include010: true);
    if ($argv === [$argv[0], '--expected-ledger']) {
        foreach (array_slice(MigrationPlan::driver010LedgerEntries(), 0, 9) as $entry) {
            echo implode("\t", ['LEDGER', $entry['version'], $entry['sha256'], 'applied',
                $entry['statement_count'], $entry['statement_count']]), PHP_EOL;
        }
        exit(0);
    }
    $expectedTables = array_slice($argv, 2);
    if ($expectedTables === [] || count(array_unique($expectedTables)) !== count($expectedTables)
        || !in_array('schema_migrations', $expectedTables, true)
        || !in_array('users', $expectedTables, true) || !in_array('drivers', $expectedTables, true)
        || !in_array('roles', $expectedTables, true)) {
        throw new SafetyException('BACKUP_EXPECTED_INVENTORY_REJECTED');
    }
    $file = new SplFileObject($argv[1], 'rb');
    $created = $dataSections = $ledger = [];
    $definitionTable = $activeData = $lastLine = null;
    $identityHeader = $versionHeader = $emailNotNull = false;
    foreach ($file as $item) {
        $line = rtrim((string)$item, "\r\n");
        if ($line === '') continue;
        $lastLine = $line;
        $identityHeader = $identityHeader || preg_match('/^-- Host:\s+127\.0\.0\.1\s+Database:\s+up_van_booking_test\s*$/D', $line);
        $versionHeader = $versionHeader || preg_match('/^-- Server version\s+8\.4\.9\s*$/D', $line);
        if (preg_match('/^CREATE TABLE `([a-z_][a-z0-9_]*)` \($/D', $line, $m)) {
            $definitionTable = $m[1];
            if (isset($created[$definitionTable])) throw new SafetyException('BACKUP_DUPLICATE_TABLE');
            $created[$definitionTable] = true;
        } elseif ($definitionTable !== null && str_starts_with($line, ') ENGINE=')) {
            $definitionTable = null;
        } elseif ($definitionTable === 'users' && preg_match('/^\s+`email` varchar\(254\)(?: CHARACTER SET \w+)?(?: COLLATE \w+)? NOT NULL(?:,|\s|$)/i', $line)) {
            $emailNotNull = true;
        } elseif (($definitionTable === 'users' && preg_match('/^\s+`nickname` /', $line))
            || ($definitionTable === 'drivers' && preg_match('/^\s+`user_id` |^\s+.*`(?:uq_drivers_user_id|fk_drivers_user)`/', $line))) {
            throw new SafetyException('BACKUP_PARTIAL_MIGRATION_010');
        }

        if (preg_match('/^LOCK TABLES `([a-z_][a-z0-9_]*)` WRITE;$/D', $line, $m)) {
            if ($activeData !== null || !isset($created[$m[1]]) || isset($dataSections[$m[1]])) {
                throw new SafetyException('BACKUP_DATA_SECTION_REJECTED');
            }
            $activeData = $m[1];
        } elseif ($line === 'UNLOCK TABLES;') {
            if ($activeData === null) throw new SafetyException('BACKUP_DATA_SECTION_REJECTED');
            $dataSections[$activeData] = true;
            $activeData = null;
        } elseif (str_starts_with($line, 'INSERT INTO ')) {
            if (!preg_match('/^INSERT INTO `([a-z_][a-z0-9_]*)` VALUES \(/', $line, $m) ||
                $m[1] !== $activeData || !str_ends_with($line, ');')) {
                throw new SafetyException('BACKUP_INCOMPLETE_ROW_OR_WRONG_TABLE');
            }
            if ($activeData === 'schema_migrations') {
                if (!preg_match("~^INSERT INTO `schema_migrations` VALUES \\('(?<version>[0-9]{3}_[a-z0-9_]+)','(?<checksum>[a-f0-9]{64})','(?<status>[a-z_]+)',(?<completed_statements>\\d+),(?<total_statements>\\d+),~D", $line, $row)) {
                    throw new SafetyException('BACKUP_LEDGER_ROW_REJECTED');
                }
                $ledger[] = $row;
            } elseif ($activeData === 'roles' && preg_match("~^INSERT INTO `roles` VALUES \\(\\d+,'driver',~i", $line)) {
                throw new SafetyException('BACKUP_DRIVER_ROLE_ALREADY_PRESENT');
            }
        } elseif (preg_match('/^\s*(?:\/\*!\d+\s*)?(?:USE |CREATE DATABASE |CREATE .*?\bVIEW )/i', $line)) {
            throw new SafetyException('BACKUP_SCHEMA_SWITCH_OR_VIEW_IN_BASE_DUMP');
        }
    }
    $actual = array_keys($created);
    $sections = array_keys($dataSections);
    sort($actual);
    sort($sections);
    sort($expectedTables);
    if (!$identityHeader || !$versionHeader || !$emailNotNull || $definitionTable !== null || $activeData !== null
        || $actual !== $expectedTables || $sections !== $expectedTables
        || !preg_match('/^-- Dump completed on \d{4}-\d{2}-\d{2}/', (string)$lastLine)) {
        throw new SafetyException('BACKUP_DUMP_IDENTITY_OR_COVERAGE_REJECTED');
    }
    Migration010DriverValidator::assertLedger($ledger, false);
    echo json_encode(['dump_coverage'=>'PASS','base_tables'=>count($actual),'ledger'=>'001-009 applied','migration_010'=>'absent']), PHP_EOL;
} catch (Throwable $error) {
    $reason = $error instanceof SafetyException && preg_match('/\A[A-Z0-9_]+\z/D', $error->getMessage())
        ? $error->getMessage() : 'BACKUP_OFFLINE_CHECK_FAILED';
    fwrite(STDERR, $reason.PHP_EOL);
    exit(1);
}
