<?php

namespace App\Console\Commands;

use App\Models\GeofenceViolationReportRow;
use App\Services\GeofenceViolationReportImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use JsonException;

class ImportGeofenceViolationReport extends Command
{
    protected $signature = 'geofence-violations:import
        {file : Absolute path to a normalized Geofence Pozuntuları api JSON file}
        {--generated-at= : Report generation timestamp}';

    protected $description = 'Import normalized rows from the Geofence Pozuntuları api report';

    public function handle(GeofenceViolationReportImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The report file does not exist or is not readable.');

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (($payload['report_name'] ?? null) !== GeofenceViolationReportRow::REPORT_NAME) {
            $this->error('The JSON document is not from the Geofence Pozuntuları api report.');

            return self::FAILURE;
        }

        $rows = $payload['rows'] ?? null;

        if (! is_array($rows)) {
            $this->error('The JSON document must contain a rows array.');

            return self::FAILURE;
        }

        $generatedAt = $this->option('generated-at')
            ? Carbon::parse((string) $this->option('generated-at'), config('app.timezone'))
            : null;
        $result = $importer->import(array_values(array_filter($rows, 'is_array')), $generatedAt);

        $this->info("Imported: {$result['imported']}; rejected: {$result['rejected']}.");

        return $result['rejected'] > 0 ? self::INVALID : self::SUCCESS;
    }
}
