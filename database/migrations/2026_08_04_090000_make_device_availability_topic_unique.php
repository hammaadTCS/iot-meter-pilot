<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `mqtt_topic` has carried a unique index since the devices table was created,
 * but `availability_topic` was added with a PLAIN index
 * (2026_04_21_150000_add_availability_columns_to_devices_table.php). Nothing at
 * the database level stopped two devices sharing a status topic.
 *
 * MeterAvailabilityProcessor resolves the owning device with an exact string
 * match and ->first(), so a collision means whichever row the database happens
 * to return first receives that meter's online/offline state — leaking when
 * another household is home, and silently starving the rightful owner of
 * availability updates.
 *
 * See docs/DEVICE_CLAIMING.md §1.3 and §8.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstExistingDuplicates();

        Schema::table('devices', function (Blueprint $table) {
            // Drop the plain index first: on MySQL the unique index would
            // otherwise be redundant with it, and leaving both wastes writes.
            $table->dropIndex('devices_availability_topic_index');
            $table->unique('availability_topic', 'devices_availability_topic_unique');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_availability_topic_unique');
            $table->index('availability_topic', 'devices_availability_topic_index');
        });
    }

    /**
     * Fail with an actionable message rather than a raw driver error.
     *
     * A duplicate here is not a migration problem — it means two live devices
     * are already fighting over one status topic, which must be resolved as a
     * data question (whose meter is it?) before the constraint can be applied.
     * NULLs are ignored: multiple devices may legitimately have no availability
     * topic, and every supported driver permits repeated NULLs under UNIQUE.
     */
    private function guardAgainstExistingDuplicates(): void
    {
        $duplicates = DB::table('devices')
            ->select('availability_topic', DB::raw('COUNT(*) as total'))
            ->whereNotNull('availability_topic')
            ->where('availability_topic', '!=', '')
            ->groupBy('availability_topic')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('total', 'availability_topic');

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($count, $topic) => "  {$topic} ({$count} devices)")
            ->implode(PHP_EOL);

        throw new RuntimeException(
            'Cannot add a unique index: these availability topics are shared by more than one device.'
            .PHP_EOL.$detail.PHP_EOL
            .'Decide which device owns each topic and clear the others before migrating '
            .'(see docs/DEVICE_CLAIMING.md §1.3).'
        );
    }
};
