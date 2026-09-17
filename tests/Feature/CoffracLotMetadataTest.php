<?php

use App\Models\Appointment;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Services\CoffracAppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends the saved lot name and delegataire instead of stale import metadata', function (string $name) {
    $lot = Lot::query()->create([
        'name' => $name,
        'type' => Lot::TYPE_FULL_CONTROL,
        'delegataire' => 'Delegataire du lot',
    ]);
    $row = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Beneficiaire',
        'installer_name' => 'Installateur',
        'address' => '10 Rue de Paris',
    ]);
    $appointment = new Appointment;
    $appointment->setRelation('service', null)->setRelation('technician', null);

    $payload = (new ReflectionMethod(CoffracAppointmentService::class, 'remoteLotCreationPayload'))->invoke(
        app(CoffracAppointmentService::class), $appointment, [
            'lot_appointment_id' => $row->id,
            'company_name' => $row->customer_name,
            'installer_name' => $row->installer_name,
            'external_payload' => [
                'lot_id' => 999999,
                'lot_name' => 'Ancien nom',
                'lot_delegataire' => 'Ancien delegataire',
                'lot_type' => Lot::TYPE_FULL_CONTACT_CONTROL,
            ],
        ],
    );

    expect($payload)->toMatchArray([
        'lot_id' => $lot->id,
        'lot_name' => $name,
        'lot_type' => Lot::TYPE_FULL_CONTROL,
        'delegataire' => 'Delegataire du lot',
        'beneficiary_name' => 'Beneficiaire',
        'installer_name' => 'Installateur',
    ]);
})->with(['Lot saisi manuellement', 'fichier-source-2026-09']);
