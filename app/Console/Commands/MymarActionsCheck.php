<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarPendingBookings;
use App\Services\Mymar\MymarSlotGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MymarActionsCheck extends Command
{
    protected $signature = 'mymar:actions-check';

    protected $description = 'Controle le pilote MAR sans placement, probleme, mail ou SMS.';

    public function handle(MymarActionSettings $settings, MymarPendingBookings $pending, MymarSlotGuard $guard): int
    {
        try {
            $config = $settings->load();
            if (! ($config['guard_enabled'] ?? false) || $config['writes_enabled'] !== false
                || ! Schema::hasColumns('mymar_booking_requests', ['id', 'command', 'status', 'cofrac_technician_id', 'reserved_starts_at', 'reserved_ends_at'])) {
                throw new \RuntimeException;
            }
            $ids = array_column($config['bindings'], 'cofrac_user_id');
            if (User::query()->internal()->where('role', 2)->where('admin', false)->whereIn('id', $ids)->count() !== count($ids)) {
                throw new \RuntimeException;
            }
            $pending->page(0, 1, true);
            $settings->token();
            foreach ($ids as $id) {
                $conflicts = $guard->liveConflicts($id, now('Europe/Paris'), now('Europe/Paris')->addHour());
                if (in_array('mymar_unknown', array_column($conflicts, 'type'), true)) {
                    throw new \RuntimeException;
                }
            }
            $this->line(json_encode(['ready' => true, 'mapped_technicians' => count($ids), 'schema_ready' => true,
                'live_availability_read' => true, 'post_requests' => false, 'messages_sent' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"ready":false,"post_requests":false,"messages_sent":false}');

            return self::FAILURE;
        }
    }
}
