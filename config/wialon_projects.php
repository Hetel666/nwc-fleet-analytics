<?php

use App\Models\Equipment;

return [
    'service_groups' => [
        'without_project' => [
            'nwc' => ['id' => '601705305', 'name' => '-Layihəsiz- NWC', 'ownership_type' => Equipment::OWNERSHIP_NWC],
            'icare' => ['id' => '601708440', 'name' => '-Layihəsiz- İcarə', 'ownership_type' => Equipment::OWNERSHIP_ICARE],
        ],
        'totals' => [
            'nwc' => ['id' => '601701870', 'name' => '+NWC+', 'ownership_type' => Equipment::OWNERSHIP_NWC],
            'icare' => ['id' => '601701871', 'name' => '+İcarə+', 'ownership_type' => Equipment::OWNERSHIP_ICARE],
            'nwc_passenger_car' => ['id' => '601708543', 'name' => '+NWC+passenger car+', 'ownership_type' => Equipment::OWNERSHIP_NWC],
        ],
    ],

    'projects' => [
        ['name' => 'Ağdam Azərsu', 'nwc_group_id' => '601701957', 'nwc_group_name' => 'Ağdam Azərsu - NWC', 'icare_group_id' => '601701958', 'icare_group_name' => 'Ağdam Azərsu - İcarə'],
        ['name' => 'Ağdam Fərrux VIP Villa', 'nwc_group_id' => '601701991', 'nwc_group_name' => 'Ağdam Fərrux VIP Villa - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Ağdərə təlim mərkəzi', 'nwc_group_id' => '601701886', 'nwc_group_name' => 'Ağdərə təlim mərkəzi - NWC', 'icare_group_id' => '601701887', 'icare_group_name' => 'Ağdərə təlim mərkəzi - İcarə'],
        ['name' => 'Ağdərə VIP Villa', 'nwc_group_id' => '601701989', 'nwc_group_name' => 'Ağdərə VIP Villa - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Dərnəgül kanalizasiya k.', 'nwc_group_id' => '601701973', 'nwc_group_name' => 'Dərnəgül kanalizasiya k. - NWC', 'icare_group_id' => '601701974', 'icare_group_name' => 'Dərnəgül kanalizasiya k. - İcarə'],
        ['name' => 'Füzuli Ağdam yol', 'nwc_group_id' => '601701903', 'nwc_group_name' => 'Füzuli Ağdam yol - NWC', 'icare_group_id' => '601701922', 'icare_group_name' => 'Füzuli Ağdam yol - İcarə'],
        ['name' => 'Füzuli daxili yollar', 'nwc_group_id' => '601701901', 'nwc_group_name' => 'Füzuli daxili yollar - NWC', 'icare_group_id' => '601701902', 'icare_group_name' => 'Füzuli daxili yollar - İcarə'],
        ['name' => 'Füzuli Xocavənd avtomobil yolu', 'nwc_group_id' => '601701915', 'nwc_group_name' => 'Füzuli Xocavənd avtomobil yolu - NWC', 'icare_group_id' => '601701917', 'icare_group_name' => 'Füzuli Xocavənd avtomobil yolu - İcarə'],
        ['name' => 'Gədəbəy yol', 'nwc_group_id' => '601701878', 'nwc_group_name' => 'Gədəbəy yol - NWC', 'icare_group_id' => '601701876', 'icare_group_name' => 'Gədəbəy yol - İcarə'],
        ['name' => 'Head Office Construction', 'nwc_group_id' => '601702053', 'nwc_group_name' => 'Head Office Construction - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Xızı layihəsi', 'nwc_group_id' => '601701899', 'nwc_group_name' => 'Xızı layihəsi - NWC', 'icare_group_id' => '601701900', 'icare_group_name' => 'Xızı layihəsi - İcarə'],
        ['name' => 'Xocavənd təlim mərkəzi', 'nwc_group_id' => '601701971', 'nwc_group_name' => 'Xocavənd təlim mərkəzi - NWC', 'icare_group_id' => '601701972', 'icare_group_name' => 'Xocavənd təlim mərkəzi - İcarə'],
        ['name' => 'Karabakh service', 'nwc_group_id' => '601702052', 'nwc_group_name' => 'Karabakh service - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Kəlbəcər yol', 'nwc_group_id' => '601701894', 'nwc_group_name' => 'Kəlbəcər yol - NWC', 'icare_group_id' => '601701897', 'icare_group_name' => 'Kəlbəcər yol - İcarə'],
        ['name' => 'Laçın yol', 'nwc_group_id' => '601701875', 'nwc_group_name' => 'Laçın yol - NWC', 'icare_group_id' => '601701881', 'icare_group_name' => 'Laçın yol - İcarə'],
        ['name' => 'Mərkəzi anbar', 'nwc_group_id' => '601702042', 'nwc_group_name' => 'Mərkəzi anbar - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Mingəçevir su təchizatı və kanalizasiya', 'nwc_group_id' => '601702039', 'nwc_group_name' => 'Mingəçevir su təchizatı və kanalizasiya - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Müşviqabad beton boru zavodu', 'nwc_group_id' => '601701963', 'nwc_group_name' => 'Müşviqabad beton boru zavodu - NWC', 'icare_group_id' => '601701964', 'icare_group_name' => 'Müşviqabad beton boru zavodu - İcarə'],
        ['name' => 'Müşviqabad kanalizasiya k.', 'nwc_group_id' => '601704597', 'nwc_group_name' => 'Müşviqabad kanalizasiya k. - NWC', 'icare_group_id' => '601701966', 'icare_group_name' => 'Müşviqabad kanalizasiya k. - İcarə'],
        ['name' => 'Naxçıvan dayaq', 'nwc_group_id' => '601702010', 'nwc_group_name' => 'Naxçıvan dayaq - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'North West Garage', 'nwc_group_id' => '601701998', 'nwc_group_name' => 'North West Garage - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Petrol HO', 'nwc_group_id' => '601702035', 'nwc_group_name' => 'Petrol HO - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Port Baku Project management office', 'nwc_group_id' => '601702044', 'nwc_group_name' => 'Port Baku Project management office - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Prefabrik tabor məntəqəsi', 'nwc_group_id' => '601701959', 'nwc_group_name' => 'Prefabrik tabor məntəqəsi - NWC', 'icare_group_id' => '601701960', 'icare_group_name' => 'Prefabrik tabor məntəqəsi - İcarə'],
        ['name' => 'Private', 'nwc_group_id' => '601702025', 'nwc_group_name' => 'Private - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Səlyan Azərsu', 'nwc_group_id' => '601701955', 'nwc_group_name' => 'Səlyan Azərsu - NWC', 'icare_group_id' => '601701956', 'icare_group_name' => 'Səlyan Azərsu - İcarə'],
        ['name' => 'Shabran private', 'nwc_group_id' => '601701995', 'nwc_group_name' => 'Shabran private - NWC', 'icare_group_id' => '601727031', 'icare_group_name' => 'Shabran private - İcarə'],
        ['name' => 'Transport HO', 'nwc_group_id' => '601702034', 'nwc_group_name' => 'Transport HO - NWC', 'icare_group_id' => null, 'icare_group_name' => null],
        ['name' => 'Yuxarı Şirvan LOT1', 'nwc_group_id' => '601701930', 'nwc_group_name' => 'Yuxarı Şirvan LOT1 - NWC', 'icare_group_id' => '601701933', 'icare_group_name' => 'Yuxarı Şirvan LOT1 - İcarə'],
        ['name' => 'Yuxarı Şirvan LOT3', 'nwc_group_id' => '601701935', 'nwc_group_name' => 'Yuxarı Şirvan LOT3 - NWC', 'icare_group_id' => '601701936', 'icare_group_name' => 'Yuxarı Şirvan LOT3 - İcarə'],
    ],

    'geofence_groups' => [
        ['project' => 'Yuxarı Şirvan LOT1', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '1', 'name' => 'Yuxarı Şirvan LOT-1', 'zones_count' => 5],
        ['project' => 'Yuxarı Şirvan LOT3', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '2', 'name' => 'Yuxarı Şirvan LOT-3', 'zones_count' => 10],
        ['project' => 'Yuxarı Şirvan LOT3', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '3', 'name' => 'M000', 'zones_count' => 5],
        ['project' => 'Müşviqabad kanalizasiya k.', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '4', 'name' => 'Müşviqabad Azərsu + Beton zavod', 'zones_count' => 3],
        ['project' => 'Xızı layihəsi', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '5', 'name' => 'Xızı project', 'zones_count' => 2],
        ['project' => 'Ağdərə təlim mərkəzi', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '6', 'name' => 'Ağdərə təlim mərkəzi', 'zones_count' => 1],
        ['project' => 'Ağdam Azərsu', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '7', 'name' => 'Ağdam Azərsu', 'zones_count' => 2],
        ['project' => 'Ağdərə VIP Villa', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '8', 'name' => 'Ağdərə VIP villa', 'zones_count' => 1],
        ['project' => 'Naxçıvan dayaq', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '9', 'name' => 'Naxçıvan dayaq', 'zones_count' => 2],
        ['project' => 'Prefabrik tabor məntəqəsi', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '10', 'name' => 'Prefabrik tabor məntəqəsi', 'zones_count' => 3],
        ['project' => 'Füzuli Xocavənd avtomobil yolu', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '11', 'name' => 'Füzuli Xocavənd yol', 'zones_count' => 4],
        ['project' => 'Füzuli Ağdam yol', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '12', 'name' => 'Füzuli Ağdam yol', 'zones_count' => 5],
        ['project' => 'Füzuli daxili yollar', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '13', 'name' => 'Füzuli daxili yollar', 'zones_count' => 1],
        ['project' => 'Xocavənd təlim mərkəzi', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '14', 'name' => 'Xocavənd təlim mərkəzi', 'zones_count' => 1],
        ['project' => 'Gədəbəy yol', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '15', 'name' => 'Gədəbəy yol', 'zones_count' => 3],
        ['project' => 'Mərkəzi anbar', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '16', 'name' => 'Mərkəzi anbar', 'zones_count' => 2],
        ['project' => 'Dərnəgül kanalizasiya k.', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '17', 'name' => 'Dərnəgül Azərsu', 'zones_count' => 1],
        ['project' => 'Ağdam Fərrux VIP Villa', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '18', 'name' => 'Ağdam Fərrux Vip Villa', 'zones_count' => 2],
        ['project' => 'Kəlbəcər yol', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '19', 'name' => 'Kəlbəcər yol', 'zones_count' => 8],
        ['project' => 'Laçın yol', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '20', 'name' => 'Laçın yol', 'zones_count' => 7],
        ['project' => 'Mingəçevir su təchizatı və kanalizasiya', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '21', 'name' => 'Mingəçevir su təchizatı', 'zones_count' => 1],
        ['project' => 'Səlyan Azərsu', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '22', 'name' => 'Salyan Azərsu', 'zones_count' => 1],
        ['project' => 'Shabran private', 'wialon_resource_id' => '601701680', 'wialon_resource_name' => 'NWCuser2', 'wialon_geofence_group_id' => '27', 'name' => 'Şabran private', 'zones_count' => 1],
    ],

    'geofence_zone_project_overrides' => [
        '601701680:3' => 'Müşviqabad beton boru zavodu',
        '601701680:27' => 'North West Garage',
        '601701680:40' => 'Yuxarı Şirvan LOT1',
    ],

    'shared_home_geofences' => [
        'Müşviqabad Azərsu + Beton zavod' => [
            'Müşviqabad beton boru zavodu',
            'Müşviqabad kanalizasiya k.',
        ],
    ],
];
