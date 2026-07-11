<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\EquipmentDailyStat;
use App\Models\EquipmentType;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->users();

        $projects = collect([
            ['name' => 'Zig Yolu Tikinti Layihəsi', 'code' => 'ZIG'],
            ['name' => 'Material Anbarı', 'code' => 'ANB'],
            ['name' => 'Yanacaq Doldurma Sahəsi', 'code' => 'YDS'],
            ['name' => 'Servis Sahəsi', 'code' => 'SRV'],
            ['name' => 'Qaradağ Zonası', 'code' => 'QRG'],
        ])->map(fn (array $data) => Project::updateOrCreate(
            ['code' => $data['code']],
            ['name' => $data['name'], 'description' => $data['name'].' üzrə demo məlumatları', 'active' => true]
        ));

        $types = collect(['Ekskavator', 'Yük maşını', 'Buldozer', 'Qreyder', 'Vibrokatok', 'Digər'])
            ->map(fn (string $name) => EquipmentType::firstOrCreate(['name' => $name]));

        foreach ($projects as $index => $project) {
            Geofence::updateOrCreate(
                ['name' => $project->name.' sərhədi'],
                [
                    'project_id' => $project->id,
                    'wialon_geofence_id' => 'demo-zone-'.$project->code,
                    'geometry_json' => $this->polygonFor($index),
                    'active' => true,
                ]
            );
        }

        $equipment = collect();

        for ($i = 1; $i <= 50; $i++) {
            $type = $types[($i - 1) % $types->count()];
            $project = $projects[($i - 1) % $projects->count()];
            $ownership = $i % 3 === 0 ? Equipment::OWNERSHIP_ICARE : Equipment::OWNERSHIP_NWC;
            $name = sprintf('%02d-%s-%03d', $i % 12 + 1, ['PT', 'JH', 'KL', 'ME', 'TP'][$i % 5], 100 + $i);

            $equipment->push(Equipment::updateOrCreate(
                ['wialon_unit_id' => 'demo-'.$i],
                [
                    'name' => $name,
                    'registration_number' => 'AZ-'.$i,
                    'equipment_type_id' => $type->id,
                    'project_id' => $project->id,
                    'ownership_type' => $ownership,
                    'calculation_mode' => [Equipment::MODE_ENGINE_HOURS, Equipment::MODE_IGNITION, Equipment::MODE_MILEAGE][$i % 3],
                    'planned_daily_hours' => 10,
                    'active' => true,
                    'last_synced_at' => now(),
                    'last_position_json' => [
                        'lat' => 40.35 + (($i % 10) * 0.012),
                        'lng' => 49.78 + (($i % 8) * 0.014),
                        'speed' => $i % 4 === 0 ? 0 : rand(5, 28),
                        'time' => now()->subMinutes($i * 7)->toDateTimeString(),
                    ],
                ]
            ));
        }

        foreach (range(29, 0) as $daysAgo) {
            $date = Carbon::today()->subDays($daysAgo);

            foreach ($equipment as $item) {
                $base = (($item->id * 13 + $date->dayOfYear) % 110) / 10;
                $workedHours = round(max(0, min(14, $base + ($item->ownership_type === Equipment::OWNERSHIP_NWC ? 1.3 : -0.4))), 2);
                $distance = round($workedHours * (8 + ($item->id % 9)), 2);
                $utilization = round(min(100, ($workedHours / max(1, (float) $item->planned_daily_hours)) * 100), 2);

                EquipmentDailyStat::updateOrCreate(
                    ['stat_date' => $date->toDateString(), 'equipment_id' => $item->id],
                    [
                        'project_id' => $item->project_id,
                        'ownership_type' => $item->ownership_type,
                        'worked_hours' => $workedHours,
                        'distance_km' => $distance,
                        'utilization_percent' => $utilization,
                        'geofence_exit_count' => $item->id % 11 === 0 ? 1 : 0,
                        'outside_geofence_minutes' => $item->id % 11 === 0 ? 18 + $item->id : 0,
                        'first_message_at' => $date->copy()->setHour(8)->addMinutes($item->id % 40),
                        'last_message_at' => $date->copy()->setHour(18)->addMinutes($item->id % 50),
                        'calculation_source' => 'demo',
                        'calculation_status' => 'ok',
                    ]
                );
            }
        }

        $geofences = Geofence::query()->get();
        foreach ($equipment->take(12) as $index => $item) {
            $exitAt = now()->subDays($index % 7)->subHours($index + 2);
            GeofenceEvent::updateOrCreate(
                ['equipment_id' => $item->id, 'exit_at' => $exitAt],
                [
                    'project_id' => $item->project_id,
                    'geofence_id' => $geofences->firstWhere('project_id', $item->project_id)?->id,
                    'return_at' => $index % 3 === 0 ? null : $exitAt->copy()->addMinutes(25 + $index * 4),
                    'outside_minutes' => 25 + $index * 4,
                    'max_distance_meters' => 120 + $index * 35,
                    'status' => $index % 3 === 0 ? 'outside' : 'returned',
                ]
            );
        }
    }

    private function users(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Demo Admin', 'password' => Hash::make('password'), 'role' => 'admin']
        );

        User::updateOrCreate(
            ['email' => 'viewer@example.com'],
            ['name' => 'Demo Viewer', 'password' => Hash::make('password'), 'role' => 'viewer']
        );
    }

    private function polygonFor(int $index): array
    {
        $lat = 40.35 + ($index * 0.035);
        $lng = 49.78 + ($index * 0.025);

        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$lng, $lat],
                [$lng + 0.11, $lat + 0.02],
                [$lng + 0.13, $lat + 0.08],
                [$lng + 0.05, $lat + 0.12],
                [$lng - 0.03, $lat + 0.07],
                [$lng, $lat],
            ]],
        ];
    }
}
