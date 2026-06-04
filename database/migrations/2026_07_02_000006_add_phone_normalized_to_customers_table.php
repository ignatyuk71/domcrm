<?php

use App\Models\Customer;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_normalized')->nullable()->after('phone')->index();
        });

        // Бекфіл для наявних клієнтів, щоб дедуплікація працювала з першого дня.
        Customer::query()->whereNotNull('phone')->chunkById(500, function ($customers) {
            foreach ($customers as $customer) {
                $normalized = PhoneNormalizer::normalize($customer->phone);
                if ($normalized) {
                    $customer->phone_normalized = $normalized;
                    $customer->saveQuietly();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('phone_normalized');
        });
    }
};
