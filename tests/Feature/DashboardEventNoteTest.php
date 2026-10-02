<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardEventNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardEventNoteTest extends TestCase
{
    use RefreshDatabase;

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
