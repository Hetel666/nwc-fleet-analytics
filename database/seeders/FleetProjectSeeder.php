<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\Project;
use App\Models\ProjectWialonGeofenceGroup;
use App\Models\ProjectWialonGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FleetProjectSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->projects() as $name) {
            Project::updateOrCreate(
                ['name' => $name],
                [
                    'code' => (string) Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->limit(50, ''),
                    'active' => true,
                ]
            );
        }

        foreach ($this->groups() as $group) {
            $project = Project::where('name', $group['project'])->firstOrFail();

            ProjectWialonGroup::updateOrCreate(
                ['wialon_group_id' => (string) $group['wialon_group_id']],
                [
                    'project_id' => $project->id,
                    'name' => $group['name'],
                    'ownership_type' => $group['ownership_type'],
                ]
            );
        }

        foreach ($this->geofenceGroups() as $group) {
            $project = Project::where('name', $group['project'])->firstOrFail();

            ProjectWialonGeofenceGroup::updateOrCreate(
                [
                    'wialon_resource_id' => (string) $group['wialon_resource_id'],
                    'wialon_geofence_group_id' => (string) $group['wialon_geofence_group_id'],
                ],
                [
                    'project_id' => $project->id,
                    'wialon_resource_name' => $group['wialon_resource_name'],
                    'name' => $group['name'],
                    'zones_count' => $group['zones_count'],
                ]
            );
        }
    }

    private function projects(): array
    {
        return [
            'Ağdam Azərsu',
            'Ağdam Fərrux VIP Villa',
            'Ağdərə təlim mərkəzi',
            'Ağdərə VIP Villa',
            'Daşkəsən Kəlbəcər yol',
            'Dərnəgül kanalizasiya k.',
            'Füzuli Ağdam yol',
            'Füzuli daxili yollar',
            'Füzuli Xocavənd avtomobil yolu',
            'Gədəbəy yol',
            'Karabakh service',
            'Kəlbəcər yol',
            'Layihəsiz',
            'Laçın yol',
            'Mingəçevir su təchizatı və kanalizasiya',
            'Müşviqabad beton boru zavodu',
            'Müşviqabad kanalizasiya k.',
            'Mərkəzi anbar',
            'Naxçıvan dayaq',
            'North West Garage',
            'Petrol HO',
            'Port Baku Project management office',
            'Prefabrik tabor məntəqəsi',
            'Private',
            'Shabran private',
            'Səlyan Azərsu',
            'Transport HO',
            'Xocavənd təlim mərkəzi',
            'Xızı layihəsi',
            'Yuxarı Şirvan LOT1',
            'Yuxarı Şirvan LOT3',
        ];
    }

    private function groups(): array
    {
        return [
            ['wialon_group_id' => 601708440, 'project' => 'Layihəsiz', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => '- Layihəsiz - İcarə'],
            ['wialon_group_id' => 601701958, 'project' => 'Ağdam Azərsu', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Ağdam Azərsu - İcarə'],
            ['wialon_group_id' => 601701957, 'project' => 'Ağdam Azərsu', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Ağdam Azərsu - NWC'],
            ['wialon_group_id' => 601701991, 'project' => 'Ağdam Fərrux VIP Villa', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Ağdam Fərrux VIP Villa - NWC'],
            ['wialon_group_id' => 601701887, 'project' => 'Ağdərə təlim mərkəzi', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Ağdərə təlim mərkəzi - İcarə'],
            ['wialon_group_id' => 601701886, 'project' => 'Ağdərə təlim mərkəzi', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Ağdərə təlim mərkəzi - NWC'],
            ['wialon_group_id' => 601701989, 'project' => 'Ağdərə VIP Villa', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Ağdərə VIP Villa - NWC'],
            ['wialon_group_id' => 601701983, 'project' => 'Daşkəsən Kəlbəcər yol', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Daşkəsən Kəlbəcər yol - İcarə'],
            ['wialon_group_id' => 601701974, 'project' => 'Dərnəgül kanalizasiya k.', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Dərnəgül kanalizasiya k. - İcarə'],
            ['wialon_group_id' => 601701973, 'project' => 'Dərnəgül kanalizasiya k.', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Dərnəgül kanalizasiya k. - NWC'],
            ['wialon_group_id' => 601701922, 'project' => 'Füzuli Ağdam yol', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Füzuli Ağdam yol - İcarə'],
            ['wialon_group_id' => 601701903, 'project' => 'Füzuli Ağdam yol', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Füzuli Ağdam yol - NWC'],
            ['wialon_group_id' => 601701902, 'project' => 'Füzuli daxili yollar', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Füzuli daxili yollar - İcarə'],
            ['wialon_group_id' => 601701901, 'project' => 'Füzuli daxili yollar', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Füzuli daxili yollar - NWC'],
            ['wialon_group_id' => 601701917, 'project' => 'Füzuli Xocavənd avtomobil yolu', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Füzuli Xocavənd avtomobil yolu - İcarə'],
            ['wialon_group_id' => 601701915, 'project' => 'Füzuli Xocavənd avtomobil yolu', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Füzuli Xocavənd avtomobil yolu - NWC'],
            ['wialon_group_id' => 601701876, 'project' => 'Gədəbəy yol', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Gədəbəy yol - İcarə'],
            ['wialon_group_id' => 601701878, 'project' => 'Gədəbəy yol', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Gədəbəy yol - NWC'],
            ['wialon_group_id' => 601702052, 'project' => 'Karabakh service', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Karabakh service - NWC'],
            ['wialon_group_id' => 601701897, 'project' => 'Kəlbəcər yol', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Kəlbəcər yol - İcarə'],
            ['wialon_group_id' => 601701894, 'project' => 'Kəlbəcər yol', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Kəlbəcər yol - NWC'],
            ['wialon_group_id' => 601705305, 'project' => 'Layihəsiz', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Layihəsiz - NWC'],
            ['wialon_group_id' => 601701881, 'project' => 'Laçın yol', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Laçın yol - İcarə'],
            ['wialon_group_id' => 601701875, 'project' => 'Laçın yol', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Laçın yol - NWC'],
            ['wialon_group_id' => 601702039, 'project' => 'Mingəçevir su təchizatı və kanalizasiya', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Mingəçevir su təchizatı və kanalizasiya - NWC'],
            ['wialon_group_id' => 601701964, 'project' => 'Müşviqabad beton boru zavodu', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Müşviqabad beton boru zavodu - İcarə'],
            ['wialon_group_id' => 601701963, 'project' => 'Müşviqabad beton boru zavodu', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Müşviqabad beton boru zavodu - NWC'],
            ['wialon_group_id' => 601701966, 'project' => 'Müşviqabad kanalizasiya k.', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Müşviqabad kanalizasiya k. - İcarə'],
            ['wialon_group_id' => 601704597, 'project' => 'Müşviqabad kanalizasiya k.', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Müşviqabad kanalizasiya k. - NWC'],
            ['wialon_group_id' => 601702042, 'project' => 'Mərkəzi anbar', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Mərkəzi anbar - NWC'],
            ['wialon_group_id' => 601702010, 'project' => 'Naxçıvan dayaq', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Naxçıvan dayaq - NWC'],
            ['wialon_group_id' => 601701998, 'project' => 'North West Garage', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'North West Garage - NWC'],
            ['wialon_group_id' => 601702035, 'project' => 'Petrol HO', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Petrol HO - NWC'],
            ['wialon_group_id' => 601702044, 'project' => 'Port Baku Project management office', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Port Baku Project management office - NWC'],
            ['wialon_group_id' => 601701960, 'project' => 'Prefabrik tabor məntəqəsi', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Prefabrik tabor məntəqəsi - İcarə'],
            ['wialon_group_id' => 601701959, 'project' => 'Prefabrik tabor məntəqəsi', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Prefabrik tabor məntəqəsi - NWC'],
            ['wialon_group_id' => 601702025, 'project' => 'Private', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Private - NWC'],
            ['wialon_group_id' => 601701995, 'project' => 'Shabran private', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Shabran private - NWC'],
            ['wialon_group_id' => 601701956, 'project' => 'Səlyan Azərsu', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Səlyan Azərsu - İcarə'],
            ['wialon_group_id' => 601701955, 'project' => 'Səlyan Azərsu', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Səlyan Azərsu - NWC'],
            ['wialon_group_id' => 601702034, 'project' => 'Transport HO', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Transport HO - NWC'],
            ['wialon_group_id' => 601701972, 'project' => 'Xocavənd təlim mərkəzi', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Xocavənd təlim mərkəzi - İcarə'],
            ['wialon_group_id' => 601701971, 'project' => 'Xocavənd təlim mərkəzi', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Xocavənd təlim mərkəzi - NWC'],
            ['wialon_group_id' => 601701900, 'project' => 'Xızı layihəsi', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Xızı layihəsi - İcarə'],
            ['wialon_group_id' => 601701899, 'project' => 'Xızı layihəsi', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Xızı layihəsi - NWC'],
            ['wialon_group_id' => 601701933, 'project' => 'Yuxarı Şirvan LOT1', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Yuxarı Şirvan LOT1 - İcarə'],
            ['wialon_group_id' => 601701930, 'project' => 'Yuxarı Şirvan LOT1', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Yuxarı Şirvan LOT1 - NWC'],
            ['wialon_group_id' => 601701936, 'project' => 'Yuxarı Şirvan LOT3', 'ownership_type' => Equipment::OWNERSHIP_ICARE, 'name' => 'Yuxarı Şirvan LOT3 - İcarə'],
            ['wialon_group_id' => 601701935, 'project' => 'Yuxarı Şirvan LOT3', 'ownership_type' => Equipment::OWNERSHIP_NWC, 'name' => 'Yuxarı Şirvan LOT3 - NWC'],
        ];
    }

    private function geofenceGroups(): array
    {
        return [
            [
                'project' => 'Yuxarı Şirvan LOT3',
                'wialon_resource_id' => 601701680,
                'wialon_resource_name' => 'NWCuser2',
                'wialon_geofence_group_id' => 3,
                'name' => 'M00 LOT-3',
                'zones_count' => 4,
            ],
        ];
    }
}
