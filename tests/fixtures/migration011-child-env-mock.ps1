$required=@{
    MIGRATION_011_APPLY_DB_HOST='127.0.0.1'
    MIGRATION_011_APPLY_DB_PORT='3306'
    MIGRATION_011_APPLY_DB_DATABASE='up_van_booking_test'
    MIGRATION_011_APPLY_DB_USERNAME='up_van_migration011_apply'
}
foreach($name in $required.Keys){if([Environment]::GetEnvironmentVariable($name) -cne $required[$name]){exit 2}}
$secret=[Environment]::GetEnvironmentVariable('MIGRATION_011_APPLY_DB_PASSWORD')
if([string]::IsNullOrEmpty($secret)){exit 3}
if([Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED')){exit 4}
if([Environment]::GetEnvironmentVariable('TEST_MIGRATION_011_APPLY_APPROVED')){exit 5}
Write-Output 'MOCK_CHILD_CREDENTIAL_RECEIVED=TRUE'
exit 0
