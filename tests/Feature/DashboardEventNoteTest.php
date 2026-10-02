<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardEventNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardEventNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_reopening_business_date_restores_only_its_concrete_event(): void
    {
        $user = User::factory()->create(['active' => true]);
        $service = app(DashboardEventNoteService::class);
        $source = (object) [
            'business_date' => '2026-09-10',
            'project_id' => null,
            'wialon_unit_id' => '601000814',
            'efficiency_status' => '0_1',
        ];
        $item = [
            'event_key' => $service->efficiencyEventKey($source),
            'dashboard_key' => DashboardEventNoteService::DASHBOARD_GENERAL_EFFICIENCY,
            'event_type' => 'work_status',
            'event_date' => '2026-09-10',
            'wialon_unit_id' => $source->wialon_unit_id,
            'unit_name' => '110-FB-814',
            'event_status' => '0_1',
            'note' => 'Təmirdə olub',
            'investigation_status' => 'justified',
        ];
        $this->actingAs($user)->postJson(route('dashboard.event-notes.store'), ['items' => [$item]])->assertOk();

        // A refreshed source record gets a new database ID but represents the same event.
        $source->id = 999;
        $source->engine_seconds = 1800;
        $row = [...$item, 'event_key' => $service->efficiencyEventKey($source)];
        $restored = app(DashboardEventNoteService::class)->attachNotes([$row], true)[0];
        $this->assertSame('Təmirdə olub', $restored['note']);
        $this->assertSame('justified', $restored['investigation_status']);

        foreach ([
            ['business_date' => '2026-09-11'],
            ['efficiency_status' => 'no_data'],
            ['project_id' => 42],
            ['wialon_unit_id' => '601000815'],
        ] as $change) {
            $other = (object) [...(array) $source, ...$change];
            $key = $service->efficiencyEventKey($other);
            $this->assertNotSame($item['event_key'], $key);
            $newEvent = $service->attachNotes([[...$row, 'event_key' => $key]], true)[0];
            $this->assertSame('', $newEvent['note']);
            $this->assertSame('investigating', $newEvent['investigation_status']);
        }
        $this->assertDatabaseCount('dashboard_event_notes', 1);
    }

    public function test_bulk_save_preserves_note_status_and_separate_dates(): void
    {
        $user = User::factory()->create(['active' => true]);
        $items = collect(['2026-10-01', '2026-10-02'])->map(fn (string $date): array => [
            'event_key' => sha1('unit-1|'.$date),
            'dashboard_key' => DashboardEventNoteService::DASHBOARD_GENERAL_EFFICIENCY,
            'event_type' => 'work_status',
            'event_date' => $date,
            'wialon_unit_id' => 'unit-1',
            'note' => 'Service '.$date,
            'investigation_status' => 'repair',
        ])->all();

        $this->actingAs($user)->postJson(route('dashboard.event-notes.store'), ['items' => $items])
            ->assertOk()->assertJson(['saved' => 2]);
        $this->assertDatabaseCount('dashboard_event_notes', 2);

        $rows = app(DashboardEventNoteService::class)->attachNotes($items, true);
        $this->assertSame('Service 2026-10-01', $rows[0]['note']);
        $this->assertSame('Service 2026-10-02', $rows[1]['note']);
        $this->assertSame('Təmir', $rows[0]['investigation_status_label']);

        $this->actingAs($user)->postJson(route('dashboard.event-notes.store'), ['items' => [[
            ...$items[0], 'dashboard_key' => DashboardEventNoteService::DASHBOARD_GEOFENCE_VIOLATIONS,
        ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.investigation_status');
    }

    public function test_user_can_save_and_update_dashboard_event_note(): void
    {
        $user = User::factory()->create(['active' => true]);
        $payload = [
            'items' => [
                [
                    'event_key' => sha1('general-efficiency|2026-09-10|110-FB-814|0_1'),
                    'dashboard_key' => DashboardEventNoteService::DASHBOARD_GENERAL_EFFICIENCY,
                    'event_type' => 'work_status',
                    'event_date' => '2026-09-10',
                    'wialon_unit_id' => '110-FB-814',
                    'unit_name' => '110-FB-814',
                    'event_status' => '0_1',
                    'note' => 'Təmirdə olub',
                ],
            ],
        ];

        $this->actingAs($user)
            ->postJson(route('dashboard.event-notes.store'), $payload)
            ->assertOk()
            ->assertJson(['saved' => 1]);

        $this->assertDatabaseHas('dashboard_event_notes', [
            'event_key' => $payload['items'][0]['event_key'],
            'note' => 'Təmirdə olub',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('dashboard.event-notes.store'), [
                'items' => [[...$payload['items'][0], 'note' => 'Servisə göndərilib']],
            ])
            ->assertOk();

        $this->assertDatabaseCount('dashboard_event_notes', 1);
        $this->assertDatabaseHas('dashboard_event_notes', [
            'event_key' => $payload['items'][0]['event_key'],
            'note' => 'Servisə göndərilib',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_geofence_violation_investigation_status_is_validated(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)
            ->postJson(route('dashboard.event-notes.store'), [
                'items' => [
                    [
                        'event_key' => sha1('geofence|bad-status'),
                        'dashboard_key' => DashboardEventNoteService::DASHBOARD_GEOFENCE_VIOLATIONS,
                        'event_type' => 'outside_project_geofence',
                        'event_date' => '2026-09-10',
                        'investigation_status' => 'wrong',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.investigation_status');

        $this->assertDatabaseCount('dashboard_event_notes', 0);
    }
}
